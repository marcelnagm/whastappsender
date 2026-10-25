<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Persists WhatsApp messages (Evolution webhooks / history) so the chat UI can read them.
 */
class ChatMessageRecorder
{
    private const STATUS_RANK = [
        'pending' => 0,
        'received' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'played' => 4,
    ];

    /**
     * Records one message from a messages.upsert / send.message payload ("data").
     *
     * @param bool $history true when importing old messages (no unread counter bump)
     */
    public function recordUpsert(string $instanceName, array $data, bool $history = false): ?ChatMessage
    {
        $instance = Instance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            return null;
        }

        $key = (array) ($data['key'] ?? []);
        $messageId = $key['id'] ?? null;
        $remoteJid = (string) ($key['remoteJid'] ?? '');

        if (!$messageId || !$this->isIndividualJid($remoteJid)) {
            return null; // groups, broadcasts, channels are out of scope
        }

        $fromMe = (bool) ($key['fromMe'] ?? false);
        $jid = $this->resolveJid($key);
        $parsed = $this->parse((array) ($data['message'] ?? []));

        if ($parsed['kind'] === 'reaction') {
            return $this->applyReaction($instance, $parsed, $fromMe ? 'me' : $jid);
        }

        if ($parsed['kind'] === 'revoke') {
            return $this->applyRevoke($instance, $parsed['target_id']);
        }

        $timestamp = $this->timestamp($data['messageTimestamp'] ?? null);
        $chat = $this->findOrCreateChat($instance, $jid, $fromMe ? null : ($data['pushName'] ?? null));

        $message = ChatMessage::where('instance_id', $instance->id)->where('message_id', $messageId)->first();
        $isNew = false;

        if (!$message && $fromMe) {
            $message = $this->adoptPending($chat, $parsed, $messageId);
        }

        $status = $fromMe ? ($this->mapStatus($data['status'] ?? null) ?? 'sent') : 'received';

