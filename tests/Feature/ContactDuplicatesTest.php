<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\Task;
use App\Models\User;
use App\Services\Contacts\ContactDuplicates;
use App\Services\Contacts\ContactMerge;
use App\Services\Pipeline\SalesPipeline;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Finding and merging people registered twice. */
class ContactDuplicatesTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function c(string $phone, array $o = []): Contact
    {
        return Contact::create($o + ['user_id' => $this->owner->id, 'name' => 'C' . $phone, 'contact' => $phone, 'status' => 'ativo']);
    }

    public function test_phone_key_ignores_format_country_code_and_the_extra_nine(): void
    {
        $k = ContactDuplicates::phoneKey('5595991234567');
        $this->assertSame($k, ContactDuplicates::phoneKey('+55 (95) 99123-4567'));
        $this->assertSame($k, ContactDuplicates::phoneKey('95991234567'));
        $this->assertSame($k, ContactDuplicates::phoneKey('559591234567'));   // old numbers without the 9
        $this->assertNotSame($k, ContactDuplicates::phoneKey('5595991234568'));
        $this->assertNull(ContactDuplicates::phoneKey('tg:12345678901'));
        $this->assertNull(ContactDuplicates::phoneKey('ana@x.com'));
        $this->assertNull(ContactDuplicates::phoneKey('12345'));
    }

    public function test_groups_by_phone_and_email_and_joins_them(): void
    {
        $a = $this->c('5595991234567');
        $b = $this->c('95991234567', ['email' => 'Ana@X.com']);
        $d = $this->c('5511988887777', ['email' => 'ana@x.com ']);   // same e-mail as b -> one group of three
        $this->c('5511900000000');                                  // alone
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        Contact::create(['user_id' => $other->id, 'name' => 'x', 'contact' => '5595991234567', 'status' => 'ativo']);

        $groups = app(ContactDuplicates::class)->groups($this->owner->id);

        $this->assertCount(1, $groups);
        $this->assertSame('both', $groups[0]['reason']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $d->id], $groups[0]['contacts']->pluck('id')->all());
    }

    public function test_ignored_groups_are_not_suggested_again(): void
    {
        $this->c('5595991234567');
        $this->c('95991234567');
        $this->actingAs($this->owner);

        $g = app(ContactDuplicates::class)->groups($this->owner->id);
        $this->post(route('contacts.duplicates.ignore'), ['keys' => $g[0]['keys']])->assertRedirect();

        $this->assertSame([], app(ContactDuplicates::class)->groups($this->owner->id));
    }

    public function test_merge_moves_everything_and_fills_gaps(): void
    {
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
        $keep = $this->c('5595991234567', ['name' => '5595991234567', 'score' => 10, 'custom' => ['city' => 'Boa Vista']]);
        $drop = $this->c('95991234567', ['name' => 'Ana Souza', 'email' => 'ana@x.com', 'score' => 70, 'custom' => ['city' => 'X', 'plan' => 'pro']]);
        Contact::whereIn('id', [$keep->id, $drop->id])->update(['score_locked' => false]); // automatic scores (a typed score is a decision and stays)
        $keep = $keep->fresh();
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'contact_id' => $drop->id, 'remote_jid' => 'a@s.whatsapp.net']);
        $f = app(SalesPipeline::class);
        $deal = $f->open($f->defaultFor($this->owner), ['title' => 'D', 'contact_id' => $drop->id]);
        $task = Task::create(['user_id' => $this->owner->id, 'title' => 't', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id, 'contact_id' => $drop->id]);
        DB::table('consent_logs')->insert(['user_id' => $this->owner->id, 'contact_id' => $drop->id, 'action' => 'granted', 'source' => 'form', 'created_at' => now()]);
        $child = $this->c('tg:99', ['linked_contact_id' => $drop->id]);

        $merged = app(ContactMerge::class)->merge($keep, $drop, $this->owner->id);

        $this->assertNull(Contact::find($drop->id));
        $this->assertSame($merged->id, $chat->fresh()->contact_id);
        $this->assertSame($keep->id, $deal->fresh()->contact_id);
        $this->assertSame($keep->id, $task->fresh()->contact_id);
        $this->assertSame($keep->id, $child->fresh()->linked_contact_id);
        $this->assertSame(1, DB::table('consent_logs')->where('contact_id', $keep->id)->count());
        $this->assertSame('Ana Souza', $merged->name);                       // the number-as-name is replaced
        $this->assertSame('ana@x.com', $merged->email);
        $this->assertSame(70, (int) $merged->score);
        $this->assertSame(['city' => 'Boa Vista', 'plan' => 'pro'], $merged->custom);   // the kept one wins
        $this->assertSame($drop->id, (int) DB::table('contact_merges')->value('dropped_id'));
        $this->assertSame('Ana Souza', json_decode(DB::table('contact_merges')->value('snapshot'), true)['name']);
    }

    public function test_an_opt_out_survives_the_merge(): void
    {
        $keep = $this->c('5595991234567', ['consent_status' => 'granted', 'consent_at' => now()->subDay()]);
        $drop = $this->c('95991234567', ['consent_status' => 'revoked', 'consent_source' => 'whatsapp', 'consent_revoked_at' => now()]);

        $m = app(ContactMerge::class)->merge($keep, $drop);

        $this->assertSame('revoked', $m->consent_status);
        $this->assertSame('whatsapp', $m->consent_source);

        $k2 = $this->c('5511999990001');
        $d2 = $this->c('11999990001', ['consent_status' => 'granted', 'consent_source' => 'form', 'consent_at' => now()]);
        $this->assertSame('granted', app(ContactMerge::class)->merge($k2, $d2)->consent_status);
    }

    public function test_guards(): void
    {
        $a = $this->c('5595991234567');
        $tg = $this->c('tg:123');
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $theirs = Contact::create(['user_id' => $other->id, 'name' => 'x', 'contact' => '5595991234567', 'status' => 'ativo']);

        foreach ([[$a, $a, 422], [$a, $tg, 422], [$a, $theirs, 403]] as [$k, $d, $code]) {
            try {
                app(ContactMerge::class)->merge($k, $d);
                $this->fail('expected ' . $code);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame($code, $e->getStatusCode());
            }
        }
        $this->assertNotNull(Contact::find($tg->id));
    }

    public function test_screens_merge_over_http_and_isolation(): void
    {
        $a = $this->c('5595991234567');
        $b = $this->c('95991234567');
        $c = $this->c('559591234567');
        $this->actingAs($this->owner);

        $this->get(route('contacts.duplicates'))->assertOk()->assertSee('Same phone');
        $this->get(route('contacts.index'))->assertOk()->assertSee(route('contacts.duplicates'), false);

        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $theirs = Contact::create(['user_id' => $other->id, 'name' => 'x', 'contact' => '1', 'status' => 'ativo']);
        $this->post(route('contacts.duplicates.merge'), ['keep_id' => $a->id, 'drop_ids' => [$theirs->id]])->assertNotFound();

        $this->post(route('contacts.duplicates.merge'), ['keep_id' => $a->id, 'drop_ids' => [$a->id, $b->id, $c->id]])->assertRedirect(route('contacts.duplicates'));
        $this->assertSame([$a->id], Contact::where('user_id', $this->owner->id)->pluck('id')->all());
        $this->assertNotNull(Contact::find($theirs->id));
        $this->get(route('contacts.duplicates'))->assertSee('No duplicates found.');
    }
}
