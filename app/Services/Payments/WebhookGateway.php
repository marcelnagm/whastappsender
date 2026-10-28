<?php

namespace App\Services\Payments;

use Illuminate\Http\Request;

/**
 * Gateways that report payments through a webhook. A notification only says "look at resource X": reconcile()
 * always re-reads the resource from the provider's API with our credentials, so a forged call cannot mark anything paid.
 */
interface WebhookGateway
{
    /** Authenticates the incoming request (shared secret). Lenient when no secret is configured: reconcile() is the real check. */
    public function verifyWebhook(Request $request): bool;

    /**
     * What this notification refers to. Empty list = nothing we care about.
     *
     * @return list<array{type:string,id:string}>
     */
    public function webhookEvents(Request $request): array;

    /**
     * Current state of the resource, read from the provider.
     * status: paid | pending | failed. null = the provider could not be reached (retry later).
     *
     * @return list<array{status:string,gateway_id:string,invoice_number:?string,invoice_id:?int,amount_cents:?int,payment_type:?string}>|null
     */
    public function reconcile(string $type, string $id): ?array;
}
