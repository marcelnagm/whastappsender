<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use App\Models\Subscription;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mercado Pago (Brazil): Payments API (/v1/payments) for Pix and one-time card payments,
 * Subscriptions API (/preapproval) for card recurrence. Credentials come from Admin > Payment gateway.
 */
class MercadoPagoGateway implements PaymentGateway
{
    public const BASE_URL = 'https://api.mercadopago.com';

    public function __construct(private GatewayConfig $config)
    {
    }

    public function name(): string
    {
        return GatewayConfig::MERCADOPAGO;
    }

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
        return $this->config->recurrenceAllowed();
    }

    // ---- Charges ----------------------------------------------------------------------------------

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        $body = [
            'transaction_amount' => $this->money($invoice->amount_cents),
            'description' => mb_substr((string) $invoice->description, 0, 200) ?: 'Invoice ' . $invoice->number,
            'external_reference' => $invoice->number,
            'payer' => $this->payer($request),
        ];

        if ($url = $this->notificationUrl()) {
            $body['notification_url'] = $url;
        }
        if ($descriptor = $this->descriptor()) {
            $body['statement_descriptor'] = $descriptor;
        }

        if ($request->method === 'pix') {
            $minutes = max(30, min(43200, (int) $this->config->setting('pix_expiration_minutes')));
            $body['payment_method_id'] = 'pix';
            $body['date_of_expiration'] = now()->addMinutes($minutes)->format('Y-m-d\TH:i:s.vP');
            $attempt = (int) (($invoice->gateway_payload['pix_attempts'] ?? 0) + 1);
            $key = "inv-{$invoice->id}-pix-{$attempt}";
        } else {
            $body += [
                'token' => $request->cardToken,
                'installments' => 1,
                'payment_method_id' => $request->paymentMethodId,
                'capture' => true,
            ];
            if ($request->issuerId) {
                $body['issuer_id'] = (int) $request->issuerId;
            }
            $key = "inv-{$invoice->id}-card-" . sha1((string) $request->cardToken);
        }

        $response = $this->send('post', '/v1/payments', $body, $key);

        if (!$response->successful()) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        return $this->paymentResult($response->json());
    }

    public function subscribe(Subscription $subscription, Invoice $invoice, PayRequest $request): PaymentResult
    {
        $months = $invoice->cycle === 'yearly' ? 12 : 1;
        // A future first charge (end of trial) starts then; otherwise right away
        $start = ($invoice->due_at && $invoice->due_at->isFuture() ? $invoice->due_at : now()->addMinute());

        $body = [
            'reason' => mb_substr((string) $invoice->description, 0, 200) ?: 'Subscription',
            'external_reference' => 'sub-' . $subscription->id,
            'payer_email' => $request->email,
            'card_token_id' => $request->cardToken,
            'back_url' => route('billing.index'),
            'status' => 'authorized',
            'auto_recurring' => [
                'frequency' => $months,
                'frequency_type' => 'months',
                'start_date' => $start->format('Y-m-d\TH:i:s.vP'),
                'transaction_amount' => $this->money($invoice->amount_cents),
                'currency_id' => $invoice->currency ?: 'BRL',
            ],
        ];

        $response = $this->send('post', '/preapproval', $body, 'sub-' . $subscription->id . '-' . sha1((string) $request->cardToken));

        if (!$response->successful()) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        $data = $response->json();
        $status = (string) ($data['status'] ?? '');

        if (!in_array($status, ['authorized', 'pending'], true) || empty($data['id'])) {
            return new PaymentResult(PaymentResult::REJECTED, __('The card was not authorized for recurring charges.'));
        }

        // The first charge is confirmed by the webhook (or already happened, see PaymentService)
        return new PaymentResult(PaymentResult::PENDING, '', null, null, (string) $data['id'], 'credit_card');
    }

    public function cancelRecurring(string $recurringId): void
    {
        $this->expectOk($this->send('put', "/preapproval/{$recurringId}", ['status' => 'cancelled']));
    }

    public function updateRecurringAmount(string $recurringId, int $amountCents): void
    {
        $this->expectOk($this->send('put', "/preapproval/{$recurringId}", [
            'auto_recurring' => ['transaction_amount' => $this->money($amountCents), 'currency_id' => 'BRL'],
        ]));
    }

    // ---- Reads used by the webhook ------------------------------------------------------------------

    public function fetchPayment(string $id): ?array
    {
        return $this->fetch("/v1/payments/{$id}");
    }

    public function fetchPreapproval(string $id): ?array
    {
        return $this->fetch("/preapproval/{$id}");
    }

    public function fetchAuthorizedPayment(string $id): ?array
    {
        return $this->fetch("/authorized_payments/{$id}");
    }

    /** @return array{ok:bool,account:?array,error:?string} */
    public function testConnection(): array
    {
        try {
            $response = $this->send('get', '/users/me');
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'account' => null, 'error' => $this->errorMessage($response)];
        }

        $me = $response->json();

        return ['ok' => true, 'account' => [
            'id' => $me['id'] ?? null,
            'nickname' => $me['nickname'] ?? null,
            'email' => $me['email'] ?? null,
            'site' => $me['site_id'] ?? null,
        ], 'error' => null];
    }

    /**
     * x-signature: "ts=...,v1=<hmac>" where hmac = HMAC-SHA256(secret, "id:<data.id>;request-id:<x-request-id>;ts:<ts>;").
     * Parts that are missing are left out of the manifest. Returns true when no secret is configured
     * (the handler still re-fetches every resource from the API, which is the authoritative check).
     */
    public function verifySignature(Request $request): bool
    {
        $secret = (string) $this->config->webhook_secret;
        if ($secret === '') {
            return true;
        }

        $header = (string) $request->header('x-signature', '');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, null);
            if ($k !== null && $v !== null) {
                $parts[trim($k)] = trim($v);
            }
        }
        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }

        $dataId = $request->query('data.id', $request->input('data.id'));
        $manifest = '';
        if ($dataId !== null && $dataId !== '') {
            $manifest .= 'id:' . (ctype_alnum((string) $dataId) ? strtolower((string) $dataId) : $dataId) . ';';
        }
        if ($requestId = $request->header('x-request-id')) {
            $manifest .= 'request-id:' . $requestId . ';';
        }
        $manifest .= 'ts:' . $parts['ts'] . ';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($parts['v1']));
    }

    // ---- Internals ------------------------------------------------------------------------------------

    private function http(?string $idempotencyKey = null): PendingRequest
    {
        $http = Http::baseUrl(self::BASE_URL)
            ->withToken((string) $this->config->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        return $idempotencyKey ? $http->withHeaders(['X-Idempotency-Key' => $idempotencyKey]) : $http;
    }

    private function send(string $method, string $path, array $body = [], ?string $idempotencyKey = null): Response
    {
        $http = $this->http($idempotencyKey ?: ($method === 'get' ? null : (string) Str::uuid()));

        return $method === 'get' ? $http->get($path) : $http->{$method}($path, $body);
    }

    private function fetch(string $path): ?array
    {
        try {
            $response = $this->send('get', $path);
        } catch (\Throwable $e) {
            Log::warning("Mercado Pago GET {$path} failed: " . $e->getMessage());

            return null;
        }

        return $response->successful() ? (array) $response->json() : null;
    }

    private function expectOk(Response $response): void
    {
        if (!$response->successful()) {
            throw new \RuntimeException('Mercado Pago: ' . $this->errorMessage($response));
        }
    }

    private function payer(PayRequest $request): array
    {
        [$first, $last] = $request->nameParts();

        return [
            'email' => $request->email,
            'first_name' => $first,
            'last_name' => $last,
            'identification' => ['type' => $request->documentType(), 'number' => $request->document],
        ];
    }

    /** Built from APP_URL (not from the current request, which may be a local/proxy host). */
    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . route('webhooks.mercadopago', [], false);
    }

    private function notificationUrl(): ?string
    {
        $url = self::webhookUrl();

        // The gateway only accepts public https URLs
        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function descriptor(): ?string
    {
        $text = trim((string) $this->config->setting('statement_descriptor'));

        return $text !== '' ? mb_substr($text, 0, 22) : null;
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Maps a /v1/payments response to a PaymentResult. Also used when a payment is fetched later. */
    public function paymentResult(array $data): PaymentResult
    {
        $status = (string) ($data['status'] ?? '');
        $detail = (string) ($data['status_detail'] ?? '');
        $id = isset($data['id']) ? (string) $data['id'] : null;
        $type = $data['payment_type_id'] ?? null;

        $tx = $data['point_of_interaction']['transaction_data'] ?? null;
        $pix = $tx ? [
            'qr_code' => $tx['qr_code'] ?? null,
            'qr_base64' => $tx['qr_code_base64'] ?? null,
            'ticket_url' => $tx['ticket_url'] ?? null,
            'expires_at' => $data['date_of_expiration'] ?? null,
        ] : null;

        return match (true) {
            $status === 'approved' => new PaymentResult(PaymentResult::PAID, '', $id, $pix, null, $type),
            in_array($status, ['pending', 'in_process', 'authorized'], true) => new PaymentResult(
                PaymentResult::PENDING,
                $detail === 'pending_review_manual' || $status === 'in_process' ? __('Your payment is being analysed. We will confirm as soon as it is approved.') : '',
                $id, $pix, null, $type
            ),
            default => new PaymentResult(PaymentResult::REJECTED, $this->rejectionMessage($detail), $id, null, null, $type),
        };
    }

    private function rejectionMessage(string $detail): string
    {
        return match (true) {
            $detail === 'cc_rejected_insufficient_amount' => __('Insufficient limit on the card.'),
            $detail === 'cc_rejected_call_for_authorize' => __('Call your card issuer to authorize this payment, then try again.'),
            $detail === 'cc_rejected_card_disabled' => __('The card is disabled. Call your issuer or use another card.'),
            str_starts_with($detail, 'cc_rejected_bad_filled') => __('Check the card data and try again.'),
            $detail === 'cc_rejected_max_attempts' => __('Too many attempts. Use another card or try later.'),
            $detail === 'cc_rejected_duplicated_payment' => __('You already made a payment for this amount. Check your invoices.'),
            default => __('The payment was not approved. Try another card or pay with Pix.'),
        };
    }

    private function errorMessage(Response $response): string
    {
        $json = (array) $response->json();
        $cause = $json['cause'][0]['description'] ?? $json['message'] ?? null;

        Log::warning('Mercado Pago error', ['status' => $response->status(), 'message' => $json['message'] ?? null, 'cause' => $json['cause'] ?? null]);

        if ($response->status() === 401) {
            return __('Mercado Pago rejected the credentials (access token invalid or expired).');
        }

        return $cause ? (string) $cause : __('Could not reach the payment provider. Try again in a moment.');
    }
}
