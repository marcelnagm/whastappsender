<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gateway credentials and options, edited by admins (Admin > Payment gateway). Secrets are encrypted at rest. */
class PaymentGateway extends Model
{
    public const MERCADOPAGO = 'mercadopago';

    protected $fillable = ['provider', 'enabled', 'access_token', 'public_key', 'webhook_secret', 'settings'];

    protected $hidden = ['access_token', 'webhook_secret'];

    protected $casts = [
        'enabled' => 'boolean',
        'access_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'settings' => 'array',
    ];

    public const DEFAULTS = [
        'card_enabled' => true,
        'pix_enabled' => true,
        'recurrence_enabled' => true,   // customers may choose "renew automatically" on every method
        'recurrence_default' => true,   // ...and the box starts checked
        'pix_expiration_minutes' => 1440,
        'statement_descriptor' => '',
    ];

    public static function mercadopago(): self
    {
        return static::firstOrCreate(['provider' => self::MERCADOPAGO], ['enabled' => false, 'settings' => self::DEFAULTS]);
    }

    public function setting(string $key, $default = null)
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public function isConfigured(): bool
    {
        return filled($this->access_token);
    }

    /** Enabled by an admin AND has credentials */
    public function isReady(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    /** Mercado Pago test credentials start with TEST- */
    public function isSandbox(): bool
    {
        return str_starts_with((string) $this->access_token, 'TEST-');
    }

    /** @return list<string> */
    public function methods(): array
    {
        $methods = [];
        if ($this->setting('card_enabled') && filled($this->public_key)) {
            $methods[] = 'card'; // card tokens are created in the browser with the public key
        }
        if ($this->setting('pix_enabled')) {
            $methods[] = 'pix';
        }

        return $methods;
    }

    public function recurrenceAllowed(): bool
    {
        return (bool) $this->setting('recurrence_enabled');
    }

    /** "TEST-1234…wxyz" → "TEST-1…wxyz": enough to recognise the credential without exposing it */
    public static function mask(?string $secret): string
    {
        if (!$secret) {
            return '';
        }

        return strlen($secret) <= 10 ? str_repeat('•', strlen($secret)) : substr($secret, 0, 6) . '…' . substr($secret, -4);
    }
}
