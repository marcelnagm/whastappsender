<?php

namespace App\Services\Email;

/** Text of a reply without the quoted history ("On ... wrote:", "> ..." lines, forwarded headers). Never returns empty if the message had text. */
class QuotedReply
{
    public static function trim(string $text): string
    {
        $keep = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (preg_match('/^\s*(On .{5,200} wrote:|Em .{5,200} escreveu:|El .{5,200} escribi[oó]:|-{2,}\s*(Original Message|Mensagem original|Mensaje original)\s*-{2,}|_{5,}|De:\s.+|From:\s.+<.+>)\s*$/iu', $line)) {
                break;
            }
            if (!preg_match('/^\s*>/', $line)) {
                $keep[] = $line;
            }
        }
        $out = trim(implode("\n", $keep));

        return $out !== '' ? $out : trim($text);
    }
}
