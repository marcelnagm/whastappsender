<?php

namespace App\Events\Billing;

use App\Models\Subscription;

/** Canceled (now or scheduled) or expired: any automatic charge at the gateway must stop. */
class SubscriptionStopped
{
    public function __construct(public Subscription $subscription)
    {
    }
}
