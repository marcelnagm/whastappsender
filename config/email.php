<?php

return [
    // How often the mailboxes (IMAP) are checked for new messages: cron expression
    'fetch_cron' => env('EMAIL_FETCH_CRON', '* * * * *'),

    // Most new messages imported from one mailbox per check (the rest is taken on the next one)
    'max_per_poll' => (int) env('EMAIL_MAX_PER_POLL', 20),

    // Attachments bigger than this are not kept in the chat (bytes, default 10 MB)
    'max_attachment_bytes' => (int) env('EMAIL_MAX_ATTACHMENT_BYTES', 10485760),

    // Seconds to wait for an SMTP / IMAP server
    'timeout' => (int) env('EMAIL_TIMEOUT', 20),

    // Allow SMTP / IMAP servers on localhost or private networks (default: only when APP_ENV=local). Keep false on a shared host (SSRF protection)
    'allow_private_hosts' => env('EMAIL_ALLOW_PRIVATE_HOSTS', env('APP_ENV') === 'local'),

    // Ports users may use (comma separated). Empty = any port
    'smtp_ports' => array_filter(array_map('intval', explode(',', (string) env('EMAIL_SMTP_PORTS', '25,465,587,2525')))),
    'imap_ports' => array_filter(array_map('intval', explode(',', (string) env('EMAIL_IMAP_PORTS', '143,993')))),

    // Loop protection: the AI answers one sender at most this many times per day
    'ai_max_replies_per_sender_per_day' => (int) env('EMAIL_AI_MAX_REPLIES_PER_DAY', 5),
];
