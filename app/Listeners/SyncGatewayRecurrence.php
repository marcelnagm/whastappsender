<?php

namespace App\Listeners;

use App\Events\Billing\InvoiceIssued;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\SubscriptionStopped;
use App\Models\Plan;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the payment gateway in step with the subscription: automatic Pix for renewals, the card
 * authorization's amount, and stopping automatic charges when a subscription ends. Failures are only logged:
 * the billing state never depends on the gateway being reachable.
 */
class SyncGatewayRecurrence
{
    public function __construct(private PaymentService $payments)
    {
    }

    public function onInvoiceIssued(InvoiceIssued $event): void
    {
        $sub = $event->subscription;
        $invoice = $event->invoice;

        if (!$sub->auto_renew || $invoice->amount_cents <= 0 || !$sub->current_period_end) {
            return;
        }

        // Only renewals (the invoice starts where the paid period ends), not upgrades or first payments
        if ($invoice->period_start->lt($sub->current_period_end)) {
            return;
        }

        try {
            if ($sub->payment_method === 'pix') {
                $this->payments->autoPix($invoice);
            } elseif ($sub->payment_method === 'card') {
                $this->payments->syncRecurringAmount($sub, $invoice->amount_cents);
            }
        } catch (\Throwable $e) {
            Log::warning("Gateway sync for invoice {$invoice->number} failed: " . $e->getMessage());
        }
    }

    public function onInvoicePaid(InvoicePaid $event): void
    {
        $sub = $event->subscription->fresh();

        if (!$sub || !$sub->auto_renew || $sub->payment_method !== 'card') {
            return;
        }

        try {
            $next = $sub->pending_plan_id ? (Plan::find($sub->pending_plan_id) ?? $sub->plan) : $sub->plan;
            $cycle = $sub->pending_cycle ?: $sub->cycle;
            $this->payments->syncRecurringAmount($sub, $next->priceFor($cycle));
        } catch (\Throwable $e) {
            Log::warning("Gateway amount sync after payment failed: " . $e->getMessage());
        }
    }

    public function onSubscriptionStopped(SubscriptionStopped $event): void
    {
        $sub = $event->subscription;

        try {
            $this->payments->cancelAtGateway($sub);
            if ($sub->auto_renew) {
                $sub->update(['auto_renew' => false]);
            }
        } catch (\Throwable $e) {
            Log::warning("Stopping gateway recurrence of subscription {$sub->id} failed: " . $e->getMessage());
        }
    }
}
