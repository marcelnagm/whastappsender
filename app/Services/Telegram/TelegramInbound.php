<?php

namespace App\Services\Telegram;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Channels\InboundAiBridge;
use App\Services\Chat\ChatMessageRecorder;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One Telegram update -> the same tables the WhatsApp chat uses (chats / chat_messages), a CRM contact and, when the account
 * has AI replies on, the AI agent of that bot. Conventions: chat jid = "tg:<chat_id>", contact = "tg:<chat_id>",
 * message id = "<chat_id>:<message_id>" (Telegram ids are only unique inside a chat).
 */
class TelegramInbound
{
    public function __construct(private ChatMessageRecorder $recorder)
    {
    }

    public static function jid(string|int $chatId): string
    {
        return 'tg:' . $chatId;
    }

    public function handle(Instance $instance, array $update): ?ChatMessage
    {
        if (!$instance->isTelegram()) {
            return null;
        }

        $edited = isset($update['edited_message']);
        $msg = (array) ($update['message'] ?? $update['edited_message'] ?? []);
        $chatInfo = (array) ($msg['chat'] ?? []);
        $from = (array) ($msg['from'] ?? []);

        // private conversations with people only: groups, channels and other bots are out of scope
        if (empty($msg['message_id']) || ($chatInfo['type'] ?? '') !== 'private' || !isset($chatInfo['id']) || !empty($from['is_bot'])) {
            return null;
        }

        $chatId = (string) $chatInfo['id'];
        $jid = self::jid($chatId);
        $messageId = $chatId . ':' . $msg['message_id'];
        $parsed = $this->parse($msg);
        $name = $this->displayName($from + $chatInfo);

        if ($edited) {
            $existing = ChatMessage::where('instance_id', $instance->id)->where('message_id', $messageId)->first();
            if ($existing && !$existing->from_me) {
                $existing->update(['body' => $parsed['body']]);
            }

            return $existing;
        }

        $contact = $this->contact($instance, $jid, $name);
        $chat = $this->chat($instance, $jid, $name, $contact);

        try {
            $message = ChatMessage::create([
                'chat_id' => $chat->id,
                'instance_id' => $instance->id,
                'user_id' => $instance->user_id,
                'message_id' => $messageId,
                'from_me' => false,
                'type' => $parsed['type'],
                'body' => $parsed['body'],
                'mimetype' => $parsed['mimetype'],
                'file_name' => $parsed['file_name'],
                'quoted_message_id' => $parsed['quoted_id'] ? $chatId . ':' . $parsed['quoted_id'] : null,
                'quoted_preview' => $parsed['quoted_preview'],
                'status' => 'received',
                'message_timestamp' => isset($msg['date']) ? Carbon::createFromTimestamp((int) $msg['date']) : now(),
            ]);
        } catch (QueryException $e) {
            return ChatMessage::where('instance_id', $instance->id)->where('message_id', $messageId)->first(); // delivered twice
        }

        if ($parsed['file_id']) {
            $this->downloadMedia($instance, $message, $parsed);
        }

        $this->recorder->touchChat($chat, $message, true);

        $this->afterRecorded($instance, $contact, $jid, $messageId, $parsed['body'], $message->type);

        return $message;
    }

    private function afterRecorded(Instance $instance, Contact $contact, string $jid, string $messageId, ?string $text, string $type): void
    {
        $text = trim((string) $text);
        if ($text === '/start') {
            $text = 'Olá'; // the button every Telegram user presses first
        }

        InboundAiBridge::run($instance, $contact, $jid, $messageId, $text, $type);
    }

    private function contact(Instance $instance, string $jid, ?string $name): Contact
    {
        $contact = Contact::where('user_id', $instance->user_id)->where('contact', $jid)->first();
        if (!$contact) {
            // status "telegram" keeps it out of WhatsApp campaigns (they only pick "ativo")
            $contact = Contact::create(['user_id' => $instance->user_id, 'contact' => $jid, 'name' => $name, 'status' => 'telegram']);
        } elseif ($name && !$contact->name) {
            $contact->update(['name' => $name]);
        }

        return $contact;
    }

