<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * AbacatePay (https://docs.abacatepay.com), API v1. Pix: /pixQrCode/create. Card: /billing/create returns a hosted
 * checkout url (CARD). Webhook: the URL carries ?secret=… (the same value stored here). Status is always re-read with the API key.
 */
class AbacatePayGateway extends HostedGateway
{
    private const BASE = 'https://api.abacatepay.com/v1';

    public function name(): string
    {
        return GatewayConfig::ABACATEPAY;
    }

    private function http(): PendingRequest
    {
        // AbacatePay has one environment: a "dev mode" key creates test charges
        return Http::baseUrl(self::BASE)->withToken((string) $this->config->access_token)->acceptJson()->asJson()->timeout(30);
    }

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        try {
            return $request->method === 'pix' ? $this->chargePix($invoice) : $this->chargeCard($invoice);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }
    }

    private function chargePix(Invoice $invoice): PaymentResult
    {
        $response = $this->http()->post('/pixQrCode/create', [
            'amount' => (int) $invoice->amount_cents,
            'expiresIn' => $this->pixMinutes() * 60,
            'description' => mb_substr('Fatura ' . $invoice->number, 0, 37),
            'metadata' => ['externalId' => $invoice->number],
        ]);

        $data = (array) $response->json('data', []);
        if (!$response->successful() || empty($data['id']) || empty($data['brCode'])) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        return new PaymentResult(PaymentResult::PENDING, '', (string) $data['id'], [
            'qr_code' => $data['brCode'],
            'qr_base64' => self::stripDataUri($data['brCodeBase64'] ?? null),
            'ticket_url' => null,
            'expires_at' => $data['expiresAt'] ?? now()->addMinutes($this->pixMinutes())->toIso8601String(),
        ], null, 'bank_transfer');
    }

    private function chargeCard(Invoice $invoice): PaymentResult
    {
        $back = route('billing.invoice', $invoice);

        $response = $this->http()->post('/billing/create', [
            'frequency' => 'ONE_TIME',
            'methods' => ['CARD'],
            'products' => [[
                'externalId' => $invoice->number,
                'name' => mb_substr($this->description($invoice), 0, 120),
                'quantity' => 1,
                'price' => max(100, (int) $invoice->amount_cents),   // AbacatePay minimum: R$ 1,00
            ]],
            'externalId' => $invoice->number,
            'returnUrl' => $back,
            'completionUrl' => $back,
        ]);

        $data = (array) $response->json('data', []);
        if (!$response->successful() || empty($data['url'])) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), (string) ($data['id'] ?? ''), null, null, 'credit_card', (string) $data['url']);
    }

    public function testConnection(): array
    {
        try {
            $response = $this->http()->get('/store/get');
            if (!$response->successful()) {
                return ['ok' => false, 'account' => null, 'error' => $this->errorMessage($response)];
            }

            return ['ok' => true, 'account' => (string) ($response->json('data.name') ?: 'AbacatePay'), 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }
    }

    // ---- webhook ------------------------------------------------------------------------------------

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) $this->config->webhook_secret;
        $given = (string) ($request->query('secret') ?? $request->query('webhookSecret') ?? '');

        return $secret === '' || hash_equals($secret, $given);
    }

    public function webhookEvents(Request $request): array
    {
        $event = (string) $request->input('event', '');
        if (!preg_match('/paid|completed/i', $event)) {
            return [];
        }

        // the resource id lives under a key named after the resource
        foreach (['data.pixQrCode.id', 'data.transparent.id', 'data.billing.id', 'data.id'] as $path) {
            if ($id = (string) $request->input($path, '')) {
                return [['type' => $event, 'id' => $id]];
            }
        }

        return [];
    }

    public function reconcile(string $type, string $id): ?array
    {
        try {
            return str_starts_with($id, 'bill_') ? $this->reconcileBilling($id) : $this->reconcilePix($id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function reconcilePix(string $id): ?array
    {
        $response = $this->http()->get('/pixQrCode/check', ['id' => $id]);
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        return [[
            'status' => $response->json('data.status') === 'PAID' ? 'paid' : 'pending',
            'gateway_id' => $id,
            'invoice_number' => null,   // found through the stored charge id
            'invoice_id' => null,
            'amount_cents' => null,     // the QR code was created with the exact amount
            'payment_type' => 'bank_transfer',
        ]];
    }

    private function reconcileBilling(string $id): ?array
    {
        $response = $this->http()->get('/billing/list');
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        foreach ((array) $response->json('data', []) as $billing) {
            if (($billing['id'] ?? null) !== $id) {
                continue;
            }
            $products = (array) ($billing['products'] ?? []);
            $total = array_sum(array_map(fn ($p) => (int) ($p['price'] ?? 0) * (int) ($p['quantity'] ?? 1), $products));
            $number = ($products[0]['externalId'] ?? null) ?: ($billing['externalId'] ?? null);

            return [[
                'status' => ($billing['status'] ?? '') === 'PAID' ? 'paid' : 'pending',
                'gateway_id' => $id,
                'invoice_number' => $number ?: null,
                'invoice_id' => null,
                'amount_cents' => $total ?: null,
                'payment_type' => 'credit_card',
            ]];
        }

        return [];
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'error' => $response->json('error')]);

        if ($response->status() === 401) {
            return __('AbacatePay rejected the API key.');
        }

        $detail = $response->json('error');

        return is_string($detail) && $detail !== '' ? $detail : __('Could not reach the payment provider. Try again in a moment.');
    }
}
