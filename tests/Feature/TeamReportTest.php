<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TeamGoal;
use App\Models\User;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Reports\TeamReport;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Performance per attendant: first response, answered conversations, deals, goals. */
class TeamReportTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $bia;
    private User $caio;
    private Instance $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->bia = $this->member('Bia', 'b', TeamRoles::AGENT);
        $this->caio = $this->member('Caio', 'c', TeamRoles::AGENT);
        $this->inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
    }

    private function member(string $name, string $u, string $role): User
    {
        $m = User::create(['name' => $name, 'email' => "$u@t.dev", 'username' => $u, 'password' => 'x']);
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => $role, 'active' => true])->save();

        return $m;
    }

    private function chat(string $phone, ?User $assigned = null): Chat
    {
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'C' . $phone, 'contact' => $phone]);

        return Chat::create(['user_id' => $this->owner->id, 'instance_id' => $this->inst->id, 'contact_id' => $c->id, 'remote_jid' => $phone . '@s.whatsapp.net', 'assigned_to' => $assigned?->id]);
    }

    private function msg(Chat $chat, bool $fromMe, Carbon $at, array $o = []): ChatMessage
    {
        return ChatMessage::create($o + ['chat_id' => $chat->id, 'instance_id' => $this->inst->id, 'user_id' => $this->owner->id, 'message_id' => uniqid(), 'from_me' => $fromMe,
            'type' => 'text', 'body' => 'x', 'message_timestamp' => $at]);
    }

    private function report(): array
    {
        return app(TeamReport::class)->build($this->owner, now()->startOfDay(), now()->endOfDay());
    }

    private function row(array $r, int $id): array
    {
        return collect($r['people'])->firstWhere('id', $id);
    }

    public function test_first_response_time_is_credited_to_the_person_who_answered(): void
    {
        $t = now()->startOfDay()->addHours(9);
        $a = $this->chat('1', $this->bia);
        $this->msg($a, false, $t);
        $this->msg($a, false, $t->copy()->addSeconds(30));                                            // still the same wait
        $this->msg($a, true, $t->copy()->addMinutes(2), ['sent_by' => $this->bia->id, 'source' => 'human']);
        $this->msg($a, true, $t->copy()->addMinutes(3), ['sent_by' => $this->bia->id, 'source' => 'human']); // follow-up, not a new answer
        $b = $this->chat('2', $this->caio);
        $this->msg($b, false, $t);
        $this->msg($b, true, $t->copy()->addMinutes(10), ['sent_by' => $this->caio->id, 'source' => 'human']);

        $r = $this->report();

        $bia = $this->row($r, $this->bia->id);
        $this->assertSame(1, $bia['answered']);
        $this->assertSame(2, $bia['messages']);
        $this->assertSame(120, $bia['avg_response']);
        $this->assertSame(600, $this->row($r, $this->caio->id)['avg_response']);
        $this->assertSame(360, $r['totals']['avg_response']); // (120 + 600) / 2
    }

    public function test_phone_replies_count_for_the_assignee_and_ai_or_campaign_for_nobody(): void
    {
        $t = now()->startOfDay()->addHours(9);
        $a = $this->chat('1', $this->bia);
        $this->msg($a, false, $t);
        $this->msg($a, true, $t->copy()->addMinutes(1)); // typed on the phone: no sender recorded
        $b = $this->chat('2', $this->bia);
        $this->msg($b, false, $t);
        $this->msg($b, true, $t->copy()->addSeconds(5), ['source' => 'ai']);
        $c = $this->chat('3', $this->bia);
        $this->msg($c, true, $t, ['source' => 'campaign']);

        $r = $this->report();

        $this->assertSame(1, $this->row($r, $this->bia->id)['answered']);
        $this->assertSame(1, $this->row($r, $this->bia->id)['messages']);
        $this->assertSame(1, $r['ai_answered']);
    }

    public function test_messages_outside_the_period_and_other_accounts_are_ignored(): void
    {
        $a = $this->chat('1', $this->bia);
        $this->msg($a, false, now()->subDays(3));
        $this->msg($a, true, now()->subDays(3)->addMinute(), ['sent_by' => $this->bia->id, 'source' => 'human']);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        ChatMessage::create(['chat_id' => 999, 'instance_id' => 1, 'user_id' => $other->id, 'message_id' => 'z', 'from_me' => true, 'type' => 'text', 'body' => 'x', 'message_timestamp' => now(), 'sent_by' => $this->bia->id, 'source' => 'human']);

        $this->assertSame(0, $this->report()['totals']['messages']);
    }

    public function test_deals_and_tasks_per_person(): void
    {
        $f = app(SalesPipeline::class);
        $p = $f->defaultFor($this->owner);
        $won = $p->stages()->where('kind', 'won')->first() ?? \App\Models\PipelineStage::where('pipeline_id', $p->id)->where('kind', 'won')->first();
        $lost = \App\Models\PipelineStage::where('pipeline_id', $p->id)->where('kind', 'lost')->first();
        $d1 = $f->open($p, ['title' => 'A', 'assigned_to' => $this->bia->id, 'value' => 100]);
        $d2 = $f->open($p, ['title' => 'B', 'assigned_to' => $this->bia->id, 'value' => 50.5]);
        $d3 = $f->open($p, ['title' => 'C', 'assigned_to' => $this->caio->id]);
        $f->move($d1, $won);
        $f->move($d2, $won);
        $f->move($d3, $lost);
        Task::create(['user_id' => $this->owner->id, 'title' => 't', 'assigned_to' => $this->bia->id, 'created_by' => $this->owner->id, 'done_at' => now(), 'done_by' => $this->caio->id]);
        Task::create(['user_id' => $this->owner->id, 'title' => 'late', 'assigned_to' => $this->bia->id, 'created_by' => $this->owner->id, 'due_at' => now()->subDay()]);

        $r = $this->report();

        $bia = $this->row($r, $this->bia->id);
        $this->assertSame(2, $bia['won']);
        $this->assertEquals(150.5, $bia['won_value']);
        $this->assertSame(1, $bia['tasks_overdue']);
        $this->assertSame(1, $this->row($r, $this->caio->id)['lost']);
        $this->assertSame(1, $this->row($r, $this->caio->id)['tasks_done']); // credited to who finished it
    }

    public function test_goals_progress_and_lower_is_better_response_goal(): void
    {
        $t = now()->startOfMonth()->addHours(10);
        $a = $this->chat('1', $this->bia);
        $this->msg($a, false, $t);
        $this->msg($a, true, $t->copy()->addMinutes(4), ['sent_by' => $this->bia->id, 'source' => 'human']);
        TeamGoal::create(['user_id' => $this->owner->id, 'member_id' => $this->bia->id, 'metric' => 'answered', 'target' => 4]);
        TeamGoal::create(['user_id' => $this->owner->id, 'member_id' => 0, 'metric' => 'first_response_minutes', 'target' => 5]);

        $g = collect(app(TeamReport::class)->goals($this->owner))->keyBy('metric');

        $this->assertSame(25, $g['answered']['pct']);
        $this->assertFalse($g['answered']['reached']);
        $this->assertSame(4.0, $g['first_response_minutes']['value']);
        $this->assertTrue($g['first_response_minutes']['reached']);
        $this->assertSame('Whole team', $g['first_response_minutes']['member']);
    }

    public function test_human_durations(): void
    {
        $this->assertSame('—', TeamReport::human(null));
        $this->assertSame('45 s', TeamReport::human(45));
        $this->assertSame('5 min', TeamReport::human(300));
        $this->assertSame('2 h 5 min', TeamReport::human(7500));
    }

    public function test_page_goals_and_permissions(): void
    {
        $this->actingAs($this->owner)->get(route('team-report.index'))->assertOk()->assertSee('Team performance');
        $this->actingAs($this->owner)->get(route('team-report.index', ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk();

        $this->post(route('team-report.goals.store'), ['metric' => 'deals_won', 'member_id' => $this->bia->id, 'target' => 5])->assertSessionHasNoErrors();
        $this->post(route('team-report.goals.store'), ['metric' => 'deals_won', 'member_id' => $this->bia->id, 'target' => 8]);
        $this->assertSame(1, TeamGoal::count());
        $this->assertEquals(8, TeamGoal::first()->target);
        $this->post(route('team-report.goals.store'), ['metric' => 'deals_won', 'member_id' => 9999, 'target' => 1])->assertSessionHasErrors('target');
        $this->post(route('team-report.goals.store'), ['metric' => 'nope', 'target' => 1])->assertSessionHasErrors('metric');
        $this->delete(route('team-report.goals.destroy', TeamGoal::first()->id));
        $this->assertSame(0, TeamGoal::count());

        $analyst = $this->member('Ana', 'a', TeamRoles::ANALYST);
        $this->actingAs($analyst)->get(route('team-report.index'))->assertOk();                       // analysts read reports
        $this->actingAs($analyst)->post(route('team-report.goals.store'), ['metric' => 'deals_won', 'target' => 1])->assertForbidden(); // but do not set goals
        $this->actingAs($this->bia)->get(route('team-report.index'))->assertForbidden();
    }

    public function test_sending_from_the_chat_records_who_sent_it(): void
    {
        $chat = $this->chat('7', $this->bia);
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Redis::spy();

        $this->actingAs($this->bia)->postJson(route('chat.api.send', $chat->id), ['text' => 'olá'])->assertStatus(201);

        $m = ChatMessage::where('chat_id', $chat->id)->first();
        $this->assertSame($this->bia->id, (int) $m->sent_by);
        $this->assertSame('human', $m->source);
    }
}
