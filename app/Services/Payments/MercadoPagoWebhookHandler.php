<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Log;

/**
 * Turns Mercado Pago notifications into billing state. A notification only says "something happened to resource X":
 * the resource is always fetched from the API with our credentials, so a forged notification cannot mark anything paid.
 *
 * Handled: payment (Pix / card), subscription_authorized_payment (recurring card charge),
 * subscription_preapproval (recurring authorization changed). Everything else is ignored.
 */
class MercadoPagoWebhookHandler
{
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const RETRY = 'retry';       // the API could not be reached: try again later

    public function __construct(private GatewayManager $gateways, private PaymentService $payments, private SubscriptionService $subscriptions)
    {
    }

    /** @return array{0:string,1:string} [status, note] */
    public function handle(string $type, string $resourceId): array
    {
        $gateway = $this->gateways->mercadopago();
        if (!$gateway->config()->isConfigured()) {
            return [self::IGNORED, 'gateway not configured'];
        }

        return match ($type) {
            'payment' => $this->payment($gateway, $resourceId),
            'subscription_authorized_payment' => $this->authorizedPayment($gateway, $resourceId),
            'subscription_preapproval', 'preapproval' => $this->preapproval($gateway, $resourceId),
            default => [self::IGNORED, "type {$type} not used"],
        };
    }

    private function payment(MercadoPagoGateway $gateway, string $id): array
    {
        $payment = $gateway->fetchPayment($id);
        if (!$payment) {
            return [self::RETRY, 'payment could not be fetched'];
        }

        $reference = (string) ($payment['external_reference'] ?? '');
        $status = (string) ($payment['status'] ?? '');

        $invoice = str_starts_with($reference, 'INV-') ? Invoice::where('number', $reference)->first() : null;
        if (!$invoice) {
            return [self::IGNORED, "no invoice for reference '{$reference}'"];
        }

        if ($status !== 'approved') {
            if ($status === 'rejected') {
                $this->noteFailure($invoice, $payment);
            }

            return [self::PROCESSED, "payment {$status}"];
        }

        return $this->settleApproved($invoice, $payment, (string) $id);
    }

    private function authorizedPayment(MercadoPagoGateway $gateway, string $id): array
    {
        $authorized = $gateway->fetchAuthorizedPayment($id);
        if (!$authorized) {
            return [self::RETRY, 'authorized payment could not be fetched'];
        }

        $preapprovalId = (string) ($authorized['preapproval_id'] ?? '');
        $sub = $preapprovalId !== '' ? Subscription::where('gateway_subscription_id', $preapprovalId)->first() : null;
        if (!$sub) {
            return [self::IGNORED, "no subscription for preapproval '{$preapprovalId}'"];
        }

        $paymentId = (string) ($authorized['payment']['id'] ?? '');
        $payment = $paymentId !== '' ? $gateway->fetchPayment($paymentId) : null;
        if (!$payment) {
            // Not charged yet (scheduled) or still processing
            return [self::PROCESSED, 'authorized payment without a payment yet (' . ($authorized['status'] ?? '?') . ')'];
        }

        $status = (string) ($payment['status'] ?? '');
        if ($status !== 'approved') {
            if ($status === 'rejected' && ($user = User::find($sub->user_id))) {
                $user->notify(new BillingNotice('payment_failed', ['invoice' => optional($sub->openInvoice())->number]));
            }

            return [self::PROCESSED, "recurring payment {$status}"];
        }

        // Payment already applied to an invoice (notification replay)
        if (Invoice::where('gateway_invoice_id', $paymentId)->where('status', Invoice::PAID)->exists()) {
            return [self::PROCESSED, 'payment already applied'];
        }

        $invoice = $this->subscriptions->openOrIssueRenewal($sub);
        if (!$invoice) {
            return [self::IGNORED, 'subscription has nothing to charge'];
        }

        $payment['external_reference'] = $payment['external_reference'] ?? $invoice->number;

        return $this->settleApproved($invoice, $payment, $paymentId);
    }

    private function preapproval(MercadoPagoGateway $gateway, string $id): array
    {
        $pre = $gateway->fetchPreapproval($id);
        if (!$pre) {
            return [self::RETRY, 'preapproval could not be fetched'];
        }

        $sub = Subscription::where('gateway_subscription_id', $id)->first();
        if (!$sub) {
            return [self::IGNORED, "no subscription for preapproval '{$id}'"];
        }

        $status = (string) ($pre['status'] ?? '');
        $sub->update(['gateway_status' => $status]);

        if (in_array($status, ['cancelled', 'paused'], true) && $sub->auto_renew) {
            $sub->update(['auto_renew' => false]);
            if ($user = User::find($sub->user_id)) {
                $user->notify(new BillingNotice('recurrence_canceled'));
            }
        } elseif ($status === 'authorized' && $sub->payment_method === 'card' && !$sub->auto_renew) {
            $sub->update(['auto_renew' => true]);
        }

        return [self::PROCESSED, "preapproval {$status}"];
    }

    /** Approved payment -> invoice paid, after checking that what was paid covers what was billed. */
    private function settleApproved(Invoice $invoice, array $payment, string $paymentId): array
    {
        $paidCents = (int) round(((float) ($payment['transaction_amount'] ?? 0)) * 100);

        if ($paidCents < $invoice->amount_cents) {
            Log::warning("Payment {$paymentId} ({$paidCents}) is lower than invoice {$invoice->number} ({$invoice->amount_cents}); not applied");

            return [self::IGNORED, "amount {$paidCents} lower than invoice {$invoice->amount_cents}"];
        }

        if ($invoice->status === Invoice::VOID) {
            return [self::IGNORED, 'invoice is void; the payment needs a manual refund'];
        }

        $this->payments->settle($invoice, $paymentId, $payment['payment_type_id'] ?? null, [
            'payment_id' => $paymentId,
            'status' => $payment['status'] ?? null,
            'payment_method_id' => $payment['payment_method_id'] ?? null,
            'amount' => $payment['transaction_amount'] ?? null,
        ]);

        return [self::PROCESSED, 'invoice ' . $invoice->number . ' paid'];
    }

    private function noteFailure(Invoice $invoice, array $payment): void
    {
        $sub = $invoice->subscription;
        if ($sub && $sub->auto_renew && $sub->payment_method === 'card' && ($user = User::find($sub->user_id))) {
            $user->notify(new BillingNotice('payment_failed', ['invoice' => $invoice->number]));
        }
    }
}
