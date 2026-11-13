<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use App\Models\Subscription;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Brasil (https://docs.stripe.com): Checkout Session (hosted page) for card and Pix (Stripe shows the Pix QR on its page,
 * so both methods redirect). Columns: access_token = secret key (sk_live_… / sk_test_…), webhook_secret = signing secret (whsec_…).
 * Webhook: Stripe-Signature (HMAC-SHA256 of "t.body"); the object is then re-read with the secret key.
 *
 * Automatic card renewal uses Stripe Billing: Checkout in mode=subscription with an inline recurring price. Stripe then charges the
 * card every cycle and tells us with invoice.paid / invoice.payment_failed / customer.subscription.deleted. Pix is paid on Stripe's
 * page and cannot be charged by us later, so "renew automatically" is card-only here.
 */
class StripeGateway extends HostedGateway
{
    private const BASE = 'https://api.stripe.com/v1';

    public function name(): string
    {
        return GatewayConfig::STRIPE;
    }

    /** Card renewal is charged by Stripe itself; Pix has no recurrence (the QR code lives on Stripe's page). */
    public function supportsRecurrence(): bool
    {
        return $this->config->recurrenceAllowed() && in_array('card', $this->methods(), true);
    }

    public function supportsCardRecurrence(): bool
    {
        return $this->supportsRecurrence();
    }

    public function supportsPixRecurrence(): bool
    {
        return false;
    }

    /** Any currency enabled on the Stripe account (invoices carry their own). */
    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    private function currency(Invoice $invoice): string
    {
        return strtolower((string) ($invoice->currency ?: config('billing.currency', 'BRL')));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withBasicAuth((string) $this->config->access_token, '')->asForm()->acceptJson()->timeout(30);
    }

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        $pix = $request->method === 'pix';
        $back = route('billing.invoice', $invoice);

