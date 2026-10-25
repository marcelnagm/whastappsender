<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;

/** Admin tools while there is no payment gateway: see subscriptions, confirm a payment by hand, grant a plan. */
class AdminBillingController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request)
    {
        $status = $request->query('status');

        $subs = Subscription::with(['user:id,name,email', 'plan:id,name,slug'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $open = Invoice::whereIn('subscription_id', $subs->pluck('id'))->where('status', Invoice::OPEN)->get()->groupBy('subscription_id');

        return view('admin.billing.index', [
            'subs' => $subs,
            'open' => $open,
            'plans' => Plan::orderBy('sort')->get(),
            'users' => User::orderBy('name')->get(['id', 'name', 'email']),
            'status' => $status,
            'counts' => Subscription::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        $this->subscriptions->markInvoicePaid($invoice, [
            'payment_method' => $request->input('method', 'manual'),
        ]);

        return back()->with('success', __('Invoice :number marked as paid.', ['number' => $invoice->number]));
    }

    public function grant(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan' => 'required|exists:plans,slug',
            'days' => 'required|integer|min:1|max:3650',
        ]);

        $this->subscriptions->grant(User::findOrFail($data['user_id']), Plan::where('slug', $data['plan'])->firstOrFail(), (int) $data['days']);

        return back()->with('success', __('Plan granted.'));
    }
}
