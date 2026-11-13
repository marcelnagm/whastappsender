<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * Currencies a customer can pay in. Plans are priced in the base currency (BILLING_CURRENCY, BRL); every other currency is
 * the base price times the configured rate (config/billing.php `currencies`), rounded to the cent when the invoice is issued.
 * Pix only exists in BRL, so it is offered for BRL invoices only.
 */
class Currencies
{
    public static function base(): string
    {
        return strtoupper((string) config('billing.currency', 'BRL'));
    }

    /** @return array<string, array{symbol:string, rate:float}> code => definition, the base currency always first */
    public static function all(): array
    {
        $out = [self::base() => ['symbol' => self::base() === 'BRL' ? 'R$' : self::base(), 'rate' => 1.0]];

        foreach ((array) config('billing.currencies', []) as $code => $def) {
            $code = strtoupper((string) $code);
            $rate = (float) ($def['rate'] ?? 0);
            if ($code !== self::base() && $rate > 0) {
                $out[$code] = ['symbol' => (string) ($def['symbol'] ?? $code), 'rate' => $rate];
            }
        }

        return $out;
    }

    public static function valid(?string $code): bool
    {
        return $code !== null && isset(self::all()[strtoupper($code)]);
    }

    /** The user's choice when it is still offered, else the base currency. */
    public static function forUser(?User $user): string
    {
        $code = strtoupper((string) ($user->billing_currency ?? ''));

        return self::valid($code) ? $code : self::base();
    }

    public static function rate(string $code): float
    {
        return self::all()[strtoupper($code)]['rate'] ?? 1.0;
    }

    /** Base-currency cents → cents of $code. A positive price never rounds down to zero. */
    public static function fromBase(int $cents, string $code): int
    {
        if ($cents <= 0) {
            return 0;
        }

        return max(1, (int) round($cents * self::rate($code)));
    }

    /** Cents of $code → base-currency cents (credit of an invoice paid in another currency). */
    public static function toBase(int $cents, ?string $code): int
    {
        $rate = self::rate((string) $code);

        return $rate > 0 ? (int) round($cents / $rate) : $cents;
    }

    public static function format(int $cents, string $code): string
    {
        $symbol = self::all()[strtoupper($code)]['symbol'] ?? strtoupper($code);

        return $symbol . ' ' . number_format($cents / 100, 2, ',', '.');
    }

    /** Pix exists only in BRL. */
    public static function pixAllowed(?string $code): bool
    {
        return strtoupper((string) $code) === 'BRL';
    }
}
