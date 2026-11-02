<?php

/*
|--------------------------------------------------------------------------
| Facebook channel (Messenger for Pages)
|--------------------------------------------------------------------------
| One Meta app serves every Page connected in "Facebook": the app receives the webhooks (callback URL + verify token + app
| secret) and each Page brings its own Page access token. The secrets default to the Instagram ones because the same Meta app
| usually carries both products. Meta only accepts a reply within the standard messaging window after the customer's last
| message, and the Page cannot start conversations.
*/
return [
    'graph_url' => rtrim((string) env('FACEBOOK_GRAPH_URL', 'https://graph.facebook.com'), '/'),
    'api_version' => env('FACEBOOK_API_VERSION', 'v21.0'),

    // Meta app dashboard > Messenger > Webhooks
    'app_secret' => env('FACEBOOK_APP_SECRET', env('INSTAGRAM_APP_SECRET')),
    'verify_token' => env('FACEBOOK_VERIFY_TOKEN', env('INSTAGRAM_VERIFY_TOKEN')),

    'timeout' => (int) env('FACEBOOK_TIMEOUT', 30),

    // Hours after the customer's last message during which replies are accepted by Messenger
    'window_hours' => (int) env('FACEBOOK_WINDOW_HOURS', 24),

    'max_download_bytes' => (int) env('FACEBOOK_MAX_DOWNLOAD_BYTES', 25 * 1024 * 1024),

    // Messenger limits a text message to 2000 characters; longer answers are split
    'max_text' => 1900,
];
