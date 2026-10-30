<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stone — through Pagar.me, Stone's online gateway (https://docs.pagar.me, API v5). Orders carry our invoice number as `code`:
 * Pix is created in-app (QR code), the card goes through a hosted checkout (order with `checkouts`). Basic auth with the secret
 * key (sk_… live, sk_test_… test: the key decides the environment). Webhook: ?secret=… + the order is re-read with the key.
 */
class PagarMeGateway extends HostedGateway
{
    private const BASE = 'https://api.pagar.me/core/v5';

    public function name(): string
    {
        return GatewayConfig::PAGARME;
    }

    public function config(): GatewayConfig
    {
        return $this->config;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withBasicAuth((string) $this->config->access_token, '')->acceptJson()->asJson()->timeout(30);
    }

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        $pix = $request->method === 'pix';

        $order = [
            'code' => $invoice->number,
            'closed' => true,
            'customer' => [
                'name' => $request->name ?: 'Cliente',
                'email' => $request->email,
                'document' => $request->document,
                'document_type' => $request->documentType(),
                'type' => $request->documentType() === 'CNPJ' ? 'company' : 'individual',
            ],
            'items' => [[
                'amount' => (int) $invoice->amount_cents,
                'description' => mb_substr($this->description($invoice), 0, 256),
                'quantity' => 1,
                'code' => $invoice->number,
            ]],
        ];

        if ($pix) {
            $order['payments'] = [['payment_method' => 'pix', 'pix' => ['expires_in' => $this->pixMinutes() * 60]]];
        } else {
            $back = route('billing.invoice', $invoice);
            $order['checkouts'] = [[
                'expires_in' => 4320,  // minutes
                'default_payment_method' => 'credit_card',
                'accepted_payment_methods' => ['credit_card'],
                'success_url' => $back,
                'skip_checkout_success_page' => true,
                'customer_editable' => false,
            ]];
        }

        try {
            $response = $this->http()->post('/orders', $order);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }

        $id = (string) $response->json('id');
        if (!$response->successful() || $id === '') {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        if (!$pix) {
            $url = (string) $response->json('checkouts.0.payment_url');

            return $url !== ''
                ? new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), $id, null, null, 'credit_card', $url)
                : new PaymentResult(PaymentResult::ERROR, __('The payment provider did not return the payment page.'), $id);
        }

        $tx = (array) $response->json('charges.0.last_transaction', []);
        if (empty($tx['qr_code'])) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response), $id);
        }

        return new PaymentResult(PaymentResult::PENDING, '', $id, [
            'qr_code' => $tx['qr_code'],
            'qr_base64' => null,
            'qr_image_url' => $tx['qr_code_url'] ?? null,
            'ticket_url' => null,
            'expires_at' => $tx['expires_at'] ?? now()->addMinutes($this->pixMinutes())->toIso8601String(),
        ], null, 'bank_transfer');
    }

    public function testConnection(): array
    {
        try {
            $response = $this->http()->get('/orders', ['page' => 1, 'size' => 1]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }
        if (!$response->successful()) {
            return ['ok' => false, 'account' => null, 'error' => $this->errorMessage($response)];
        }

        return ['ok' => true, 'account' => 'Stone / Pagar.me (' . (str_starts_with((string) $this->config->access_token, 'sk_test') ? 'test' : 'live') . ' key)', 'error' => null];
    }

    // ---- webhook ------------------------------------------------------------------------------------

    public function verifyWebhook(Request $request): bool
    {
        return $this->secretFromQuery($request);
    }

    public function webhookEvents(Request $request): array
    {
        $type = (string) $request->input('type', '');

        $id = match ($type) {
            'order.paid' => (string) $request->input('data.id', ''),
            'charge.paid' => (string) $request->input('data.order.id', ''),
            default => '',
        };

        return str_starts_with($id, 'or_') ? [['type' => $type, 'id' => $id]] : [];
    }

    public function reconcile(string $type, string $id): ?array
    {
        try {
            $response = $this->http()->get('/orders/' . rawurlencode($id));
        } catch (\Throwable $e) {
            return null;
        }
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        $o = (array) $response->json();
        $paid = ($o['status'] ?? '') === 'paid';
        $method = (string) ($o['charges'][0]['payment_method'] ?? '');

        return [[
            'status' => $paid ? 'paid' : 'pending',
            'gateway_id' => (string) ($o['id'] ?? $id),
            'invoice_number' => ($o['code'] ?? null) ?: null,
            'invoice_id' => null,
            'amount_cents' => $paid ? (int) array_sum(array_map(fn ($c) => ($c['status'] ?? '') === 'paid' ? (int) ($c['paid_amount'] ?? $c['amount'] ?? 0) : 0, (array) ($o['charges'] ?? []))) : null,
            'payment_type' => $method === 'pix' ? 'bank_transfer' : 'credit_card',
        ]];
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'errors' => $response->json('errors'), 'message' => $response->json('message')]);

        if ($response->status() === 401) {
            return __('Pagar.me rejected the secret key.');
        }

        $first = collect((array) $response->json('errors', []))->flatten()->first();

        return (string) ($first ?: $response->json('message') ?: __('Could not reach the payment provider. Try again in a moment.'));
    }
}
