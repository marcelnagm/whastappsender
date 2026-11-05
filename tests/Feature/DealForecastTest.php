<?php

namespace Tests\Feature;

use App\Models\AiProfile;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\Forecast\DealForecast;
use App\Services\Pipeline\SalesPipeline;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

/** Chance to close and priorities of open deals. */
class DealForecastTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private SalesPipeline $funnel;
    private Instance $inst;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->funnel = app(SalesPipeline::class);
        $this->inst = Instance::create(['user_id' => $this->owner->id, 'name' => 'n', 'instance_name' => 'i', 'status' => 'connected']);
    }

    private function stage(string $name): PipelineStage
    {
        return PipelineStage::where('pipeline_id', $this->funnel->defaultFor($this->owner)->id)->where('name', $name)->firstOrFail();
    }

    private function deal(array $o = [], ?PipelineStage $stage = null): Deal
    {
        return $this->funnel->open($this->funnel->defaultFor($this->owner), $o + ['title' => 'D' . Deal::count(), 'value' => 100], $stage);
    }

    private function chatFor(string $phone = '1'): Chat
    {
        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'C' . $phone, 'contact' => $phone]);

        return Chat::create(['user_id' => $this->owner->id, 'instance_id' => $this->inst->id, 'contact_id' => $c->id, 'remote_jid' => $phone . '@s.whatsapp.net']);
    }

    private function say(Chat $chat, bool $fromMe, $at, string $body = 'x'): void
    {
        ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $this->inst->id, 'user_id' => $this->owner->id, 'message_id' => uniqid(), 'from_me' => $fromMe, 'type' => 'text', 'body' => $body, 'message_timestamp' => $at]);
    }

    private function f(Deal $d): array
    {
        return app(DealForecast::class)->scoreMany(collect([$d->fresh()]))[$d->id];
    }

    public function test_later_stages_score_higher(): void
    {
        $new = $this->f($this->deal([], $this->stage('New lead')))['score'];
        $neg = $this->f($this->deal([], $this->stage('Negotiation')))['score'];

        $this->assertGreaterThan($new, $neg);
    }

    public function test_recent_customer_activity_raises_the_score_and_silence_lowers_it(): void
    {
        $hot = $this->chatFor('1');
        $this->say($hot, false, now()->subHours(2));
        $this->say($hot, true, now()->subHour());
        $cold = $this->chatFor('2');
        $this->say($cold, false, now()->subDays(20));
        $this->say($cold, true, now()->subDays(19));
        $none = $this->chatFor('3');

        $a = $this->f($this->deal(['chat_id' => $hot->id]))['score'];
        $b = $this->f($this->deal(['chat_id' => $cold->id]))['score'];
        $c = $this->f($this->deal(['chat_id' => $none->id]))['score'];
        $base = $this->f($this->deal())['score']; // no conversation: neutral

        $this->assertGreaterThan($base, $a);
        $this->assertLessThan($base, $b);
        $this->assertLessThan($base, $c);
    }

    public function test_waiting_customer_is_the_next_action_and_jumps_the_priority_list(): void
    {
        $chat = $this->chatFor();
        $this->say($chat, false, now()->subHours(5));
        $d = $this->deal(['chat_id' => $chat->id]);

        $f = $this->f($d);

        $this->assertSame(5, $f['waiting_hours']);
        $this->assertStringContainsString('5', $f['next']);

        $answered = $this->chatFor('2');
        $this->say($answered, false, now()->subHours(3));
        $this->say($answered, true, now()->subHours(2));
        $this->assertNull($this->f($this->deal(['chat_id' => $answered->id]))['waiting_hours']);
    }

    public function test_tasks_dates_age_and_contact_score_move_the_score(): void
    {
        $base = $this->f($d = $this->deal());
        $reasons = fn ($f) => collect($f['reasons'])->pluck('delta', 'text');

        Task::create(['user_id' => $this->owner->id, 'title' => 't', 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id, 'deal_id' => $d->id, 'due_at' => now()->subDay()]);
        $late = $this->f($d);
        $this->assertSame(-5, $reasons($late)['Overdue follow-up']);
        $this->assertSame('Do the overdue follow-up', $late['next']);

        $d2 = $this->deal(['expected_close_date' => now()->subDay()]);
        $this->assertSame(-8, $reasons($this->f($d2))['Expected close date has passed']);
        $d3 = $this->deal(['expected_close_date' => now()->addDays(3)]);
        $this->assertSame(5, $reasons($this->f($d3))['Expected to close within a week']);

        $old = $this->deal();
        Deal::whereKey($old->id)->update(['created_at' => now()->subDays(70)]);
        $this->assertSame(-10, $reasons($this->f($old))['Open for over 60 days']);

        $c = Contact::create(['user_id' => $this->owner->id, 'name' => 'V', 'contact' => '9', 'score' => 90]);
        $this->assertSame(8, $reasons($this->f($this->deal(['contact_id' => $c->id])))['High contact score']);
    }

    public function test_score_is_clamped_and_closed_deals_are_not_scored(): void
    {
        $d = $this->deal([], $this->stage('Negotiation'));
        $f = $this->f($d);
        $this->assertGreaterThanOrEqual(1, $f['score']);
        $this->assertLessThanOrEqual(99, $f['score']);
        $this->assertContains($f['level'], ['hot', 'warm', 'cold']);

        $won = $this->funnel->move($d, $this->stage('Won'));
        $this->assertNull($this->f($won)['score']);
    }

    public function test_weighted_forecast_sums_value_times_chance(): void
    {
        $a = $this->deal(['value' => 1000]);
        $b = $this->deal(['value' => 500]);
        $svc = app(DealForecast::class);
        $deals = Deal::all();
        $scores = $svc->scoreMany($deals);

        $expected = round(1000 * $scores[$a->id]['score'] / 100 + 500 * $scores[$b->id]['score'] / 100, 2);
        $this->assertSame($expected, $svc->weightedValue($deals, $scores));
    }

    public function test_pages_show_scores_and_priorities_ranked(): void
    {
        $low = $this->deal(['title' => 'Frio'], $this->stage('New lead'));
        $high = $this->deal(['title' => 'Quente'], $this->stage('Negotiation'));
        $this->actingAs($this->owner);

        $this->get(route('pipeline.index'))->assertOk()->assertSee('Forecast');
        $html = $this->get(route('pipeline.priorities'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Frio'), strpos($html, 'Quente'));
    }

    public function test_advice_uses_the_agent_and_fails_soft(): void
    {
        $d = $this->deal(['title' => 'Site']);
        $this->actingAs($this->owner);

        $this->postJson(route('pipeline.deals.advice', $d->id))->assertOk()->assertJson(['text' => null])->assertJsonStructure(['error']); // no agent

        AiProfile::create(['user_id' => $this->owner->id, 'name' => 'A', 'enabled' => true, 'provider' => 'groq', 'model' => 'm', 'temperature' => 0.5, 'max_tokens' => 200, 'api_key' => 'k']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Ligue hoje e confirme o prazo.']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);
        $this->postJson(route('pipeline.deals.advice', $d->id))->assertOk()->assertJson(['text' => 'Ligue hoje e confirme o prazo.', 'error' => null]);

        $won = $this->funnel->move($d, $this->stage('Won'));
        $this->postJson(route('pipeline.deals.advice', $won->id))->assertStatus(422);
    }

    public function test_advice_fails_soft_when_the_provider_errors(): void
    {
        $d = $this->deal();
        AiProfile::create(['user_id' => $this->owner->id, 'name' => 'A', 'enabled' => true, 'provider' => 'groq', 'model' => 'm', 'temperature' => 0.5, 'max_tokens' => 200, 'api_key' => 'k']);
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($this->owner)->postJson(route('pipeline.deals.advice', $d->id))->assertOk()->assertJson(['text' => null])->assertJsonStructure(['error']);
    }

    public function test_other_accounts_deals_are_not_reachable(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $d = $this->funnel->open($this->funnel->defaultFor($other), ['title' => 'X']);

        $this->actingAs($this->owner)->postJson(route('pipeline.deals.advice', $d->id))->assertNotFound();
    }
}
