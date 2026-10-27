<?php

/*
|--------------------------------------------------------------------------
| WhatsApp session health and alerts
|--------------------------------------------------------------------------
|
| When a session (instance) goes down and sending stops, its owner gets an in-app
| notification and an e-mail. Short blips are ignored: the session must still be down
| after `alert_after_minutes`, and a session that never had anything to send is not reported.
|
*/

return [

    // Wait this long after a disconnect before alerting (WhatsApp connections flap for a few seconds)
    'alert_after_minutes' => (int) env('INSTANCE_ALERT_AFTER_MINUTES', 3),

    // A session "was sending" when it delivered something in this window, or has messages waiting
    'recent_send_hours' => (int) env('INSTANCE_RECENT_SEND_HOURS', 24),

    // One alert per outage; if the session stays down this long, remind again
    'realert_after_hours' => (int) env('INSTANCE_REALERT_AFTER_HOURS', 12),

    // N consecutive failed sends (within ttl) make the app ask Evolution for the real state at once
    'failure_streak' => [
        'threshold' => (int) env('INSTANCE_FAILURE_THRESHOLD', 3),
        'ttl_minutes' => 15,
    ],

    // `instances:health` (scheduled): checks the state of busy sessions against Evolution, catches missed
    // webhooks and tells admins when the Evolution API itself is unreachable
    'check_cron' => env('INSTANCE_HEALTH_CRON', '*/5 * * * *'),

    // Queue backlog alert for admins (queue:monitor)
    'queue_max_pending' => (int) env('QUEUE_BACKLOG_MAX', 500),
    'queue_alert_cooldown_minutes' => 60,
];
