<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Subscription;

/**
 * What the billing core needs from a payment provider. Mercado Pago is the first implementation.
 * Recurrence ("renew automatically") exists for every method:
 *   - card: the provider charges the card each cycle (subscribe / cancelRecurring / updateRecurringAmount)
 *   - pix:  no provider-side authorization; a new Pix charge is issued automatically for each renewal invoice
 */
interface PaymentGateway
{
    public function name(): string;

    public function isReady(): bool;

    /** @return list<string> enabled methods: card, pix */
    public function methods(): array;

    /** "Renew automatically" is offered (Pix: a new code per renewal; card: only when supportsCardRecurrence()). */
    public function supportsRecurrence(): bool;

    /** The provider itself charges the card every cycle (Mercado Pago). Hosted-page gateways pay the card once per invoice. */
    public function supportsCardRecurrence(): bool;

    /** token: card data is tokenised in the browser with the gateway's JS. redirect: the card is paid on the provider's hosted page. */
    public function cardMode(): string;

    /** One payment for one invoice (card with token, or Pix). */
    public function charge(Invoice $invoice, PayRequest $request): PaymentResult;

    /** Card recurrence: authorizes the card for automatic charges starting with this invoice. */
    public function subscribe(Subscription $subscription, Invoice $invoice, PayRequest $request): PaymentResult;

    public function cancelRecurring(string $recurringId): void;

    public function updateRecurringAmount(string $recurringId, int $amountCents): void;
}
