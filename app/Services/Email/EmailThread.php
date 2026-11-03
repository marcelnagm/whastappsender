<?php

namespace App\Services\Email;

use App\Models\Chat;

/** Subject and Message-IDs of an e-mail conversation, so our answers land in the same thread of the customer's mail app. */
class EmailThread
{
    public static function subject(Chat $chat, string $fallbackName = ''): string
    {
        $s = trim((string) $chat->email_subject);
        if ($s === '') {
            return __('Message from :name', ['name' => $fallbackName !== '' ? $fallbackName : (string) config('app.name')]);
        }

        return preg_match('/^(re|res|aw|sv)\s*:/i', $s) ? $s : 'Re: ' . $s;
    }

    /** @return string[] */
    public static function refs(Chat $chat): array
    {
        return array_values(array_filter(explode(' ', (string) $chat->email_refs)));
    }

    public static function replyTo(Chat $chat): ?string
    {
        $r = self::refs($chat);

        return $r ? end($r) : null;
    }

    public static function remember(Chat $chat, string $messageId): void
    {
        $refs = array_slice(array_values(array_unique(array_merge(self::refs($chat), [$messageId]))), -10);
        $chat->forceFill(['email_refs' => implode(' ', $refs)])->save();
    }

    /** Options for EmailClient::send to answer inside the thread */
    public static function options(Chat $chat, bool $auto = false): array
    {
        return ['in_reply_to' => self::replyTo($chat), 'references' => self::refs($chat), 'auto' => $auto];
    }
}
