<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * Applies a notification of Asaas / Efí / AbacatePay: re-reads the resource from the provider (WebhookGateway::reconcile)
 * and, only when the provider says it is paid and the amount covers the invoice, settles it.
 */
class GatewayWebhookHandler
{
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const RETRY = 'retry';

    public function __construct(private GatewayManager $gateways, private PaymentService $payments)
    {
    }

    /** @return array{0:string,1:string} [status, note] */
    public function handle(string $provider, string $type, string $resourceId): array
    {
        $gateway = $this->gateways->driver($provider);
        if (!$gateway instanceof WebhookGateway || !$gateway->config()->isConfigured()) {
            return [self::IGNORED, 'gateway not configured'];
        }

        $results = $gateway->reconcile($type, $resourceId);
        if ($results === null) {
            return [self::RETRY, 'resource could not be fetched'];
        }
        if (!$results) {
            return [self::IGNORED, 'nothing to apply'];
        }

        $notes = [];
        foreach ($results as $r) {
            $notes[] = $this->apply($provider, $r);
        }

        return [self::PROCESSED, implode('; ', $notes)];
    }

    private function apply(string $provider, array $r): string
    {
        if ($r['status'] !== 'paid') {
            return "{$r['gateway_id']} {$r['status']}";
        }

        $invoice = $this->findInvoice($r);
        if (!$invoice) {
            return "no invoice for {$r['gateway_id']}";
        }
        if ($invoice->status === Invoice::PAID) {
            return "invoice {$invoice->number} already paid";
        }
        if ($invoice->status === Invoice::VOID) {
            return "invoice {$invoice->number} is void; the payment needs a manual refund";
        }
        if ($r['amount_cents'] !== null && $r['amount_cents'] < $invoice->amount_cents) {
            Log::warning("{$provider}: payment {$r['gateway_id']} ({$r['amount_cents']}) is lower than invoice {$invoice->number} ({$invoice->amount_cents}); not applied");

            return "amount {$r['amount_cents']} lower than invoice {$invoice->amount_cents}";
        }

        $this->payments->settle($invoice, $r['gateway_id'], $r['payment_type'], [
            'payment_id' => $r['gateway_id'], 'amount' => $r['amount_cents'], 'provider' => $provider,
        ], $provider);

        return "invoice {$invoice->number} paid";
    }

    /** By our invoice number, by the id embedded in the Pix txid, or by the charge id we stored when creating it. */
    private function findInvoice(array $r): ?Invoice
    {
        if (!empty($r['invoice_number']) && ($i = Invoice::where('number', $r['invoice_number'])->first())) {
            return $i;
        }
        if (!empty($r['invoice_id']) && ($i = Invoice::find($r['invoice_id']))) {
            return $i;
        }

        return Invoice::where('gateway_invoice_id', $r['gateway_id'])->first();
    }
}
