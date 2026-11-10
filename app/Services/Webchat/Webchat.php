<?php

namespace App\Services\Webchat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Channels\InboundAiBridge;
use App\Services\Chat\ChatMessageRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The chat widget of a website, as a channel: a visitor is a contact "wc:<visitor id>", the conversation lives in the
 * same chat tables as WhatsApp, the AI agent of the widget answers and the team can take over in the inbox. The
 * visitor's browser polls for the answers (no sockets needed).
 */
class Webchat
{
    public const MAX_TEXT = 1000;

    public static function defaults(): array
    {
        return ['title' => '', 'welcome' => '', 'color' => '#25d366', 'origins' => '', 'ask_contact' => true, 'active' => true];
    }

    public static function config(Instance $i): array
    {
        return array_merge(self::defaults(), (array) $i->webchat_config);
    }

    public static function color(?string $c): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? $c : '#25d366';
    }

    /** Sites allowed to show the widget: hosts without scheme, one per line or comma. Empty = any site. */
    public static function origins(Instance $i): array
    {
        return array_values(array_filter(array_map(fn ($d) => Str::lower(trim(preg_replace('#^https?://#i', '', trim($d)), " \t/")), preg_split('/[\s,]+/', (string) self::config($i)['origins']))));
    }

    public static function jid(string $visitor): string
    {
        return 'wc:' . $visitor;
    }

    public static function validVisitor(?string $v): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', (string) $v);
    }

    /** @return ChatMessage|null null when it was already received (same client id) */
    public function receive(Instance $instance, string $visitor, string $text, ?string $name = null, ?string $email = null, ?string $page = null, ?string $clientId = null): ?ChatMessage
    {
        $text = trim(mb_substr($text, 0, self::MAX_TEXT));
        $jid = self::jid($visitor);
        $name = $name !== null ? trim(strip_tags(mb_substr($name, 0, 80))) : null;
        $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : null;

        $contact = Contact::where('user_id', $instance->user_id)->where('contact', $jid)->first()
            ?? Contact::create(['user_id' => $instance->user_id, 'contact' => $jid, 'name' => $name ?: __('Website visitor') . ' ' . substr($visitor, 0, 4), 'email' => $email, 'status' => 'email']);
        $changes = [];
        if ($name && (str_starts_with((string) $contact->name, __('Website visitor')))) {
            $changes['name'] = $name;
        }
        if ($email && !$contact->email) {
            $changes['email'] = $email;
        }
        if ($changes) {
            $contact->update($changes);
        }
        if ($email) {
            \App\Services\Channels\ContactLinks::linkByEmail($contact->fresh());
        }

        $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first();
        if (!$chat) {
            try {
                $chat = Chat::create(['user_id' => $instance->user_id, 'instance_id' => $instance->id, 'remote_jid' => $jid, 'phone' => null, 'name' => $contact->name, 'contact_id' => $contact->id]);
            } catch (QueryException $e) {
                $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->firstOrFail();
            }
        }

        $id = 'wc-' . Str::lower(Str::random(20));
        if ($clientId && ChatMessage::where('chat_id', $chat->id)->where('client_id', $clientId)->exists()) {
            return null; // the browser retried
        }
        $body = $text . ($page && !ChatMessage::where('chat_id', $chat->id)->exists() ? "\n\n🔗 " . mb_substr(strip_tags($page), 0, 200) : '');
        $message = ChatMessage::create(['chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id, 'message_id' => $id,
            'client_id' => $clientId, 'from_me' => false, 'type' => 'text', 'body' => $body, 'status' => 'received', 'message_timestamp' => now()]);
        app(ChatMessageRecorder::class)->touchChat($chat, $message, true);

        InboundAiBridge::run($instance, $contact->fresh(), $jid, $id, $text, 'text');

        return $message;
    }

    /** What the visitor's browser shows: everything said in the conversation after a given message id. */
    public function thread(Instance $instance, string $visitor, int $after = 0): array
    {
        $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', self::jid($visitor))->first();
        if (!$chat) {
            return [];
        }

        return ChatMessage::where('chat_id', $chat->id)->where('id', '>', $after)->where('deleted', false)->where('type', 'text')->orderBy('id')->limit(100)->get()
            ->map(fn (ChatMessage $m) => ['id' => $m->id, 'from' => $m->from_me ? 'agent' : 'visitor', 'text' => (string) $m->body])->all();
    }
}
