<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\PaymentGateway as GatewayConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Efí (https://dev.efipay.com.br). Two APIs share one application (client id + secret):
 *  - Pix API (pix.api.efipay.com.br): immediate charges (cob). Every call needs the account's mTLS certificate (.pem path in settings).
 *  - Cobranças API (cobrancas.api.efipay.com.br): card via one-step payment link (hosted page); status arrives as a
 *    notification token that is exchanged for the charge data.
 * Columns: public_key = client id, access_token = client secret.
 */
class EfiGateway extends HostedGateway
{
    public function name(): string
    {
        return GatewayConfig::EFI;
    }

    private function pixBase(): string
    {
        return $this->config->isSandbox() ? 'https://pix-h.api.efipay.com.br' : 'https://pix.api.efipay.com.br';
    }

    private function chargesBase(): string
    {
        return $this->config->isSandbox() ? 'https://cobrancas-h.api.efipay.com.br' : 'https://cobrancas.api.efipay.com.br';
    }

    private function certificate(): ?string
    {
        $path = trim((string) $this->config->setting('certificate_path'));

        return $path !== '' && is_readable($path) ? $path : null;
    }

    private function token(string $api): string
    {
        $key = "efi-token-{$api}-" . sha1($this->config->public_key . ($this->config->isSandbox() ? 's' : 'p'));

        return Cache::remember($key, 3000, function () use ($api) {
            $http = Http::withBasicAuth((string) $this->config->public_key, (string) $this->config->access_token)->acceptJson()->asJson()->timeout(30);
            if ($api === 'pix') {
                $cert = $this->certificate();
                if (!$cert) {
                    throw new \RuntimeException(__('The Efí certificate file was not found on the server.'));
                }
                $response = $http->withOptions(['cert' => $cert])->post($this->pixBase() . '/oauth/token', ['grant_type' => 'client_credentials']);
            } else {
                $response = $http->post($this->chargesBase() . '/v1/authorize', ['grant_type' => 'client_credentials']);
            }
            if (!$response->successful() || !$response->json('access_token')) {
                throw new \RuntimeException('Efí authorization failed (HTTP ' . $response->status() . ')');
            }

            return (string) $response->json('access_token');
        });
    }

    private function pix(): PendingRequest
    {
        return Http::baseUrl($this->pixBase())->withToken($this->token('pix'))->withOptions(['cert' => $this->certificate()])
            ->acceptJson()->asJson()->timeout(30);
    }

    private function charges(): PendingRequest
    {
        return Http::baseUrl($this->chargesBase())->withToken($this->token('charges'))->acceptJson()->asJson()->timeout(30);
    }

    public function charge(Invoice $invoice, PayRequest $request): PaymentResult
    {
        try {
            return $request->method === 'pix' ? $this->chargePix($invoice, $request) : $this->chargeCard($invoice);
        } catch (\Throwable $e) {
            $this->warn('charge failed: ' . $e->getMessage());

            return new PaymentResult(PaymentResult::ERROR, __('Could not reach the payment provider. Try again in a moment.'));
        }
    }

    private function chargePix(Invoice $invoice, PayRequest $request): PaymentResult
    {
        // txid: 26-35 chars [a-zA-Z0-9]. Carries the invoice id so a notification maps back to the invoice
        $txid = 'sama' . str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT) . bin2hex(random_bytes(8));

        $debtor = ['nome' => mb_substr($request->name ?: 'Cliente', 0, 200)];
        $debtor[$request->documentType() === 'CNPJ' ? 'cnpj' : 'cpf'] = $request->document;

        $response = $this->pix()->put("/v2/cob/{$txid}", [
            'calendario' => ['expiracao' => $this->pixMinutes() * 60],
            'devedor' => $debtor,
            'valor' => ['original' => number_format($invoice->amount_cents / 100, 2, '.', '')],
            'chave' => (string) $this->config->setting('pix_key'),
            'solicitacaoPagador' => mb_substr('Fatura ' . $invoice->number, 0, 140),
        ]);

        if (!$response->successful()) {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        $code = (string) $response->json('pixCopiaECola');
        $locId = $response->json('loc.id');
        if ($code === '') {
            return new PaymentResult(PaymentResult::ERROR, __('The payment provider returned an unexpected answer.'));
        }

        $qrImage = null;
        if ($locId) {
            $qr = $this->pix()->get("/v2/loc/{$locId}/qrcode");
            $qrImage = $qr->successful() ? self::stripDataUri($qr->json('imagemQrcode')) : null;
        }

        return new PaymentResult(PaymentResult::PENDING, '', $txid, [
            'qr_code' => $code,
            'qr_base64' => $qrImage,
            'ticket_url' => null,
            'expires_at' => now()->addMinutes($this->pixMinutes())->toIso8601String(),
        ], null, 'bank_transfer');
    }

    private function chargeCard(Invoice $invoice): PaymentResult
    {
        $metadata = ['custom_id' => $invoice->number];
        if ($url = $this->notificationUrl(true)) {
            $metadata['notification_url'] = $url;
        }

        $response = $this->charges()->post('/v1/charge/one-step/link', [
            'items' => [['name' => mb_substr($this->description($invoice), 0, 255), 'value' => (int) $invoice->amount_cents, 'amount' => 1]],
            'metadata' => $metadata,
            'settings' => [
                'payment_method' => 'credit_card',
                'expire_at' => now('America/Sao_Paulo')->addDays(3)->format('Y-m-d'),
                'request_delivery_address' => false,
                'message' => mb_substr('Fatura ' . $invoice->number, 0, 80),
            ],
        ]);

        $url = (string) $response->json('data.payment_url');
        if (!$response->successful() || $url === '') {
            return new PaymentResult(PaymentResult::ERROR, $this->errorMessage($response));
        }

        return new PaymentResult(PaymentResult::PENDING, __('Redirecting to the secure card payment page...'), (string) $response->json('data.charge_id'), null, null, 'credit_card', $url);
    }

    public function testConnection(): array
    {
        try {
            $this->token('charges');
            $account = 'Efí';
            if (in_array('pix', $this->methods(), true)) {
                $this->token('pix'); // proves the certificate and the Pix scopes
                $account .= ' (Pix + ' . __('card') . ')';
            } else {
                $account .= ' (' . __('card only: fill in the certificate and Pix key to enable Pix') . ')';
            }

            return ['ok' => true, 'account' => $account, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'error' => $e->getMessage()];
        }
    }

    /** Points Efí's Pix notifications at our endpoint (Efí appends /pix; the secret goes in the query string). */
    public function registerPixWebhook(): array
    {
        $url = $this->notificationUrl(true);
        if (!$url) {
            return ['ok' => false, 'error' => __('APP_URL must be a public https address.')];
        }

        try {
            $response = $this->pix()->withHeaders(['x-skip-mtls-checking' => 'true'])
                ->put('/v2/webhook/' . rawurlencode((string) $this->config->setting('pix_key')), ['webhookUrl' => $url]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return $response->successful() ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => $this->errorMessage($response)];
    }

    // ---- webhook ------------------------------------------------------------------------------------

    public function verifyWebhook(Request $request): bool
    {
        return $this->secretFromQuery($request);
    }

    public function webhookEvents(Request $request): array
    {
        $events = [];

        // Pix: {"pix":[{"endToEndId":"E..","txid":"..","valor":"129.00"}]}
        foreach ((array) $request->input('pix', []) as $item) {
            if (!empty($item['txid'])) {
                $events[] = ['type' => 'pix', 'id' => (string) $item['txid']];
            }
        }

        // Cobranças: only a notification token
        if ($token = (string) $request->input('notification', '')) {
            $events[] = ['type' => 'notification', 'id' => $token];
        }

        return $events;
    }

    public function reconcile(string $type, string $id): ?array
    {
        try {
            return $type === 'pix' ? $this->reconcilePix($id) : $this->reconcileNotification($id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function reconcilePix(string $txid): ?array
    {
        $response = $this->pix()->get('/v2/cob/' . rawurlencode($txid));
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        $invoiceId = preg_match('/^sama(\d{8})/', $txid, $m) ? (int) $m[1] : null;

        return [[
            'status' => $response->json('status') === 'CONCLUIDA' ? 'paid' : 'pending',
            'gateway_id' => $txid,
            'invoice_number' => null,
            'invoice_id' => $invoiceId,
            'amount_cents' => $response->json('valor.original') !== null ? $this->cents($response->json('valor.original')) : null,
            'payment_type' => 'bank_transfer',
        ]];
    }

    private function reconcileNotification(string $token): ?array
    {
        $response = $this->charges()->get('/v1/notification/' . rawurlencode($token));
        if ($response->serverError() || $response->status() === 429) {
            return null;
        }
        if (!$response->successful()) {
            return [];
        }

        $out = [];
        foreach ((array) $response->json('data', []) as $change) {
            $current = (string) ($change['status']['current'] ?? '');
            if (!in_array($current, ['paid', 'settled'], true)) {
                continue;
            }
            $out[] = [
                'status' => 'paid',
                'gateway_id' => (string) ($change['identifiers']['charge_id'] ?? $token),
                'invoice_number' => ($change['custom_id'] ?? null) ?: null,
                'invoice_id' => null,
                'amount_cents' => isset($change['value']) ? (int) $change['value'] : null,   // already in cents
                'payment_type' => 'credit_card',
            ];
        }

        return $out;
    }

    private function errorMessage(Response $response): string
    {
        $this->warn('error', ['status' => $response->status(), 'body' => Str::limit((string) $response->body(), 300)]);

        if (in_array($response->status(), [401, 403], true)) {
            return __('Efí rejected the credentials (client id/secret, certificate or permissions).');
        }

        return (string) ($response->json('mensagem') ?: $response->json('error_description') ?: __('Could not reach the payment provider. Try again in a moment.'));
    }
}
