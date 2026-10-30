<?php

/*
|--------------------------------------------------------------------------
| TikTok channel (Business Messaging API)
|--------------------------------------------------------------------------
| One TikTok app (developers.tiktok.com / business-api.tiktok.com/portal/apps) serves every account connected in "TikTok":
| the app owns the webhook and the OAuth client, each account is authorised once through OAuth and gets its own tokens.
| TikTok only exposes this API to approved apps and Business Accounts in eligible regions (not EEA / UK / Switzerland),
| and a business can only reply to people who messaged it first, inside the messaging window.
|
| Paths are configurable because TikTok publishes the full reference only to approved developers: if the portal shows a
| different path for your API version, override it here or in .env instead of changing code.
*/
return [
    'api_url' => rtrim((string) env('TIKTOK_API_URL', 'https://business-api.tiktok.com/open_api/v1.3'), '/'),
    'auth_url' => env('TIKTOK_AUTH_URL', 'https://business-api.tiktok.com/portal/auth'),

    // App credentials (Developer Portal > your app)
    'app_id' => env('TIKTOK_APP_ID'),
    'app_secret' => env('TIKTOK_APP_SECRET'),

    'timeout' => (int) env('TIKTOK_TIMEOUT', 30),

    // Replies are accepted for this many hours after the customer's last message
    'window_hours' => (int) env('TIKTOK_WINDOW_HOURS', 48),

    // Webhook timestamps older than this many seconds are rejected (replay protection; keep the server clock synced)
    'signature_tolerance' => (int) env('TIKTOK_SIGNATURE_TOLERANCE', 300),

    'max_text' => 6000,
    'max_image_bytes' => 3 * 1024 * 1024, // JPG / PNG only
    'max_download_bytes' => (int) env('TIKTOK_MAX_DOWNLOAD_BYTES', 25 * 1024 * 1024),

    'paths' => [
        'token' => env('TIKTOK_PATH_TOKEN', '/tt_user/oauth2/token/'),
        'refresh' => env('TIKTOK_PATH_REFRESH', '/tt_user/oauth2/refresh_token/'),
        'send' => env('TIKTOK_PATH_SEND', '/business/message/send/'),
        'messages' => env('TIKTOK_PATH_MESSAGES', '/business/message/content/list/'),
        'upload' => env('TIKTOK_PATH_UPLOAD', '/business/message/media/upload/'),
        'download' => env('TIKTOK_PATH_DOWNLOAD', '/business/message/media/download/'),
        'webhook' => env('TIKTOK_PATH_WEBHOOK', '/business/webhook/update/'),
    ],
];
