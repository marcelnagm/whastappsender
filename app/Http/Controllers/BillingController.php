<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionService;
use App\Services\Payments\PayRequest;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\PaymentService;
use App\Support\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Customer side: see plans, subscribe / change / cancel, list invoices, pay (Pix / card, one-time or automatic renewal). */
class BillingController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions, private Entitlements $entitlements, private PaymentService $payments)
    {
    }

    public function index()
    {
        $user = Auth::user();
        $subscription = $this->subscriptions->current($user);

        return view('billing.index', [
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get(),
            'currentPlan' => $this->entitlements->plan($user),
            'subscription' => $subscription,
            'status' => $this->entitlements->status($user),
            'usage' => $this->entitlements->usageSummary($user),
            'openInvoice' => $subscription ? $subscription->openInvoice() : null,
            'invoices' => Invoice::where('user_id', $user->id)->orderByDesc('id')->limit(24)->get(),
            'enabled' => $this->entitlements->enabled(),
            'unlimited' => $this->entitlements->isUnlimited($user),
            'blocked' => $this->entitlements->isBlocked($user),
        ]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'plan' => 'required|string|exists:plans,slug',
            'cycle' => 'nullable|in:monthly,yearly',
        ]);

        $plan = Plan::where('slug', $data['plan'])->where('is_active', true)->firstOrFail();
        $this->subscriptions->subscribe(Auth::user(), $plan, $data['cycle'] ?? 'monthly');

        return redirect()->route('billing.index')->with('success', __('Plan updated. Check the invoice below.'));
    }

    public function cancel()
    {
        if ($sub = $this->subscriptions->current(Auth::user())) {
            $this->subscriptions->cancel($sub, true);
        }

        return redirect()->route('billing.index')->with('success', __('Cancellation registered. You keep your plan until the end of the paid period.'));
    }

    public function resume()
    {
        if ($sub = $this->subscriptions->current(Auth::user())) {
            $this->subscriptions->resume($sub);
        }

        return redirect()->route('billing.index')->with('success', __('Subscription resumed.'));
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        abort_unless((int) $invoice->user_id === (int) Auth::id(), 403);

        // Several providers can be active: the customer picks one (?gateway=asaas); default = first ready
        $gateway = $this->payments->gateway($request->query('gateway'));
        $available = collect($this->payments->available())->map(fn ($g) => config("payments.providers.{$g->name()}.label", $g->name()))->all();
        $subscription = $invoice->subscription;

        return view('billing.invoice', [
            'invoice' => $invoice->load('plan'),
            'gateway' => $gateway,
            'available' => $available,
            'selected' => $gateway?->name(),
            'cardMode' => $gateway ? $gateway->cardMode() : 'token',
            'methods' => $gateway ? $gateway->methods() : [],
            'recurrenceAllowed' => $gateway && $gateway->supportsRecurrence(),
            'recurrenceDefault' => $gateway ? (bool) $gateway->config()->setting('recurrence_default') : false,
            'recurringCardOk' => $gateway && $gateway->supportsCardRecurrence() && $this->recurringCardAllowed($invoice),
            'cardRecurrenceSupported' => $gateway ? $gateway->supportsCardRecurrence() : false,
            'publicKey' => $gateway && $gateway->name() === 'mercadopago' ? $gateway->config()->public_key : null,
            'cardUrl' => $invoice->isOpen() ? (($invoice->gateway_payload ?? [])['redirect_url'] ?? null) : null,
            'pix' => $invoice->isOpen() ? (($invoice->gateway_payload ?? [])['pix'] ?? null) : null,
            'subscription' => $subscription,
            'lastError' => ($invoice->gateway_payload ?? [])['last_error'] ?? null,
        ]);
    }

    public function pay(Request $request, Invoice $invoice)
    {
        abort_unless((int) $invoice->user_id === (int) Auth::id(), 403);

        $data = $request->validate([
            'method' => 'required|in:card,pix',
            'gateway' => 'nullable|in:' . implode(',', \App\Models\PaymentGateway::PROVIDERS),
            'recurring' => 'nullable|boolean',
            'document' => 'required|string|max:20',
            'card_token' => 'nullable|string|max:255',
            'payment_method_id' => 'nullable|string|max:50',
            'issuer_id' => 'nullable|string|max:50',
        ]);

        if (!Document::valid($data['document'])) {
            return $this->payResponse($request, $invoice, ['document' => __('Enter a valid CPF or CNPJ.')], 422);
        }

        $user = Auth::user();
        $recurring = $request->boolean('recurring');

        $chosen = $this->payments->gateway($data['gateway'] ?? null);
        if ($data['method'] === 'card' && $recurring && $chosen && !$chosen->supportsCardRecurrence()) {
            return $this->payResponse($request, $invoice, ['recurring' => __('Automatic card renewal is not available with this payment provider. Pay by Pix to renew automatically.')], 422);
        }

        if ($data['method'] === 'card' && $recurring && !$this->recurringCardAllowed($invoice)) {
            return $this->payResponse($request, $invoice, ['recurring' => __('Automatic renewal on the card is only available when paying the full plan price. Pay this invoice once; renewal can be set up on the next one.')], 422);
        }

        $payRequest = new PayRequest(
            $data['method'], $recurring, (string) $user->email, (string) $user->name, Document::digits($data['document']),
            $data['card_token'] ?? null, $data['payment_method_id'] ?? null, $data['issuer_id'] ?? null
        );

        try {
            $result = $this->payments->pay($user, $invoice, $payRequest, $data['gateway'] ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->payResponse($request, $invoice, collect($e->errors())->map(fn ($m) => $m[0])->all(), 422);
        }

        if ($result->redirectUrl && $result->status === PaymentResult::PENDING && !$request->wantsJson()) {
            return redirect()->away($result->redirectUrl); // the provider's secure card page
        }

        if ($request->wantsJson()) {
            return response()->json($result->toArray() + ['paid' => $result->isPaid()], in_array($result->status, [PaymentResult::REJECTED, PaymentResult::ERROR], true) ? 422 : 200);
        }

        if ($result->isPaid()) {
            return redirect()->route('billing.invoice', $invoice)->with('success', __('Payment approved. Thank you!'));
        }
        if (in_array($result->status, [PaymentResult::REJECTED, PaymentResult::ERROR], true)) {
            return redirect()->route('billing.invoice', $invoice)->withErrors(['payment' => $result->message])->withInput($request->except('card_token'));
        }

        return redirect()->route('billing.invoice', $invoice)->with('success', $result->message ?: ($result->pix ? __('Pix generated. Pay with the QR code below.') : __('Payment being processed.')));
    }

    /** Polled by the checkout page while it waits for a Pix / first recurring charge. */
    public function status(Invoice $invoice)
    {
        abort_unless((int) $invoice->user_id === (int) Auth::id(), 403);

        return response()->json(['status' => $invoice->fresh()->status, 'paid' => $invoice->fresh()->status === Invoice::PAID]);
    }

    public function stopAutoRenew()
    {
        if (($sub = $this->subscriptions->current(Auth::user())) && $sub->auto_renew) {
            $this->payments->disableRecurrence($sub);
        }

        return redirect()->route('billing.index')->with('success', __('Automatic renewal turned off. You will receive each invoice to pay manually.'));
    }

    /** The provider charges a fixed amount every cycle, so only an invoice at full plan price can start a card authorization. */
    private function recurringCardAllowed(Invoice $invoice): bool
    {
        $plan = $invoice->plan;

        return $plan && !$plan->isFree() && (int) $invoice->amount_cents === (int) $plan->priceFor($invoice->cycle ?: 'monthly');
    }

    private function payResponse(Request $request, Invoice $invoice, array $errors, int $status)
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => 'error', 'errors' => $errors, 'message' => reset($errors)], $status);
        }

        return redirect()->route('billing.invoice', $invoice)->withErrors($errors)->withInput($request->except('card_token'));
    }
}
