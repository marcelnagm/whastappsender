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

    // Cron expression of the scheduled run (only when WHATSAPP_AUTOSEND is enabled)
    'cron' => env('WHATSAPP_QUEUE_CRON', '0 * * * *'),
];
