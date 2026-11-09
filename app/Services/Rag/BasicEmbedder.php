<?php

namespace App\Services\Rag;

use Illuminate\Support\Str;

/**
 * Built-in embedder with no external service: hashes words (and word pairs) into a fixed-size vector. It finds
 * passages that share vocabulary with the question; a hosted model also understands synonyms. It is what makes the
 * feature work out of the box, and the fallback when no API key is available.
 */
class BasicEmbedder implements Embedder
{
    private const DIM = 512;

    private const STOP = ['a', 'o', 'e', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'um', 'uma', 'que', 'com', 'por', 'para', 'the', 'of', 'and', 'to', 'is', 'in', 'it', 'for', 'on', 'el', 'la', 'los', 'las', 'un', 'una', 'y', 'es', 'se', 'ou', 'os', 'as', 'eu', 'me', 'meu', 'minha'];

    public function id(): string
    {
        return 'basic';
    }

    public function kind(): string
    {
        return 'basic';
    }

    public function embed(array $texts): array
    {
        return array_map(fn ($t) => $this->vector((string) $t), $texts);
    }

    private function vector(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter(array_map(fn ($w) => $this->stem($w), $words), fn ($w) => strlen($w) > 1 && !in_array($w, self::STOP, true)));

        $v = array_fill(0, self::DIM, 0.0);
        $counts = array_count_values($words);
        foreach ($counts as $w => $n) {
            $v[crc32($w) % self::DIM] += 1 + log($n);
        }
        for ($i = 1; $i < count($words); $i++) {   // word pairs catch short phrases ("horario funcionamento")
            $v[crc32($words[$i - 1] . ' ' . $words[$i]) % self::DIM] += 0.5;
        }

        return Vectors::normalize($v);
    }

    /** Crude plural/gender trim so "precos" matches "preco" and "horarios" "horario". */
    private function stem(string $w): string
    {
        if (strlen($w) > 4 && str_ends_with($w, 'oes')) {
            return substr($w, 0, -3) . 'ao';
        }
        if (strlen($w) > 3 && str_ends_with($w, 's')) {
            $w = substr($w, 0, -1);
        }

        // long words share their beginning across forms (funcionamento/funcionamos, vendemos/vendas -> vende/venda)
        return strlen($w) > 6 ? substr($w, 0, 6) : $w;
    }
}
