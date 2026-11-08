<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Pipeline\StageChecklist;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesTestSchema;
use Tests\TestCase;

class StageChecklistTest extends TestCase
{
    use CreatesTestSchema;

    private User $owner;
    private PipelineStage $a;
    private PipelineStage $b;
    private PipelineStage $lost;
    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Redis::spy();
        $this->owner = User::create(['name' => 'Dono', 'email' => 'd@t.dev', 'username' => 'd', 'password' => 'x'])->fresh();
        $p = Pipeline::forceCreate(['user_id' => $this->owner->id, 'name' => 'Vendas']);
        $mk = fn ($n, $kind, $pos) => PipelineStage::forceCreate(['user_id' => $this->owner->id, 'pipeline_id' => $p->id, 'name' => $n, 'kind' => $kind, 'position' => $pos]);
        $this->a = $mk('Qualificação', 'open', 1);
        $this->b = $mk('Proposta', 'open', 2);
        $this->lost = $mk('Perdido', 'lost', 3);
        $this->a->update(['checklist' => ['Ligar', 'Enviar catálogo'], 'checklist_required' => true]);
        $this->deal = Deal::forceCreate(['user_id' => $this->owner->id, 'pipeline_id' => $p->id, 'stage_id' => $this->a->id, 'title' => 'N', 'value' => 1, 'status' => 'open']);
    }

    public function test_parse_cleans_lines(): void
    {
        $this->assertSame(['A', 'B'], StageChecklist::parse("  A \n\nB\nA\r\n"));
        $this->assertCount(20, StageChecklist::parse(implode("\n", range(1, 30))));
    }

    public function test_required_checklist_blocks_moving_forward_but_not_to_lost(): void
    {
        $svc = app(StageChecklist::class);
        $this->actingAs($this->owner)->postJson(route('pipeline.deals.move', $this->deal->id), ['stage_id' => $this->b->id])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Finish the checklist of "Qualificação" first: Ligar; Enviar catálogo']);
        $this->assertSame($this->a->id, $this->deal->fresh()->stage_id);

        foreach ($svc->state($this->deal) as $i) {
            $this->actingAs($this->owner)->putJson(route('pipeline.deals.checklist.toggle', [$this->deal->id, $i['hash']]), ['done' => true])->assertOk();
        }
        $this->assertSame(['done' => 2, 'total' => 2], $svc->progress($this->deal));
        $this->actingAs($this->owner)->postJson(route('pipeline.deals.move', $this->deal->id), ['stage_id' => $this->b->id])->assertOk();
    }

    public function test_lost_is_always_allowed_and_progress_is_per_stage(): void
    {
        $this->actingAs($this->owner)->postJson(route('pipeline.deals.move', $this->deal->id), ['stage_id' => $this->lost->id])->assertOk();
        $this->assertSame('lost', $this->deal->fresh()->status);
        $this->assertSame(['done' => 0, 'total' => 0], app(StageChecklist::class)->progress($this->deal->fresh()));
    }

    public function test_other_accounts_and_unknown_items_are_refused(): void
    {
        $hash = app(StageChecklist::class)->state($this->deal)[0]['hash'];
        $other = User::create(['name' => 'O', 'email' => 'o@t.dev', 'username' => 'o', 'password' => 'x'])->fresh();
        $this->actingAs($other)->getJson(route('pipeline.deals.checklist.index', $this->deal->id))->assertStatus(404);
        $this->actingAs($this->owner)->putJson(route('pipeline.deals.checklist.toggle', [$this->deal->id, str_repeat('a', 32)]), ['done' => true])->assertStatus(404);
        $this->actingAs($this->owner)->putJson(route('pipeline.deals.checklist.toggle', [$this->deal->id, $hash]), ['done' => true])->assertOk();
    }

    public function test_stage_form_saves_checklist(): void
    {
        $this->actingAs($this->owner)->put(route('pipeline.stages.update', $this->b->id), ['name' => 'Proposta', 'checklist' => "Enviar proposta\nRevisar preço", 'checklist_required' => '1'])->assertRedirect();
        $b = $this->b->fresh();
        $this->assertSame(['Enviar proposta', 'Revisar preço'], $b->checklist);
        $this->assertTrue($b->checklist_required);
        $this->actingAs($this->owner)->put(route('pipeline.stages.update', $this->b->id), ['name' => 'Proposta']);
        $this->assertNull($this->b->fresh()->checklist);
    }
}
