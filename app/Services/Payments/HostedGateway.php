<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shared behaviour of the gateways whose card is paid on the provider's hosted page (Asaas, Efí, AbacatePay):
 * Pix is generated here and shown as a QR code; "renew automatically" means a new Pix code per renewal invoice
 * (the card is paid once per invoice, so the provider does not charge it by itself).
 */
abstract class HostedGateway implements PaymentGateway, WebhookGateway
{
    public function __construct(protected GatewayConfig $config)
    {
    }

    abstract public function name(): string;

    public function config(): GatewayConfig
    {
        return $this->config;
    }

    public function isReady(): bool
    {
        return $this->config->isReady();
    }

    public function methods(): array
    {
        return $this->config->methods();
    }

    public function supportsRecurrence(): bool
    {
        return $this->config->recurrenceAllowed() && in_array('pix', $this->methods(), true);
    }

    public function supportsCardRecurrence(): bool
    {
        return false;
    }

    public function cardMode(): string
    {
        return 'redirect';
    }

    public function subscribe(Subscription $subscription, Invoice $invoice, PayRequest $request): PaymentResult
    {
        return new PaymentResult(PaymentResult::ERROR, __('Automatic card renewal is not available with this payment provider.'));
    }

    public function cancelRecurring(string $recurringId): void
    {
        // no provider-side recurrence
    }

    public function updateRecurringAmount(string $recurringId, int $amountCents): void
    {
        // no provider-side recurrence
    }

    /** @return array{ok:bool,account:?string,error:?string} used by "Test connection" in the admin */
    abstract public function testConnection(): array;

    /** Public URL the provider calls (built from APP_URL, never from the current request). */
    public static function webhookUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/') . route('webhooks.gateway', ['provider' => $provider], false);
    }

    /** The webhook URL including the shared secret when the provider authenticates by query string. */
    protected function notificationUrl(bool $withSecret = false): ?string
    {
        $url = self::webhookUrl($this->name());
        if (!str_starts_with($url, 'https://')) {
            return null; // the providers only accept public https endpoints
        }
        $secret = (string) $this->config->webhook_secret;

        return $withSecret && $secret !== '' ? $url . '?secret=' . rawurlencode($secret) : $url;
    }

    protected function secretFromQuery(Request $request): bool
    {
        $secret = (string) $this->config->webhook_secret;

        return $secret === '' || hash_equals($secret, (string) $request->query('secret', ''));
    }

    protected function cents(float|int|string|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    protected function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    protected function description(Invoice $invoice): string
    {
        return mb_substr((string) $invoice->description, 0, 200) ?: 'Invoice ' . $invoice->number;
    }

    protected function pixMinutes(): int
    {
        return max(30, min(43200, (int) $this->config->setting('pix_expiration_minutes')));
    }

    /** Provider messages end up on the customer's screen and in logs: never the credentials. */
    protected function warn(string $what, array $context = []): void
    {
        Log::warning("{$this->name()} {$what}", $context);
    }

    protected static function stripDataUri(?string $base64): ?string
    {
        return $base64 ? preg_replace('#^data:image/[a-z]+;base64,#i', '', $base64) : null;
    }
}
