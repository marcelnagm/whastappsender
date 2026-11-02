<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactFormSubmissionJob;
use App\Jobs\SendAiReplyJob;
use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Models\Instance;
use App\Models\User;
use App\Services\Forms\ContactFormFields;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        config(['app.url' => 'https://crm.test', 'forms.default_country_code' => '55', 'ai.allow_private_hosts' => false]);
        $this->user = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x', 'ai_enabled' => true, 'ai_mode' => 'auto'])->fresh();
    }

    private function form(array $o = []): ContactForm
    {
        return ContactForm::create($o + ['user_id' => $this->user->id, 'name' => 'Site', 'fields' => ContactFormFields::normalize(ContactFormFields::defaults())]);
    }

    private function wa(string $status = 'connected'): Instance
    {
        return Instance::create(['user_id' => $this->user->id, 'name' => 'Zap', 'instance_name' => 'zap-1', 'status' => $status]);
    }

    private function agent(): void
    {
        AiProfile::create(['user_id' => $this->user->id, 'name' => 'A', 'enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-1234', 'temperature' => 0.5, 'max_tokens' => 200, 'system_prompt' => 'x']);
    }

    private function lead(array $o = []): array
    {
        return $o + ['name' => 'Ana', 'phone' => '(95) 98111-0000', 'email' => 'ana@x.com', 'message' => 'Quero um orçamento'];
    }

    public function test_script_is_served_with_the_forms_address_and_key(): void
    {
        $f = $this->form();
        $r = $this->get('/f/' . $f->public_key . '.js');
        $r->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsString('application/javascript', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('/api/public/forms/' . $f->public_key, $r->getContent());
        $this->assertStringContainsString("'" . $f->public_key . "'", $r->getContent());

        $f->update(['active' => false]);
        $this->get('/f/' . $f->public_key . '.js')->assertNotFound();
        $this->get('/f/nope.js')->assertNotFound();
    }

    public function test_definition_lists_the_configured_fields(): void
    {
        $f = $this->form(['title' => 'Fale conosco', 'accent_color' => '#ff0000']);
        $this->getJson('/api/public/forms/' . $f->public_key)->assertOk()
            ->assertJsonPath('title', 'Fale conosco')->assertJsonPath('accent_color', '#ff0000')
            ->assertJsonCount(4, 'fields')->assertJsonPath('fields.1.type', 'tel');
    }

    public function test_submission_validates_and_normalizes_the_phone(): void
    {
        Queue::fake();
        $f = $this->form();
        $this->postJson('/api/public/forms/' . $f->public_key, ['name' => '', 'phone' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
        $this->assertSame(0, ContactFormSubmission::count());

        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk()->assertJsonPath('ok', true);
        $s = ContactFormSubmission::first();
        $this->assertSame('5595981110000', $s->data['phone']);
        $this->assertSame(1, $f->fresh()->submissions_count);
        Queue::assertPushed(ProcessContactFormSubmissionJob::class);
    }

    public function test_honeypot_stores_nothing(): void
    {
        Queue::fake();
        $f = $this->form();
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead(['_hp' => 'http://spam']))->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(0, ContactFormSubmission::count());
        Queue::assertNothingPushed();
    }

    public function test_allowed_sites_restrict_who_can_submit(): void
    {
        Queue::fake();
        $f = $this->form(['allowed_origins' => "https://meusite.com.br\nloja.com"]);
        $url = '/api/public/forms/' . $f->public_key;

        $this->postJson($url, $this->lead())->assertStatus(403);
        $this->postJson($url, $this->lead(), ['Origin' => 'https://evil.com'])->assertStatus(403);
        $this->postJson($url, $this->lead(), ['Origin' => 'https://notmeusite.com.br'])->assertStatus(403);
        $this->postJson($url, $this->lead(), ['Origin' => 'https://www.meusite.com.br'])->assertOk();
        $this->getJson($url, ['Origin' => 'https://loja.com'])->assertOk();
        $this->getJson($url, ['Origin' => 'https://evil.com'])->assertStatus(403);
    }

    public function test_submissions_are_throttled_per_ip(): void
    {
        Queue::fake();
        config(['forms.throttle_per_minute' => 2]);
        $f = $this->form();
        $url = '/api/public/forms/' . $f->public_key;
        $this->postJson($url, $this->lead())->assertOk();
        $this->postJson($url, $this->lead())->assertOk();
        $this->postJson($url, $this->lead())->assertStatus(429);
    }

    public function test_lead_becomes_contact_and_chat_and_the_ai_answers_on_whatsapp(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $this->wa();
        $this->agent();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Olá Ana! Já te mando o orçamento.']]], 'usage' => []])]);
        $f = $this->form();

        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();

        $c = Contact::first();
        $this->assertSame('5595981110000', $c->contact);
        $this->assertSame('Ana', $c->name);
        $this->assertSame('ana@x.com', $c->email);

        $chat = Chat::first();
        $this->assertSame('5595981110000@s.whatsapp.net', $chat->remote_jid);
        $this->assertSame($c->id, (int) $chat->contact_id);
        $m = ChatMessage::first();
        $this->assertFalse((bool) $m->from_me);
        $this->assertStringContainsString('Quero um orçamento', $m->body);
        $this->assertGreaterThan(0, $chat->unread_count);

        $out = AiMessage::where('role', 'assistant')->first();
        $this->assertSame('Olá Ana! Já te mando o orçamento.', $out->content);
        Queue::assertPushed(SendAiReplyJob::class);

        $s = ContactFormSubmission::first();
        $this->assertSame('whatsapp', $s->delivery);
        $this->assertTrue($s->ai_requested);
        $this->assertSame('processed', $s->status);
        $this->assertSame($c->id, (int) $s->contact_id);
    }

    public function test_existing_contact_is_reused_and_a_brazilian_ninth_digit_variant_matches(): void
    {
        Queue::fake();
        $this->wa();
        $existing = Contact::create(['user_id' => $this->user->id, 'contact' => '559581110000', 'name' => '+559581110000', 'status' => 'ativo']);
        $f = $this->form(['ai_reply' => false]);

        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();
        // (queue is faked: run the job by hand)
        Queue::fake([]);
        (new ProcessContactFormSubmissionJob(ContactFormSubmission::first()->id))->handle(app(\App\Services\Forms\FormSubmissionProcessor::class));

        $this->assertSame(1, Contact::count());
        $this->assertSame('Ana', $existing->fresh()->name);
        $this->assertSame('ana@x.com', $existing->fresh()->email);
    }

    public function test_ai_switched_off_still_lands_in_the_crm_without_a_reply(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $this->wa();
        $this->agent();
        Http::fake();
        $f = $this->form(['ai_reply' => false]);
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();

        $this->assertSame(1, Chat::count());
        $this->assertSame(0, AiMessage::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai'));
        $this->assertFalse((bool) ContactFormSubmission::first()->ai_requested);
    }

    public function test_opted_out_contact_gets_no_ai_message(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $this->wa();
        $this->agent();
        Http::fake();
        Contact::create(['user_id' => $this->user->id, 'contact' => '5595981110000', 'name' => 'Ana', 'ignore_me' => 1, 'status' => 'ativo']);
        $f = $this->form();
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();

        $this->assertSame(1, Chat::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai'));
        Queue::assertNotPushed(SendAiReplyJob::class);
    }

    public function test_without_a_connected_number_the_lead_is_stored_only(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $this->wa('disconnected');
        $f = $this->form();
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();

        $s = ContactFormSubmission::first();
        $this->assertSame('none', $s->delivery);
        $this->assertSame('processed', $s->status);
        $this->assertSame(1, Contact::count());
        $this->assertSame(0, Chat::count());
    }

    public function test_the_form_can_pin_the_number_that_answers(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $a = $this->wa();
        $b = Instance::create(['user_id' => $this->user->id, 'name' => 'Vendas', 'instance_name' => 'vendas-1', 'status' => 'connected']);
        $f = $this->form(['instance_id' => $b->id, 'ai_reply' => false]);
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();
        $this->assertSame($b->id, (int) Chat::first()->instance_id);
    }

    public function test_falls_back_to_a_linked_channel_with_an_open_window(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $this->agent();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Oi!']]], 'usage' => []])]);
        $tg = Instance::create(['user_id' => $this->user->id, 'name' => 'Bot', 'instance_name' => 'tg-1', 'status' => 'connected', 'channel' => 'telegram']);
        $root = Contact::create(['user_id' => $this->user->id, 'contact' => '5595981110000', 'name' => 'Ana', 'status' => 'ativo']);
        $tgContact = Contact::create(['user_id' => $this->user->id, 'contact' => 'tg:777', 'name' => 'Ana TG', 'status' => 'ativo', 'linked_contact_id' => $root->id]);
        Chat::create(['user_id' => $this->user->id, 'instance_id' => $tg->id, 'remote_jid' => 'tg:777', 'contact_id' => $tgContact->id, 'last_message_at' => now()]);
        $f = $this->form();

        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();

        $s = ContactFormSubmission::first();
        $this->assertSame('telegram', $s->delivery);
        Queue::assertPushed(SendAiReplyJob::class, fn ($j) => true);
    }

    public function test_channel_selection_can_exclude_whatsapp(): void
    {
        Queue::fake([SendAiReplyJob::class]);
        $this->wa();
        $f = $this->form(['reply_channels' => ['telegram']]);
        $this->postJson('/api/public/forms/' . $f->public_key, $this->lead())->assertOk();
        $this->assertSame('none', ContactFormSubmission::first()->delivery);
        $this->assertSame(0, Chat::count());
    }

    public function test_field_builder_rules(): void
    {
        $rows = ContactFormFields::normalize([
            ['label' => 'Nome', 'map' => 'name', 'required' => 1],
            ['label' => 'Zap', 'map' => 'phone', 'required' => 1],
            ['label' => 'Interesse', 'type' => 'select', 'options' => "Site\nApp"],
            ['label' => '  '],
        ]);
        $this->assertCount(3, $rows);
        $this->assertSame('tel', $rows[1]['type']);
        $this->assertSame('interesse', $rows[2]['key']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        ContactFormFields::normalize([['label' => 'Nome', 'map' => 'name']]); // no way to contact the person
    }

    public function test_owner_builds_edits_and_removes_a_form_from_the_panel(): void
    {
        $this->actingAs($this->user)->get(route('forms.index'))->assertOk();
        $this->actingAs($this->user)->get(route('forms.create'))->assertOk()->assertSee(__('Fields'));

        $payload = ['name' => 'Landing', 'ai_reply' => 1, 'active' => 1, 'reply_channels' => ['whatsapp'], 'fields' => [
            ['label' => 'Nome', 'map' => 'name', 'required' => 1],
            ['label' => 'WhatsApp', 'map' => 'phone', 'required' => 1],
        ]];
        $this->actingAs($this->user)->post(route('forms.store'), $payload)->assertRedirect();
        $f = ContactForm::first();
        $this->assertSame($this->user->id, (int) $f->user_id);
        $this->assertCount(2, $f->fields);
        $this->actingAs($this->user)->get(route('forms.edit', $f->id))->assertOk()->assertSee($f->scriptUrl());

        $this->actingAs($this->user)->put(route('forms.update', $f->id), ['name' => 'Landing 2', 'fields' => $payload['fields']])->assertRedirect();
        $this->assertSame('Landing 2', $f->fresh()->name);
        $this->assertFalse($f->fresh()->active); // unchecked box = off

        $this->actingAs($this->user)->get(route('forms.submissions', $f->id))->assertOk();
        $this->actingAs($this->user)->delete(route('forms.destroy', $f->id))->assertRedirect();
        $this->assertSame(0, ContactForm::count());
    }

    public function test_nobody_touches_another_accounts_form(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $f = $this->form();
        $this->actingAs($other)->get(route('forms.edit', $f->id))->assertNotFound();
        $this->actingAs($other)->delete(route('forms.destroy', $f->id))->assertNotFound();
        $this->assertSame(1, ContactForm::count());
    }

    public function test_team_roles_for_forms(): void
    {
        $mk = function (string $role) {
            $m = User::create(['name' => $role, 'email' => "$role@t.dev", 'username' => "$role@t.dev", 'password' => 'x']);
            $m->forceFill(['parent_user_id' => $this->user->id, 'team_role' => $role])->save();

            return $m->fresh();
        };
        $this->actingAs($mk('agent'), 'web')->get(route('forms.index'))->assertForbidden();
        $this->actingAs($mk('creator'), 'web')->get(route('forms.index'))->assertOk();
    }
}
