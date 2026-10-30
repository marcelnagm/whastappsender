<?php

/*
|--------------------------------------------------------------------------
| Instagram channel (Direct messages)
|--------------------------------------------------------------------------
| Uses the "Instagram API with Instagram Login" (Messaging). One Meta app serves every account connected in "Instagram":
| the app receives the webhooks (callback URL + verify token + app secret) and each account brings its own access token.
| Meta only allows replying within the messaging window after the customer's last message; the app cannot start conversations.
*/
return [
    'graph_url' => rtrim((string) env('INSTAGRAM_GRAPH_URL', 'https://graph.instagram.com'), '/'),
    'api_version' => env('INSTAGRAM_API_VERSION', 'v21.0'),

    // Meta app dashboard > Instagram > API setup > Webhooks
    'app_secret' => env('INSTAGRAM_APP_SECRET'),
    'verify_token' => env('INSTAGRAM_VERIFY_TOKEN'),

    'timeout' => (int) env('INSTAGRAM_TIMEOUT', 30),

    // Hours after the customer's last message during which replies are accepted by Instagram
    'window_hours' => (int) env('INSTAGRAM_WINDOW_HOURS', 24),

    'max_download_bytes' => (int) env('INSTAGRAM_MAX_DOWNLOAD_BYTES', 25 * 1024 * 1024),

    // Lifetime (minutes) of the signed link Instagram uses to fetch media we send
    'media_link_minutes' => (int) env('INSTAGRAM_MEDIA_LINK_MINUTES', 30),

    // Instagram limits a text message to 1000 characters; longer answers are split
    'max_text' => 950,
];
