<?php

namespace App\Services\TikTok;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Channels\InboundAiBridge;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Instagram\InstagramInbound;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One TikTok message event -> chats / chat_messages, a CRM contact and the AI agent.
 * Conventions: chat jid and contact = "tt:<conversation_id>" (TikTok routes replies by conversation), message id = TikTok message_id.
 * The same event also fires for messages the business sent (from our app or the TikTok app): those are stored as outgoing.
 */
class TikTokInbound
{
    public function __construct(private ChatMessageRecorder $recorder)
    {
    }

    public static function jid(string $conversationId): string
    {
        return 'tt:' . $conversationId;
    }

    public static function rememberSent(array $ids): void
    {
        foreach ($ids as $id) {
            if ($id !== '') {
                Cache::put('tt-sent:' . InstagramInbound::msgId($id), 1, 3600);
            }
        }
    }

    /** Reads the first present key; TikTok sometimes nests ids as {id: ...}. */
    private static function pick(array $a, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $a[$k] ?? null;
            if (is_array($v)) {
                $v = $v['id'] ?? $v['open_id'] ?? null;
            }
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $c the message content of the webhook event */
    public function handle(Instance $instance, array $c): void
    {
        $conversation = self::pick($c, ['conversation_id']);
        $mid = self::pick($c, ['message_id']);
        if (!$instance->isTiktok() || !$conversation || !$mid) {
            return;
        }

        $sender = self::pick($c, ['from_user', 'sender', 'from']);
        $echo = $sender !== null && $sender === (string) $instance->tiktok_open_id;

        $id = InstagramInbound::msgId($mid);
        if (Cache::has('tt-sent:' . $id) || ChatMessage::where('instance_id', $instance->id)->where('message_id', $id)->exists()) {
            return;
        }

        $jid = self::jid($conversation);
        $contact = $this->contact($instance, $jid, $conversation);
        $chat = $this->chat($instance, $jid, $contact);

        $type = strtoupper((string) ($c['message_type'] ?? 'TEXT'));
        $ts = $c['timestamp'] ?? $c['create_time'] ?? null;
        $ts = $ts ? Carbon::createFromTimestamp((int) ($ts > 1e12 ? $ts / 1000 : $ts)) : now();

        $text = is_array($c['text'] ?? null) ? ($c['text']['body'] ?? null) : ($c['text'] ?? null);
        $item = ['type' => 'text', 'body' => $text, 'media' => null, 'mimetype' => null];
        if ($type === 'IMAGE' && !empty($c['image']['media_id'])) {
            $item = ['type' => 'image', 'body' => null, 'media' => [(string) $c['image']['media_id'], 'IMAGE'], 'mimetype' => 'image/jpeg'];
        } elseif ($type === 'VIDEO' && !empty($c['video']['media_id'])) {
            $item = ['type' => 'video', 'body' => null, 'media' => [(string) $c['video']['media_id'], 'VIDEO'], 'mimetype' => 'video/mp4'];
        } elseif ($type === 'SHARE_POST') {
            $item['body'] = trim('[post] ' . ($c['share_post']['embed_url'] ?? ''));
        } elseif (in_array($type, ['STICKER', 'EMOJI'], true)) {
            $item['body'] = trim('[' . strtolower($type) . '] ' . ($c['sticker']['url'] ?? $c['emoji']['url'] ?? ''));
        } elseif ($type !== 'TEXT') {
            $item['body'] = '[' . strtolower($type) . ']';
        }

        $ref = $c['referenced_message_info']['referenced_message_id'] ?? null;
        $quoted = $ref ? InstagramInbound::msgId((string) $ref) : null;

        try {
            $message = ChatMessage::create([
                'chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id, 'message_id' => $id,
                'from_me' => $echo, 'type' => $item['type'], 'body' => $item['body'], 'mimetype' => $item['mimetype'],
                'quoted_message_id' => $quoted, 'status' => $echo ? 'sent' : 'received', 'message_timestamp' => $ts,
            ]);
        } catch (QueryException $e) {
            return; // delivered twice
        }

        if ($item['media']) {
            $this->downloadMedia($instance, $message, $conversation, $mid, $item['media']);
        }
        $this->recorder->touchChat($chat, $message, true);

        if (!$echo) {
            InboundAiBridge::run($instance, $contact, $jid, $id, $item['body'], $message->type);
        }
    }

    private function contact(Instance $instance, string $jid, string $conversation): Contact
    {
        $contact = Contact::where('user_id', $instance->user_id)->where('contact', $jid)->first();
        if ($contact) {
            return $contact;
        }

        $name = TikTokClient::for($instance)->customerName($conversation);

        // status "tiktok" keeps it out of WhatsApp campaigns (they only pick "ativo")
        return Contact::create(['user_id' => $instance->user_id, 'contact' => $jid, 'name' => $name, 'status' => 'tiktok']);
    }

    private function chat(Instance $instance, string $jid, Contact $contact): Chat
    {
        $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first();
        if ($chat) {
            if (!$chat->contact_id) {
                $chat->update(['contact_id' => $contact->id]);
            }

            return $chat;
        }

        try {
            return Chat::create([
                'user_id' => $instance->user_id, 'instance_id' => $instance->id, 'remote_jid' => $jid, 'phone' => null,
                'name' => $contact->name ?: 'TikTok ' . substr($jid, -4), 'contact_id' => $contact->id,
            ]);
        } catch (QueryException $e) {
            return Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first() ?? throw $e;
        }
    }

    private function downloadMedia(Instance $instance, ChatMessage $message, string $conversation, string $mid, array $media): void
    {
        try {
            $binary = TikTokClient::for($instance)->download($conversation, $mid, $media[0], $media[1]);
        } catch (\Throwable $e) {
            Log::info('TikTok media not downloaded: ' . $e->getMessage(), ['instance_id' => $instance->id]);

            return;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: ($message->mimetype ?: 'application/octet-stream');
        $ext = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', default => 'bin' };
        $path = config('chat.media_path', 'chat-media') . '/' . $instance->id . '/' . Str::uuid() . '.' . $ext;
        Storage::put($path, $binary);
        $message->update(['media_path' => $path, 'mimetype' => $mime]);
    }
}
