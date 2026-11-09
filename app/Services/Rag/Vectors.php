<?php

namespace App\Services\Rag;

class Vectors
{
    /** @param float[] $v */
    public static function normalize(array $v): array
    {
        $n = 0.0;
        foreach ($v as $x) {
            $n += $x * $x;
        }
        $n = sqrt($n);
        if ($n <= 0.0) {
            return $v;
        }

        return array_map(fn ($x) => round($x / $n, 6), $v);
    }

    /** Dot product of two normalised vectors = cosine similarity. */
    public static function dot(array $a, array $b): float
    {
        $s = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }

        return $s;
    }
}
