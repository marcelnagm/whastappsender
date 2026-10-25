<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class BillingRun extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Issue renewal invoices and move subscriptions through past_due / suspended / expired by their dates';

    public function handle(SubscriptionService $subscriptions): int
    {
        $stats = $subscriptions->runLifecycle();

        $this->table(['Transition', 'Subscriptions'], collect($stats)->map(fn ($n, $k) => [$k, $n])->values()->all());

        return self::SUCCESS;
    }
}
