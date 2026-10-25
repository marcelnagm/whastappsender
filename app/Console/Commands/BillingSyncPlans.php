<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;

class BillingSyncPlans extends Command
{
    protected $signature = 'billing:sync-plans {--force : Overwrite prices, limits and features of existing plans with config/billing.php}';

    protected $description = 'Create the plans of config/billing.php that are missing in the database';

    public function handle(): int
    {
        $plans = Plan::syncFromConfig((bool) $this->option('force'));

        $this->table(
            ['Slug', 'Name', 'Monthly', 'Instances', 'Contacts', 'Messages/mo', 'AI replies/mo'],
            $plans->map(fn (Plan $p) => [
                $p->slug, $p->name, number_format($p->price_cents / 100, 2, ',', '.'),
                $p->limit('instances') ?? '∞', $p->limit('contacts') ?? '∞',
                $p->limit('messages_per_month') ?? '∞', $p->limit('ai_replies_per_month') ?? '∞',
            ])->all()
        );

        return self::SUCCESS;
    }
}
