<?php

namespace App\Services\Consent;

use App\Models\Contact;
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
}
