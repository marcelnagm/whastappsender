<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\BillingNotice;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Takes a customer's checkout choice and turns it into a payment, wiring the result into the billing core
 * (SubscriptionService::markInvoicePaid is the only place that grants access).
 */
class PaymentService
{
    public function __construct(private GatewayManager $gateways, private SubscriptionService $subscriptions)
    {
    }

    public function gateway(): ?PaymentGateway
    {
        return $this->gateways->active();
    }

    /**
     * @throws ValidationException when the request cannot be honoured (field => message)
     */
    public function pay(User $user, Invoice $invoice, PayRequest $request): PaymentResult
    {
        $gateway = $this->gateway();

        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if (!$gateway) {
            $fail('method', __('Online payment is not available right now.'));
        }
        if ((int) $invoice->user_id !== (int) $user->id) {
            abort(403);
        }
        if (!$invoice->isOpen()) {
            $fail('method', __('This invoice is not open for payment.'));
        }
        if (!in_array($request->method, $gateway->methods(), true)) {
            $fail('method', __('This payment method is not available.'));
        }
        if ($request->recurring && !$gateway->supportsRecurrence()) {
            $fail('recurring', __('Automatic renewal is not available.'));
        }
        if ($request->method === 'card' && !$request->cardToken) {
            $fail('card', __('Enter the card data again.'));
        }

        $subscription = $invoice->subscription;

        // Pix already issued and still valid: show the same QR code instead of creating another charge
        if ($request->method === 'pix' && ($existing = $this->validPix($invoice))) {
            $this->rememberRecurrence($subscription, $request);

            return new PaymentResult(PaymentResult::PENDING, '', $invoice->gateway_invoice_id, $existing);
        }

        if ($request->method === 'card' && $request->recurring) {
            return $this->payByRecurringCard($gateway, $subscription, $invoice, $request);
        }

        $result = $gateway->charge($invoice, $request);

        $this->recordCharge($invoice, $request, $result);
        $this->rememberRecurrence($subscription, $request, $result);

        return $result;
    }

    /** Pix for a renewal invoice of a customer who chose Pix + automatic renewal. Called by the billing events. */
    public function autoPix(Invoice $invoice): ?PaymentResult
    {
        $gateway = $this->gateway();
        $subscription = $invoice->subscription;

        if (!$gateway || !$subscription || !$subscription->auto_renew || $subscription->payment_method !== 'pix'
            || !$invoice->isOpen() || !in_array('pix', $gateway->methods(), true)
            || !$subscription->payer_document || $this->validPix($invoice)) {
            return null;
        }

        $user = $subscription->user ?? User::find($subscription->user_id);
        $request = new PayRequest('pix', true, (string) $user->email, (string) $user->name, (string) $subscription->payer_document);

        $result = $gateway->charge($invoice, $request);
        $this->recordCharge($invoice, $request, $result);

        if ($result->status === PaymentResult::PENDING && $result->pix) {
            $user->notify(new BillingNotice('pix_ready', [
                'invoice' => $invoice->number,
                'amount' => number_format($invoice->amount_cents / 100, 2, ',', '.') . ' ' . $invoice->currency,
                'due' => optional($invoice->due_at)->format('d/m/Y'),
                'pix_code' => $result->pix['qr_code'] ?? null,
            ]));
        } else {
            Log::warning("Automatic Pix for invoice {$invoice->number} failed: {$result->message}");
        }

        return $result;
    }

    /** Stops automatic renewal (the customer will receive the next invoice and pay it by hand). */
    public function disableRecurrence(Subscription $subscription): void
    {
        $this->cancelAtGateway($subscription);
        $subscription->update(['auto_renew' => false]);
    }

    /** Cancels the card authorization at the provider, if any. Never throws: billing state must not depend on it. */
    public function cancelAtGateway(Subscription $subscription): void
    {
        if (!$subscription->gateway_subscription_id) {
            return;
        }

        try {
            $this->gateways->mercadopago()->cancelRecurring($subscription->gateway_subscription_id);
        } catch (\Throwable $e) {
            Log::warning("Could not cancel recurring authorization of subscription {$subscription->id}: " . $e->getMessage());
        }

        $subscription->update(['gateway_status' => 'cancelled']);
    }

    /** Keeps the provider's recurring amount equal to what the next invoice will charge. */
    public function syncRecurringAmount(Subscription $subscription, int $amountCents): void
    {
        if (!$subscription->gateway_subscription_id || !$subscription->auto_renew || $subscription->payment_method !== 'card' || $amountCents <= 0) {
            return;
        }

        try {
            $this->gateways->mercadopago()->updateRecurringAmount($subscription->gateway_subscription_id, $amountCents);
        } catch (\Throwable $e) {
            Log::warning("Could not update recurring amount of subscription {$subscription->id}: " . $e->getMessage());
        }
    }

