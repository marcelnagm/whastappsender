<?php

namespace App\Services\Email;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Channels\ContactLinks;
use App\Services\Channels\InboundAiBridge;
use App\Services\Chat\ChatMessageRecorder;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One received e-mail -> the same tables the WhatsApp chat uses, a CRM contact and, when the account has AI replies on, the
 * AI agent of that mailbox. Conventions: chat jid = contact = "em:<address>", message id = the e-mail Message-ID.
 * Automatic mail (newsletters, out-of-office, no-reply) is shown in the chat but never answered by the AI.
 */
class EmailInbound
{
    public function __construct(private ChatMessageRecorder $recorder)
    {
    }

    public static function jid(string $address): string
    {
        return 'em:' . strtolower(trim($address));
    }

    /**
     * @param array|null $parsed result of MimeParser::parse (already parsed by the caller)
     * @param bool $answer false = only record it (no AI reply)
     */
    public function handle(Instance $instance, string $raw, ?array $parsed = null, bool $answer = true): ?ChatMessage
    {
        if (!$instance->isEmail()) {
            return null;
        }

        $m = $parsed ?? MimeParser::parse($raw);
        $from = $m['from_email'];
        if (!$from || $m['bounce'] || strtolower($from) === strtolower((string) $instance->email_address)) {
            return null; // bounces and our own copies are not conversations
        }

        $messageId = $m['message_id'] ?: 'gen-' . sha1($raw);
        if (ChatMessage::where('instance_id', $instance->id)->where('message_id', $messageId)->exists()) {
            return null; // fetched twice
        }

        $jid = self::jid($from);
        $contact = $this->contact($instance, $jid, $from, $m['from_name']);
        $chat = $this->chat($instance, $jid, $m['from_name'], $contact);

        $reply = MimeParser::trimQuoted($m['text']);
        $subject = $m['subject'];
        $firstOfThread = !ChatMessage::where('chat_id', $chat->id)->exists();
        $body = ($subject !== '' ? '✉️ ' . $subject . ($reply !== '' ? "\n\n" : '') : '') . $reply;
        if ($body === '') {
            $body = '✉️';
        }

        try {
            $message = ChatMessage::create([
                'chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id,
                'message_id' => $messageId, 'from_me' => false, 'type' => 'text', 'body' => Str::limit($body, 20000, ''),
                'status' => 'received', 'message_timestamp' => $this->date($m['date']),
            ]);
        } catch (QueryException $e) {
            return null;
        }

        // answer inside the same thread later
        $refs = array_slice(array_values(array_filter(array_merge(explode(' ', (string) $chat->email_refs), $m['references'], [$messageId]))), -10);
        $chat->forceFill(['email_subject' => $subject !== '' ? Str::limit($subject, 250, '') : $chat->email_subject, 'email_refs' => implode(' ', array_unique($refs))])->save();

        $this->recorder->touchChat($chat, $message, true);

        foreach ($m['attachments'] as $i => $a) {
            $this->attachment($instance, $chat, $messageId . '#' . ($i + 1), $a);
        }

        if ($answer && !$m['auto'] && $this->mayAnswer($instance, $from)) {
            // first message of a conversation: the subject says what it is about, so the AI gets it too
            $aiText = ($firstOfThread && $subject !== '' ? $subject . "\n\n" : '') . $reply;
            InboundAiBridge::run($instance, $contact, $jid, $messageId, $reply === '' ? $aiText : (in_array(Str::lower($reply), ['/stop', '#sair'], true) ? $reply : $aiText), 'text');
        }

        return $message;
    }

    /** Loop protection: the AI answers one address only a few times per day, however many mails it sends. */
    private function mayAnswer(Instance $instance, string $from): bool
    {
        $max = (int) config('email.ai_max_replies_per_sender_per_day', 5);
        $key = 'email-ai:' . $instance->id . ':' . sha1(strtolower($from)) . ':' . now()->format('Ymd');
        Cache::add($key, 0, now()->addDay());

        return Cache::increment($key) <= $max;
    }

    private function contact(Instance $instance, string $jid, string $address, ?string $name): Contact
    {
        $contact = Contact::where('user_id', $instance->user_id)->where('contact', $jid)->first();
        if (!$contact) {
            // status "email" keeps it out of WhatsApp campaigns (they only pick "ativo")
            $contact = Contact::create(['user_id' => $instance->user_id, 'contact' => $jid, 'name' => $name ?: $address, 'email' => $address, 'status' => 'email']);
        } elseif ($name && (!$contact->name || $contact->name === $address)) {
            $contact->update(['name' => $name]);
        }

        // same address on a WhatsApp contact (CRM, form, import) = same person
        ContactLinks::linkByEmail($contact);

        return $contact;
    }

    private function chat(Instance $instance, string $jid, ?string $name, Contact $contact): Chat
    {
        $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first();
        if (!$chat) {
            try {
                $chat = Chat::create(['user_id' => $instance->user_id, 'instance_id' => $instance->id, 'remote_jid' => $jid, 'phone' => null, 'name' => $name, 'contact_id' => $contact->id]);
            } catch (QueryException $e) {
                $chat = Chat::where('instance_id', $instance->id)->where('remote_jid', $jid)->first() ?? throw $e;
            }
        } elseif (!$chat->contact_id) {
            $chat->update(['contact_id' => $contact->id]);
        }

        return $chat;
    }

    private function attachment(Instance $instance, Chat $chat, string $id, array $a): void
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($a['name'], PATHINFO_EXTENSION))) ?: 'bin';
        $path = config('chat.media_path', 'chat-media') . '/' . $instance->id . '/' . Str::uuid() . '.' . $ext;
        Storage::put($path, $a['data']);

        $type = match (true) {
            str_starts_with($a['mime'], 'image/') => 'image',
            str_starts_with($a['mime'], 'video/') => 'video',
            str_starts_with($a['mime'], 'audio/') => 'audio',
            default => 'document',
        };

        try {
            $message = ChatMessage::create([
                'chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id, 'message_id' => $id,
                'from_me' => false, 'type' => $type, 'body' => null, 'mimetype' => $a['mime'], 'file_name' => $a['name'],
                'media_path' => $path, 'status' => 'received', 'message_timestamp' => now(),
            ]);
            $this->recorder->touchChat($chat, $message, true);
        } catch (QueryException $e) {
            Storage::delete($path);
        }
    }

    private function date(?string $d): Carbon
    {
        try {
            $t = $d ? Carbon::parse($d) : now();
        } catch (\Throwable $e) {
            return now();
        }

        return $t->isFuture() ? now() : $t;
    }
}
