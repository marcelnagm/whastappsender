<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStageEntry;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Reports\FunnelReport;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class FunnelReportTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private Pipeline $p;
    private array $st = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $this->p = Pipeline::forceCreate(['user_id' => $this->owner->id, 'name' => 'Vendas']);
        foreach ([['Novo', 'open'], ['Proposta', 'open'], ['Ganho', 'won'], ['Perdido', 'lost']] as $i => [$n, $k]) {
            $this->st[$n] = PipelineStage::forceCreate(['user_id' => $this->owner->id, 'pipeline_id' => $this->p->id, 'name' => $n, 'kind' => $k, 'position' => $i + 1]);
        }
    }

    public function test_history_is_recorded_on_create_and_move(): void
    {
        $svc = app(SalesPipeline::class);
        $d = $svc->open($this->p, ['title' => 'A', 'value' => 100]);
        $this->assertSame(1, DealStageEntry::where('deal_id', $d->id)->count());
        $svc->move($d, $this->st['Proposta']);
        $svc->move($d->fresh(), $this->st['Proposta']);   // same stage: nothing new
        $this->assertSame(2, DealStageEntry::where('deal_id', $d->id)->count());
        $this->assertNotNull(DealStageEntry::where('deal_id', $d->id)->orderBy('id')->first()->left_at);
    }

    public function test_conversion_per_stage_and_velocity(): void
    {
        $svc = app(SalesPipeline::class);
        $a = $svc->open($this->p, ['title' => 'A', 'value' => 100]);
        $b = $svc->open($this->p, ['title' => 'B', 'value' => 300]);
        $c = $svc->open($this->p, ['title' => 'C', 'value' => 50]);
        $svc->move($a, $this->st['Proposta']);
        $svc->move($a->fresh(), $this->st['Ganho']);
        $svc->move($b, $this->st['Proposta']);
        $svc->move($c, $this->st['Perdido']);
        DealStageEntry::query()->update(['entered_at' => now()->subDays(2)]);
        Deal::whereKey($a->id)->toBase()->update(['created_at' => now()->subDays(10), 'closed_at' => now()]);

        $r = app(FunnelReport::class)->build($this->p, now()->subDays(29)->startOfDay(), now()->endOfDay());
        $novo = $r['rows'][0];
        $this->assertSame([3, 3], [$novo['reached'], $novo['advanced']]);
        $prop = $r['rows'][1];
        $this->assertSame([2, 1], [$prop['reached'], $prop['advanced']]);
        $this->assertSame(50.0, $prop['conversion']);
        $this->assertSame(1, $r['won']);
        $this->assertEquals(10.0, $r['cycle_days']);
        $this->assertSame(50.0, $r['win_rate']);
        $this->assertSame(round(1 * 0.5 * 100 / 10, 2), $r['velocity_day']);   // 1 open deal (B)
    }

    public function test_page_renders_and_is_scoped(): void
    {
        $this->actingAs($this->owner)->get(route('pipeline.funnel'))->assertOk()->assertSee('Novo');
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->get(route('pipeline.funnel', ['pipeline' => $this->p->id]))->assertStatus(404);
    }
}
