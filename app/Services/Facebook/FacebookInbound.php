<?php

namespace App\Services\Facebook;

use App\Models\Instance;
use App\Services\Instagram\InstagramInbound;
use Illuminate\Support\Facades\Cache;

/**
 * One Messenger event -> chats / chat_messages, a CRM contact and the AI agent. Same event format as Instagram, so the
 * parsing is inherited. Conventions: chat jid and contact = "fb:<PSID>"; echoes of the Page's own sends are recorded as outgoing.
 */
class FacebookInbound extends InstagramInbound
{
    public static function jid(string $psid): string
    {
        return 'fb:' . $psid;
    }

    /** Remember ids we sent, so their echo webhooks do not create duplicates. */
    public static function rememberSent(array $mids): void
    {
        foreach ($mids as $mid) {
            if ($mid !== '') {
                Cache::put('fb-sent:' . self::msgId($mid), 1, 3600);
            }
        }
    }

    protected function channelKey(): string
    {
        return 'facebook';
    }

    protected function owns(Instance $instance): bool
    {
        return $instance->isFacebook();
    }

    protected function accountId(Instance $instance): string
    {
        return (string) $instance->facebook_page_id;
    }

    protected function sentKey(string $messageId): string
    {
        return 'fb-sent:' . $messageId;
    }

    protected function jidFor(string $customerId): string
    {
        return self::jid($customerId);
    }

    protected function client(Instance $instance)
    {
        return FacebookClient::for($instance);
    }

    protected function displayName(array $profile): ?string
    {
        return ($profile['name'] ?? '') !== '' ? $profile['name'] : null;
    }
}
