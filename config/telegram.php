<?php

/*
|--------------------------------------------------------------------------
| Telegram channel (bots)
|--------------------------------------------------------------------------
| Each bot (created with @BotFather) is connected in "Telegram" and behaves like a WhatsApp number in the chat and AI agents.
| Telegram bots cannot start a conversation: they can only answer people who wrote to the bot first.
*/
return [
    // Change only for a self-hosted Bot API server
    'api_url' => rtrim((string) env('TELEGRAM_API_URL', 'https://api.telegram.org'), '/'),

    'timeout' => (int) env('TELEGRAM_TIMEOUT', 30),

    // Bot API limits: files the bot can download (getFile) and upload
    'max_download_bytes' => (int) env('TELEGRAM_MAX_DOWNLOAD_BYTES', 20 * 1024 * 1024),

    // Updates requested from Telegram
    'allowed_updates' => ['message', 'edited_message'],
];
