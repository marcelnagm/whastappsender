<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMercadoPagoEventJob;
use App\Models\WebhookEvent;
use App\Services\Payments\GatewayManager;
use Illuminate\Http\Request;

/**
 * Public endpoint called by Mercado Pago. Answers 200 quickly (it retries after 22 s) and does the work on the queue.
 */
class MercadoPagoWebhookController extends Controller
{
    public function receive(Request $request, GatewayManager $gateways)
    {
        $gateway = $gateways->mercadopago();

        // Notifications come as JSON body + query (?data.id=...&type=...) or, for the legacy IPN, ?topic=...&id=...
        $type = (string) ($request->input('type') ?? $request->query('type') ?? $request->query('topic') ?? '');
        $id = (string) ($request->input('data.id') ?? $request->query('data.id') ?? $request->query('id') ?? '');

        if (!$gateway->verifySignature($request)) {
            WebhookEvent::create([
                'provider' => 'mercadopago', 'type' => $type ?: null, 'resource_id' => $id ?: null,
                'status' => 'rejected', 'note' => 'invalid signature', 'payload' => $request->all(),
            ]);

            return response()->json(['status' => 'invalid signature'], 401);
        }

        if ($type === '' || $id === '') {
            return response()->json(['status' => 'ignored']);
        }

        $event = WebhookEvent::create([
            'provider' => 'mercadopago', 'type' => $type, 'resource_id' => $id,
            'status' => 'received', 'payload' => $request->all(),
        ]);

        ProcessMercadoPagoEventJob::dispatch($event->id)->onQueue('default');

        return response()->json(['status' => 'queued']);
    }
}
