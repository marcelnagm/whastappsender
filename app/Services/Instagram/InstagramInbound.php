<?php

namespace App\Services\Instagram;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Channels\InboundAiBridge;
use App\Services\Chat\ChatMessageRecorder;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One Instagram "messaging" event -> chats / chat_messages, a CRM contact and the AI agent.
 * Conventions: chat jid and contact = "ig:<IGSID>", message id = Instagram mid (hashed when longer than the column).
 * Echo events (messages the account itself sent, e.g. from the Instagram app) are recorded as outgoing messages.
 */
class InstagramInbound
{
    public function __construct(private ChatMessageRecorder $recorder)
    {
    }

    public static function jid(string $igsid): string
    {
        return 'ig:' . $igsid;
    }

    /** chat_messages.message_id is 120 chars; Instagram ids can be longer. */
    public static function msgId(string $mid): string
    {
        return strlen($mid) <= 110 ? $mid : 'h:' . sha1($mid);
    }

    /** Remember ids we sent, so their echo webhooks do not create duplicates. */
    public static function rememberSent(array $mids): void
    {
        foreach ($mids as $mid) {
            if ($mid !== '') {
                Cache::put('ig-sent:' . self::msgId($mid), 1, 3600);
            }
        }
    }

    public function handle(Instance $instance, array $event): void
    {
        $msg = $event['message'] ?? null;
        if (!$instance->isInstagram() || !is_array($msg) || empty($msg['mid']) || !empty($msg['is_deleted'])) {
            return;
        }

        $echo = !empty($msg['is_echo']);
        $customer = (string) ($echo ? ($event['recipient']['id'] ?? '') : ($event['sender']['id'] ?? ''));
        if ($customer === '' || $customer === (string) $instance->instagram_id) {
            return;
        }

        $mid = (string) $msg['mid'];
        $base = self::msgId($mid);
        if (Cache::has('ig-sent:' . $base) || ChatMessage::where('instance_id', $instance->id)->where('message_id', $base)->exists()) {
            return; // already known (our own send, or delivered twice)
        }

        $jid = self::jid($customer);
        $contact = $this->contact($instance, $jid);
        $chat = $this->chat($instance, $jid, $customer, $contact);
        $ts = isset($event['timestamp']) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : now();

        [$quotedId, $quotedPreview] = $this->quoted($instance, $msg);
        $items = $this->items($msg);
        $created = [];

        foreach ($items as $i => $item) {
            $id = $i === 0 ? $base : self::msgId($mid . '#' . $i);
            try {
                $message = ChatMessage::create([
                    'chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id,
                    'message_id' => $id, 'from_me' => $echo, 'type' => $item['type'], 'body' => $item['body'],
                    'mimetype' => $item['mimetype'], 'file_name' => $item['file_name'],
                    'quoted_message_id' => $i === 0 ? $quotedId : null, 'quoted_preview' => $i === 0 ? $quotedPreview : null,
                    'status' => $echo ? 'sent' : 'received', 'message_timestamp' => $ts,
                ]);
            } catch (QueryException $e) {
                continue; // delivered twice
            }

            if ($item['url']) {
                $this->downloadMedia($instance, $message, $item['url']);
            }
            $this->recorder->touchChat($chat, $message, true);
            $created[] = [$message, $item];
        }

        if (!$echo) {
            foreach ($created as [$message, $item]) {
                InboundAiBridge::run($instance, $contact, $jid, $message->message_id, $item['body'], $message->type);
            }
        }
    }

    private function contact(Instance $instance, string $jid): Contact
    {
        $contact = Contact::where('user_id', $instance->user_id)->where('contact', $jid)->first();
        if ($contact) {
            return $contact;
        }

        $profile = InstagramClient::for($instance)->profile(substr($jid, 3));
        $name = $profile['name'] ?? null;
        $name = $name ?: (!empty($profile['username']) ? '@' . $profile['username'] : null);

        // status "instagram" keeps it out of WhatsApp campaigns (they only pick "ativo")
        return Contact::create(['user_id' => $instance->user_id, 'contact' => $jid, 'name' => $name ? mb_substr($name, 0, 120) : null, 'status' => 'instagram']);
    }

    private function chat(Instance $instance, string $jid, string $igsid, Contact $contact): Chat
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
                'user_id' => $instance->user_id, 'instance_id' => $instance->id, 'remote_jid' => $jid,
                'phone' => null, 'name' => $contact->name ?: 'Instagram ' . substr($igsid, -4), 'contact_id' => $contact->id,
            ]);
        } catch (QueryException $e) {
            return Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first() ?? throw $e;
        }
    }

    /** @return array{0:?string,1:?string} */
    private function quoted(Instance $instance, array $msg): array
    {
        $replyMid = $msg['reply_to']['mid'] ?? null;
        if ($replyMid) {
            $id = self::msgId((string) $replyMid);
            $orig = ChatMessage::where('instance_id', $instance->id)->where('message_id', $id)->first();

            return [$id, $orig ? Str::limit((string) ($orig->body ?: $orig->type), 160, '…') : null];
        }
        if (!empty($msg['reply_to']['story'])) {
            return [null, '[story]'];
        }

        return [null, null];
    }

    /** @return array<int,array{type:string,body:?string,mimetype:?string,file_name:?string,url:?string}> */
    private function items(array $msg): array
    {
        $text = isset($msg['text']) ? (string) $msg['text'] : null;
        $blank = ['body' => null, 'mimetype' => null, 'file_name' => null, 'url' => null];
        $out = [];

        foreach ((array) ($msg['attachments'] ?? []) as $a) {
            $type = (string) ($a['type'] ?? '');
            $url = $a['payload']['url'] ?? null;
            $map = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'document'];

            if (isset($map[$type]) && $url) {
                $sticker = $type === 'image' && !empty($a['payload']['sticker_id']);
                $out[] = ['type' => $sticker ? 'sticker' : $map[$type], 'url' => $url] + $blank;
            } else {
                // shared posts, reels, story mentions, location...: keep the link as text
                $label = in_array($type, ['share', 'ig_reel', 'ig_post', 'story_mention'], true) ? '[' . $type . ']' : '[' . ($type ?: 'attachment') . ']';
                $out[] = ['type' => 'text', 'body' => trim($label . ' ' . ($url ?? '')), 'mimetype' => null, 'file_name' => null, 'url' => null];
            }
        }

        if ($text !== null && $text !== '') {
            if ($out && $out[0]['type'] !== 'text') {
                $out[0]['body'] = $text; // caption of the first media
            } else {
                array_unshift($out, ['type' => 'text', 'body' => $text, 'mimetype' => null, 'file_name' => null, 'url' => null]);
            }
        }

        return $out ?: [['type' => 'text', 'body' => '[unsupported message]'] + $blank];
    }

    private function downloadMedia(Instance $instance, ChatMessage $message, string $url): void
    {
        try {
            $binary = InstagramClient::for($instance)->download($url);
        } catch (\Throwable $e) {
            Log::info('Instagram media not downloaded: ' . $e->getMessage(), ['instance_id' => $instance->id]);

            return;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: 'application/octet-stream';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3', 'audio/mp4', 'video/quicktime' => 'm4a', 'audio/ogg' => 'ogg', 'application/pdf' => 'pdf', default => 'bin',
        };
        $path = config('chat.media_path', 'chat-media') . '/' . $instance->id . '/' . Str::uuid() . '.' . $ext;
        Storage::put($path, $binary);
        $message->update(['media_path' => $path, 'mimetype' => $mime]);
    }
}
