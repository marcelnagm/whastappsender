<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Brasil (https://docs.stripe.com): Checkout Session (hosted page) for card and Pix (Stripe shows the Pix QR on its page,
 * so both methods redirect). Columns: access_token = secret key (sk_live_… / sk_test_…), webhook_secret = signing secret (whsec_…).
 * Webhook: Stripe-Signature (HMAC-SHA256 of "t.body"); the session is then re-read with the secret key.
 */
class StripeGateway extends HostedGateway
{
    private const BASE = 'https://api.stripe.com/v1';

    public function name(): string
    {
        return GatewayConfig::STRIPE;
    }

    /** Pix is paid on Stripe's page, so there is no QR code to email for a renewal: automatic renewal is not offered. */
    public function supportsRecurrence(): bool
    {
        return false;
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
                    'currency' => 'brl',
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

    public function webhookEvents(Request $request): array
    {
        $type = (string) $request->input('type', '');
        $id = (string) $request->input('data.object.id', '');

        return in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && str_starts_with($id, 'cs_')
            ? [['type' => $type, 'id' => $id]] : [];
    }

    public function reconcile(string $type, string $id): ?array
    {
        try {
            $response = $this->http()->get('/checkout/sessions/' . rawurlencode($id));
        } catch (\Throwable $e) {
            return null;
        }
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        $s = (array) $response->json();
        $pix = in_array('pix', (array) ($s['payment_method_types'] ?? []), true) && !in_array('card', (array) ($s['payment_method_types'] ?? []), true);

        return [[
            'status' => ($s['payment_status'] ?? '') === 'paid' ? 'paid' : 'pending',
            'gateway_id' => (string) ($s['id'] ?? $id),
            'invoice_number' => ($s['client_reference_id'] ?? null) ?: null,
            'invoice_id' => null,
            'amount_cents' => isset($s['amount_total']) && ($s['currency'] ?? 'brl') === 'brl' ? (int) $s['amount_total'] : 0, // other currency never covers the invoice
            'payment_type' => $pix ? 'bank_transfer' : 'credit_card',
        ]];
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