    private function chat(Instance $instance, string $jid, ?string $name, Contact $contact): Chat
    {
        $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first();

        if (!$chat) {
            try {
                $chat = Chat::create([
                    'user_id' => $instance->user_id, 'instance_id' => $instance->id, 'remote_jid' => $jid,
                    'phone' => null, 'name' => $name, 'contact_id' => $contact->id,
                ]);
            } catch (QueryException $e) {
                $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first() ?? throw $e;
            }
        } elseif (!$chat->contact_id) {
            $chat->update(['contact_id' => $contact->id]);
        }

        return $chat;
    }

    private function displayName(array $p): ?string
    {
        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 120) : (!empty($p['username']) ? '@' . $p['username'] : null);
    }

    /** @return array{type:string,body:?string,mimetype:?string,file_name:?string,file_id:?string,file_size:?int,quoted_id:?int,quoted_preview:?string} */
    private function parse(array $m): array
    {
        $out = ['type' => 'unsupported', 'body' => null, 'mimetype' => null, 'file_name' => null, 'file_id' => null, 'file_size' => null, 'quoted_id' => null, 'quoted_preview' => null];

        if (!empty($m['reply_to_message']['message_id'])) {
            $r = (array) $m['reply_to_message'];
            $out['quoted_id'] = (int) $r['message_id'];
            $out['quoted_preview'] = Str::limit((string) ($r['text'] ?? $r['caption'] ?? ''), 160, '…') ?: null;
        }

        $caption = isset($m['caption']) ? (string) $m['caption'] : null;

        if (isset($m['text'])) {
            return ['type' => 'text', 'body' => (string) $m['text']] + $out;
        }
        if (!empty($m['photo'])) {
            $largest = (array) collect($m['photo'])->sortByDesc(fn ($p) => ($p['file_size'] ?? 0) ?: (($p['width'] ?? 0) * ($p['height'] ?? 0)))->first();

            return ['type' => 'image', 'body' => $caption, 'mimetype' => 'image/jpeg', 'file_id' => $largest['file_id'] ?? null, 'file_size' => $largest['file_size'] ?? null] + $out;
        }
        foreach (['video' => 'video', 'voice' => 'audio', 'audio' => 'audio', 'document' => 'document', 'video_note' => 'video'] as $key => $type) {
            if (!empty($m[$key]['file_id'])) {
                $f = (array) $m[$key];

                return [
                    'type' => $type, 'body' => $caption, 'mimetype' => $f['mime_type'] ?? ($key === 'voice' ? 'audio/ogg' : null),
                    'file_name' => isset($f['file_name']) ? Str::limit((string) $f['file_name'], 200, '') : null,
                    'file_id' => $f['file_id'], 'file_size' => $f['file_size'] ?? null,
                ] + $out;
            }
        }
        if (!empty($m['sticker'])) {
            return ['type' => 'sticker', 'body' => $m['sticker']['emoji'] ?? null] + $out;
        }

        return $out;
    }

    private function downloadMedia(Instance $instance, ChatMessage $message, array $parsed): void
    {
        try {
            $file = TelegramClient::for($instance)->download((string) $parsed['file_id'], $parsed['file_size']);
        } catch (\Throwable $e) {
            Log::info('Telegram media not downloaded: ' . $e->getMessage(), ['instance_id' => $instance->id]);

            return;
        }

        $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $path = config('chat.media_path', 'chat-media') . '/' . $instance->id . '/' . Str::uuid() . '.' . $ext;
        Storage::put($path, $file['binary']);

        $mime = $message->mimetype ?: (match ($ext) { 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'oga', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', default => 'application/octet-stream' });
        $message->update(['media_path' => $path, 'mimetype' => $mime]);
    }
}
