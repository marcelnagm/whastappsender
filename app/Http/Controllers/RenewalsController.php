<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Services\Pipeline\Renewals;
use Illuminate\Http\Request;

/** Subscriptions: what renews soon, MRR / ARR, renew and cancel. */
class RenewalsController extends Controller
{
    public function index(Request $request, Renewals $svc)
    {
        $days = in_array((int) $request->query('days'), [30, 60, 90, 365], true) ? (int) $request->query('days') : 60;

        return view('pipeline.renewals', ['rows' => $svc->upcoming($request->user(), $days), 'sum' => $svc->summary($request->user()), 'days' => $days]);
    }

    public function renew(Request $request, int $deal, Renewals $svc)
    {
        $svc->renew($this->deal($request, $deal));

        return back()->with('success', __('Renewed.'));
    }

    public function cancel(Request $request, int $deal, Renewals $svc)
    {
        $svc->cancel($this->deal($request, $deal));

        return back()->with('success', __('Subscription cancelled.'));
    }

    private function deal(Request $request, int $id): Deal
    {
        return Deal::where('user_id', $request->user()->id)->where('status', 'won')->whereNotNull('billing_cycle')->findOrFail($id);
    }
}
