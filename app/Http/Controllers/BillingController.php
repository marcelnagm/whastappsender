<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Customer side: see plans, subscribe / change / cancel, list invoices. Payment itself comes with the gateway. */
class BillingController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions, private Entitlements $entitlements)
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

    public function invoice(Invoice $invoice)
    {
        abort_unless((int) $invoice->user_id === (int) Auth::id(), 403);

        return view('billing.invoice', ['invoice' => $invoice->load('plan')]);
    }
}
