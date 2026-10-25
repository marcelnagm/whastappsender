<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Web-style chat (CRM)
|--------------------------------------------------------------------------
*/

return [

    // Browser polling intervals (milliseconds)
    'poll_messages_ms' => (int) env('CHAT_POLL_MESSAGES_MS', 2500),
    'poll_chats_ms' => (int) env('CHAT_POLL_CHATS_MS', 6000),

    // Messages returned per page
    'page_size' => 40,

    // Max upload size (kilobytes). WhatsApp accepts up to ~16MB for media.
    'max_upload_kb' => (int) env('CHAT_MAX_UPLOAD_KB', 16384),

    // Folder (inside storage/app) where chat media is cached
    'media_path' => 'chat-media',

    // When an agent replies from the chat, pause the AI auto-reply for that contact
    'pause_ai_on_reply' => (bool) env('CHAT_PAUSE_AI_ON_REPLY', false),

    // Mark messages as read on WhatsApp (blue ticks for the customer) when the agent opens the chat
    'send_read_receipts' => (bool) env('CHAT_SEND_READ_RECEIPTS', true),

    // History import (Evolution findChats / findMessages)
    'history' => [
        'max_chats' => 50,
        'per_chat' => 30,
    ],
];
