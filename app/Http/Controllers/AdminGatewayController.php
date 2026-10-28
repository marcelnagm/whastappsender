<?php

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use App\Services\Payments\EfiGateway;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\HostedGateway;
use App\Services\Payments\MercadoPagoGateway;
use Illuminate\Http\Request;

/**
 * Admin > Payment gateway: one tab per provider (Asaas, Efí, AbacatePay, Mercado Pago). Several can be enabled at once and
 * the customer chooses at checkout. Secrets are never sent back to the browser. The provider is the `provider` field/query
 * (default: mercadopago, as before).
 */
class AdminGatewayController extends Controller
{
    private function provider(Request $request): string
    {
        $provider = (string) ($request->input('provider') ?? $request->query('provider') ?? PaymentGateway::MERCADOPAGO);

        return in_array($provider, PaymentGateway::PROVIDERS, true) ? $provider : abort(404);
    }

    private function back(string $provider)
    {
        return redirect()->route('admin.gateway.edit', $provider === PaymentGateway::MERCADOPAGO ? [] : ['provider' => $provider]);
    }

    public function edit(Request $request)
    {
        $provider = $this->provider($request);
        $gateway = PaymentGateway::for($provider)->fresh();

        $tabs = [];
        foreach (PaymentGateway::PROVIDERS as $p) {
            $c = PaymentGateway::for($p);
            $tabs[$p] = ['label' => config("payments.providers.{$p}.label"), 'ready' => $c->isReady() && $c->methods() !== []];
        }

        return view('admin.gateway.edit', [
            'provider' => $provider,
            'info' => config("payments.providers.{$provider}"),
            'tabs' => $tabs,
            'gateway' => $gateway,
            'maskedToken' => PaymentGateway::mask($gateway->access_token),
            'maskedSecret' => PaymentGateway::mask($gateway->webhook_secret),
            'webhookUrl' => $provider === PaymentGateway::MERCADOPAGO ? MercadoPagoGateway::webhookUrl() : HostedGateway::webhookUrl($provider),
            'events' => WebhookEvent::where('provider', $provider)->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'enabled' => 'nullable|boolean',
            'access_token' => 'nullable|string|max:255',
            'public_key' => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'card_enabled' => 'nullable|boolean',
            'pix_enabled' => 'nullable|boolean',
            'recurrence_enabled' => 'nullable|boolean',
            'recurrence_default' => 'nullable|boolean',
            'pix_expiration_minutes' => 'required|integer|min:30|max:43200',
            'statement_descriptor' => 'nullable|string|max:22',
            'clear_webhook_secret' => 'nullable|boolean',
            'sandbox' => 'nullable|boolean',
            'certificate_path' => 'nullable|string|max:255',
            'pix_key' => 'nullable|string|max:100',
        ]);

        $gateway = PaymentGateway::for($provider)->fresh();

        // Blank secret fields keep what is stored
        if (filled($data['access_token'] ?? null)) {
            $gateway->access_token = trim($data['access_token']);
        }
        if (filled($data['webhook_secret'] ?? null)) {
            $gateway->webhook_secret = trim($data['webhook_secret']);
        } elseif ($request->boolean('clear_webhook_secret')) {
            $gateway->webhook_secret = null;
        }

        $gateway->public_key = trim((string) ($data['public_key'] ?? '')) ?: null;
        $gateway->enabled = $request->boolean('enabled');

        $settings = [
            'card_enabled' => $request->boolean('card_enabled'),
            'pix_enabled' => $request->boolean('pix_enabled'),
            'recurrence_enabled' => $request->boolean('recurrence_enabled'),
            'recurrence_default' => $request->boolean('recurrence_default'),
            'pix_expiration_minutes' => (int) $data['pix_expiration_minutes'],
            'statement_descriptor' => trim((string) ($data['statement_descriptor'] ?? '')),
        ];
        if ($provider !== PaymentGateway::MERCADOPAGO) {
            $settings['sandbox'] = $request->boolean('sandbox');
        }
        if ($provider === PaymentGateway::EFI) {
            $settings['certificate_path'] = trim((string) ($data['certificate_path'] ?? ''));
            $settings['pix_key'] = trim((string) ($data['pix_key'] ?? ''));
        }
        $gateway->settings = $settings;
        $gateway->save();

        $warning = null;
        if ($gateway->enabled && !$gateway->isConfigured()) {
            $warning = __('Gateway enabled, but the credentials are incomplete: customers still cannot pay online with it.');
        } elseif ($gateway->enabled && $provider === PaymentGateway::MERCADOPAGO && $gateway->setting('card_enabled') && blank($gateway->public_key)) {
            $warning = __('Card payments need the public key; only Pix is offered until it is filled in.');
        } elseif ($gateway->enabled && $provider === PaymentGateway::EFI && $gateway->setting('pix_enabled') && !in_array('pix', $gateway->methods(), true)) {
            $warning = __('Efí Pix needs the certificate path and the Pix key; only card is offered until they are filled in.');
        } elseif ($gateway->enabled && $gateway->methods() === []) {
            $warning = __('No payment method is enabled for this provider.');
        }

        return $this->back($provider)->with('success', __('Gateway settings saved.'))->with($warning ? ['warning' => $warning] : []);
    }

    public function test(Request $request, GatewayManager $gateways)
    {
        $provider = $this->provider($request);
        $gateway = $gateways->driver($provider);

        if (!$gateway->config()->isConfigured()) {
            return $this->back($provider)->with('error', __('Save the credentials first.'));
        }

        $result = $gateway->testConnection();

        if (!$result['ok']) {
            return $this->back($provider)->with('error', __('Connection failed: :error', ['error' => $result['error']]));
        }

        $mode = $gateway->config()->isSandbox() ? __('test mode') : __('production');

        if ($provider === PaymentGateway::MERCADOPAGO) {
            $acc = $result['account'];

            return $this->back($provider)->with('success', __('Connected to Mercado Pago as :name (account :id, :mode).', [
                'name' => $acc['nickname'] ?: $acc['email'], 'id' => $acc['id'], 'mode' => $mode,
            ]));
        }

        return $this->back($provider)->with('success', __('Connected to :name (:mode).', ['name' => $result['account'], 'mode' => $mode]));
    }

    /** Efí only: registers our URL as the Pix notification endpoint of the configured Pix key. */
    public function registerWebhook(Request $request, GatewayManager $gateways)
    {
        $gateway = $gateways->driver(PaymentGateway::EFI);
        abort_unless($gateway instanceof EfiGateway, 404);

        if (!$gateway->config()->isConfigured() || !in_array('pix', $gateway->methods(), true)) {
            return $this->back(PaymentGateway::EFI)->with('error', __('Save the credentials, certificate path and Pix key first.'));
        }

        $result = $gateway->registerPixWebhook();

        return $this->back(PaymentGateway::EFI)->with($result['ok']
            ? ['success' => __('Pix webhook registered at Efí.')]
            : ['error' => __('Connection failed: :error', ['error' => $result['error']])]);
    }
}
