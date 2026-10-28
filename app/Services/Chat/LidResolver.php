<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\LidMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp sometimes identifies a person by an opaque "@lid" id instead of the phone number.
 * This finds the real phone number, converts the chat to "<phone>@s.whatsapp.net" (merging it into the
 * chat that already exists for that phone, if any) and remembers the lid on the CRM contact.
 */
class LidResolver
{
    public function __construct(private EvolutionChatClient $evolution)
    {
    }

    public static function isLid(?string $jid): bool
    {
        return $jid !== null && str_ends_with($jid, '@lid');
    }

    /** Digits only, or null when it does not look like a phone number */
    public static function phoneFrom(?string $value): ?string
    {
        if (!$value || str_ends_with($value, '@lid') || str_contains($value, '@g.us')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', explode('@', explode(':', $value)[0])[0]);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    /** Cheap lookup in our own data (no HTTP): contacts that stored this lid. */
    public function fromDatabase(int $userId, string $lid): ?string
    {
        $contact = Contact::where('user_id', $userId)->where('lid', $lid)->first();

        return $contact ? self::phoneFrom($contact->contact) : null;
    }

    /** Asks Evolution (contacts, then the chat's own messages, which carry remoteJidAlt/senderPn). */
    public function fromEvolution(Instance $instance, string $lid): ?string
    {
        $name = $instance->instance_name;

        try {
            foreach ($this->evolution->findContacts($name, $lid) as $row) {
                foreach (['remoteJidAlt', 'senderPn', 'phoneNumber', 'number'] as $field) {
                    if ($phone = self::phoneFrom($row[$field] ?? null)) {
                        return $phone;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to the messages
        }

        try {
            foreach ($this->evolution->findMessages($name, $lid, 1, 20) as $record) {
                $key = (array) ($record['key'] ?? []);
                foreach (['remoteJidAlt', 'senderPn', 'participantPn'] as $field) {
                    if ($phone = self::phoneFrom($key[$field] ?? null)) {
                        return $phone;
                    }
                }
            }
        } catch (\Throwable $e) {
            // unreachable Evolution: try again later
        }

        return null;
    }

    /**
     * A lid -> phone pair is certain: keep it for good and convert every chat of this user that was opened under the lid
     * (each instance keeps its own chats; they are only merged with the phone chat of the SAME instance).
     *
     * @return Chat|null the chat of $instance that now carries the phone, when there was one
     */
    public function learn(Instance $instance, string $lid, string $phone, string $source = 'message'): ?Chat
    {
        $phone = (string) self::phoneFrom($phone);
        if ($phone === '' || !self::isLid($lid)) {
            return null;
        }

        LidMapping::updateOrCreate(['lid' => $lid], ['phone' => $phone, 'source' => $source]);
        Cache::put("lid-map:{$instance->id}:{$lid}", $phone, now()->addDays(30));

        $result = null;
        Chat::where('remote_jid', $lid)->where('user_id', $instance->user_id)->get()->each(function (Chat $chat) use ($phone, $instance, &$result) {
            $converted = $this->convert($chat, $phone);
            if ((int) $chat->instance_id === (int) $instance->id) {
                $result = $converted;
            }
        });

        return $result;
    }

    public function resolve(Instance $instance, string $lid, bool $allowHttp = true): ?string
    {
        if ($mapped = Cache::get("lid-map:{$instance->id}:{$lid}")) {
            return $mapped;
        }

        if ($stored = LidMapping::where('lid', $lid)->value('phone')) {
            Cache::put("lid-map:{$instance->id}:{$lid}", $stored, now()->addDays(30));

            return $stored;
        }

        if ($phone = $this->fromDatabase((int) $instance->user_id, $lid)) {
            return $phone;
        }

        if (!$allowHttp) {
            return null;
        }

        // do not hammer Evolution for a lid it could not resolve
        $key = "lid-miss:{$instance->id}:{$lid}";
        if (Cache::has($key)) {
            return null;
        }

        $phone = $this->fromEvolution($instance, $lid);
        if ($phone) {
            $this->learn($instance, $lid, $phone, 'evolution');
        } else {
            Cache::put($key, 1, now()->addMinutes(10));
        }

        return $phone;
    }

    /**
     * Turns a @lid chat into the phone chat. Returns the resulting chat (the same row, or the existing phone chat
     * that absorbed it), or null when the number is still unknown.
     */
    public function fixChat(Chat $chat, bool $allowHttp = true): ?Chat
    {
        if (!self::isLid($chat->remote_jid)) {
            return $chat;
        }

        $instance = Instance::find($chat->instance_id);
        $phone = $instance ? $this->resolve($instance, $chat->remote_jid, $allowHttp) : null;
        if (!$phone) {
            return null;
        }

        return $this->convert($chat, $phone);
    }

    public function convert(Chat $chat, string $phone): Chat
    {
        $lid = $chat->remote_jid;
        $jid = $phone . '@s.whatsapp.net';

        return DB::transaction(function () use ($chat, $phone, $jid, $lid) {
            $existing = Chat::where('instance_id', $chat->instance_id)->where('remote_jid', $jid)->where('id', '!=', $chat->id)->first();

            if ($existing) {
                $target = $this->merge($chat, $existing);
            } else {
                $chat->remote_jid = $jid;
                $chat->phone = $phone;
                $chat->save();
                $target = $chat;
            }

            // remember the lid so the next one is resolved without HTTP, and link the CRM contact
            $contact = app(ChatMessageRecorder::class)->findContact((int) $target->user_id, $phone);
            if ($contact) {
                if (!$contact->lid) {
                    $contact->lid = $lid;
                    $contact->save();
                }
                if (!$target->contact_id) {
                    $target->contact_id = $contact->id;
                    $target->save();
                }
            }

            return $target;
        });
    }

    /** Moves the messages of $from into $into and recomputes the summary of $into. */
    private function merge(Chat $from, Chat $into): Chat
    {
        ChatMessage::where('chat_id', $from->id)->update(['chat_id' => $into->id]);

        $into->unread_count = (int) $into->unread_count + (int) $from->unread_count;
        $into->name = $into->name ?: $from->name;
        $into->avatar_path = $into->avatar_path ?: $from->avatar_path;
        $into->avatar_url = $into->avatar_url ?: $from->avatar_url;

        $last = ChatMessage::where('chat_id', $into->id)->orderByDesc('message_timestamp')->orderByDesc('id')->first();
        if ($last) {
            $into->last_message_id = $last->id;
            $into->last_message_type = $last->deleted ? 'deleted' : $last->type;
            $into->last_message_preview = app(ChatMessageRecorder::class)->previewFor($last);
            $into->last_message_from_me = $last->from_me;
            $into->last_message_status = $last->from_me ? $last->status : null;
            $into->last_message_at = $last->message_timestamp ?? $last->created_at;
        }
        $into->save();

        $from->delete();

        return $into;
    }
}
