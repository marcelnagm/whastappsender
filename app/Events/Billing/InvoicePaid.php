<?php

namespace App\Events\Billing;

use App\Models\Invoice;
use App\Models\Subscription;

class InvoicePaid
{
    public function __construct(public Invoice $invoice, public Subscription $subscription)
    {
    }
}
