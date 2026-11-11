<?php

namespace App\Services\Consent;

use App\Jobs\SendChatMessageJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Chat\ChatMessageRecorder;
use App\Models\User;

/**
 * Lets a customer stop receiving messages by themselves: a signed link anyone can open (no login) and the opt-out words.
 * The account decides whether every campaign message carries that instruction ("Reply STOP..." and/or the link).
 */
class OptOut
{
    public const MODES = ['off', 'keyword', 'link', 'both'];

    /** Signed, per contact, never guessable and never expiring (an old message must still work). */
    public static function signature(int $contactId): string
    {
        return substr(hash_hmac('sha256', 'optout:' . $contactId, (string) config('app.key')), 0, 24);
    }

    public static function valid(int $contactId, string $signature): bool
    {
        return hash_equals(self::signature($contactId), $signature);
    }

    public static function url(Contact $contact): string
    {
        return route('optout.show', ['contact' => $contact->id, 'signature' => self::signature($contact->id)]);
    }

    /** The word the customer is told to send: plain text from the configured list ("stop", not "/stop"), in the language of the message when there is one. */
    public static function word(): string
    {
        $plain = array_values(array_filter((array) config('consent.optout_keywords'), fn ($w) => preg_match('/^[\p{L}]+$/u', $w)));
        $prefer = str_starts_with((string) app()->getLocale(), 'pt') ? 'parar' : 'stop';

        return mb_strtoupper(in_array($prefer, $plain, true) ? $prefer : ($plain[0] ?? 'stop'));
    }

    /** Default sentence for the mode; the account can write its own (placeholders {word} and {link}). */
    public static function defaultText(string $mode): string
    {
        return match ($mode) {
            'keyword' => __('To stop receiving our messages, reply :word.', ['word' => '{word}']),
            'link' => __('To stop receiving our messages: :link', ['link' => '{link}']),
            'both' => __('To stop receiving our messages, reply :word or open: :link', ['word' => '{word}', 'link' => '{link}']),
            default => '',
        };
    }

    /** The line added after a campaign message, '' when the account turned it off. */
    public function footer(User $owner, Contact $contact): string
    {
        $mode = in_array($owner->optout_mode, self::MODES, true) ? $owner->optout_mode : 'off';
        if ($mode === 'off') {
            return '';
        }

        // written in the language of the account (a queue worker has no browser to take it from)
        $previous = app()->getLocale();
        app()->setLocale($owner->locale ?: $previous);
        try {
            $text = trim((string) $owner->optout_text) !== '' ? (string) $owner->optout_text : self::defaultText($mode);

            return trim(strtr($text, ['{word}' => self::word(), '{link}' => self::url($contact)]));
        } finally {
            app()->setLocale($previous);
        }
    }

    /** Message + footer (a voice note or an empty text gets nothing). */
    public function append(?string $text, User $owner, Contact $contact): ?string
    {
        if (trim((string) $text) === '') {
            return $text;
        }
        $footer = $this->footer($owner, $contact);

        return $footer === '' || str_contains($text, $footer) ? $text : rtrim($text) . "\n\n" . $footer;
    }

    /**
     * "Done, you will not receive more messages": the answer to a customer who opted out (or back in) by writing, sent in the
     * same conversation (WhatsApp, Telegram, Instagram, e-mail, website chat). It is a bot message, so it also counts as answered.
     */
    public function confirm(Contact $contact, ?Instance $instance, string $action): void
    {
        $owner = User::find($contact->user_id);
        if (!$owner || !$owner->optout_confirm) {
            return;
        }
        $chat = Chat::where('user_id', $owner->id)
            ->when($instance, fn ($q) => $q->where('instance_id', $instance->id))
            ->where(fn ($q) => $q->where('contact_id', $contact->id)->orWhere('remote_jid', $contact->contact . '@s.whatsapp.net')->orWhere('remote_jid', $contact->contact))
            ->orderByDesc('last_message_at')->first();
        if (!$chat) {
            return;
        }

        $text = $this->confirmation($owner, $contact, $action);
        $message = ChatMessage::create([
            'chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $owner->id, 'from_me' => true,
            'source' => 'bot', 'type' => 'text', 'body' => $text, 'status' => 'pending', 'message_timestamp' => now(),
        ]);
        app(ChatMessageRecorder::class)->touchChat($chat, $message, true);
        SendChatMessageJob::dispatch($message->id)->onQueue('default')->delay(now()->addSeconds(2));
    }

    public function confirmation(User $owner, Contact $contact, string $action): string
    {
        $previous = app()->getLocale();
        app()->setLocale($owner->locale ?: $previous);
        try {
            if ($action === 'granted') {
                return __('Done! You will receive messages from :company again.', ['company' => $owner->name]);
            }
            $custom = trim((string) $owner->optout_confirm_text);
            $text = $custom !== '' ? $custom : __('Done: :company will not send you more messages. To receive them again, send :optin or open: :link', ['company' => '{company}', 'optin' => '{optin}', 'link' => '{link}']);

            return trim(strtr($text, ['{company}' => $owner->name, '{optin}' => self::optInWord(), '{link}' => self::url($contact)]));
        } finally {
            app()->setLocale($previous);
        }
    }

    /** The word that brings the customer back (the first configured one). */
    public static function optInWord(): string
    {
        return mb_strtoupper((string) (((array) config('consent.optin_keywords'))[0] ?? '#entrar'));
    }
}
