<?php

namespace App\Listeners;

use App\Notifications\InstanceAlert;
use App\Services\Instances\InstanceHealth;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Cache;

/** queue:monitor found a queue above the limit: tell the admins (at most once per cooldown per queue). */
class NotifyQueueBacklog
{
    public function handle(QueueBusy $event): void
    {
        $cooldown = max(1, (int) config('instance_health.queue_alert_cooldown_minutes', 60));

        if (!Cache::add("health:queue_backlog:{$event->queue}", 1, now()->addMinutes($cooldown))) {
            return;
        }

        app(InstanceHealth::class)->notifyAdmins(new InstanceAlert('queue_backlog', [
            'queue' => $event->queue,
            'size' => $event->size,
        ]));
    }
}
