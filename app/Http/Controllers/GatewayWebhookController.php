<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGatewayEventJob;
use App\Models\WebhookEvent;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\WebhookGateway;
use Illuminate\Http\Request;

/** Public endpoint for Asaas / Efí / AbacatePay. Stores what was received, answers 200 fast, applies it on the queue. */
class GatewayWebhookController extends Controller
{
    public function receive(Request $request, GatewayManager $gateways, string $provider, ?string $path = null)
    {
        $gateway = $gateways->driver($provider);
        abort_unless($gateway instanceof WebhookGateway, 404);

        if (!$gateway->verifyWebhook($request)) {
            WebhookEvent::create(['provider' => $provider, 'type' => null, 'resource_id' => null, 'status' => 'rejected', 'note' => 'invalid secret', 'payload' => $this->safePayload($request)]);

            return response()->json(['status' => 'invalid secret'], 401);
        }

        $events = $gateway->webhookEvents($request);
        if (!$events) {
            return response()->json(['status' => 'ignored']);
        }

        foreach ($events as $e) {
            $stored = WebhookEvent::create([
                'provider' => $provider, 'type' => $e['type'], 'resource_id' => $e['id'],
                'status' => 'received', 'payload' => $this->safePayload($request),
            ]);
            ProcessGatewayEventJob::dispatch($stored->id)->onQueue('default');
        }

        return response()->json(['status' => 'queued']);
    }

    /** The shared secret may travel in the query string: never store it. */
    private function safePayload(Request $request): array
    {
        return $request->except(['secret', 'webhookSecret']);
    }
}
