<?php

namespace App\Services\Rag;

/** Cuts a text into passages of about N characters on paragraph/sentence boundaries, with a little overlap. */
class Chunker
{
    /** @return string[] */
    public function split(string $text, ?int $size = null, ?int $overlap = null): array
    {
        $size = max(200, $size ?? (int) config('rag.chunk_chars', 1500));
        $overlap = min((int) ($overlap ?? config('rag.chunk_overlap', 150)), intdiv($size, 3));
        $text = trim(preg_replace("/[ \t]+/", ' ', str_replace(["\r\n", "\r"], "\n", $text)));
        if ($text === '') {
            return [];
        }

        // pieces no longer than $size: paragraphs, then sentences, then hard cuts
        $pieces = [];
        foreach (preg_split("/\n{2,}/", $text) as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (mb_strlen($para) <= $size) {
                $pieces[] = $para;
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+|\n/u', $para) as $sentence) {
                $sentence = trim($sentence);
                while (mb_strlen($sentence) > $size) {
                    $pieces[] = mb_substr($sentence, 0, $size);
                    $sentence = mb_substr($sentence, $size);
                }
                if ($sentence !== '') {
                    $pieces[] = $sentence;
                }
            }
        }

        $chunks = [];
        $cur = '';
        foreach ($pieces as $p) {
            if ($cur !== '' && mb_strlen($cur) + mb_strlen($p) + 1 > $size) {
                $chunks[] = $cur;
                $tail = $overlap > 0 ? mb_substr($cur, -$overlap) : '';
                $cur = $tail !== '' ? ltrim($tail) . ' ' . $p : $p;
            } else {
                $cur = $cur === '' ? $p : $cur . "\n" . $p;
            }
        }
        if (trim($cur) !== '') {
            $chunks[] = $cur;
        }

        return $chunks;
    }
}
