<?php

namespace App\Services\Instances;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Pacing of ONE WhatsApp session, shared by every worker (and every server when the cache is Redis):
 *  - a lock: only one message at a time leaves a given session;
 *  - a "next send at" stamp: a pause between two sends of that session (anti-ban).
 * A job that cannot enter is simply postponed; it never sleeps inside the worker.
 */
class InstanceSendGate
{
    private function cfg(string $key)
    {
        return config("whatsapp_queue.pacing.$key");
    }

    private function nextKey(int $instanceId): string
    {
        return "whatsapp:session:{$instanceId}:next_send_at";
    }

    /** Seconds until this session may send again (0 = now) */
    public function waitSeconds(int $instanceId): int
    {
        return max(0, (int) Cache::get($this->nextKey($instanceId), 0) - time());
    }

    /**
     * @return array{0:bool,1:int,2:?Lock} [may send, seconds to wait when not, the lock to hand back to leave()]
     */
    public function enter(int $instanceId): array
    {
        if (!$this->cfg('enabled')) {
            return [true, 0, null];
        }

        if (($wait = $this->waitSeconds($instanceId)) > 0) {
            return [false, $wait + rand(1, 5), null];
        }

        $lock = Cache::lock("whatsapp:session:{$instanceId}:lock", (int) $this->cfg('lock_seconds'));
        if (!$lock->get()) {
            return [false, rand(10, 25), null];     // another worker is sending from this session right now
        }

        // another worker may have finished between the first check and the lock
        if (($wait = $this->waitSeconds($instanceId)) > 0) {
            $lock->release();

            return [false, $wait + rand(1, 5), null];
        }

        return [true, 0, $lock];
    }

    /** Call when the attempt is over. A message that actually went out starts the pause before the next one. */
    public function leave(int $instanceId, ?Lock $lock, bool $sent): void
    {
        $min = (int) $this->cfg('cooldown_min');
        $max = max($min, (int) $this->cfg('cooldown_max'));

        if ($sent && $max > 0) {
            $pause = rand($min, $max);
            Cache::put($this->nextKey($instanceId), time() + $pause, $pause + 60);
        }

        if ($lock) {
            $lock->release();
        }
    }
}
