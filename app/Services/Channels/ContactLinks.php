<?php

namespace App\Services\Channels;

use App\Models\Chat;
use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * Ties the Telegram / Instagram / TikTok contact of a person to their WhatsApp contact, so the chat can show the person's
 * conversations in every channel and send a message through any of them. Channel contacts store "tg:/ig:/tt:<id>" in `contact`.
 */
class ContactLinks
{
    public const PREFIXES = ['tg' => 'telegram', 'ig' => 'instagram', 'tt' => 'tiktok'];

    public static function channelOf(Contact $c): string
    {
        $p = substr((string) $c->contact, 0, 3);

        return self::PREFIXES[rtrim($p, ':')] ?? 'whatsapp';
    }

    public static function isChannelContact(Contact $c): bool
    {
        return self::channelOf($c) !== 'whatsapp';
    }

    /** The WhatsApp contact that anchors the person (itself, or the one a channel contact points to). */
    public static function root(Contact $c): Contact
    {
        if (self::isChannelContact($c) && $c->linked_contact_id) {
            return Contact::where('user_id', $c->user_id)->find($c->linked_contact_id) ?? $c;
        }

        return $c;
    }

    /** @return Collection<int,Contact> the person's contacts in every channel (root first) */
    public static function group(Contact $c): Collection
    {
        $root = self::root($c);
        $members = collect([$root]);
        if (!self::isChannelContact($root)) {
            $members = $members->merge(Contact::where('user_id', $root->user_id)->where('linked_contact_id', $root->id)->orderBy('id')->get());
        }

        return $members->unique('id')->values();
    }

    /** @return Collection<int,Chat> chats of all members, one entry per chat, newest activity first */
    public static function chats(Contact $c): Collection
    {
        $ids = self::group($c)->pluck('id');

        return Chat::with('instance')->where('user_id', $c->user_id)->whereIn('contact_id', $ids)->orderByDesc('last_message_at')->get();
    }

    /** @throws \InvalidArgumentException */
    public static function link(Contact $a, Contact $b): void
    {
        if ($a->user_id !== $b->user_id) {
            throw new \InvalidArgumentException('Different owners.');
        }
        [$channel, $whatsapp] = self::isChannelContact($a) ? [$a, $b] : [$b, $a];
        if (!self::isChannelContact($channel) || self::isChannelContact($whatsapp)) {
            throw new \InvalidArgumentException('Link one WhatsApp contact to one Telegram, Instagram or TikTok contact.');
        }

        $channel->update(['linked_contact_id' => $whatsapp->id]);
    }

    public static function unlink(Contact $channel): void
    {
        if (self::isChannelContact($channel)) {
            $channel->update(['linked_contact_id' => null]);
        }
    }
}
