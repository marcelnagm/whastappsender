<?php

namespace App\Support;

/** Brazilian CPF / CNPJ helpers (payment gateways require the payer's document). */
class Document
{
    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    /** CPF | CNPJ | null */
    public static function type(?string $value): ?string
    {
        return match (strlen(self::digits($value))) {
            11 => 'CPF',
            14 => 'CNPJ',
            default => null,
        };
    }

    public static function valid(?string $value): bool
    {
        $d = self::digits($value);

        return match (strlen($d)) {
            11 => self::validCpf($d),
            14 => self::validCnpj($d),
            default => false,
        };
    }

    private static function validCpf(string $d): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $d[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    private static function validCnpj(string $d): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        foreach ([12, 13] as $len) {
            $weights = $len === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $len; $i++) {
                $sum += (int) $d[$i] * $weights[$i];
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ((int) $d[$len] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
