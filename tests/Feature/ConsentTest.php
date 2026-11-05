<?php

namespace Tests\Feature;

use App\Jobs\ProcessEvolutionWebhookJob;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Models\Instance;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Consent\Consent;
use App\Services\Forms\ContactFormFields;
use App\Services\Forms\FormSubmissionProcessor;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Consent register: keywords, proof, campaigns respecting it, forms with a consent box. */
class ConsentTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Instance $wa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        config(['forms.default_country_code' => '55']);
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->wa = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
    }

    private function contact(string $phone, array $o = []): Contact
    {
        return Contact::create($o + ['user_id' => $this->owner->id, 'name' => 'C' . $phone, 'contact' => $phone, 'status' => 'ativo']);
    }

    private function logs(): array
    {
        return DB::table('consent_logs')->orderBy('id')->get()->map(fn ($l) => [$l->contact_id, $l->action, $l->source])->all();
    }

    private function say(string $phone, string $text, bool $fromMe = false): void
    {
        \Illuminate\Support\Facades\Redis::spy();
        (new ProcessEvolutionWebhookJob(['event' => 'messages.upsert', 'instance' => '5595111', 'data' => [
            'key' => ['id' => 'M' . uniqid(), 'remoteJid' => $phone . '@s.whatsapp.net', 'fromMe' => $fromMe],
            'pushName' => 'X', 'message' => ['conversation' => $text], 'messageTimestamp' => now()->timestamp,
        ]]))->handle();
    }

    public function test_keywords_are_whole_message_only(): void
    {
        $this->assertSame('optout', Consent::keyword(' #SAIR '));
        $this->assertSame('optout', Consent::keyword('/stop'));
        $this->assertSame('optout', Consent::keyword('Parar'));
        $this->assertSame('optin', Consent::keyword('#entrar'));
        $this->assertSame('optin', Consent::keyword('/start'));
        $this->assertNull(Consent::keyword('quero parar de pagar'));
        $this->assertNull(Consent::keyword(''));
        $this->assertNull(Consent::keyword(null));
    }

    public function test_whatsapp_opt_out_and_opt_in_keep_the_proof(): void
    {
        $c = $this->contact('5595900000001');
        $this->say('5595900000001', '#sair');
        $c = $c->fresh();
        $this->assertSame('revoked', $c->consent_status);
        $this->assertSame(1, (int) $c->ignore_me);
        $this->assertNotNull($c->consent_revoked_at);

        $this->say('5595900000001', '#entrar');
        $c = $c->fresh();
        $this->assertSame('granted', $c->consent_status);
        $this->assertNull($c->ignore_me);
        $this->assertSame('keyword', $c->consent_source);
        $this->assertSame([[$c->id, 'revoked', 'keyword'], [$c->id, 'granted', 'keyword']], $this->logs());
        $this->assertSame('#entrar', DB::table('consent_logs')->orderByDesc('id')->value('evidence'));
    }

    public function test_opt_out_only_touches_the_account_that_owns_the_number(): void
    {
        $other = User::create(['name' => 'Outro', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x']);
        $mine = $this->contact('5595900000001');
        $theirs = Contact::create(['user_id' => $other->id, 'name' => 'Same person', 'contact' => '5595900000001', 'status' => 'ativo']);

        $this->say('5595900000001', 'stop');
        $this->assertSame('revoked', $mine->fresh()->consent_status);
        $this->assertSame('unknown', $theirs->fresh()->consent_status);
        $this->assertNull($theirs->fresh()->ignore_me);
    }

    public function test_what_the_attendant_types_is_not_the_customers_choice(): void
    {
        $c = $this->contact('5595900000001');
        $this->say('5595900000001', '#sair', true);
        $this->assertSame('unknown', $c->fresh()->consent_status);
    }

    public function test_opting_out_on_one_channel_stops_the_same_person_everywhere(): void
    {
        $wa = $this->contact('5595900000001');
        $tg = $this->contact('tg:777', ['status' => 'telegram', 'linked_contact_id' => $wa->id]);

        app(Consent::class)->revoke($tg, 'keyword', '/stop');
        $this->assertSame('revoked', $wa->fresh()->consent_status);
        $this->assertSame(1, (int) $wa->fresh()->ignore_me);
        $this->assertCount(2, $this->logs());
    }

    public function test_granting_twice_is_recorded_once(): void
    {
        $c = $this->contact('5595900000001');
        app(Consent::class)->grant($c, 'manual', 'ligação', null, 1);
        app(Consent::class)->grant($c->fresh(), 'manual', 'de novo');
        $this->assertCount(1, $this->logs());
    }

    public function test_campaigns_respect_consent_only_when_the_account_requires_it(): void
    {
        $yes = $this->contact('5595900000001');
        app(Consent::class)->grant($yes, 'manual');
        $this->contact('5595900000002'); // no record
        $no = $this->contact('5595900000003');
        app(Consent::class)->revoke($no, 'keyword');

        $audience = new CampaignAudience();
        $count = fn () => $audience->count($this->owner->id, ['channels' => ['whatsapp']])['total'];
        $this->assertSame(2, $count()); // off: everyone who did not opt out (as always)

        $this->owner->forceFill(['consent_required' => true])->save();
        $this->assertSame(1, $count()); // on: only the one with consent
        $this->assertSame([$yes->id], Contact::withConsent($this->owner->id)->where('ignore_me', null)->pluck('id')->all());
    }

    // ---- screen, manual and import ---------------------------------------------------------------------------------------

    public function test_register_page_switch_manual_record_and_csv_proof(): void
    {
        $c = $this->contact('5595900000001', ['email' => 'a@x.com']);
        $this->actingAs($this->owner)->post("/consent/contacts/{$c->id}", ['action' => 'granted', 'evidence' => '=HYPERLINK("x")'])->assertRedirect();
        $this->assertSame('granted', $c->fresh()->consent_status);

        $this->actingAs($this->owner)->get('/consent')->assertOk()->assertSee('C5595900000001');
        $this->actingAs($this->owner)->post('/consent/settings', ['consent_required' => '1'])->assertRedirect();
        $this->assertTrue((bool) $this->owner->fresh()->consent_required);
        $this->actingAs($this->owner)->post('/consent/settings', [])->assertRedirect();
        $this->assertFalse((bool) $this->owner->fresh()->consent_required);

        $csv = $this->actingAs($this->owner)->get('/consent/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('granted,manual', $csv);
        $this->assertStringContainsString("\"'=HYPERLINK", $csv); // never a live formula
    }

    public function test_only_roles_that_manage_contacts_reach_the_register(): void
    {
        $m = User::create(['name' => 'at', 'email' => 'at@t.dev', 'username' => 'at@t.dev', 'password' => 'secret123']);
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT])->save();
        $this->actingAs($m->fresh())->get('/consent')->assertForbidden();
    }

    public function test_another_account_cannot_change_a_contact(): void
    {
        $c = $this->contact('5595900000001');
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->post("/consent/contacts/{$c->id}", ['action' => 'revoked'])->assertNotFound();
        $this->assertSame('unknown', $c->fresh()->consent_status);
    }

    public function test_contact_created_with_consent_leaves_its_proof(): void
    {
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'Imp', 'contact' => '5595900000009', 'status' => 'ativo', 'consent_status' => 'granted', 'consent_source' => 'import', 'consent_at' => now()]);
        $this->assertSame([[$c->id, 'granted', 'import']], $this->logs());
    }

    // ---- forms ----------------------------------------------------------------------------------------------------------

    private function form(?string $consent): ContactForm
    {
        return ContactForm::create(['user_id' => $this->owner->id, 'name' => 'Site', 'fields' => ContactFormFields::normalize(ContactFormFields::defaults()), 'consent_text' => $consent]);
    }

    private function lead(array $o = []): array
    {
        return $o + ['name' => 'Ana', 'phone' => '(95) 98111-0000', 'email' => 'ana@x.com', 'message' => 'Oi'];
    }

    public function test_form_with_consent_text_requires_the_box_and_keeps_what_was_accepted(): void
    {
        Queue::fake();
        $f = $this->form('Aceito receber mensagens.');
        $url = '/api/public/forms/' . $f->public_key;

        $this->getJson($url)->assertOk()->assertJsonPath('consent', 'Aceito receber mensagens.');
        $this->postJson($url, $this->lead())->assertStatus(422)->assertJsonValidationErrors(['_consent']);
        $this->assertSame(0, ContactFormSubmission::count());

        $this->postJson($url, $this->lead(['_consent' => true]))->assertOk();
        $this->assertSame('Aceito receber mensagens.', ContactFormSubmission::first()->consent_text);
    }

    public function test_form_without_consent_text_asks_for_nothing(): void
    {
        Queue::fake();
        $f = $this->form(null);
        $this->getJson('/api/public/forms/' . $f->public_key)->assertJsonPath('consent', null);
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();
        $this->assertNull(ContactFormSubmission::first()->consent_text);
    }

    public function test_processing_a_form_with_consent_grants_it_with_the_proof(): void
    {
        $f = $this->form('Aceito receber mensagens.');
        $s = ContactFormSubmission::create(['contact_form_id' => $f->id, 'user_id' => $this->owner->id, 'status' => 'pending', 'ip' => '203.0.113.9',
            'consent_text' => 'Aceito receber mensagens.', 'data' => ['name' => 'Ana', 'phone' => '5595981110000', 'email' => 'ana@x.com', 'message' => 'Oi']]);
        app(FormSubmissionProcessor::class)->process($s->fresh());

        $c = Contact::where('contact', '5595981110000')->first();
        $this->assertSame('granted', $c->consent_status);
        $this->assertSame('form', $c->consent_source);
        $log = DB::table('consent_logs')->where('contact_id', $c->id)->first();
        $this->assertSame('203.0.113.9', $log->ip);
        $this->assertStringContainsString('Aceito receber mensagens.', $log->evidence);
        $this->assertStringContainsString('Site', $log->evidence);
    }

    public function test_form_without_the_box_leaves_no_record(): void
    {
        $f = $this->form(null);
        $s = ContactFormSubmission::create(['contact_form_id' => $f->id, 'user_id' => $this->owner->id, 'status' => 'pending',
            'data' => ['name' => 'Ana', 'phone' => '5595981110000', 'email' => 'ana@x.com', 'message' => 'Oi']]);
        app(FormSubmissionProcessor::class)->process($s->fresh());
        $this->assertSame('unknown', Contact::where('contact', '5595981110000')->value('consent_status'));
    }
}
