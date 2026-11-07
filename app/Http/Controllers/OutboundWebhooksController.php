<?php

namespace App\Http\Controllers;

use App\Models\OutboundWebhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\OutboundWebhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Endpoints that receive the CRM's events (Zapier, n8n, Make...). */
class OutboundWebhooksController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user();
        $hooks = OutboundWebhook::where('user_id', $owner->id)->orderBy('id')->get();
        $deliveries = WebhookDelivery::whereIn('webhook_id', $hooks->pluck('id'))->latest('id')->limit(25)->get();

        return view('webhooks.index', ['hooks' => $hooks, 'deliveries' => $deliveries, 'events' => OutboundWebhooks::EVENTS]);
    }

    public function store(Request $request)
    {
        $owner = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'url' => 'required|url|max:500',
            'events' => 'required|array|min:1',
            'events.*' => 'in:' . implode(',', OutboundWebhooks::EVENTS),
        ]);
        if (!OutboundWebhooks::urlAllowed($data['url'])) {
            return back()->withErrors(['url' => __('Use a public https address.')])->withInput();
        }
        if (OutboundWebhook::where('user_id', $owner->id)->count() >= OutboundWebhooks::MAX_HOOKS) {
            return back()->withErrors(['name' => __('Limit of :n endpoints reached.', ['n' => OutboundWebhooks::MAX_HOOKS])])->withInput();
        }
        OutboundWebhook::create(['user_id' => $owner->id, 'name' => $data['name'], 'url' => $data['url'], 'secret' => Str::random(40), 'events' => array_values($data['events']), 'active' => true]);

        return back()->with('success', __('Endpoint created.'));
    }

    public function toggle(Request $request, int $webhook)
    {
        $h = OutboundWebhook::where('user_id', $request->user()->id)->findOrFail($webhook);
        $h->update(['active' => !$h->active, 'failures' => 0]);

        return back();
    }

    public function test(Request $request, int $webhook)
    {
        $h = OutboundWebhook::where('user_id', $request->user()->id)->findOrFail($webhook);
        app(OutboundWebhooks::class)->queue($h, 'ping', ['message' => 'Test event from SAMA']);

        return back()->with('success', __('Test event queued.'));
    }

    public function destroy(Request $request, int $webhook)
    {
        $h = OutboundWebhook::where('user_id', $request->user()->id)->findOrFail($webhook);
        WebhookDelivery::where('webhook_id', $h->id)->delete();
        $h->delete();

        return back()->with('success', __('Endpoint removed.'));
    }
}
