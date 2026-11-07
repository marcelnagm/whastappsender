<?php

namespace App\Services\Pipeline;

use App\Models\Chat;
use App\Models\Deal;
use App\Models\StaleDealSetting;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\Automation\Automations;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Open deals nobody touched (and the customer did not write about) for N days: the owner of the deal is told once per stalled period. */
class StaleDeals
{
    /** Last sign of life of a deal: its own update or a message on its conversation. */
    public function lastActivity(Deal $deal): Carbon
    {
        $at = $deal->updated_at ?? $deal->created_at;
        if ($deal->chat_id && ($last = Chat::whereKey($deal->chat_id)->value('last_message_at'))) {
            $last = Carbon::parse($last);
            $at = $at->gt($last) ? $at : $last;
        }

        return $at;
    }

    /** @return Collection<int,array{deal:Deal,idle:int}> most idle first */
    public function stale(int $userId, int $days, ?Carbon $now = null): Collection
    {
        $now = $now ?? now();
        $out = collect();
        Deal::with(['contact', 'stage'])->where('user_id', $userId)->where('status', 'open')->where('updated_at', '<=', $now->copy()->subDays($days))->get()->each(function (Deal $d) use ($days, $now, $out) {
            $idle = (int) $this->lastActivity($d)->diffInDays($now);
            if ($idle >= $days) {
                $out->push(['deal' => $d, 'idle' => $idle]);
            }
        });

        return $out->sortByDesc('idle')->values();
    }

    /** Notifies new stale deals. Returns how many. */
    public function run(?Carbon $now = null): int
    {
        $now = $now ?? now();
        $n = 0;
        foreach (StaleDealSetting::where('enabled', true)->get() as $cfg) {
            $owner = User::find($cfg->user_id);
            if (!$owner) {
                continue;
            }
            foreach ($this->stale($cfg->user_id, $cfg->days, $now) as $row) {
                $d = $row['deal'];
                // once per stalled period: a fresh sign of life makes it eligible again
                if ($d->stale_notified_at && $d->stale_notified_at->gte($this->lastActivity($d))) {
                    continue;
                }
                Deal::whereKey($d->id)->toBase()->update(['stale_notified_at' => $now]); // without touching updated_at
                $to = ($d->assigned_to ? User::find($d->assigned_to) : null) ?: $owner;
                $to->notify(new AutomationNotice(__('Deal stalled for :n days: :title', ['n' => $row['idle'], 'title' => $d->title]), route('pipeline.index', ['pipeline' => $d->pipeline_id])));
                app(Automations::class)->fire('deal.stale', $owner, ['deal' => $d]);
                $n++;
            }
        }

        return $n;
    }
}
