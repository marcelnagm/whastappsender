<?php

namespace App\Services\Instagram;

use App\Models\Chat;
use App\Models\ChatMessage;

/** Instagram only accepts a reply for a limited time after the customer's last message. */
class InstagramWindow
{
    public static function hours(): int
    {
        return max(1, (int) config('instagram.window_hours', 24));
    }

    public static function isOpen(Chat $chat): bool
    {
        $last = ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->max('message_timestamp');

        return $last && \Carbon\Carbon::parse($last)->greaterThan(now()->subHours(self::hours()));
    }

    public static function closedMessage(): string
    {
        return __('Instagram only accepts replies up to :hours hours after the customer\'s last message.', ['hours' => self::hours()]);
    }
}
