<?php

namespace Tests\Feature;

use App\Jobs\FetchChatAvatarJob;
use App\Models\Automation;
use App\Models\Chat;
use App\Models\ChatTag;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** "When X, do Y" automations over the funnel, labels, tasks and the chat. */
class AutomationsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private User $bia;
    private SalesPipeline $funnel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Storage::fake('local');
        Cache::flush();
        Queue::fake([FetchChatAvatarJob::class]);
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $m = User::create(['name' => 'bia', 'email' => 'bia@t.dev', 'username' => 'bia@t.dev', 'password' => 'secret123']);
        $m->forceFill(['parent_user_id' => $this->owner->id, 'team_role' => TeamRoles::AGENT])->save();
        $this->bia = $m->fresh();
        Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => '5595111', 'status' => 'connected']);
        $this->funnel = app(SalesPipeline::class);
    }

    private function stage(string $kind, int $nth = 0): PipelineStage
    {
        $p = $this->funnel->defaultFor($this->owner);

        return PipelineStage::where('pipeline_id', $p->id)->where('kind', $kind)->orderBy('position')->skip($nth)->firstOrFail();
    }

    private function rule(string $trigger, array $actions, array $cond = [], bool $active = true): Automation
    {
        return Automation::create(['user_id' => $this->owner->id, 'name' => 'R', 'trigger' => $trigger, 'conditions' => $cond, 'actions' => $actions, 'active' => $active]);
    }

    private function deal(array $o = []): Deal
    {
        return $this->funnel->open($this->funnel->defaultFor($this->owner), $o + ['title' => 'Lead']);
    }

    private function inbound(string $text, string $phone = '5595999990001'): void
    {
        \Illuminate\Support\Facades\Redis::spy();
        app(ChatMessageRecorder::class)->recordUpsert('5595111', [
            'key' => ['id' => 'M' . uniqid(), 'remoteJid' => $phone . '@s.whatsapp.net', 'fromMe' => false],
            'pushName' => 'Cliente', 'message' => ['conversation' => $text], 'messageTimestamp' => now()->timestamp,
        ]);
    }

    public function test_new_deal_creates_a_task_for_the_owner(): void
    {
        $this->rule('deal.created', [['type' => 'create_task', 'title' => 'Ligar', 'due_hours' => 2, 'assignee' => 'owner']]);

        $d = $this->deal();

        $t = Task::firstOrFail();
        $this->assertSame(['Ligar', $d->id, $this->owner->id], [$t->title, $t->deal_id, $t->assigned_to]);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $t->due_at->timestamp, 90);
        $this->assertSame(1, Automation::first()->runs);
        $this->assertSame('ok', DB::table('automation_runs')->value('status'));
    }

    public function test_stage_and_won_triggers_with_conditions(): void
    {
        $this->rule('deal.stage', [['type' => 'notify', 'message' => 'Proposta!', 'assignee' => 'owner']], ['stage_id' => $this->stage('open', 2)->id]);
        $this->rule('deal.won', [['type' => 'create_task', 'title' => 'Pós-venda', 'due_hours' => 24, 'assignee' => 'owner']]);
        $d = $this->deal();

        $this->funnel->move($d, $this->stage('open', 1));
        $this->assertSame(0, DB::table('notifications')->count()); // wrong stage

        $this->funnel->move($d->fresh(), $this->stage('open', 2));
        $this->assertSame(1, DB::table('notifications')->count());

        $this->funnel->move($d->fresh(), $this->stage('won'));
        $this->assertSame(['Pós-venda'], Task::pluck('title')->all());

        $this->funnel->move($d->fresh(), $this->stage('won')); // already won: nothing again
        $this->assertSame(1, Task::count());
    }

    public function test_inactive_rule_and_other_accounts_rules_do_not_run(): void
    {
        $this->rule('deal.created', [['type' => 'create_task', 'title' => 'X']], [], false);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        Automation::create(['user_id' => $other->id, 'name' => 'R', 'trigger' => 'deal.created', 'actions' => [['type' => 'create_task', 'title' => 'Y']]]);

        $this->deal();

        $this->assertSame(0, Task::count());
    }

    public function test_keyword_message_tags_and_assigns(): void
    {
        $tag = ChatTag::create(['user_id' => $this->owner->id, 'name' => 'Preço', 'color' => '#ff0000']);
        $this->rule('message.keyword', [['type' => 'add_tag', 'tag_id' => $tag->id], ['type' => 'assign', 'assignee' => $this->bia->id]], ['keywords' => ['orçamento', 'preço']]);

        $this->inbound('Oi, bom dia');
        $chat = Chat::firstOrFail();
        $this->assertSame(0, $chat->tags()->count());

        $this->inbound('Qual o PREÇO do plano?');
        $chat = $chat->fresh();
        $this->assertSame([$tag->id], $chat->tags()->pluck('chat_tags.id')->all());
        $this->assertSame($this->bia->id, (int) $chat->assigned_to);
    }

    public function test_tag_added_triggers_a_move_of_the_open_deal(): void
    {
        $this->owner->forceFill(['deal_auto_chat' => false])->save();
        $this->inbound('Olá');
        $chat = Chat::firstOrFail();
        $d = $this->deal(['chat_id' => $chat->id, 'title' => 'Do chat']);
        $tag = ChatTag::create(['user_id' => $this->owner->id, 'name' => 'Quente', 'color' => '#ff0000']);
        $this->rule('tag.added', [['type' => 'move_deal', 'stage_id' => $this->stage('open', 3)->id]], ['tag_id' => $tag->id]);

        $this->actingAs($this->owner)->putJson(route('chat.api.tags.sync', $chat->id), ['tag_ids' => [$tag->id]])->assertOk();

        $this->assertSame($this->stage('open', 3)->id, $d->fresh()->stage_id);
    }

    public function test_chains_do_not_loop(): void
    {
        $a = $this->stage('open', 1);
        $b = $this->stage('open', 2);
        $this->rule('deal.stage', [['type' => 'move_deal', 'stage_id' => $b->id]], ['stage_id' => $a->id]);
        $this->rule('deal.stage', [['type' => 'move_deal', 'stage_id' => $a->id]], ['stage_id' => $b->id]);
        $d = $this->deal();

        $this->funnel->move($d, $a); // A -> rule1 moves to B -> rule2 moves to A -> rule1 is blocked

        $this->assertLessThanOrEqual(2, DB::table('automation_runs')->count());
        $this->assertContains($d->fresh()->stage_id, [$a->id, $b->id]);
    }

    public function test_one_failing_action_does_not_stop_the_others_or_the_deal(): void
    {
        $this->rule('deal.created', [['type' => 'add_tag', 'tag_id' => 999], ['type' => 'create_task', 'title' => 'Mesmo assim']]);

        $d = $this->deal();

        $this->assertNotNull($d->id);
        $this->assertSame(['Mesmo assim'], Task::pluck('title')->all());
        $this->assertSame('partial', DB::table('automation_runs')->value('status'));
    }

    public function test_form_validation_and_crud(): void
    {
        $this->actingAs($this->owner);
        $tag = ChatTag::create(['user_id' => $this->owner->id, 'name' => 'T', 'color' => '#ff0000']);
        $stage = $this->stage('open', 1);

        $this->post(route('automations.store'), ['name' => 'Sem ação', 'trigger' => 'deal.created', 'actions' => [['type' => 'bogus']]])->assertSessionHasErrors('name');
        $this->post(route('automations.store'), ['name' => 'Sem palavra', 'trigger' => 'message.keyword', 'actions' => [['type' => 'notify', 'message' => 'x']]])->assertSessionHasErrors('name');
        $this->post(route('automations.store'), ['name' => 'Trigger ruim', 'trigger' => 'nope', 'actions' => [['type' => 'notify']]])->assertSessionHasErrors('trigger');

        $this->post(route('automations.store'), [
            'name' => 'Proposta', 'trigger' => 'deal.stage', 'stage_id' => $stage->id,
            'actions' => [['type' => 'create_task', 'title' => 'Cobrar', 'due_hours' => 5000, 'assignee' => '999'], ['type' => 'add_tag', 'tag_id' => $tag->id], ['type' => 'move_deal', 'stage_id' => 99999]],
        ])->assertSessionHasNoErrors();

        $r = Automation::firstOrFail();
        $this->assertSame(['stage_id' => $stage->id], $r->conditions);
        $this->assertCount(2, $r->actions);                        // the invalid move was dropped
        $this->assertSame(24 * 90, $r->actions[0]['due_hours']);   // capped
        $this->assertSame('owner', $r->actions[0]['assignee']);    // stranger falls back to the owner

        $this->get(route('automations.index'))->assertOk()->assertSee('Proposta');
        $this->post(route('automations.toggle', $r->id));
        $this->assertFalse($r->fresh()->active);
        $this->delete(route('automations.destroy', $r->id));
        $this->assertSame(0, Automation::count());
    }

    public function test_only_admins_among_members_reach_the_screen(): void
    {
        $this->assertTrue(TeamRoles::allows(TeamRoles::ADMIN, 'automations'));
        $this->assertFalse(TeamRoles::allows(TeamRoles::AGENT, 'automations'));
        $this->assertFalse(TeamRoles::allows(TeamRoles::CREATOR, 'automations'));
    }
}
