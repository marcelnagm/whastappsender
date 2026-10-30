<?php

namespace App\Services\Instances;

use App\Jobs\ConfirmInstanceDownJob;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Notifications\InstanceAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Knows when a WhatsApp session went down and tells its owner that sending stopped.
 *
 * Flow: a disconnect is reported (Evolution webhook, failed sends, or the scheduled check) ->
 * the session is marked down -> after a short debounce (connections flap) ConfirmInstanceDownJob asks Evolution again ->
 * if it is really down AND it was sending (messages waiting / delivered recently) the owner is notified once per outage.
 * When it comes back, a recovery notice follows. Short-lived state (debounce, one-alert guard, failure streak) lives in
 * the cache, i.e. in Redis when CACHE_DRIVER=redis.
 */
class InstanceHealth
{
    public function __construct(private EvolutionStatus $evolution)
    {
    }

    // ---- status reports ---------------------------------------------------------------------------------------

    /** Webhook / check says the session is open (true) or not (false). Idempotent. */
    public function report(Instance $instance, bool $connected, bool $confirmNow = false): void
    {
        if ($connected) {
            $this->markConnected($instance);

            return;
        }

        if ($confirmNow) {
            $this->confirmDown($instance->id);

            return;
        }

        // An unconfirmed "close" (webhook) changes nothing by itself: connections flap and a session being paired
        // sends close/connecting before open. The delayed job asks Evolution and only then records the outage.

        // one pending confirmation per session, however many disconnect events arrive
        $minutes = max(0, (int) config('instance_health.alert_after_minutes'));
        if (Cache::add("instance:{$instance->id}:confirm_pending", 1, now()->addMinutes($minutes + 2))) {
            ConfirmInstanceDownJob::dispatch($instance->id)->delay(now()->addMinutes($minutes))->onQueue('default');
        }
    }

    private function markConnected(Instance $instance): void
    {
        $wasAlerted = $instance->down_alerted_at !== null;

        $instance->forceFill(['status' => 'connected', 'disconnected_at' => null, 'down_alerted_at' => null])->save();
        Cache::forget("instance:{$instance->id}:fail_streak");
        Cache::forget("instance:{$instance->id}:down_alert");
        Cache::forget("instance:{$instance->id}:confirm_pending");

        if ($wasAlerted && ($owner = $instance->user)) {
            $this->notify($owner, new InstanceAlert('instance_recovered', ['instance' => $instance->name, 'url' => url('/instances')]));
        }
    }

    /** Runs after the debounce: still down? was it sending? then tell the owner. */
    public function confirmDown(int $instanceId): void
    {
        $instance = Instance::find($instanceId);
        Cache::forget("instance:{$instanceId}:confirm_pending");

        if (!$instance) {
            return;
        }

        // Evolution is the source of truth: the webhook that said "open" may simply have been late
        $state = $this->evolution->state($instance->instance_name);
        if ($state === 'open') {
            $this->markConnected($instance);

            return;
        }
        if ($state === null) {
            return;     // Evolution unreachable: the scheduled check reports that to the admins, not as a dead number
        }

        if ($instance->status !== 'disconnected') {
            $instance->forceFill(['status' => 'disconnected', 'disconnected_at' => $instance->disconnected_at ?? now()])->save();
        }

        $impact = $this->impact($instance);
        if ($impact['pending'] === 0 && $impact['recent_sends'] === 0) {
            return;     // an idle number going offline is not "sending stopped"
        }

        $guardHours = max(1, (int) config('instance_health.realert_after_hours'));
        if (!Cache::add("instance:{$instance->id}:down_alert", 1, now()->addHours($guardHours))) {
            return;     // already told for this outage
        }

        $instance->forceFill(['down_alerted_at' => now()])->save();

        if ($owner = $instance->user) {
            $this->notify($owner, new InstanceAlert('instance_down', [
                'instance' => $instance->name,
                'pending' => $impact['pending'],
                'since' => optional($instance->disconnected_at)->format('d/m/Y H:i'),
                'url' => url('/instances'),
            ]));
        }
    }

    /** @return array{pending:int,recent_sends:int} what the outage affects */
    public function impact(Instance $instance): array
    {
        $waiting = WhatsappJob::where('user_id', $instance->user_id)->whereIn('status', ['pendente', 'fila']);

        // With another connected number the user's messages still go out through it: only this number's share is stuck
        $otherConnected = Instance::where('user_id', $instance->user_id)->where('id', '!=', $instance->id)->where('status', 'connected')->exists();
        if ($otherConnected) {
            $waiting->where('instance_id', $instance->id);
        }

        $recent = WhatsappJob::where('instance_id', $instance->id)->where('status', 'processado')
            ->where('updated_at', '>=', now()->subHours(max(1, (int) config('instance_health.recent_send_hours'))))->count();

        return ['pending' => $waiting->count(), 'recent_sends' => $recent];
    }

