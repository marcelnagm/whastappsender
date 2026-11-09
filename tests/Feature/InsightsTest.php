<?php

namespace Tests\Feature;

use App\Models\AiProfile;
use App\Models\Deal;
use App\Models\InsightReport;
use App\Models\User;
use App\Services\Insights\InsightBlocks;
use App\Services\Insights\InsightMetrics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
    }

    private function agent(): AiProfile
    {
        return AiProfile::create(['user_id' => $this->owner->id, 'name' => 'A', 'enabled' => true, 'provider' => 'groq', 'model' => 'm', 'api_key' => 'k', 'temperature' => 0.7, 'max_tokens' => 256]);
    }

    private function llm(string $text): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => $text]]]])]);
    }

    public function test_sanitizer_keeps_known_blocks_and_strips_html(): void
    {
        $r = InsightBlocks::parse("```json\n" . json_encode(['summary' => 'Ok <script>x</script>', 'blocks' => [
            ['type' => 'kpi', 'label' => 'Vendas', 'value' => '<b>10</b>', 'hint' => ''],
            ['type' => 'iframe', 'src' => 'x'],
            ['type' => 'bars', 'title' => 'T', 'labels' => ['a', 'b'], 'values' => [3, 'x']],
            ['type' => 'recommendation', 'title' => 'Faça', 'body' => 'x', 'priority' => 'urgent'],
            ['type' => 'kpi', 'label' => '', 'value' => ''],
        ]]) . "\n```");

        $this->assertSame('Ok x', $r['summary']);
        $this->assertCount(3, $r['blocks']);
        $this->assertSame('10', $r['blocks'][0]['value']);
        $this->assertSame([3.0, 0.0], $r['blocks'][1]['values']);
        $this->assertSame('medium', $r['blocks'][2]['priority']);
        $this->assertNull(InsightBlocks::parse('isto não é json'));
    }

    public function test_metrics_are_computed_from_the_accounts_own_data_only(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        foreach ([[$this->owner, 100], [$other, 999]] as [$u, $v]) {
            Deal::create(['user_id' => $u->id, 'title' => 'D', 'value' => $v, 'status' => 'won', 'pipeline_id' => 1, 'stage_id' => 1, 'closed_at' => now(), 'position' => 0]);
        }
        $m = app(InsightMetrics::class)->collect($this->owner, 30);

        $this->assertSame(1, $m['sales']['won']);
        $this->assertEquals(100.0, $m['sales']['won_value']);
        $this->assertSame(30, $m['period']['days']);
    }

    public function test_generate_stores_report_and_page_escapes_everything(): void
    {
        $this->agent();
        $this->llm(json_encode(['summary' => 'Resumo', 'blocks' => [
            ['type' => 'kpi', 'label' => 'Ganhos', 'value' => '1'],
            ['type' => 'text', 'title' => 'Achados', 'body' => '<img src=x onerror=alert(1)> texto'],
            ['type' => 'recommendation', 'title' => 'Responder rápido', 'body' => 'porque sim', 'priority' => 'high'],
        ]]));

        $this->actingAs($this->owner)->post(route('ai.insights.generate'), ['business_type' => 'retail', 'days' => 30])->assertRedirect();
        $report = InsightReport::first();
        $this->assertSame('ok', $report->status);

        $html = $this->actingAs($this->owner)->get(route('ai.insights.index', ['r' => $report->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Responder rápido', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_invalid_ai_answer_falls_back_to_a_text_block(): void
    {
        $this->agent();
        $this->llm('Aqui vai minha análise sem JSON.');
        $this->actingAs($this->owner)->post(route('ai.insights.generate'), ['business_type' => 'general', 'days' => 7])->assertRedirect();

        $r = InsightReport::first();
        $this->assertSame('fallback', $r->status);
        $this->assertSame('text', $r->blocks[0]['type']);
    }

    public function test_without_a_usable_agent_nothing_is_generated(): void
    {
        $this->actingAs($this->owner)->post(route('ai.insights.generate'), ['business_type' => 'general', 'days' => 7])->assertSessionHasErrors('ai');
        $this->assertSame(0, InsightReport::count());
    }

    public function test_reports_are_private_to_the_account(): void
    {
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $r = InsightReport::create(['user_id' => $other->id, 'business_type' => 'general', 'period_days' => 7, 'blocks' => [], 'data' => []]);

        $this->actingAs($this->owner)->delete(route('ai.insights.destroy', $r->id));
        $this->assertSame(1, InsightReport::count());
        $this->actingAs($this->owner)->get(route('ai.insights.index', ['r' => $r->id]))->assertOk()->assertDontSee('Numbers the AI received');
    }
}
