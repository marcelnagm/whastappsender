<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use App\Models\Subscription;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PayPal (https://developer.paypal.com): Orders v2 for a one-time payment (PayPal wallet or card on PayPal's page) and the
 * Subscriptions API for automatic renewal. Columns: public_key = Client ID, access_token = Client secret, webhook_secret = Webhook ID.
 * Sandbox is the admin "test mode" checkbox.
 *
 * Flow: the customer approves on PayPal and comes back to the invoice page with ?token=<order>; we capture the order right there
 * (and again on CHECKOUT.ORDER.APPROVED, idempotently). Subscriptions: PayPal bills each cycle and sends PAYMENT.SALE.COMPLETED.
 * Webhooks are authenticated by asking PayPal to verify the signature (verify-webhook-signature) with the Webhook ID.
 */
class PayPalGateway extends HostedGateway
{
    public function name(): string
    {
        return GatewayConfig::PAYPAL;
    }

    private function base(): string
    {
        return $this->config->isSandbox() ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    /** PayPal has no Pix: the customer pays with the PayPal balance or a card on PayPal's page. */
    public function methods(): array
    {
        return $this->config->setting('card_enabled') ? ['card'] : [];
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;   // the PayPal account decides which currencies it can receive
    }

    public function supportsRecurrence(): bool
    {
        return $this->config->recurrenceAllowed() && $this->methods() !== [];
    }

    public function supportsCardRecurrence(): bool
    {
        return $this->supportsRecurrence();
    }

    public function supportsPixRecurrence(): bool
    {
        return false;
    }

    // ---- HTTP ---------------------------------------------------------------------------------------

    private function token(): ?string
    {
        $key = 'paypal_token_' . md5($this->base() . $this->config->public_key);

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $r = Http::baseUrl($this->base())->withBasicAuth((string) $this->config->public_key, (string) $this->config->access_token)
            ->asForm()->acceptJson()->timeout(30)->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (!$r->successful() || !$r->json('access_token')) {
            $this->warn('oauth failed', ['status' => $r->status(), 'error' => $r->json('error')]);

            return null;
        }

        Cache::put($key, $r->json('access_token'), max(60, (int) $r->json('expires_in', 300) - 120));

        return (string) $r->json('access_token');
    }

    private function http(array $headers = []): PendingRequest
    {
        $token = $this->token();
        if (!$token) {
            throw new \RuntimeException('PayPal rejected the Client ID / secret.');
        }

        return Http::baseUrl($this->base())->withToken($token)->withHeaders($headers)->asJson()->acceptJson()->timeout(30);
    }

    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function currency(Invoice $invoice): string
    {
        return strtoupper((string) ($invoice->currency ?: config('billing.currency', 'BRL')));
    }

    private function approveUrl(Response $r): string
    {
        foreach ((array) $r->json('links', []) as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                return (string) $link['href'];
            }
        }

        return '';
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'name' => $response->json('name'), 'debug_id' => $response->json('debug_id')]);

        return (string) ($response->json('message') ?: __('Could not reach the payment provider. Try again in a moment.'));
    }

    // ---- one-time payment ---------------------------------------------------------------------------

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        $back = route('billing.invoice', $invoice);

        try {
            $response = $this->http(['PayPal-Request-Id' => (string) Str::uuid()])->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $invoice->number,
                    'custom_id' => $invoice->number,
                    'invoice_id' => $invoice->number . '-' . ((int) (($invoice->gateway_payload ?? [])['paypal_attempts'] ?? 0) + 1),
                    'description' => mb_substr($this->description($invoice), 0, 127),
                    'amount' => ['currency_code' => $this->currency($invoice), 'value' => $this->amount((int) $invoice->amount_cents)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'return_url' => $back, 'cancel_url' => $back, 'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING', 'brand_name' => mb_substr((string) config('app.name'), 0, 127),
                ]]],
            ]);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }

        $url = $this->approveUrl($response);
        if (!$response->successful() || $url === '') {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), (string) $response->json('id'), null, null, 'credit_card', $url);
    }

    // ---- subscriptions ------------------------------------------------------------------------------

    /** One billing plan per subscription (so a price change only touches this customer), under one product. */
    public function subscribe(Subscription $subscription, Invoice $invoice, PayRequest $request): PaymentResult
    {
        $back = route('billing.invoice', $invoice);

        try {
            $productId = $this->productId();

            $plan = $this->http(['PayPal-Request-Id' => (string) Str::uuid()])->post('/v1/billing/plans', [
                'product_id' => $productId,
                'name' => mb_substr($this->description($invoice), 0, 120),
                'status' => 'ACTIVE',
                'billing_cycles' => [[
                    'frequency' => ['interval_unit' => $invoice->cycle === 'yearly' ? 'YEAR' : 'MONTH', 'interval_count' => 1],
                    'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0,
                    'pricing_scheme' => ['fixed_price' => ['value' => $this->amount((int) $invoice->amount_cents), 'currency_code' => $this->currency($invoice)]],
                ]],
                'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 3],
            ]);
            if (!$plan->successful() || !$plan->json('id')) {
                return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($plan));
            }

            $name = explode(' ', trim($request->name), 2);
            $response = $this->http(['PayPal-Request-Id' => (string) Str::uuid()])->post('/v1/billing/subscriptions', [
                'plan_id' => $plan->json('id'),
                'custom_id' => $invoice->number,
                'subscriber' => array_filter([
                    'email_address' => $request->email ?: null,
                    'name' => $request->name ? ['given_name' => $name[0], 'surname' => $name[1] ?? $name[0]] : null,
                ]),
                'application_context' => [
                    'brand_name' => mb_substr((string) config('app.name'), 0, 127), 'user_action' => 'SUBSCRIBE_NOW',
                    'shipping_preference' => 'NO_SHIPPING', 'return_url' => $back, 'cancel_url' => $back,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->warn('subscribe failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }

        $url = $this->approveUrl($response);
        if (!$response->successful() || $url === '' || !$response->json('id')) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        $id = (string) $response->json('id');

        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), $id, null, $id, 'credit_card', $url);
    }

    private function productId(): string
    {
        $key = 'paypal_product_' . md5($this->base() . $this->config->public_key);

        return Cache::rememberForever($key, function () {
            $r = $this->http(['PayPal-Request-Id' => 'product-' . md5((string) config('app.name'))])->post('/v1/catalogs/products', [
                'name' => mb_substr((string) config('app.name') . ' subscription', 0, 127), 'type' => 'SERVICE',
            ]);
            if (!$r->successful() || !$r->json('id')) {
                throw new \RuntimeException($this->errorMessage($r));
            }

            return (string) $r->json('id');
        });
    }

    public function cancelRecurring(string $recurringId): void
    {
        $r = $this->http()->post('/v1/billing/subscriptions/' . rawurlencode($recurringId) . '/cancel', ['reason' => 'Canceled by the customer']);

        // 404: unknown; 422: already canceled/never approved. Nothing left to stop.
        if (!$r->successful() && !in_array($r->status(), [404, 422], true)) {
            throw new \RuntimeException($this->errorMessage($r));
        }
    }

    /** The plan belongs to this subscription alone: changing its price changes the next renewals only (PayPal may ask the customer to approve big increases). */
    public function updateRecurringAmount(string $recurringId, int $amountCents): void
    {
        $sub = $this->http()->get('/v1/billing/subscriptions/' . rawurlencode($recurringId));
        if (!$sub->successful() || !$sub->json('plan_id')) {
            throw new \RuntimeException($this->errorMessage($sub));
        }

        $plan = $this->http()->get('/v1/billing/plans/' . rawurlencode((string) $sub->json('plan_id')));
        $price = $plan->json('billing_cycles.0.pricing_scheme.fixed_price');
        if (!$plan->successful() || !$price || $this->cents($price['value'] ?? 0) === $amountCents) {
            return;
        }

        $r = $this->http()->post('/v1/billing/plans/' . rawurlencode((string) $sub->json('plan_id')) . '/update-pricing-schemes', [
            'pricing_schemes' => [[
                'billing_cycle_sequence' => 1,
                'pricing_scheme' => ['fixed_price' => ['value' => $this->amount($amountCents), 'currency_code' => $price['currency_code']]],
            ]],
        ]);
        if (!$r->successful()) {
            throw new \RuntimeException($this->errorMessage($r));
        }
    }

    /** Called when the customer comes back from PayPal: captures the approved order right away. Null when it is not applicable. */
    public function captureOrder(string $orderId): ?array
    {
        return $this->reconcile('CHECKOUT.ORDER.APPROVED', $orderId);
    }

    public function testConnection(): array
    {
        try {
            $token = $this->token();
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }

        return $token
            ? ['ok' => true, 'account' => 'PayPal (' . ($this->config->isSandbox() ? 'sandbox' : 'live') . ')', 'error' => null]
            : ['ok' => false, 'account' => null, 'error' => __('PayPal rejected the Client ID / secret.')];
    }

    // ---- webhook ------------------------------------------------------------------------------------

    /** PayPal verifies its own signature: we send it the headers + body together with our Webhook ID. */
    public function verifyWebhook(Request $request): bool
    {
        $webhookId = (string) $this->config->webhook_secret;
        $event = json_decode($request->getContent());
        if ($webhookId === '' || !is_object($event)) {
            return false;
        }

        try {
            $r = $this->http()->post('/v1/notifications/verify-webhook-signature', [
                'auth_algo' => (string) $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => (string) $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => (string) $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $event,
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        return $r->successful() && $r->json('verification_status') === 'SUCCESS';
    }

    private const EVENTS = [
        'CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.SALE.COMPLETED',
        'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.SALE.REFUNDED', 'CUSTOMER.DISPUTE.CREATED',
    ];

    public function webhookEvents(Request $request): array
    {
        $type = (string) $request->input('event_type', '');
        $id = (string) ($type === 'CUSTOMER.DISPUTE.CREATED' ? ($request->input('resource.dispute_id') ?: $request->input('resource.id')) : $request->input('resource.id', ''));

        return in_array($type, self::EVENTS, true) && $id !== '' ? [['type' => $type, 'id' => $id]] : [];
    }

    public function reconcile(string $type, string $id): ?array
    {
        try {
            return match ($type) {
                'CHECKOUT.ORDER.APPROVED' => $this->fromOrder($id),
                'PAYMENT.CAPTURE.COMPLETED' => $this->get('/v2/payments/captures/' . rawurlencode($id), fn ($c) => $this->fromCapture($c)),
                'PAYMENT.SALE.COMPLETED' => $this->get('/v1/payments/sale/' . rawurlencode($id), fn ($s) => $this->fromSale($s)),
                'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.EXPIRED'
                    => $this->get('/v1/billing/subscriptions/' . rawurlencode($id), fn ($s) => $this->fromSubscription($s, 'recurrence_canceled', ['CANCELLED', 'SUSPENDED', 'EXPIRED'])),
                'BILLING.SUBSCRIPTION.PAYMENT.FAILED'
                    => $this->get('/v1/billing/subscriptions/' . rawurlencode($id), fn ($s) => $this->fromSubscription($s, 'payment_failed', null)),
                'PAYMENT.CAPTURE.REFUNDED' => $this->fromRefund('/v2/payments/refunds/' . rawurlencode($id)),
                'PAYMENT.SALE.REFUNDED' => $this->get('/v1/payments/refund/' . rawurlencode($id), fn ($r) => $this->fromSaleRefund($r)),
                'CUSTOMER.DISPUTE.CREATED' => $this->get('/v1/customer/disputes/' . rawurlencode($id), fn ($d) => $this->fromDispute($d)),
                default => [],
            };
        } catch (\Throwable $e) {
            return null;   // credentials/network trouble: the job retries with backoff
        }
    }

    private function get(string $path, callable $map): ?array
    {
        $response = $this->http()->get($path);
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }

        return $response->successful() ? $map((array) $response->json()) : [];
    }

    private function row(string $status, string $gatewayId, array $extra = []): array
    {
        return $extra + [
            'status' => $status, 'gateway_id' => $gatewayId, 'invoice_number' => null, 'invoice_id' => null,
            'amount_cents' => null, 'currency' => null, 'payment_type' => 'credit_card',
            'event' => null, 'subscription_gateway_id' => null, 'customer_id' => null,
        ];
    }

    /** Approved order: capture it (idempotent on PayPal's side by the request id), then report the capture. */
    private function fromOrder(string $id): ?array
    {
        $order = $this->http()->get('/v2/checkout/orders/' . rawurlencode($id));
        if ($order->serverError() || $order->status() === 429) {
            return null;
        }
        if (!$order->successful()) {
            return [];
        }

        if ($order->json('status') === 'APPROVED') {
            $order = $this->http(['PayPal-Request-Id' => 'capture-' . $id])->post('/v2/checkout/orders/' . rawurlencode($id) . '/capture');
            if ($order->serverError() || $order->status() === 429) {
                return null;
            }
            if (!$order->successful()) {
                return [];
            }
        }

        $unit = (array) $order->json('purchase_units.0', []);
        $capture = (array) ($unit['payments']['captures'][0] ?? []);
        $paid = $order->json('status') === 'COMPLETED' && ($capture['status'] ?? '') === 'COMPLETED';

        return [$this->row($paid ? 'paid' : 'pending', (string) ($capture['id'] ?? $id), [
            'invoice_number' => ($unit['custom_id'] ?? null) ?: ($unit['reference_id'] ?? null),
            'amount_cents' => isset($capture['amount']['value']) ? $this->cents($capture['amount']['value']) : null,
            'currency' => strtoupper((string) ($capture['amount']['currency_code'] ?? '')),
        ])];
    }

    private function fromCapture(array $c): array
    {
        if (empty($c['custom_id'])) {
            return [];   // a subscription payment is reported by PAYMENT.SALE.COMPLETED
        }

        return [$this->row(($c['status'] ?? '') === 'COMPLETED' ? 'paid' : 'pending', (string) $c['id'], [
            'invoice_number' => $c['custom_id'],
            'amount_cents' => $this->cents($c['amount']['value'] ?? 0),
            'currency' => strtoupper((string) ($c['amount']['currency_code'] ?? '')),
        ])];
    }

    /** A charge PayPal made for a subscription (the first one and every renewal). */
    private function fromSale(array $s): array
    {
        if (empty($s['billing_agreement_id'])) {
            return [];
        }

        return [$this->row(($s['state'] ?? '') === 'completed' ? 'paid' : 'pending', (string) $s['id'], [
            'amount_cents' => $this->cents($s['amount']['total'] ?? 0),
            'currency' => strtoupper((string) ($s['amount']['currency'] ?? '')),
            'event' => 'renewal',
            'subscription_gateway_id' => (string) $s['billing_agreement_id'],
        ])];
    }

    private function fromSubscription(array $s, string $event, ?array $statuses): array
    {
        if ($statuses !== null && !in_array($s['status'] ?? '', $statuses, true)) {
            return [];
        }

        return [$this->row('pending', (string) $s['id'], ['event' => $event, 'subscription_gateway_id' => (string) $s['id']])];
    }

    private function fromRefund(string $path): ?array
    {
        $refund = $this->http()->get($path);
        if ($refund->serverError() || $refund->status() === 429) {
            return null;
        }
        if (!$refund->successful()) {
            return [];
        }

        $captureId = null;
        foreach ((array) $refund->json('links', []) as $link) {
            if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([^/?]+)#', (string) $link['href'], $m)) {
                $captureId = $m[1];
            }
        }

        return [$this->row('pending', (string) $refund->json('id'), [
            'event' => 'refunded', 'ref_id' => $captureId,
            'amount_cents' => $this->cents($refund->json('amount.value', 0)),
            'currency' => strtoupper((string) $refund->json('amount.currency_code', '')),
        ])];
    }

    private function fromSaleRefund(array $r): array
    {
        return [$this->row('pending', (string) $r['id'], [
            'event' => 'refunded', 'ref_id' => $r['sale_id'] ?? null,
            'amount_cents' => $this->cents($r['amount']['total'] ?? 0),
            'currency' => strtoupper((string) ($r['amount']['currency'] ?? '')),
        ])];
    }

    private function fromDispute(array $d): array
    {
        $tx = (array) ($d['disputed_transactions'][0] ?? []);

        return [$this->row('pending', (string) ($d['dispute_id'] ?? ''), [
            'event' => 'dispute', 'ref_id' => $tx['seller_transaction_id'] ?? null, 'reason' => $d['reason'] ?? null,
            'amount_cents' => $this->cents($d['dispute_amount']['value'] ?? 0),
            'currency' => strtoupper((string) ($d['dispute_amount']['currency_code'] ?? '')),
        ])];
    }
}
