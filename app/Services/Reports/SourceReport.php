<?php

namespace App\Services\Reports;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Funnel result per lead origin, for deals created in a period. */
class SourceReport
{
    /**
     * @return array{rows:array<int,array{origin:?string,created:int,open:int,won:int,lost:int,win_rate:?float,won_value:float,avg_days:?float}>,totals:array}
     */
    public function build(User $owner, Carbon $from, Carbon $to): array
    {
        $groups = Deal::where('user_id', $owner->id)->whereBetween('created_at', [$from, $to])->get()
            ->groupBy(fn (Deal $d) => $d->origin !== null && trim($d->origin) !== '' ? $d->origin : null);

        $rows = [];
        foreach ($groups as $origin => $deals) {
            $won = $deals->where('status', 'won');
            $lost = $deals->where('status', 'lost');
            $closed = $won->count() + $lost->count();
            $days = $won->filter(fn ($d) => $d->closed_at)->map(fn ($d) => $d->created_at->diffInSeconds($d->closed_at) / 86400);
            $rows[] = [
                'origin' => $origin === '' ? null : $origin,
                'created' => $deals->count(),
                'open' => $deals->where('status', 'open')->count(),
                'won' => $won->count(),
                'lost' => $lost->count(),
                'win_rate' => $closed ? round(100 * $won->count() / $closed, 1) : null,
                'won_value' => round((float) $won->sum('value'), 2),
                'avg_days' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
            ];
        }
        usort($rows, fn ($a, $b) => [$b['won_value'], $b['created']] <=> [$a['won_value'], $a['created']]);

        $won = array_sum(array_column($rows, 'won'));
        $lost = array_sum(array_column($rows, 'lost'));

        return ['rows' => $rows, 'totals' => [
            'created' => array_sum(array_column($rows, 'created')), 'won' => $won, 'lost' => $lost,
            'win_rate' => ($won + $lost) ? round(100 * $won / ($won + $lost), 1) : null, 'won_value' => round(array_sum(array_column($rows, 'won_value')), 2),
        ]];
    }

    /** Origins already used by the account, for the suggestions of the deal window. */
    public function known(User $owner): array
    {
        return Deal::where('user_id', $owner->id)->whereNotNull('origin')->where('origin', '!=', '')->distinct()->orderBy('origin')->limit(50)->pluck('origin')->all();
    }
}
