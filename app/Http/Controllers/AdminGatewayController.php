<?php

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use App\Services\Payments\GatewayManager;
use Illuminate\Http\Request;

/** Admin > Payment gateway: credentials and options of Mercado Pago. Secrets are never sent back to the browser. */
class AdminGatewayController extends Controller
{
    public function edit()
    {
        $gateway = PaymentGateway::mercadopago()->fresh();

        return view('admin.gateway.edit', [
            'gateway' => $gateway,
            'maskedToken' => PaymentGateway::mask($gateway->access_token),
            'maskedSecret' => PaymentGateway::mask($gateway->webhook_secret),
            'webhookUrl' => \App\Services\Payments\MercadoPagoGateway::webhookUrl(),
            'events' => WebhookEvent::where('provider', 'mercadopago')->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function update(Request $request)
    {
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
        ]);

        $gateway = PaymentGateway::mercadopago()->fresh();

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
        $gateway->settings = [
            'card_enabled' => $request->boolean('card_enabled'),
            'pix_enabled' => $request->boolean('pix_enabled'),
            'recurrence_enabled' => $request->boolean('recurrence_enabled'),
            'recurrence_default' => $request->boolean('recurrence_default'),
            'pix_expiration_minutes' => (int) $data['pix_expiration_minutes'],
            'statement_descriptor' => trim((string) ($data['statement_descriptor'] ?? '')),
        ];
        $gateway->save();

        $warning = null;
        if ($gateway->enabled && !$gateway->isConfigured()) {
            $warning = __('Gateway enabled, but there is no access token yet: customers still cannot pay online.');
        } elseif ($gateway->enabled && $gateway->setting('card_enabled') && blank($gateway->public_key)) {
            $warning = __('Card payments need the public key; only Pix is offered until it is filled in.');
        }

        return redirect()->route('admin.gateway.edit')->with('success', __('Gateway settings saved.'))->with($warning ? ['warning' => $warning] : []);
    }

    public function test(GatewayManager $gateways)
    {
        $gateway = $gateways->mercadopago();

        if (!$gateway->config()->isConfigured()) {
            return redirect()->route('admin.gateway.edit')->with('error', __('Save the access token first.'));
        }

        $result = $gateway->testConnection();

        if (!$result['ok']) {
            return redirect()->route('admin.gateway.edit')->with('error', __('Connection failed: :error', ['error' => $result['error']]));
        }

        $acc = $result['account'];

        return redirect()->route('admin.gateway.edit')->with('success', __('Connected to Mercado Pago as :name (account :id, :mode).', [
            'name' => $acc['nickname'] ?: $acc['email'],
            'id' => $acc['id'],
            'mode' => $gateway->config()->isSandbox() ? __('test mode') : __('production'),
        ]));
    }
}
