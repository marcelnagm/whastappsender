<?php

namespace App\Services\Reports;

use App\Models\Deal;
use App\Models\LostReason;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Why deals are won or lost: results of deals closed in a period, by lost reason, lead origin and person in charge. */
class WinLossReport
{
    public function build(User $owner, Carbon $from, Carbon $to): array
    {
        $deals = Deal::where('user_id', $owner->id)->whereIn('status', ['won', 'lost'])->whereBetween('closed_at', [$from, $to])->get();
        $won = $deals->where('status', 'won');
        $lost = $deals->where('status', 'lost');

        $reasons = $lost->groupBy(fn (Deal $d) => $this->normalize($d->lost_reason))->map(fn ($g, $k) => [
            'reason' => $k === '' ? null : $g->first()->lost_reason,
            'count' => $g->count(),
            'value' => round((float) $g->sum('value'), 2),
            'share' => $lost->count() ? round(100 * $g->count() / $lost->count(), 1) : 0.0,
        ])->sortByDesc('count')->values()->all();

        return [
            'won' => $won->count(), 'lost' => $lost->count(),
            'win_rate' => $deals->count() ? round(100 * $won->count() / $deals->count(), 1) : null,
            'won_value' => round((float) $won->sum('value'), 2), 'lost_value' => round((float) $lost->sum('value'), 2),
            'reasons' => $reasons,
            'by_origin' => $this->split($deals, fn (Deal $d) => trim((string) $d->origin) ?: null),
            'by_owner' => $this->split($deals, fn (Deal $d) => $d->assigned_to ?: null, true),
        ];
    }

    /** @return array<int,array{key:mixed,won:int,lost:int,win_rate:?float}> */
    private function split($deals, \Closure $key, bool $people = false): array
    {
        $names = $people ? User::whereIn('id', $deals->pluck('assigned_to')->filter()->unique())->pluck('name', 'id') : collect();
        $rows = $deals->groupBy(fn ($d) => $key($d) ?? '')->map(function ($g, $k) use ($names, $people) {
            $w = $g->where('status', 'won')->count();
            $l = $g->where('status', 'lost')->count();

            return ['key' => $k === '' ? null : ($people ? ($names[$k] ?? '#' . $k) : $k), 'won' => $w, 'lost' => $l, 'win_rate' => ($w + $l) ? round(100 * $w / ($w + $l), 1) : null];
        })->sortByDesc(fn ($r) => $r['won'] + $r['lost'])->values()->all();

        return $rows;
    }

    private function normalize(?string $reason): string
    {
        return mb_strtolower(trim((string) $reason));
    }

    /** Reasons offered when losing a deal: the account's own list first, then the ones already used. */
    public function options(User $owner): array
    {
        $own = LostReason::where('user_id', $owner->id)->orderBy('name')->pluck('name')->all();
        $used = Deal::where('user_id', $owner->id)->where('status', 'lost')->whereNotNull('lost_reason')->where('lost_reason', '!=', '')
            ->distinct()->limit(30)->pluck('lost_reason')->all();

        return array_values(array_unique(array_merge($own, $used)));
    }
}
