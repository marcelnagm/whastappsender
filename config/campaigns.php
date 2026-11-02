<?php

return [
    // Seconds between two messages of a campaign sent through Telegram / Instagram / Facebook / TikTok
    'channel_delay_seconds' => (int) env('CAMPAIGN_CHANNEL_DELAY', 3),
];
