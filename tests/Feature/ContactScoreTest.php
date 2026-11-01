<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Services\Channels\ContactLinks;
use App\Services\Contacts\ContactScorer;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class ContactScoreTest extends TestCase
{
    use CreatesTestSchema;

    private User $user;
    private Instance $wa;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->user = User::create(['name' => 'u', 'email' => 'u@t.dev', 'username' => 'u', 'password' => 'x']);
        $this->wa = Instance::create(['user_id' => $this->user->id, 'name' => 'Zap', 'instance_name' => 'zap1', 'status' => 'connected']);
    }

    private function contact(string $number = '5511999990000', array $o = []): Contact
    {
        return Contact::create($o + ['user_id' => $this->user->id, 'contact' => $number, 'name' => null, 'status' => 'ativo']);
    }

    private function msg(Contact $c, bool $fromMe, float $hoursAgo, ?Instance $inst = null): void
    {
        $inst ??= $this->wa;
        $chat = Chat::where('instance_id', $inst->id)->where('contact_id', $c->id)->first()
            ?? Chat::create(['user_id' => $this->user->id, 'instance_id' => $inst->id, 'remote_jid' => 'j' . $c->id, 'contact_id' => $c->id]);
        $this->seq++;
        ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $inst->id, 'user_id' => $this->user->id, 'message_id' => 'M' . $this->seq, 'from_me' => $fromMe,
            'type' => 'text', 'body' => 'x', 'status' => $fromMe ? 'sent' : 'received', 'message_timestamp' => now()->subMinutes((int) ($hoursAgo * 60)),
        ]);
    }

    private function job(Contact $c, string $evo, float $hoursAgo = 5): void
    {
        $t = now()->subMinutes((int) ($hoursAgo * 60))->toDateTimeString();
        DB::table('whatsapp_job')->insert(['endpoint' => 'x', 'status' => 'processado', 'message_id' => 'W' . (++$this->seq), 'evolution_status' => $evo, 'user_id' => $this->user->id, 'contact_id' => $c->id, 'created_at' => $t, 'updated_at' => $t]);
    }

    public function test_silent_contact_scores_low_and_engaged_contact_ranks_hot(): void
    {
        $silent = $this->contact('5511000000001');
        $this->assertLessThan(15, (new ContactScorer())->calculate($silent)['score']);

        $hot = $this->contact('5511000000002', ['name' => 'Ana', 'email' => 'a@b.co']);
        for ($i = 0; $i < 6; $i++) {
            $this->msg($hot, true, 30 - $i);       // we write
            $this->msg($hot, false, 29.5 - $i);    // they answer
        }
        $this->job($hot, 'READ');
        $this->job($hot, 'DELIVERY_ACK');
        $r = (new ContactScorer())->calculate($hot);

        $this->assertSame('hot', $r['tier']);
        $this->assertGreaterThanOrEqual(70, $r['score']);
        $this->assertLessThanOrEqual(100, $r['score']);
        $this->assertSame(5, $r['breakdown']['profile']);
        $this->assertSame(5, $r['breakdown']['conversation']);
    }

    public function test_recency_decays_and_ranking_order_makes_sense(): void
    {
        $today = $this->contact('5511000000003');
        $this->msg($today, false, 1);
        $old = $this->contact('5511000000004');
        $this->msg($old, false, 24 * 60);   // 60 days ago
        $s = new ContactScorer();

        $this->assertGreaterThan($s->calculate($old)['score'], $s->calculate($today)['score']);
        $this->assertSame(30, $s->calculate($today)['breakdown']['recency'] >= 29 ? 30 : 0);
    }

    public function test_undelivered_inactive_and_opted_out_are_penalised(): void
    {
        $s = new ContactScorer();
        $base = $this->contact('5511000000005');
        $this->msg($base, false, 2);
        $ok = $s->calculate($base)['score'];

        $bad = $this->contact('5511000000006');
        $this->msg($bad, false, 2);
        $this->job($bad, 'sent', 80);
        $this->job($bad, 'sent', 70);
        $this->assertLessThan($ok, $s->calculate($bad)['score']);
        $this->assertLessThan(0, $s->calculate($bad)['breakdown']['penalties']);

        $d = $this->contact('5511000000007', ['status' => 'inativo', 'deactivated_reason' => 'undelivered']);
        $this->msg($d, false, 2);
        $this->assertSame(-40, $s->calculate($d)['breakdown']['penalties']);

        $nowa = $this->contact('5511000000008', ['status' => 'no-whatsapp']);
        $this->msg($nowa, false, 1);
        $this->assertSame(0, $s->calculate($nowa)['score']);
        $optout = $this->contact('5511000000009', ['ignore_me' => true]);
        $this->msg($optout, false, 1);
        $this->assertSame(0, $s->calculate($optout)['score']);
    }

    public function test_activity_on_linked_channels_counts_for_the_whole_person(): void
    {
        $ig = Instance::create(['user_id' => $this->user->id, 'name' => 'Ig', 'instance_name' => 'ig-1', 'status' => 'connected', 'channel' => 'instagram']);
        $person = $this->contact('5511000000010');
        $igContact = $this->contact('ig:77', ['status' => 'instagram']);
        $before = (new ContactScorer())->calculate($person)['score'];

        $this->msg($igContact, false, 3, $ig);
        ContactLinks::link($igContact, $person);

        $r = (new ContactScorer())->calculate($person);
        $this->assertGreaterThan($before, $r['score']);
        $this->assertSame(3, $r['breakdown']['channels']);        // one extra channel: 2.5 rounded
        $this->assertGreaterThan(0, $r['breakdown']['recency']);
        // the channel contact shares the person's score
        $this->assertSame($r['score'], (new ContactScorer())->calculate($igContact->fresh())['score']);
    }

    public function test_manual_score_locks_and_can_be_handed_back(): void
    {
        $c = $this->contact('5511000000011');
        $this->msg($c, false, 1);
        $s = new ContactScorer();

        $this->assertNotNull($s->recalculate($c));
        $this->assertFalse($c->fresh()->score_locked);

        $c->update(['score' => 7]);
        $this->assertTrue($c->fresh()->score_locked);
        $this->assertNull($s->recalculate($c->fresh()));
        $this->assertSame(7, (int) $c->fresh()->score);

        $c->update(['score_locked' => false]);
        $this->assertNotNull($s->recalculate($c->fresh()));
        $this->assertNotSame(7, (int) $c->fresh()->score);
        $this->assertNotNull($c->fresh()->score_breakdown);
    }

    public function test_refresh_only_touches_due_contacts_and_command_runs(): void
    {
        $fresh = $this->contact('5511000000012');
        $fresh->update(['score_updated_at' => now()]);
        DB::table('contacts')->where('id', $fresh->id)->update(['score_locked' => false]);
        $never = $this->contact('5511000000013');
        $locked = $this->contact('5511000000014', ['score' => 50]);

        $n = (new ContactScorer())->refreshDue();
        $this->assertSame(1, $n);
        $this->assertNotNull($never->fresh()->score_updated_at);
        $this->assertSame(50, (int) $locked->fresh()->score);

        // new message after the last calculation makes it due again
        $this->msg($fresh, false, 0.01);
        DB::table('contacts')->where('id', $fresh->id)->update(['score_updated_at' => now()->subMinute()]);
        $this->assertSame(1, (new ContactScorer())->refreshDue());

        $this->artisan('contacts:score', ['--all' => true])->assertExitCode(0);
    }

    public function test_tier_scope_ranking_page_and_api(): void
    {
        $hot = $this->contact('5511000000015', ['name' => 'Quente']);
        $cold = $this->contact('5511000000016', ['name' => 'Frio']);
        DB::table('contacts')->where('id', $hot->id)->update(['score' => 90]);
        DB::table('contacts')->where('id', $cold->id)->update(['score' => 5]);

        $this->assertSame([$hot->id], Contact::tier('hot')->pluck('id')->all());
        $this->assertSame([$cold->id], Contact::tier('frozen')->pluck('id')->all());
        $this->assertSame('hot', $hot->fresh()->scoreTier());

        $this->actingAs($this->user)->get(route('contacts.index', ['tier' => 'hot', 'sort' => 'score']))->assertOk()->assertSee('Quente')->assertDontSee('Frio');
        $page = $this->actingAs($this->user)->get(route('contacts.index', ['sort' => 'score']))->assertOk();
        $page->assertSeeInOrder(['Quente', 'Frio']);

        $this->user->forceFill(['role' => 'admin'])->save(); // administrators are not limited by plan features
        Sanctum::actingAs($this->user->fresh());
        $r = $this->getJson('/api/v1/contacts/ranking?limit=5')->assertOk();
        $this->assertSame(['Quente', 'Frio'], collect($r->json('data'))->pluck('name')->all());
        $this->assertSame('hot', $r->json('data.0.tier'));
        $this->getJson('/api/v1/contacts/ranking?tier=cold')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/contacts/{$hot->id}/rescore")->assertOk();
        $this->putJson("/api/v1/contacts/{$hot->id}", ['name' => 'Quente', 'contact' => '5511000000015', 'score' => 33])->assertOk();
        $this->assertTrue($hot->fresh()->score_locked);
        $this->putJson("/api/v1/contacts/{$hot->id}", ['name' => 'Quente', 'contact' => '5511000000015', 'score_locked' => false])->assertOk();
        $this->assertFalse($hot->fresh()->score_locked);
    }
}
