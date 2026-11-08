<?php

namespace App\Services\Reports;

use App\Models\Deal;
use App\Models\DealStageEntry;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Support\Carbon;

/**
 * Funnel conversion and sales velocity of a pipeline, from the history of stage changes (kept since the feature
 * was installed; older deals only count from their next move).
 */
class FunnelReport
{
    /** Records that the deal is now in this stage (closing its previous stay). Called on creation and on every move. */
    public static function record(Deal $deal): void
    {
        $last = DealStageEntry::where('deal_id', $deal->id)->whereNull('left_at')->latest('id')->first();
        if ($last && (int) $last->stage_id === (int) $deal->stage_id) {
            return;
        }
        DealStageEntry::where('deal_id', $deal->id)->whereNull('left_at')->update(['left_at' => now()]);
        DealStageEntry::create(['deal_id' => $deal->id, 'user_id' => $deal->user_id, 'pipeline_id' => $deal->pipeline_id, 'stage_id' => $deal->stage_id, 'entered_at' => now()]);
    }

    public function build(Pipeline $pipeline, Carbon $from, Carbon $to): array
    {
        $stages = PipelineStage::where('pipeline_id', $pipeline->id)->orderBy('position')->orderBy('id')->get();
        $rank = $stages->pluck('position', 'id')->map(fn ($p) => (int) $p);
        $entries = DealStageEntry::where('pipeline_id', $pipeline->id)->whereBetween('entered_at', [$from, $to])->orderBy('entered_at')->get();
        $byDeal = $entries->groupBy('deal_id');

        $rows = [];
        foreach ($stages->where('kind', 'open') as $s) {
            $reached = $entries->where('stage_id', $s->id)->pluck('deal_id')->unique();
            $advanced = $reached->filter(fn ($id) => $byDeal[$id]->contains(fn ($e) => ($rank[$e->stage_id] ?? 0) > $rank[$s->id] && $e->entered_at >= $byDeal[$id]->firstWhere('stage_id', $s->id)->entered_at))->count();
            $stays = $entries->where('stage_id', $s->id)->filter(fn ($e) => $e->left_at);
            $rows[] = [
                'stage' => $s->name, 'color' => $s->color, 'reached' => $reached->count(), 'advanced' => $advanced,
                'conversion' => $reached->count() ? round(100 * $advanced / $reached->count(), 1) : null,
                'avg_days' => $stays->count() ? round($stays->avg(fn ($e) => $e->entered_at->diffInSeconds($e->left_at)) / 86400, 1) : null,
            ];
        }

        $closed = Deal::where('pipeline_id', $pipeline->id)->whereIn('status', ['won', 'lost'])->whereBetween('closed_at', [$from, $to])->get();
        $won = $closed->where('status', 'won');
        $winRate = $closed->count() ? $won->count() / $closed->count() : null;
        $avgValue = $won->count() ? (float) $won->avg('value') : null;
        $cycle = $won->count() ? $won->avg(fn ($d) => $d->created_at->diffInSeconds($d->closed_at) / 86400) : null;
        $open = Deal::where('pipeline_id', $pipeline->id)->where('status', 'open')->count();
        $days = max(1, $from->diffInDays($to));

        return [
            'rows' => $rows, 'won' => $won->count(), 'lost' => $closed->count() - $won->count(),
            'win_rate' => $winRate !== null ? round(100 * $winRate, 1) : null,
            'avg_value' => $avgValue !== null ? round($avgValue, 2) : null,
            'cycle_days' => $cycle !== null ? round($cycle, 1) : null,
            // classic sales velocity: open deals x win rate x average value / cycle length = revenue per day
            'velocity_day' => ($winRate !== null && $avgValue !== null && $cycle > 0) ? round($open * $winRate * $avgValue / $cycle, 2) : null,
            'open' => $open, 'period_days' => $days,
        ];
    }
}
