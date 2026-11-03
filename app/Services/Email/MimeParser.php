<?php

namespace App\Services\Email;

/** Reads a raw RFC 822 / MIME message: decoded headers, readable text body and attachments. No extension required (mbstring only). */
class MimeParser
{
    /**
     * @return array{subject:string,from_email:?string,from_name:?string,to:?string,message_id:?string,in_reply_to:?string,references:string[],date:?string,
     *               text:string,attachments:array<int,array{name:string,mime:string,data:string}>,auto:bool,bounce:bool}
     */
    public static function parse(string $raw): array
    {
        [$head, $body] = self::split($raw);
        $h = self::headers($head);

        $parts = ['text' => '', 'html' => '', 'attachments' => []];
        self::walk($h, $body, $parts, 0);

        $text = trim($parts['text']) !== '' ? $parts['text'] : self::htmlToText($parts['html']);

        [$fromEmail, $fromName] = self::address($h['from'] ?? '');
        $local = strtolower(explode('@', (string) $fromEmail)[0]);

        $auto = isset($h['auto-submitted']) && strtolower(trim($h['auto-submitted'])) !== 'no'
            || isset($h['x-autoreply']) || isset($h['x-autorespond']) || isset($h['list-id']) || isset($h['list-unsubscribe'])
            || in_array(strtolower(trim($h['precedence'] ?? '')), ['bulk', 'junk', 'list', 'auto_reply'], true)
            || (bool) preg_match('/^(no-?reply|do-?not-?reply|notifications?|newsletter)/', $local);

        return [
            'subject' => trim(self::decodeHeader($h['subject'] ?? '')),
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'to' => $h['to'] ?? null,
            'message_id' => self::id($h['message-id'] ?? ''),
            'in_reply_to' => self::id($h['in-reply-to'] ?? ''),
            'references' => self::ids($h['references'] ?? ''),
            'date' => $h['date'] ?? null,
            'text' => trim($text),
            'attachments' => $parts['attachments'],
            'auto' => $auto,
            'bounce' => in_array($local, ['mailer-daemon', 'postmaster'], true),
        ];
    }

