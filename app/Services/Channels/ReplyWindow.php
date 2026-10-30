<?php

namespace App\Services\Channels;

use App\Models\Chat;
use App\Models\ChatMessage;

/** Instagram and TikTok accept a reply only for a limited time after the customer's last message; WhatsApp and Telegram have no such window here. */
class ReplyWindow
{
    public static function hours(string $channel): ?int
    {
        return match ($channel) {
            'instagram' => max(1, (int) config('instagram.window_hours', 24)),
            'tiktok' => max(1, (int) config('tiktok.window_hours', 48)),
            default => null,
        };
    }

    public static function isOpen(Chat $chat): bool
    {
        $channel = $chat->instance ? $chat->instance->channelKey() : 'whatsapp';
        $hours = self::hours($channel);
        if ($hours === null) {
            return true;
        }

        $last = ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->max('message_timestamp');

        return $last && \Carbon\Carbon::parse($last)->greaterThan(now()->subHours($hours));
    }

    public static function closedMessage(string $channel): string
    {
        $key = $channel === 'tiktok' ? 'TikTok' : 'Instagram';

        return __(':channel only accepts replies up to :hours hours after the customer\'s last message.', ['channel' => $key, 'hours' => self::hours($channel)]);
    }
}
