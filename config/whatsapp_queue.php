<?php

/*
|--------------------------------------------------------------------------
| Fair send queue
|--------------------------------------------------------------------------
|
| `php artisan whatsapp:queue` builds a batch of messages and pushes it to the
| "disparos" queue. These settings control how that batch is shared.
|
*/

return [

    // Messages dispatched on each run of whatsapp:queue
    'batch_size' => max(1, (int) env('WHATSAPP_BATCH_SIZE', 50)),

    // How the batch is shared:
    //   session : equal share per connected WhatsApp session (users with more numbers send more)
    //   user    : equal share per user, whatever the number of sessions
    //   fifo    : legacy behaviour, oldest messages first (one big campaign can starve the others)
    'fairness' => env('WHATSAPP_FAIRNESS', 'session'),

    // Messages already waiting in the queue ("fila") count against their owner, so a user
    // whose earlier batch is still queued does not get more than their share. Rows stuck
    // in "fila" for longer than this are ignored (e.g. a worker was down).
    'inflight_window_hours' => (int) env('WHATSAPP_INFLIGHT_WINDOW_HOURS', 24),

    // Priority by plan. Every plan has a "queue_priority" weight (config/billing.php limits; Free 1, Starter 2, Pro 4,
    // Business 8). It multiplies the user's capacity in the fair share, so a Business account gets about 8x the slots of
    // a Free one in every batch.
    //   weighted : proportional share (default): lower plans slow down but never starve
    //   strict   : higher priorities are served first; lower plans only get what is left over
    // Nothing is stored per message: the weight is read from the owner's plan when the batch is built.
    'priority' => [
        'enabled' => (bool) env('WHATSAPP_PRIORITY', true),
        'mode' => env('WHATSAPP_PRIORITY_MODE', 'weighted'),
    ],

    // Cron expression of the scheduled run (only when WHATSAPP_AUTOSEND is enabled)
    'cron' => env('WHATSAPP_QUEUE_CRON', '0 * * * *'),

    // Per-session send pacing (anti-ban). One message at a time per WhatsApp session, and a pause between two
    // sends of the SAME session. It is kept in the cache (Redis when CACHE_DRIVER=redis, so every worker and every
    // server sees it) instead of sleeping inside the worker, which used to block a worker for ~1 minute per message.
    // Local development skips the pauses so tests and demos are instant.
    'pacing' => [
        'enabled' => (bool) env('WHATSAPP_PACING', true),
        'cooldown_min' => (int) env('WHATSAPP_COOLDOWN_MIN', env('APP_ENV') === 'local' ? 0 : 25),
        'cooldown_max' => (int) env('WHATSAPP_COOLDOWN_MAX', env('APP_ENV') === 'local' ? 0 : 50),
        'humanize_min' => (int) env('WHATSAPP_HUMANIZE_MIN', env('APP_ENV') === 'local' ? 0 : 4),
        'humanize_max' => (int) env('WHATSAPP_HUMANIZE_MAX', env('APP_ENV') === 'local' ? 0 : 9),
        'lock_seconds' => 180,   // a crashed worker cannot hold a session longer than this
    ],
];
