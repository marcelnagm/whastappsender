<?php

namespace App\Services\Payments;

use App\Models\PaymentGateway as GatewayConfig;

/** Resolves the payment providers. Several can be enabled at once; the customer picks one at checkout. */
class GatewayManager
{
    public function mercadopago(): MercadoPagoGateway
    {
        return new MercadoPagoGateway(GatewayConfig::mercadopago()->fresh());
    }

    public function driver(string $provider): PaymentGateway
    {
        $config = GatewayConfig::for($provider)->fresh();

        return match ($provider) {
            GatewayConfig::ASAAS => new AsaasGateway($config),
            GatewayConfig::EFI => new EfiGateway($config),
            GatewayConfig::ABACATEPAY => new AbacatePayGateway($config),
            GatewayConfig::PAGARME => new PagarMeGateway($config),
            GatewayConfig::STRIPE => new StripeGateway($config),
            GatewayConfig::PAGBANK => new PagBankGateway($config),
            default => new MercadoPagoGateway($config),
        };
    }

    /** @return array<string, PaymentGateway> ready providers (enabled + configured + at least one method), by name */
    public function ready(): array
    {
        $out = [];
        foreach (GatewayConfig::PROVIDERS as $provider) {
            $gateway = $this->driver($provider);
            if ($gateway->isReady() && $gateway->methods()) {
                $out[$provider] = $gateway;
            }
        }

        return $out;
    }

    /**
     * The gateway a customer pays with: the one asked for (when ready), else the first ready one,
     * or null while nothing is enabled and configured.
     */
    public function active(?string $provider = null): ?PaymentGateway
    {
        $ready = $this->ready();

        if ($provider !== null && isset($ready[$provider])) {
            return $ready[$provider];
        }

        return $ready ? reset($ready) : null;
    }
}
