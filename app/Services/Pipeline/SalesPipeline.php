<?php

namespace App\Services\Pipeline;

use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;

/** Rules of the sales funnel: default pipeline, moving a deal between stages and what "won" / "lost" mean. */
class SalesPipeline
{
    /** Stages every new account starts with */
    public const DEFAULT_STAGES = [
        ['New lead', 'open', '#6c757d'],
        ['Contacted', 'open', '#0d6efd'],
        ['Proposal', 'open', '#6f42c1'],
        ['Negotiation', 'open', '#fd7e14'],
        ['Won', 'won', '#198754'],
        ['Lost', 'lost', '#dc3545'],
    ];

    public function defaultFor(User $owner): Pipeline
    {
        $p = Pipeline::where('user_id', $owner->id)->orderByDesc('is_default')->orderBy('id')->first();

        return $p ?: $this->create($owner, __('Sales'), true);
    }

    public function create(User $owner, string $name, bool $default = false): Pipeline
    {
        $p = Pipeline::create(['user_id' => $owner->id, 'name' => $name, 'is_default' => $default]);
        foreach (self::DEFAULT_STAGES as $i => [$label, $kind, $color]) {
            PipelineStage::create(['user_id' => $owner->id, 'pipeline_id' => $p->id, 'name' => __($label), 'kind' => $kind, 'color' => $color, 'position' => $i]);
        }

        return $p;
    }

    /** Creates a deal at the end of the first open stage (or of the given one). */
    public function open(Pipeline $p, array $data, ?PipelineStage $stage = null): Deal
    {
        $stage ??= PipelineStage::where('pipeline_id', $p->id)->where('kind', 'open')->orderBy('position')->orderBy('id')->first()
            ?? PipelineStage::where('pipeline_id', $p->id)->orderBy('position')->firstOrFail();

        $deal = new Deal($data + ['source' => 'manual']);
        $deal->user_id = $p->user_id;
        $deal->pipeline_id = $p->id;
        $deal->position = 1 + (int) Deal::where('stage_id', $stage->id)->max('position');
        $this->place($deal, $stage);
        $deal->save();
        app(\App\Services\Automation\Automations::class)->fire('deal.created', $p->user_id, ['deal' => $deal, 'stage_id' => $stage->id]);

        return $deal;
    }

    /** Moves a deal to a stage of the same pipeline, at a position (0 = top). Won / lost stages close it; any other reopens it. */
    public function move(Deal $deal, PipelineStage $stage, ?int $position = null, ?string $lostReason = null): Deal
    {
        abort_unless($stage->pipeline_id === $deal->pipeline_id && $stage->user_id === $deal->user_id, 422);

        $wasStage = $deal->stage_id;
        $wasStatus = $deal->status;
        $this->place($deal, $stage, $lostReason);

        $siblings = Deal::where('stage_id', $stage->id)->where('id', '!=', $deal->id)->orderBy('position')->orderBy('id')->pluck('id')->all();
        $at = $position === null ? count($siblings) : max(0, min($position, count($siblings)));
        array_splice($siblings, $at, 0, [$deal->id]);

        $deal->save();
        foreach ($siblings as $i => $id) {
            Deal::where('id', $id)->update(['position' => $i]);
        }

        $deal = $deal->fresh();
        if ($wasStage !== $stage->id) {
            $auto = app(\App\Services\Automation\Automations::class);
            $ctx = ['deal' => $deal, 'stage_id' => $stage->id];
            $auto->fire('deal.stage', $deal->user_id, $ctx);
            if ($wasStatus !== $stage->kind && in_array($stage->kind, ['won', 'lost'], true)) {
                $auto->fire('deal.' . $stage->kind, $deal->user_id, $ctx);
            }
        }

        return $deal;
    }

    private function place(Deal $deal, PipelineStage $stage, ?string $lostReason = null): void
    {
        $was = $deal->status;
        $deal->stage_id = $stage->id;
        $deal->status = $stage->kind;
        if ($stage->kind === 'open') {
            $deal->closed_at = null;
            $deal->lost_reason = null;
        } else {
            $deal->closed_at = $was === $stage->kind && $deal->closed_at ? $deal->closed_at : now();
            $deal->lost_reason = $stage->kind === 'lost' ? ($lostReason !== null && $lostReason !== '' ? mb_substr($lostReason, 0, 255) : $deal->lost_reason) : null;
        }
    }

    /** @return array{open:int,open_value:float,won:int,won_value:float,lost:int,win_rate:?float} */
    public function summary(Pipeline $p): array
    {
        $rows = Deal::where('pipeline_id', $p->id)->selectRaw('status, count(*) as n, sum(value) as v')->groupBy('status')->get()->keyBy('status');
        $n = fn ($s) => (int) ($rows[$s]->n ?? 0);
        $v = fn ($s) => (float) ($rows[$s]->v ?? 0);
        $closed = $n('won') + $n('lost');

        return ['open' => $n('open'), 'open_value' => $v('open'), 'won' => $n('won'), 'won_value' => $v('won'), 'lost' => $n('lost'),
            'win_rate' => $closed ? round($n('won') / $closed * 100, 1) : null];
    }
}
