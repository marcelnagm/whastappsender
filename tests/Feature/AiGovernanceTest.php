<?php

namespace Tests\Feature;

use App\Models\AiProfile;
use App\Models\AiUsage;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Instance;
use App\Models\InsightReport;
use App\Models\InsightSchedule;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Notifications\InsightReady;
use App\Services\AI\AgentResponder;
use App\Services\Insights\InsightGenerator;
use App\Services\Insights\InsightScheduler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** AI usage log, plan limits, report model routing, 👍/👎 on AI answers and scheduled reports. */
class AiGovernanceTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        Notification::fake();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agent(string $provider = 'groq', string $model = 'llama-3.3-70b-versatile'): AiProfile
    {
        return AiProfile::create(['user_id' => $this->owner->id, 'name' => 'A', 'enabled' => true, 'provider' => $provider, 'model' => $model, 'api_key' => 'k', 'temperature' => 0.7, 'max_tokens' => 256]);
    }

    private function llm(string $text = 'ok', array $usage = ['prompt_tokens' => 11, 'completion_tokens' => 7]): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $text]]], 'usage' => $usage])]);
    }

    public function test_every_agent_reply_is_logged_with_its_tokens(): void
    {
        $this->llm('Olá!');
        $r = app(AgentResponder::class)->generateReply($this->agent(), [['role' => 'user', 'content' => 'oi']]);

        $this->assertSame('Olá!', $r['reply']);
        $u = AiUsage::first();
        $this->assertSame('reply', $u->kind);
        $this->assertSame($this->owner->id, (int) $u->user_id);
        $this->assertSame(11, (int) $u->tokens_in);
    }

    public function test_deep_business_types_use_the_stronger_report_model_and_light_ones_the_agents_own(): void
    {
        $this->llm(json_encode(['summary' => 's', 'blocks' => [['type' => 'text', 'title' => 't', 'body' => 'b']]]));
        $this->agent('openai', 'gpt-4o-mini');

        $light = app(InsightGenerator::class)->generate($this->owner, 'retail', 30);
        $deep = app(InsightGenerator::class)->generate($this->owner, 'b2b', 30);

        $this->assertSame('openai/gpt-4o-mini', $light->model);
        $this->assertSame('openai/' . config('ai.insight_models.openai'), $deep->model);
        $this->assertSame(2, AiUsage::where('kind', 'insight')->count());
    }

    public function test_plan_limits_cap_reports_and_knowledge_sources(): void
    {
        config(['billing.enabled' => true]);
        $this->agent();
        $this->llm(json_encode(['summary' => 's', 'blocks' => [['type' => 'text', 'title' => 't', 'body' => 'b']]]));

        // free plan: 0 AI reports, 3 knowledge sources
        $this->actingAs($this->owner)->post(route('ai.insights.generate'), ['business_type' => 'general', 'days' => 30]);
        $this->assertSame(0, InsightReport::count());

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->owner)->post(route('ai.knowledge.text'), ['title' => "T$i", 'content' => "Conteúdo $i do negócio."]);
        }
        $this->assertSame(3, KnowledgeSource::count());
    }

    public function test_team_can_rate_only_ai_answers_and_only_of_their_account(): void
    {
        $inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => '55', 'status' => 'connected']);
        $chat = Chat::create(['user_id' => $this->owner->id, 'instance_id' => $inst->id, 'remote_jid' => '5595@s.whatsapp.net', 'phone' => '5595', 'last_message_at' => now(), 'last_message_preview' => 'x']);
        $mk = fn ($uid, $src) => ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $inst->id, 'user_id' => $uid, 'message_id' => uniqid(), 'from_me' => true, 'source' => $src, 'type' => 'text', 'body' => 'resp', 'status' => 'sent']);
        $ai = $mk($this->owner->id, 'ai');
        $human = $mk($this->owner->id, null);
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();

        $this->actingAs($this->owner)->postJson(route('chat.api.ai-feedback', $ai->id), ['value' => -1, 'note' => 'preço errado'])->assertOk()->assertJsonPath('message.ai_feedback', -1);
        $this->assertSame('preço errado', $ai->fresh()->ai_feedback_note);
        $this->actingAs($this->owner)->postJson(route('chat.api.ai-feedback', $ai->id), ['value' => 0])->assertOk();
        $this->assertNull($ai->fresh()->ai_feedback);

        $this->actingAs($this->owner)->postJson(route('chat.api.ai-feedback', $human->id), ['value' => 1])->assertStatus(422);
        $this->actingAs($other)->postJson(route('chat.api.ai-feedback', $ai->id), ['value' => 1])->assertStatus(403);
    }

    public function test_scheduled_report_goes_out_on_the_due_day_once_and_failures_do_not_stop_it(): void
    {
        $this->agent();
        $this->llm(json_encode(['summary' => 'Resumo', 'blocks' => [['type' => 'kpi', 'label' => 'A', 'value' => '1']]]));
        $s = InsightSchedule::create(['user_id' => $this->owner->id, 'enabled' => true, 'frequency' => 'weekly', 'business_type' => 'general', 'period_days' => 30]);

        $tuesday = Carbon::parse('2026-11-03 07:30');
        $monday = Carbon::parse('2026-11-02 07:30');
        $this->assertSame(0, app(InsightScheduler::class)->run($tuesday));
        $this->assertSame(1, app(InsightScheduler::class)->run($monday));
        $this->assertSame(0, app(InsightScheduler::class)->run($monday));
        Notification::assertSentTo($this->owner, InsightReady::class);

        // monthly runs on the 1st; with no agent the reason is recorded instead of throwing
        AiProfile::query()->delete();
        $s->update(['frequency' => 'monthly', 'last_run_at' => null]);
        $this->assertSame(0, app(InsightScheduler::class)->run(Carbon::parse('2026-12-01 07:30')));
        $this->assertNotNull($s->fresh()->last_error);
    }

    public function test_insights_page_shows_usage_schedule_and_saves_the_schedule(): void
    {
        $this->actingAs($this->owner)->put(route('ai.insights.schedule'), ['enabled' => 1, 'frequency' => 'monthly', 'business_type' => 'b2b', 'days' => 90])->assertRedirect();
        $s = InsightSchedule::first();
        $this->assertTrue($s->enabled);
        $this->assertSame('monthly', $s->frequency);

        $this->actingAs($this->owner)->get(route('ai.insights.index'))->assertOk()->assertSee('Automatic report')->assertSee('AI usage this month');
    }
}
