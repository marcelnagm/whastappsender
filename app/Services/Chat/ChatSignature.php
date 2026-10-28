<?php

namespace App\Services\Chat;

/**
 * "Who sent this from the CRM": a name chosen by the user is put in front of what the customer receives,
 * in WhatsApp bold: "*Ana (CRM):*" + new line + the message. No name (empty, only spaces) = no signature.
 */
class ChatSignature
{
    public const MAX = 40;

    /** Clean name, or null when it is empty / only spaces / only formatting characters. */
    public static function normalize(?string $name): ?string
    {
        // WhatsApp formatting characters and line breaks would break the bold header
        $name = preg_replace('/[*_~`\x00-\x1F\x7F]+/u', ' ', (string) $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $name = trim(mb_substr($name, 0, self::MAX));

        return $name === '' ? null : $name;
    }

    public static function header(?string $name): ?string
    {
        $name = self::normalize($name);

        return $name === null ? null : '*' . $name . ' ' . config('chat.signature_suffix', '(CRM)') . ':*';
    }

    /** Text as it leaves the CRM. A media caption may be only the header; plain text never is. */
    public static function apply(?string $name, ?string $text, bool $headerAlone = false): ?string
    {
        $header = self::header($name);
        $text = $text === null ? '' : trim($text);

        if ($header === null) {
            return $text === '' ? null : $text;
        }

        if ($text === '') {
            return $headerAlone ? $header : null;
        }

        return $header . "\n" . $text;
    }
}
