<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gateway credentials and options, edited by admins (Admin > Payment gateway). Secrets are encrypted at rest. */
class PaymentGateway extends Model
{
    public const MERCADOPAGO = 'mercadopago';
    public const ASAAS = 'asaas';
    public const EFI = 'efi';
    public const ABACATEPAY = 'abacatepay';
    public const PAGARME = 'pagarme';     // Stone's online gateway
    public const STRIPE = 'stripe';
    public const PAGBANK = 'pagbank';

    /** Providers the customer can be offered, in the order shown to admins/customers (see config/payments.php) */
    public const PROVIDERS = [self::ASAAS, self::EFI, self::ABACATEPAY, self::PAGARME, self::STRIPE, self::PAGBANK, self::MERCADOPAGO];

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
        'sandbox' => false,             // Asaas / Efí / AbacatePay (Mercado Pago detects TEST- tokens)
        'certificate_path' => '',       // Efí: .pem certificate used for the Pix API (mTLS)
        'pix_key' => '',                // Efí: the Pix key that receives the money
    ];

    public static function mercadopago(): self
    {
        return static::firstOrCreate(['provider' => self::MERCADOPAGO], ['enabled' => false, 'settings' => self::DEFAULTS]);
    }

    public static function for(string $provider): self
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return static::firstOrCreate(['provider' => $provider], ['enabled' => false, 'settings' => self::DEFAULTS]);
    }

    public function setting(string $key, $default = null)
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public function isConfigured(): bool
    {
        if ($this->provider === self::EFI) {
            return filled($this->access_token) && filled($this->public_key); // client secret + client id
        }

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
        // the key itself tells the environment on these
        if (in_array($this->provider, [self::STRIPE, self::PAGARME], true)) {
            return str_starts_with((string) $this->access_token, 'sk_test');
        }
        if ($this->provider !== self::MERCADOPAGO) {
            return (bool) $this->setting('sandbox');
        }

        return str_starts_with((string) $this->access_token, 'TEST-');
    }

    /** @return list<string> */
    public function methods(): array
    {
        $methods = [];

        if ($this->provider === self::MERCADOPAGO) {
            if ($this->setting('card_enabled') && filled($this->public_key)) {
                $methods[] = 'card'; // card tokens are created in the browser with the public key
            }
            if ($this->setting('pix_enabled')) {
                $methods[] = 'pix';
            }

            return $methods;
        }

        // Asaas / Efí / AbacatePay: the card is paid on the provider's hosted page (card data never reaches us)
        if ($this->setting('card_enabled')) {
            $methods[] = 'card';
        }
        // Efí's Pix API needs the mTLS certificate and the receiving Pix key
        $pixReady = $this->provider !== self::EFI || (filled($this->setting('certificate_path')) && filled($this->setting('pix_key')));
        if ($this->setting('pix_enabled') && $pixReady) {
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
