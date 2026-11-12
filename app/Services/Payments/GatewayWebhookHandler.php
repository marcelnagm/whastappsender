<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\SubscriptionService;
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

    public function __construct(private GatewayManager $gateways, private PaymentService $payments, private SubscriptionService $subscriptions)
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
        // provider-driven recurrence (Stripe Billing): the provider charges, cancels or refunds on its own
        $event = $r['event'] ?? null;
        if (in_array($event, ['payment_failed', 'recurrence_canceled', 'refunded', 'dispute'], true)) {
            return $this->applyEvent($event, $r);
        }

        if ($r['status'] !== 'paid') {
            return "{$r['gateway_id']} {$r['status']}";
        }

        if ($event === 'renewal') {
            return $this->applyRenewal($provider, $r);
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
        if ($note = $this->shortfall($provider, $invoice, $r)) {
            return $note;
        }

        $this->payments->settle($invoice, $r['gateway_id'], $r['payment_type'], [
            'payment_id' => $r['gateway_id'], 'amount' => $r['amount_cents'], 'provider' => $provider,
        ], $provider);

        // first charge of a provider-side subscription: remember it so renewals, cancelation and the portal find it
        if (!empty($r['subscription_gateway_id']) && ($sub = $invoice->subscription)) {
            $sub->update(array_filter([
                'gateway' => $provider,
                'gateway_subscription_id' => $r['subscription_gateway_id'],
                'gateway_customer_id' => $r['customer_id'] ?? null,
            ]) + ['gateway_status' => 'active', 'auto_renew' => true, 'payment_method' => 'card']);
        }

        return "invoice {$invoice->number} paid";
    }

    /** @return string|null why the payment does not cover the invoice (other currency or lower amount) */
    private function shortfall(string $provider, Invoice $invoice, array $r): ?string
    {
        if (!empty($r['currency']) && strtoupper($r['currency']) !== strtoupper((string) $invoice->currency)) {
            Log::warning("{$provider}: payment {$r['gateway_id']} is in {$r['currency']}, invoice {$invoice->number} is in {$invoice->currency}; not applied");

            return "currency {$r['currency']} differs from invoice {$invoice->currency}";
        }
        if ($r['amount_cents'] !== null && $r['amount_cents'] < $invoice->amount_cents) {
            Log::warning("{$provider}: payment {$r['gateway_id']} ({$r['amount_cents']}) is lower than invoice {$invoice->number} ({$invoice->amount_cents}); not applied");

            return "amount {$r['amount_cents']} lower than invoice {$invoice->amount_cents}";
        }

        return null;
    }

    private function subscriptionOf(array $r): ?Subscription
    {
        $id = (string) ($r['subscription_gateway_id'] ?? '');

        return $id !== '' ? Subscription::where('gateway_subscription_id', $id)->first() : null;
    }

    /** The provider charged the card for a new cycle: settle the renewal invoice (issued now if billing:run has not yet). */
    private function applyRenewal(string $provider, array $r): string
    {
        if (Invoice::where('gateway_invoice_id', $r['gateway_id'])->where('status', Invoice::PAID)->exists()) {
            return "{$r['gateway_id']} already applied";
        }

        $sub = $this->subscriptionOf($r);
        if (!$sub) {
            return "no subscription for {$r['subscription_gateway_id']}";
        }

        $invoice = $this->subscriptions->openOrIssueRenewal($sub);
        if (!$invoice) {
            return 'subscription has nothing to charge';
        }
        if ($note = $this->shortfall($provider, $invoice, $r)) {
            return $note;
        }

        $this->payments->settle($invoice, $r['gateway_id'], 'credit_card', [
            'payment_id' => $r['gateway_id'], 'amount' => $r['amount_cents'], 'provider' => $provider,
        ], $provider);
        $sub->update(['gateway_status' => 'active', 'auto_renew' => true]);

        return "invoice {$invoice->number} paid (renewal)";
    }

    private function applyEvent(string $event, array $r): string
    {
        if ($event === 'payment_failed' || $event === 'recurrence_canceled') {
            $sub = $this->subscriptionOf($r);
            if (!$sub) {
                return "no subscription for {$r['subscription_gateway_id']}";
            }
            $user = User::find($sub->user_id);

            if ($event === 'payment_failed') {
                $sub->update(['gateway_status' => 'past_due']);
                $user?->notify(new BillingNotice('payment_failed', ['invoice' => optional($sub->openInvoice())->number]));

                return 'renewal charge failed';
            }

            $sub->update(['gateway_status' => 'cancelled']);
            if ($sub->auto_renew) {
                $sub->update(['auto_renew' => false]);
                $user?->notify(new BillingNotice('recurrence_canceled'));
            }

            return 'recurrence canceled';
        }

        // refund / dispute: access is not revoked automatically; it is recorded for the team to decide
        $invoice = (!empty($r['invoice_number']) ? Invoice::where('number', $r['invoice_number'])->first() : null)
            ?? (!empty($r['stripe_invoice']) ? Invoice::where('gateway_invoice_id', $r['stripe_invoice'])->first() : null);
        $sub = $invoice?->subscription;

        if ($sub) {
            SubscriptionEvent::create(['subscription_id' => $sub->id, 'user_id' => $sub->user_id, 'type' => $event === 'dispute' ? 'dispute_opened' : 'refunded', 'data' => [
                'invoice' => $invoice->number, 'amount_cents' => $r['amount_cents'], 'currency' => $r['currency'], 'gateway_id' => $r['gateway_id'],
            ]]);
        }
        if ($invoice && $event === 'refunded') {
            $payload = $invoice->gateway_payload ?? [];
            $payload['refunded_cents'] = $r['amount_cents'];
            $invoice->update(['gateway_payload' => $payload]);
        }
        Log::warning("stripe {$event} {$r['gateway_id']} ({$r['amount_cents']} {$r['currency']})" . ($invoice ? " on invoice {$invoice->number}" : ' (invoice not identified)'));

        return $event . ($invoice ? " on invoice {$invoice->number}" : ' (invoice not identified)');
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