    /** Approved payment confirmed by the gateway (response or webhook). Idempotent. */
    public function settle(Invoice $invoice, string $gatewayId, ?string $paymentType, array $payload = []): Invoice
    {
        return $this->subscriptions->markInvoicePaid($invoice, [
            'gateway' => 'mercadopago',
            'gateway_invoice_id' => $gatewayId,
            'payment_method' => $this->methodLabel($paymentType),
            'payload' => $payload ?: null,
        ]);
    }

    public function methodLabel(?string $paymentType): string
    {
        return match ($paymentType) {
            'bank_transfer' => 'pix',
            'credit_card', 'debit_card', 'prepaid_card' => 'card',
            default => $paymentType ?: 'gateway',
        };
    }

    // ---- internals --------------------------------------------------------------------------------------

    private function payByRecurringCard(PaymentGateway $gateway, Subscription $subscription, Invoice $invoice, PayRequest $request): PaymentResult
    {
        $result = $gateway->subscribe($subscription, $invoice, $request);

        if ($result->status !== PaymentResult::PENDING || !$result->recurringId) {
            return $result;
        }

        $subscription->update([
            'gateway' => 'mercadopago',
            'gateway_subscription_id' => $result->recurringId,
            'gateway_status' => 'authorized',
            'auto_renew' => true,
            'payment_method' => 'card',
            'payer_document' => $request->document,
        ]);

        $invoice->update([
            'gateway' => 'mercadopago',
            'payment_method' => 'card',
            'gateway_payload' => ['method' => 'card', 'recurring' => true] + ($invoice->gateway_payload ?? []),
        ]);

        return new PaymentResult(
            PaymentResult::PENDING,
            __('Card authorized for automatic renewal. We are confirming the first charge; this page updates by itself.'),
            null, null, $result->recurringId, 'credit_card'
        );
    }

    private function recordCharge(Invoice $invoice, PayRequest $request, PaymentResult $result): void
    {
        $payload = $invoice->gateway_payload ?? [];
        $payload['method'] = $request->method;

        if ($request->method === 'pix') {
            $payload['pix_attempts'] = (int) ($payload['pix_attempts'] ?? 0) + 1;
        }

        if ($result->pix) {
            $payload['pix'] = $result->pix;
        }

        if (in_array($result->status, [PaymentResult::REJECTED, PaymentResult::ERROR], true)) {
            $payload['last_error'] = $result->message;
        } else {
            unset($payload['last_error']);
        }

        $invoice->update([
            'gateway' => 'mercadopago',
            'payment_method' => $request->method,
            'gateway_invoice_id' => $result->gatewayId ?: $invoice->gateway_invoice_id,
            'gateway_payload' => $payload,
        ]);

        if ($result->isPaid() && $result->gatewayId) {
            $this->settle($invoice, $result->gatewayId, $result->paymentType);
        }
    }

    /** Recurrence choice for methods that have no provider-side authorization (Pix) or are paid once (card). */
    private function rememberRecurrence(Subscription $subscription, PayRequest $request, ?PaymentResult $result = null): void
    {
        if ($result && in_array($result->status, [PaymentResult::REJECTED, PaymentResult::ERROR], true)) {
            return;
        }

        if ($request->method === 'pix' && $request->recurring) {
            // A card authorization from before is replaced by automatic Pix
            if ($subscription->gateway_subscription_id && $subscription->payment_method === 'card') {
                $this->cancelAtGateway($subscription);
            }
            $subscription->update(['auto_renew' => true, 'payment_method' => 'pix', 'gateway' => 'mercadopago', 'payer_document' => $request->document]);
        } elseif (!$request->recurring) {
            // One-time payment: no automatic renewal after this one
            if ($subscription->auto_renew) {
                $this->disableRecurrence($subscription);
            }
        }
    }

    /** @return array|null the stored Pix of the invoice while it can still be paid */
    private function validPix(Invoice $invoice): ?array
    {
        $pix = ($invoice->gateway_payload ?? [])['pix'] ?? null;

        if (!$pix || empty($pix['qr_code']) || !$invoice->gateway_invoice_id) {
            return null;
        }

        $expires = !empty($pix['expires_at']) ? \Carbon\Carbon::parse($pix['expires_at']) : null;

        return (!$expires || $expires->gt(now()->addMinutes(2))) ? $pix : null;
    }
}
