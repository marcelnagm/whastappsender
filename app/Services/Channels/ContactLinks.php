<?php

namespace App\Services\Channels;

use App\Models\Chat;
use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * Ties the Telegram / Instagram / Facebook / TikTok contact of a person to their WhatsApp contact, so the chat can show the person's
 * conversations in every channel and send a message through any of them. Channel contacts store "tg:/ig:/fb:/tt:<id>" in `contact`.
 */
class ContactLinks
{
    public const PREFIXES = ['tg' => 'telegram', 'ig' => 'instagram', 'tt' => 'tiktok', 'fb' => 'facebook', 'em' => 'email'];

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
            throw new \InvalidArgumentException('Link one WhatsApp contact to one Telegram, Instagram, Facebook or TikTok contact.');
        }

        $channel->update(['linked_contact_id' => $whatsapp->id]);
    }

    /**
     * Same e-mail address = same person: ties the e-mail contact ("em:<address>") to the WhatsApp contact that has that address
     * (whichever of the two appeared first). Never overrides a link a person made, never guesses when two WhatsApp contacts share the address.
     */
    public static function linkByEmail(Contact $c): void
    {
        if (self::channelOf($c) === 'email') {
            $address = strtolower(substr((string) $c->contact, 3));
            if ($address === '' || $c->linked_contact_id) {
                return;
            }
            $matches = Contact::where('user_id', $c->user_id)->whereRaw('LOWER(email) = ?', [$address])
                ->where(fn ($q) => $q->whereNull('linked_contact_id'))->where('contact', 'not like', '%:%')->limit(2)->get();
            if ($matches->count() === 1) {
                $c->update(['linked_contact_id' => $matches->first()->id]);
            }

            return;
        }

        $address = strtolower(trim((string) $c->email));
        if ($address === '' || self::isChannelContact($c)) {
            return;
        }
        $mail = Contact::where('user_id', $c->user_id)->where('contact', 'em:' . $address)->whereNull('linked_contact_id')->first();
        if ($mail && Contact::where('user_id', $c->user_id)->whereRaw('LOWER(email) = ?', [$address])->where('contact', 'not like', '%:%')->count() === 1) {
            $mail->update(['linked_contact_id' => $c->id]);
        }
    }

    public static function unlink(Contact $channel): void
    {
        if (self::isChannelContact($channel)) {
            $channel->update(['linked_contact_id' => null]);
        }
    }
}
