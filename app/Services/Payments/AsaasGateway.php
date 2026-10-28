<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Asaas (https://docs.asaas.com): customer + payment (/v3/payments). Pix returns a QR code (/pixQrCode);
 * the card is paid on the payment's hosted invoiceUrl. Webhook: header asaas-access-token = the auth token set in Asaas.
 */
class AsaasGateway extends HostedGateway
{
    public function name(): string
    {
        return GatewayConfig::ASAAS;
    }

    private function baseUrl(): string
    {
        return $this->config->isSandbox() ? 'https://api-sandbox.asaas.com/v3' : 'https://api.asaas.com/v3';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['access_token' => (string) $this->config->access_token, 'User-Agent' => 'SAMA-Envios'])
            ->acceptJson()->asJson()->timeout(30);
    }

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        try {
            $customerId = $this->customerId($request);
            if (!$customerId) {
                return new PaymentResult(PaymentResult::ERROR, __('Could not register the payer at the payment provider. Check the CPF/CNPJ and try again.'));
            }

            $pix = $request->method === 'pix';
            $days = $pix ? max(1, (int) ceil($this->pixMinutes() / 1440)) : 3;

            $response = $this->http()->post('/payments', [
                'customer' => $customerId,
                'billingType' => $pix ? 'PIX' : 'CREDIT_CARD',
                'value' => $this->money($invoice->amount_cents),
                'dueDate' => now('America/Sao_Paulo')->addDays($days)->format('Y-m-d'),
                'description' => $this->description($invoice),
                'externalReference' => $invoice->number,
            ]);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }

        if (!$response->successful()) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        $payment = (array) $response->json();
        $id = (string) ($payment['id'] ?? '');
        if ($id === '') {
            return new PaymentResult(PaymentResult::ERROR, __('The payment provider returned an unexpected answer.'));
        }

        if (!$pix) {
            $url = (string) ($payment['invoiceUrl'] ?? '');

            return $url !== ''
                ? new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), $id, null, null, 'credit_card', $url)
                : new PaymentResult(PaymentResult::ERROR, __('The payment provider did not return the payment page.'), $id);
        }

        $qr = $this->http()->get("/payments/{$id}/pixQrCode");
        if (!$qr->successful() || empty($qr->json('payload'))) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($qr), $id);
        }

        return new PaymentResult(PaymentResult::PENDING, '', $id, [
            'qr_code' => $qr->json('payload'),
            'qr_base64' => $qr->json('encodedImage'),
            'ticket_url' => $payment['invoiceUrl'] ?? null,
            'expires_at' => $this->expiry($qr->json('expirationDate')),
        ], null, 'bank_transfer');
    }

    /** Finds the customer by CPF/CNPJ or creates it. */
    private function customerId(PayRequest $request): ?string
    {
        $found = $this->http()->get('/customers', ['cpfCnpj' => $request->document, 'limit' => 1]);
        if ($found->successful() && ($id = $found->json('data.0.id'))) {
            return (string) $id;
        }

        $created = $this->http()->post('/customers', [
            'name' => $request->name ?: 'Cliente',
            'email' => $request->email,
            'cpfCnpj' => $request->document,
            'notificationDisabled' => true, // our own emails; Asaas would also email/SMS the customer
        ]);

        return $created->successful() && $created->json('id') ? (string) $created->json('id') : null;
    }

    public function testConnection(): array
    {
        try {
            $response = $this->http()->get('/myAccount/commercialInfo');
            if (!$response->successful()) {
                $response = $this->http()->get('/finance/balance'); // older accounts / restricted keys
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'account' => null, 'error' => $this->errorMessage($response)];
        }

        return ['ok' => true, 'account' => (string) ($response->json('companyName') ?? $response->json('name') ?? 'Asaas'), 'error' => null];
    }

    // ---- webhook ------------------------------------------------------------------------------------

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) $this->config->webhook_secret;

        return $token === '' || hash_equals($token, (string) $request->header('asaas-access-token', ''));
    }

    public function webhookEvents(Request $request): array
    {
        $event = (string) $request->input('event', '');
        $id = (string) $request->input('payment.id', '');

        return in_array($event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true) && $id !== '' ? [['type' => $event, 'id' => $id]] : [];
    }

    public function reconcile(string $type, string $id): ?array
    {
        try {
            $response = $this->http()->get('/payments/' . rawurlencode($id));
        } catch (\Throwable $e) {
            return null;
        }
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        $p = (array) $response->json();
        $paid = in_array($p['status'] ?? '', ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'], true);

        return [[
            'status' => $paid ? 'paid' : 'pending',
            'gateway_id' => (string) ($p['id'] ?? $id),
            'invoice_number' => ($p['externalReference'] ?? null) ?: null,
            'invoice_id' => null,
            'amount_cents' => isset($p['value']) ? $this->cents($p['value']) : null,
            'payment_type' => ($p['billingType'] ?? '') === 'PIX' ? 'bank_transfer' : 'credit_card',
        ]];
    }

    // ---- helpers ------------------------------------------------------------------------------------

    private function expiry(?string $date): ?string
    {
        try {
            return $date ? \Carbon\Carbon::parse($date, 'America/Sao_Paulo')->toIso8601String() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'errors' => $response->json('errors')]);

        if ($response->status() === 401) {
            return __('Asaas rejected the credentials (API key invalid or for another environment).');
        }

        return (string) ($response->json('errors.0.description') ?: __('Could not reach the payment provider. Try again in a moment.'));
    }
}