        $body = [
            'mode' => 'payment',
            'payment_method_types' => [$pix ? 'pix' : 'card'],
            'client_reference_id' => $invoice->number,
            'customer_email' => $request->email,
            'metadata' => ['invoice' => $invoice->number],
            'payment_intent_data' => ['metadata' => ['invoice' => $invoice->number], 'description' => mb_substr('Fatura ' . $invoice->number, 0, 200)],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->currency($invoice),
                    'unit_amount' => (int) $invoice->amount_cents,
                    'product_data' => ['name' => mb_substr($this->description($invoice), 0, 250)],
                ],
            ]],
            'success_url' => $back,
            'cancel_url' => $back,
            'expires_at' => now()->addHours(23)->timestamp,   // Stripe: between 30 min and 24 h
        ];
        if ($pix) {
            $body['payment_method_options'] = ['pix' => ['expires_after_seconds' => min(1209600, max(60, $this->pixMinutes() * 60))]];
        }

        try {
            $response = $this->http()->post('/checkout/sessions', $body);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }

        $url = (string) $response->json('url');
        if (!$response->successful() || $url === '') {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), (string) $response->json('id'), null, null, $pix ? 'bank_transfer' : 'credit_card', $url);
    }

    /** Checkout in subscription mode: the first charge is this invoice, Stripe renews it every cycle afterwards. */
    public function subscribe(Subscription $subscription, Invoice $invoice, PayRequest $request): PaymentResult
    {
        $back = route('billing.invoice', $invoice);
        $meta = ['invoice' => $invoice->number, 'subscription' => (string) $subscription->id];

        $body = [
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'client_reference_id' => $invoice->number,
            'customer_email' => $request->email,
            'metadata' => $meta,
            'subscription_data' => ['metadata' => $meta],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $this->currency($invoice),
                    'unit_amount' => (int) $invoice->amount_cents,
                    'recurring' => ['interval' => $invoice->cycle === 'yearly' ? 'year' : 'month'],
                    'product_data' => ['name' => mb_substr($this->description($invoice), 0, 250)],
                ],
            ]],
            'success_url' => $back,
            'cancel_url' => $back,
            'expires_at' => now()->addHours(23)->timestamp,
        ];

        try {
            $response = $this->http()->post('/checkout/sessions', $body);
        } catch (\Throwable $e) {
            $this->warn('subscribe failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }

        $url = (string) $response->json('url');
        if (!$response->successful() || $url === '') {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        $id = (string) $response->json('id');

        // the subscription id (sub_…) only exists after the customer pays: until then the session id stands in for it
        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), $id, null, $id, 'credit_card', $url);
    }

    public function cancelRecurring(string $recurringId): void
    {
        if (str_starts_with($recurringId, 'cs_')) {
            $this->http()->post('/checkout/sessions/' . rawurlencode($recurringId) . '/expire');   // never paid: just close the page

            return;
        }
        if (!str_starts_with($recurringId, 'sub_')) {
            return;
        }

        $r = $this->http()->delete('/subscriptions/' . rawurlencode($recurringId));
        if (!$r->successful() && $r->status() !== 404) {
            throw new \RuntimeException($this->errorMessage($r));
        }
    }

    /** Next renewals charge the new price (no proration: the current period was already paid). */
    public function updateRecurringAmount(string $recurringId, int $amountCents): void
    {
        if (!str_starts_with($recurringId, 'sub_')) {
            return;
        }

        $sub = $this->http()->get('/subscriptions/' . rawurlencode($recurringId));
        if (!$sub->successful()) {
            throw new \RuntimeException($this->errorMessage($sub));
        }

        $item = $sub->json('items.data.0');
        if (!$item || (int) ($item['price']['unit_amount'] ?? 0) === $amountCents) {
            return;
        }

        $r = $this->http()->post('/subscriptions/' . rawurlencode($recurringId), [
            'proration_behavior' => 'none',
            'items' => [[
                'id' => $item['id'],
                'price_data' => [
                    'currency' => $item['price']['currency'],
                    'product' => is_array($item['price']['product'] ?? null) ? $item['price']['product']['id'] : $item['price']['product'],
                    'unit_amount' => $amountCents,
                    'recurring' => ['interval' => $item['price']['recurring']['interval'] ?? 'month'],
                ],
            ]],
        ]);
        if (!$r->successful()) {
            throw new \RuntimeException($this->errorMessage($r));
        }
    }

    /** Stripe's customer portal: the customer changes the card or cancels there. Null when it cannot be opened. */
    public function portalUrl(string $customerId, string $returnUrl): ?string
    {
        try {
            $r = $this->http()->post('/billing_portal/sessions', ['customer' => $customerId, 'return_url' => $returnUrl]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$r->successful()) {
            $this->errorMessage($r);

            return null;
        }

        return (string) $r->json('url') ?: null;
    }

    public function testConnection(): array
    {
        try {
            $response = $this->http()->get('/account');
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }
        if (!$response->successful()) {
            return ['ok' => false, 'account' => null, 'error' => $this->errorMessage($response)];
        }

        $name = $response->json('business_profile.name') ?: $response->json('settings.dashboard.display_name') ?: $response->json('email') ?: 'Stripe';

        return ['ok' => true, 'account' => (string) $name, 'error' => null];
    }

    // ---- webhook ------------------------------------------------------------------------------------

    /** Stripe-Signature: "t=<unix>,v1=<hmac>[,v1=…]"; hmac = HMAC-SHA256(secret, "<t>.<raw body>"); 5 min tolerance. */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) $this->config->webhook_secret;
        if ($secret === '') {
            return false; // Stripe always signs: without the signing secret nothing can be authenticated
        }

        $t = null;
        $sigs = [];
        foreach (explode(',', (string) $request->header('Stripe-Signature', '')) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, null);
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1' && $v !== null) {
                $sigs[] = $v;
            }
        }
        if (!$t || !ctype_digit($t) || !$sigs || abs(now()->timestamp - (int) $t) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $request->getContent(), $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private const EVENTS = [
        'checkout.session.completed' => 'cs_',
        'checkout.session.async_payment_succeeded' => 'cs_',
        'invoice.paid' => 'in_',
        'invoice.payment_failed' => 'in_',
        'customer.subscription.deleted' => 'sub_',
        'charge.refunded' => 'ch_',
        'charge.dispute.created' => 'dp_',
    ];

    public function webhookEvents(Request $request): array
    {
        $type = (string) $request->input('type', '');
        $id = (string) $request->input('data.object.id', '');
        $prefix = self::EVENTS[$type] ?? null;

        return $prefix && str_starts_with($id, $prefix) ? [['type' => $type, 'id' => $id]] : [];
    }

    public function reconcile(string $type, string $id): ?array
    {
        return match (true) {
            str_starts_with($id, 'cs_') => $this->fetch('/checkout/sessions/' . rawurlencode($id), fn ($s) => $this->fromSession($s, $id)),
            $type === 'invoice.paid' => $this->fetch('/invoices/' . rawurlencode($id), fn ($i) => $this->fromRenewalInvoice($i, true)),
            $type === 'invoice.payment_failed' => $this->fetch('/invoices/' . rawurlencode($id), fn ($i) => $this->fromRenewalInvoice($i, false)),
            $type === 'customer.subscription.deleted' => $this->fetch('/subscriptions/' . rawurlencode($id), fn ($s) => $this->fromSubscription($s)),
            $type === 'charge.refunded' => $this->fetch('/charges/' . rawurlencode($id), fn ($c) => $this->fromCharge($c)),
            $type === 'charge.dispute.created' => $this->fetch('/disputes/' . rawurlencode($id), fn ($d) => $this->fromDispute($d)),
            default => [],
        };
    }

    /** GET + the retry contract of reconcile(): null = try again later, [] = nothing to apply. */
    private function fetch(string $path, callable $map): ?array
    {
        try {
            $response = $this->http()->get($path);
        } catch (\Throwable $e) {
            return null;
        }
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        return $map((array) $response->json());
    }

    private function base(string $status, string $gatewayId, array $extra = []): array
    {
        return $extra + [
            'status' => $status, 'gateway_id' => $gatewayId, 'invoice_number' => null, 'invoice_id' => null,
            'amount_cents' => null, 'currency' => null, 'payment_type' => 'credit_card',
            'event' => null, 'subscription_gateway_id' => null, 'customer_id' => null,
        ];
    }

    private function fromSession(array $s, string $id): array
    {
        $types = (array) ($s['payment_method_types'] ?? []);
        $pix = in_array('pix', $types, true) && !in_array('card', $types, true);

        return [$this->base(($s['payment_status'] ?? '') === 'paid' ? 'paid' : 'pending', (string) ($s['id'] ?? $id), [
            'invoice_number' => ($s['client_reference_id'] ?? null) ?: null,
            'amount_cents' => (int) ($s['amount_total'] ?? 0),
            'currency' => strtoupper((string) ($s['currency'] ?? '')),
            'payment_type' => $pix ? 'bank_transfer' : 'credit_card',
            'subscription_gateway_id' => ($s['mode'] ?? '') === 'subscription' ? (($s['subscription'] ?? null) ?: null) : null,
            'customer_id' => ($s['customer'] ?? null) ?: null,
        ])];
    }

    /** A charge Stripe made by itself for a later cycle (the first invoice is settled by its checkout session). */
    private function fromRenewalInvoice(array $i, bool $paid): array
    {
        $sub = ($i['subscription'] ?? null) ?: ($i['parent']['subscription_details']['subscription'] ?? null);
        if (($i['billing_reason'] ?? '') !== 'subscription_cycle' || !$sub) {
            return [];
        }

        return [$this->base($paid ? ($i['status'] === 'paid' ? 'paid' : 'pending') : 'failed', (string) $i['id'], [
            'amount_cents' => (int) ($i['amount_paid'] ?? 0),
            'currency' => strtoupper((string) ($i['currency'] ?? '')),
            'event' => $paid ? 'renewal' : 'payment_failed',
            'subscription_gateway_id' => (string) $sub,
            'customer_id' => ($i['customer'] ?? null) ?: null,
        ])];
    }

    private function fromSubscription(array $s): array
    {
        if (($s['status'] ?? '') !== 'canceled') {
            return [];
        }

        return [$this->base('pending', (string) $s['id'], ['event' => 'recurrence_canceled', 'subscription_gateway_id' => (string) $s['id']])];
    }

    private function fromCharge(array $c): array
    {
        return [$this->base('pending', (string) $c['id'], [
            'event' => 'refunded',
            'invoice_number' => ($c['metadata']['invoice'] ?? null) ?: null,
            'amount_cents' => (int) ($c['amount_refunded'] ?? 0),
            'currency' => strtoupper((string) ($c['currency'] ?? '')),
            'ref_id' => ($c['invoice'] ?? null) ?: ($c['payment_intent'] ?? null),
            'full' => (bool) ($c['refunded'] ?? false),
        ])];
    }

    private function fromDispute(array $d): array
    {
        return [$this->base('pending', (string) $d['id'], [
            'event' => 'dispute',
            'amount_cents' => (int) ($d['amount'] ?? 0),
            'currency' => strtoupper((string) ($d['currency'] ?? '')),
            'ref_id' => ($d['charge'] ?? null) ?: null,
            'reason' => $d['reason'] ?? null,
        ])];
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'type' => $response->json('error.type'), 'code' => $response->json('error.code')]);

        if ($response->status() === 401) {
            return __('Stripe rejected the secret key.');
        }

        return (string) ($response->json('error.message') ?: __('Could not reach the payment provider. Try again in a moment.'));
    }
}