        if ($message) {
            if ($fromMe && $this->rank($status) > $this->rank($message->status)) {
                $message->status = $status;
            }
            $message->save();
        } else {
            try {
                $message = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'instance_id' => $instance->id,
                    'user_id' => $instance->user_id,
                    'message_id' => $messageId,
                    'from_me' => $fromMe,
                    'type' => $parsed['type'],
                    'body' => $parsed['body'],
                    'mimetype' => $parsed['mimetype'],
                    'file_name' => $parsed['file_name'],
                    'quoted_message_id' => $parsed['quoted_id'],
                    'quoted_preview' => $parsed['quoted_preview'],
                    'status' => $status,
                    'message_timestamp' => $timestamp,
                ]);
                $isNew = true;
            } catch (QueryException $e) {
                // Parallel webhook already created it (unique instance_id + message_id)
                $message = ChatMessage::where('instance_id', $instance->id)->where('message_id', $messageId)->first();
                if (!$message) {
                    throw $e;
                }
            }
        }

        $this->touchChat($chat, $message, $isNew, $history);

        return $message;
    }

    /**
     * Applies a delivery/read status update (messages.update payload).
     */
    public function recordStatus(string $instanceName, array $update): void
    {
        $messageId = $update['keyId'] ?? ($update['key']['id'] ?? ($update['messageId'] ?? null));
        $status = $this->mapStatus($update['status'] ?? ($update['update']['status'] ?? null));

        if (!$messageId || !$status) {
            return;
        }

        $instance = Instance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            return;
        }

        $message = ChatMessage::where('instance_id', $instance->id)->where('message_id', $messageId)->first();
        if (!$message || !$message->from_me) {
            return;
        }

        if ($status === 'error' || $this->rank($status) > $this->rank($message->status)) {
            $message->status = $status;
            $message->save();

            Chat::where('id', $message->chat_id)
                ->where('last_message_id', $message->id)
                ->update(['last_message_status' => $status]);
        }
    }

    // ---------------------------------------------------------------------
    // Chat helpers
    // ---------------------------------------------------------------------

    public function findOrCreateChat(Instance $instance, string $jid, ?string $pushName = null): Chat
    {
        $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first();

        if (!$chat) {
            $phone = str_ends_with($jid, '@s.whatsapp.net') ? explode('@', $jid)[0] : null;

            try {
                $chat = Chat::create([
                    'user_id' => $instance->user_id,
                    'instance_id' => $instance->id,
                    'remote_jid' => $jid,
                    'phone' => $phone,
                    'name' => $pushName,
                ]);
            } catch (QueryException $e) {
                $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first();
                if (!$chat) {
                    throw $e;
                }
            }
        }

        $dirty = false;
        if ($pushName && !$chat->name) {
            $chat->name = $pushName;
            $dirty = true;
        }
        if (!$chat->contact_id && $chat->phone) {
            $contact = $this->findContact((int) $instance->user_id, $chat->phone);
            if ($contact) {
                $chat->contact_id = $contact->id;
                $dirty = true;
            }
        }
        if ($dirty) {
            $chat->save();
        }

        // Profile picture is fetched in the background (no-op unless never fetched or due for a refresh)
        app(ChatAvatarService::class)->queueIfDue($chat);

        return $chat;
    }

    /**
     * CRM contact lookup that tolerates the Brazilian 9th digit (55 + DDD + 9 + 8 digits).
     */
    public function findContact(int $userId, string $phone): ?Contact
    {
        $variants = [$phone];
        if (preg_match('/^55(\d{2})9(\d{8})$/', $phone, $m)) {
            $variants[] = '55' . $m[1] . $m[2];
        } elseif (preg_match('/^55(\d{2})(\d{8})$/', $phone, $m)) {
            $variants[] = '55' . $m[1] . '9' . $m[2];
        }

        return Contact::where('user_id', $userId)->whereIn('contact', $variants)->first();
    }

    public function previewFor(ChatMessage $message): ?string
    {
        if ($message->deleted) {
            return null;
        }

        return $message->body !== null ? Str::limit(trim($message->body), 120, '…') : null;
    }

    public function touchChat(Chat $chat, ChatMessage $message, bool $isNew, bool $history = false): void
    {
        $ts = $message->message_timestamp ?? $message->created_at ?? now();

        $isLatest = !$chat->last_message_at || $ts->greaterThanOrEqualTo($chat->last_message_at);

        if ($isLatest) {
            $chat->last_message_id = $message->id;
            $chat->last_message_type = $message->deleted ? 'deleted' : $message->type;
            $chat->last_message_preview = $this->previewFor($message);
            $chat->last_message_from_me = $message->from_me;
            $chat->last_message_status = $message->from_me ? $message->status : null;
            $chat->last_message_at = $ts;
        }

        if ($isNew && !$message->from_me && !$history) {
            $chat->unread_count = (int) $chat->unread_count + 1;
        }

        if ($chat->isDirty()) {
            $chat->save();
        }
    }

    // ---------------------------------------------------------------------
    // Parsing
    // ---------------------------------------------------------------------

    /**
     * @return array{kind:string,type:string,body:?string,mimetype:?string,file_name:?string,quoted_id:?string,quoted_preview:?string,target_id:?string,emoji:?string}
     */
    public function parse(array $message): array
    {
        $out = [
            'kind' => 'message',
            'type' => 'unsupported',
            'body' => null,
            'mimetype' => null,
            'file_name' => null,
            'quoted_id' => null,
            'quoted_preview' => null,
            'target_id' => null,
            'emoji' => null,
        ];

        // Unwrap ephemeral / view-once / caption wrappers
        for ($i = 0; $i < 4; $i++) {
            $inner = null;
            foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage', 'editedMessage'] as $wrap) {
                if (isset($message[$wrap]['message']) && is_array($message[$wrap]['message'])) {
                    $inner = $message[$wrap]['message'];
                    break;
                }
            }
            if ($inner === null) {
                break;
            }
            $message = $inner;
        }

        if (isset($message['reactionMessage'])) {
            $out['kind'] = 'reaction';
            $out['target_id'] = $message['reactionMessage']['key']['id'] ?? null;
            $out['emoji'] = $message['reactionMessage']['text'] ?? '';

            return $out;
        }

        if (isset($message['protocolMessage'])) {
            $protocol = $message['protocolMessage'];
            $type = $protocol['type'] ?? null;
            if (($type === 'REVOKE' || $type === 0) && isset($protocol['key']['id'])) {
                $out['kind'] = 'revoke';
                $out['target_id'] = $protocol['key']['id'];
            } else {
                $out['kind'] = 'ignore';
            }

            return $out;
        }

        $context = null;

        if (isset($message['conversation'])) {
            $out['type'] = 'text';
            $out['body'] = (string) $message['conversation'];
        } elseif (isset($message['extendedTextMessage'])) {
            $m = $message['extendedTextMessage'];
            $out['type'] = 'text';
            $out['body'] = (string) ($m['text'] ?? '');
            $context = $m['contextInfo'] ?? null;
        } elseif (isset($message['imageMessage'])) {
            $m = $message['imageMessage'];
            $out = array_merge($out, ['type' => 'image', 'body' => $m['caption'] ?? null, 'mimetype' => $m['mimetype'] ?? 'image/jpeg']);
            $context = $m['contextInfo'] ?? null;
        } elseif (isset($message['videoMessage'])) {
            $m = $message['videoMessage'];
            $out = array_merge($out, ['type' => 'video', 'body' => $m['caption'] ?? null, 'mimetype' => $m['mimetype'] ?? 'video/mp4']);
            $context = $m['contextInfo'] ?? null;
        } elseif (isset($message['audioMessage'])) {
            $m = $message['audioMessage'];
            $out = array_merge($out, ['type' => 'audio', 'mimetype' => $m['mimetype'] ?? 'audio/ogg']);
            $context = $m['contextInfo'] ?? null;
        } elseif (isset($message['documentMessage'])) {
            $m = $message['documentMessage'];
            $out = array_merge($out, [
                'type' => 'document',
                'body' => $m['caption'] ?? null,
                'mimetype' => $m['mimetype'] ?? 'application/octet-stream',
                'file_name' => $m['fileName'] ?? ($m['title'] ?? 'document'),
            ]);
            $context = $m['contextInfo'] ?? null;
        } elseif (isset($message['stickerMessage'])) {
            $m = $message['stickerMessage'];
            $out = array_merge($out, ['type' => 'sticker', 'mimetype' => $m['mimetype'] ?? 'image/webp']);
        } elseif (isset($message['locationMessage'])) {
            $m = $message['locationMessage'];
            $lat = $m['degreesLatitude'] ?? null;
            $lng = $m['degreesLongitude'] ?? null;
            $label = trim(($m['name'] ?? '') . ' ' . ($m['address'] ?? ''));
            $out['type'] = 'location';
            $out['body'] = trim($label . ($lat !== null ? "\nhttps://maps.google.com/?q={$lat},{$lng}" : ''));
        } elseif (isset($message['contactMessage'])) {
            $out['type'] = 'contact';
            $out['body'] = $message['contactMessage']['displayName'] ?? null;
        } elseif (isset($message['contactsArrayMessage'])) {
            $out['type'] = 'contact';
            $out['body'] = $message['contactsArrayMessage']['displayName'] ?? null;
        }

        if (is_array($context) && !empty($context['stanzaId'])) {
            $out['quoted_id'] = $context['stanzaId'];
            $out['quoted_preview'] = $this->quotedPreview((array) ($context['quotedMessage'] ?? []));
        }

        return $out;
    }

    private function quotedPreview(array $quoted): ?string
    {
        if (isset($quoted['conversation'])) {
            return Str::limit((string) $quoted['conversation'], 140, '…');
        }
        if (isset($quoted['extendedTextMessage']['text'])) {
            return Str::limit((string) $quoted['extendedTextMessage']['text'], 140, '…');
        }
        if (isset($quoted['imageMessage'])) {
            return '📷 ' . ($quoted['imageMessage']['caption'] ?? '');
        }
        if (isset($quoted['videoMessage'])) {
            return '🎥 ' . ($quoted['videoMessage']['caption'] ?? '');
        }
        if (isset($quoted['audioMessage'])) {
            return '🎤';
        }
        if (isset($quoted['documentMessage'])) {
            return '📄 ' . ($quoted['documentMessage']['fileName'] ?? '');
        }
        if (isset($quoted['stickerMessage'])) {
            return '🏷️';
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function applyReaction(Instance $instance, array $parsed, string $who): ?ChatMessage
    {
        if (!$parsed['target_id']) {
            return null;
        }

        $target = ChatMessage::where('instance_id', $instance->id)->where('message_id', $parsed['target_id'])->first();
        if (!$target) {
            return null;
        }

        $reactions = $target->reactions ?? [];
        if ($parsed['emoji'] === '' || $parsed['emoji'] === null) {
            unset($reactions[$who]);
        } else {
            $reactions[$who] = $parsed['emoji'];
        }

        $target->reactions = $reactions;
        $target->save();

        return $target;
    }

    private function applyRevoke(Instance $instance, ?string $targetId): ?ChatMessage
    {
        if (!$targetId) {
            return null;
        }

        $target = ChatMessage::where('instance_id', $instance->id)->where('message_id', $targetId)->first();
        if (!$target) {
            return null;
        }

        $target->deleted = true;
        $target->body = null;
        $target->save();

        Chat::where('id', $target->chat_id)->where('last_message_id', $target->id)->update([
            'last_message_type' => 'deleted',
            'last_message_preview' => null,
        ]);

        return $target;
    }

    /**
     * An outgoing message created by the UI (no WhatsApp id yet) that the webhook is now confirming.
     */
    private function adoptPending(Chat $chat, array $parsed, string $messageId): ?ChatMessage
    {
        $pending = ChatMessage::where('chat_id', $chat->id)
            ->where('from_me', true)
            ->whereNull('message_id')
            ->where('type', $parsed['type'])
            ->where('created_at', '>=', now()->subMinutes(5))
            ->when($parsed['type'] === 'text', fn ($q) => $q->where('body', $parsed['body']))
            ->orderBy('id')
            ->first();

        if ($pending) {
            $pending->message_id = $messageId;
            $pending->save();
        }

        return $pending;
    }

    private function resolveJid(array $key): string
    {
        $remoteJid = (string) ($key['remoteJid'] ?? '');
        $alt = $key['senderPn'] ?? ($key['remoteJidAlt'] ?? null);

        if (str_ends_with($remoteJid, '@lid') && $alt) {
            return explode('@', (string) $alt)[0] . '@s.whatsapp.net';
        }

        return $remoteJid;
    }

    private function isIndividualJid(string $jid): bool
    {
        return str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@lid');
    }

    private function timestamp($value): Carbon
    {
        if (is_array($value)) {
            $value = $value['low'] ?? null;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return Carbon::createFromTimestamp((int) $value);
        }

        return now();
    }

    private function mapStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return [
            'PENDING' => 'pending',
            'SERVER_ACK' => 'sent',
            'DELIVERY_ACK' => 'delivered',
            'READ' => 'read',
            'PLAYED' => 'played',
            'ERROR' => 'error',
        ][strtoupper(trim($status))] ?? null;
    }

    private function rank(?string $status): int
    {
        return self::STATUS_RANK[$status ?? 'pending'] ?? 0;
    }
}
