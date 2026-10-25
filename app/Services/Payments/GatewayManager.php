<?php

namespace App\Services\Payments;

use App\Models\PaymentGateway as GatewayConfig;

/** Resolves the configured provider. Only Mercado Pago exists today; others can be added here. */
class GatewayManager
{
    public function mercadopago(): MercadoPagoGateway
    {
        return new MercadoPagoGateway(GatewayConfig::mercadopago()->fresh());
    }

    /** The gateway customers pay with, or null while nothing is enabled and configured. */
    public function active(): ?PaymentGateway
    {
        $gateway = $this->mercadopago();

        return $gateway->isReady() ? $gateway : null;
    }
}
