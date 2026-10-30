<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PagBank (https://developer.pagbank.com.br): Pix through POST /orders with a QR code (shown in-app), card through the hosted
 * Checkout (POST /checkouts). Bearer token; sandbox flag switches to sandbox.api.pagseguro.com. reference_id = our invoice number.
 * Webhook: ?secret=… in notification_urls + the order is re-read with the token.
 */
class PagBankGateway extends HostedGateway
{
    public function name(): string
    {
        return GatewayConfig::PAGBANK;
    }

    private function base(): string
    {
        return $this->config->isSandbox() ? 'https://sandbox.api.pagseguro.com' : 'https://api.pagseguro.com';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->base())->withToken((string) $this->config->access_token)->acceptJson()->asJson()->timeout(30);
    }

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        try {
            return $request->method === 'pix' ? $this->chargePix($invoice, $request) : $this->chargeCard($invoice, $request);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }
    }

    private function customer(PayRequest $request): array
    {
        return ['name' => $request->name ?: 'Cliente', 'email' => $request->email, 'tax_id' => $request->document];
    }

    private function chargePix(Invoice $invoice, PayRequest $request): PaymentResult
    {
        $expires = now('America/Sao_Paulo')->addMinutes($this->pixMinutes());

        $body = [
            'reference_id' => $invoice->number,
            'customer' => $this->customer($request),
            'items' => [['name' => mb_substr($this->description($invoice), 0, 100), 'quantity' => 1, 'unit_amount' => (int) $invoice->amount_cents]],
            'qr_codes' => [['amount' => ['value' => (int) $invoice->amount_cents], 'expiration_date' => $expires->format('Y-m-d\TH:i:sP')]],
        ];
        if ($url = $this->notificationUrl(true)) {
            $body['notification_urls'] = [$url];
        }

        $response = $this->http()->post('/orders', $body);
        $id = (string) $response->json('id');
        $code = (string) $response->json('qr_codes.0.text');
        if (!$response->successful() || $id === '' || $code === '') {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        $image = collect((array) $response->json('qr_codes.0.links', []))->firstWhere('media', 'image/png')['href'] ?? null;

        return new PaymentResult(PaymentResult::PENDING, '', $id, [
            'qr_code' => $code,
            'qr_base64' => null,
            'qr_image_url' => $image,
            'ticket_url' => null,
            'expires_at' => $response->json('qr_codes.0.expiration_date') ?? $expires->toIso8601String(),
        ], null, 'bank_transfer');
    }

    private function chargeCard(Invoice $invoice, PayRequest $request): PaymentResult
    {
        $back = route('billing.invoice', $invoice);

        $body = [
            'reference_id' => $invoice->number,
            'customer' => $this->customer($request),
            'customer_modifiable' => false,
            'items' => [['reference_id' => $invoice->number, 'name' => mb_substr($this->description($invoice), 0, 100), 'quantity' => 1, 'unit_amount' => (int) $invoice->amount_cents]],
            'payment_methods' => [['type' => 'CREDIT_CARD']],
            'redirect_url' => $back,
            'expiration_date' => now('America/Sao_Paulo')->addDays(3)->format('Y-m-d\TH:i:sP'),
        ];
        if ($url = $this->notificationUrl(true)) {
            $body['notification_urls'] = [$url];
            $body['payment_notification_urls'] = [$url];
        }

        $response = $this->http()->post('/checkouts', $body);

        $link = collect((array) $response->json('links', []))->first(fn ($l) => strtoupper((string) ($l['rel'] ?? '')) === 'PAY')['href'] ?? '';
        if (!$response->successful() || $link === '') {
            return new PaymentResult(PaymentResult::ERROR, $response->successful() ? __('The payment provider did not return the payment page.') : $this->errorMessage($response));
        }

        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), (string) $response->json('id'), null, null, 'credit_card', $link);
    }

    public function testConnection(): array
    {
        try {
            // an order id that cannot exist: 401/403 = bad token, anything else means the token was accepted
            $response = $this->http()->get('/orders/ORDE_00000000-0000-0000-0000-000000000000');
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }
        if (in_array($response->status(), [401, 403], true)) {
            return ['ok' => false, 'account' => null, 'error' => $this->errorMessage($response)];
        }
        if ($response->serverError()) {
            return ['ok' => false, 'account' => null, 'error' => __('Could not reach the payment provider. Try again in a moment.')];
        }

        return ['ok' => true, 'account' => 'PagBank', 'error' => null];
    }

    // ---- webhook ------------------------------------------------------------------------------------

    public function verifyWebhook(Request $request): bool
    {
        return $this->secretFromQuery($request);
    }

    public function webhookEvents(Request $request): array
    {
        $id = (string) $request->input('id', '');
        $anyPaid = collect((array) $request->input('charges', []))->contains(fn ($c) => strtoupper((string) ($c['status'] ?? '')) === 'PAID');

        return $anyPaid && str_starts_with($id, 'ORDE_') ? [['type' => 'order', 'id' => $id]] : [];
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
        $paidCharges = array_values(array_filter((array) ($o['charges'] ?? []), fn ($c) => strtoupper((string) ($c['status'] ?? '')) === 'PAID'));
        $method = strtoupper((string) ($paidCharges[0]['payment_method']['type'] ?? ''));

        return [[
            'status' => $paidCharges ? 'paid' : 'pending',
            'gateway_id' => (string) ($o['id'] ?? $id),
            'invoice_number' => ($o['reference_id'] ?? null) ?: null,
            'invoice_id' => null,
            'amount_cents' => $paidCharges ? (int) array_sum(array_map(fn ($c) => (int) ($c['amount']['value'] ?? 0), $paidCharges)) : null,
            'payment_type' => $method === 'PIX' ? 'bank_transfer' : 'credit_card',
        ]];
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'errors' => $response->json('error_messages')]);

        if (in_array($response->status(), [401, 403], true)) {
            return __('PagBank rejected the token.');
        }

        return (string) ($response->json('error_messages.0.description') ?: __('Could not reach the payment provider. Try again in a moment.'));
    }
}