    /** Text of a reply without the quoted history ("On ... wrote:", "> ..." lines, forwarded headers). Never returns empty if the message had text. */
    public static function trimQuoted(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $keep = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(On .{5,200} wrote:|Em .{5,200} escreveu:|El .{5,200} escribi[oó]:|-{2,}\s*(Original Message|Mensagem original|Mensaje original)\s*-{2,}|_{5,}|De:\s.+|From:\s.+<.+>)\s*$/iu', $line)) {
                break;
            }
            if (preg_match('/^\s*>/', $line)) {
                // a quoted block ends the reply; a lone quoted line in the middle is kept out too
                continue;
            }
            $keep[] = $line;
        }
        $out = trim(implode("\n", $keep));

        return $out !== '' ? $out : trim($text);
    }

    // ---- structure ----------------------------------------------------------------------------------------------------

    private static function split(string $raw): array
    {
        if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            return [substr($raw, 0, $m[0][1]), substr($raw, $m[0][1] + strlen($m[0][0]))];
        }

        return [$raw, ''];
    }

    /** @return array<string,string> lower-case name => raw value (first occurrence) */
    private static function headers(string $head): array
    {
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
        $out = [];
        foreach (preg_split('/\r?\n/', $head) as $line) {
            if (($p = strpos($line, ':')) > 0) {
                $name = strtolower(trim(substr($line, 0, $p)));
                $out[$name] ??= trim(substr($line, $p + 1));
            }
        }

        return $out;
    }

    private static function walk(array $h, string $body, array &$parts, int $depth): void
    {
        $ct = self::params($h['content-type'] ?? 'text/plain');
        $type = strtolower($ct['_value'] ?: 'text/plain');
        $disp = self::params($h['content-disposition'] ?? '');

        if (str_starts_with($type, 'multipart/') && !empty($ct['boundary']) && $depth < 8) {
            $b = preg_quote($ct['boundary'], '/');
            foreach (preg_split('/--' . $b . '(?:--)?[ \t]*\r?\n?/', $body) as $i => $chunk) {
                if ($i === 0 || trim($chunk) === '') {
                    continue; // preamble / epilogue
                }
                [$ph, $pb] = self::split(ltrim($chunk, "\r\n"));
                self::walk(self::headers($ph), $pb, $parts, $depth + 1);
            }

            return;
        }

        $data = self::decodeBody($body, strtolower(trim($h['content-transfer-encoding'] ?? '7bit')));
        $filename = $disp['filename'] ?? $ct['name'] ?? null;
        $isAttachment = ($disp['_value'] ?? '') === 'attachment' || ($filename !== null && !in_array($type, ['text/plain', 'text/html'], true))
            || (!str_starts_with($type, 'text/') && !str_starts_with($type, 'multipart/') && $type !== 'message/rfc822' && ($disp['_value'] ?? '') !== '');

        if (!$isAttachment && ($type === 'text/plain' || $type === 'text/html')) {
            $data = self::toUtf8($data, $ct['charset'] ?? 'utf-8');
            $parts[$type === 'text/plain' ? 'text' : 'html'] .= ($parts[$type === 'text/plain' ? 'text' : 'html'] !== '' ? "\n" : '') . $data;

            return;
        }

        if ($data !== '' && strlen($data) <= (int) config('email.max_attachment_bytes', 10485760) && count($parts['attachments']) < 5 && $type !== 'message/rfc822') {
            $parts['attachments'][] = [
                'name' => self::safeName(self::decodeHeader((string) ($filename ?: 'attachment'))),
                'mime' => $type,
                'data' => $data,
            ];
        }
    }

    /** @return array<string,string> "_value" = main value, then parameters (lower-case names) */
    private static function params(string $v): array
    {
        $out = ['_value' => strtolower(trim(explode(';', $v, 2)[0]))];
        if (preg_match_all('/;\s*([\w.-]+?)(\*\d*\*?)?\s*=\s*("([^"]*)"|[^;]*)/', $v, $m, PREG_SET_ORDER)) {
            foreach ($m as $p) {
                $val = ($p[4] ?? '') !== '' ? $p[4] : trim($p[3], '" ');
                if (($p[2] ?? '') !== '' && preg_match("/^([\w-]*)'[\w-]*'(.*)$/", $val, $x)) { // RFC 2231: utf-8''na%C3%AFve.pdf
                    $val = self::toUtf8(rawurldecode($x[2]), $x[1] ?: 'utf-8');
                }
                $out[strtolower($p[1])] = ($out[strtolower($p[1])] ?? '') . $val;
            }
        }

        return $out;
    }

    // ---- decoding ----------------------------------------------------------------------------------------------------

    private static function decodeBody(string $body, string $cte): string
    {
        return match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    public static function decodeHeader(string $v): string
    {
        if (!str_contains($v, '=?')) {
            return self::toUtf8($v, 'utf-8');
        }
        $d = @mb_decode_mimeheader($v);

        return self::toUtf8($d !== false ? $d : $v, 'utf-8');
    }

    private static function toUtf8(string $s, string $charset): string
    {
        $charset = strtolower(trim($charset)) ?: 'utf-8';
        if (in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        try {
            return mb_convert_encoding($s, 'UTF-8', $charset);
        } catch (\Throwable $e) {
            return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
    }

    private static function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $html);
        $html = preg_replace('#<\s*br\s*/?>|</\s*(p|div|tr|li|h[1-6])\s*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $text));
    }

    private static function safeName(string $n): string
    {
        $n = preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', $n);

        return mb_substr(trim($n, ". \t"), 0, 150) ?: 'attachment';
    }

    /** @return array{0:?string,1:?string} [address (lower-case), display name] */
    private static function address(string $v): array
    {
        $v = self::decodeHeader($v);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>\s]+@[^>\s]+)>/', $v, $m)) {
            return [strtolower(trim($m[2])), trim($m[1]) !== '' ? mb_substr(trim($m[1]), 0, 120) : null];
        }
        if (preg_match('/([^\s<>",;]+@[^\s<>",;]+)/', $v, $m)) {
            return [strtolower($m[1]), null];
        }

        return [null, null];
    }

    private static function id(string $v): ?string
    {
        return preg_match('/<([^>]+)>/', $v, $m) ? trim($m[1]) : null;
    }

    /** @return string[] */
    private static function ids(string $v): array
    {
        preg_match_all('/<([^>]+)>/', $v, $m);

        return array_slice($m[1], -10);
    }
}