    // ---- send results (called by EnviarMensagemJob) -------------------------------------------------------------

    public function sendSucceeded(Instance $instance): void
    {
        Cache::forget("instance:{$instance->id}:fail_streak");
    }

    /** Consecutive failures reveal a dead session even when no webhook arrived. */
    public function sendFailed(Instance $instance, string $detail = ''): void
    {
        $key = "instance:{$instance->id}:fail_streak";
        $ttl = now()->addMinutes((int) config('instance_health.failure_streak.ttl_minutes'));
        Cache::add($key, 0, $ttl);
        $streak = (int) Cache::increment($key);

        $closed = (bool) preg_match('/connection closed|not connected|disconnected/i', $detail);
        if ($streak < (int) config('instance_health.failure_streak.threshold') && !$closed) {
            return;
        }

        // ask Evolution at most once every few minutes per session
        if (!Cache::add("instance:{$instance->id}:streak_check", 1, now()->addMinutes(5))) {
            return;
        }

        $state = $this->evolution->state($instance->instance_name);
        if ($state !== null && $state !== 'open') {
            $this->report($instance->fresh() ?? $instance, false, true);
        }
    }

    // ---- scheduled check ---------------------------------------------------------------------------------------

    /**
     * @return array{evolution:bool,checked:int,down:int,confirmed:int}
     */
    public function checkAll(): array
    {
        $result = ['evolution' => true, 'checked' => 0, 'down' => 0, 'confirmed' => 0];

        if (!$this->evolutionAlive()) {
            $result['evolution'] = false;

            return $result;
        }

        // 1) sessions that look connected but are busy: the webhook that would say otherwise may have been lost
        $busyUsers = WhatsappJob::whereIn('status', ['pendente', 'fila'])->distinct()->pluck('user_id');
        $recentSince = now()->subHours(max(1, (int) config('instance_health.recent_send_hours')));
        $recentIds = WhatsappJob::where('status', 'processado')->where('updated_at', '>=', $recentSince)->whereNotNull('instance_id')->distinct()->pluck('instance_id');

        Instance::where('status', 'connected')
            ->where('channel', 'whatsapp')
            ->where(fn ($q) => $q->whereIn('user_id', $busyUsers)->orWhereIn('id', $recentIds))
            ->get()
            ->each(function (Instance $instance) use (&$result) {
                $result['checked']++;
                $state = $this->evolution->state($instance->instance_name);
                if ($state !== null && $state !== 'open') {
                    $result['down']++;
                    $this->report($instance, false, true);
                }
            });

        // 2) outages whose confirmation never ran (queue was down): confirm them now
        Instance::where('status', 'disconnected')->where('channel', 'whatsapp')->whereNotNull('disconnected_at')->whereNull('down_alerted_at')
            ->where('disconnected_at', '<=', now()->subMinutes(max(1, (int) config('instance_health.alert_after_minutes'))))
            ->get()
            ->each(function (Instance $instance) use (&$result) {
                $before = $instance->down_alerted_at;
                $this->confirmDown($instance->id);
                if (!$before && $instance->fresh()->down_alerted_at) {
                    $result['confirmed']++;
                }
            });

        return $result;
    }

    /** Evolution answering? Alerts the admins once per outage, and once more when it is back. */
    public function evolutionAlive(): bool
    {
        $alive = $this->evolution->reachable();

        if (!$alive) {
            if (Cache::add('health:evolution_down', 1, now()->addHours(24))) {
                $this->notifyAdmins(new InstanceAlert('evolution_unreachable', ['url' => url('/')]));
            }

            return false;
        }

        if (Cache::pull('health:evolution_down')) {
            $this->notifyAdmins(new InstanceAlert('evolution_recovered'));
        }

        return true;
    }

    // ---- notifications -----------------------------------------------------------------------------------------

    public function notifyAdmins(InstanceAlert $alert): void
    {
        User::where('role', 'admin')->get()->each(fn (User $admin) => $this->notify($admin, $alert));
    }

    /** An alert must never break the flow that raised it (mail server down, etc.) */
    private function notify(User $user, InstanceAlert $alert): void
    {
        try {
            $user->notify($alert);
        } catch (\Throwable $e) {
            Log::warning("Could not deliver {$alert->type} alert to user {$user->id}: " . $e->getMessage());
        }
    }
}
