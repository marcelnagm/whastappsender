<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class BillingGrant extends Command
{
    protected $signature = 'billing:grant {user : User id, e-mail, or "all" for every non-admin user without a live subscription} {plan : Plan slug} {--days=30 : Days of access without charge}';

    protected $description = 'Give a plan for some days without charging (courtesy, or to grandfather users before enabling billing)';

    public function handle(SubscriptionService $subscriptions): int
    {
        $plan = Plan::where('slug', $this->argument('plan'))->first();
        if (!$plan) {
            $this->error('Unknown plan. Available: ' . Plan::pluck('slug')->implode(', '));

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        if ($this->argument('user') === 'all') {
            $count = 0;
            User::where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', 'admin'))->each(function (User $user) use ($subscriptions, $plan, $days, &$count) {
                if (!$subscriptions->current($user)) {
                    $subscriptions->grant($user, $plan, $days);
                    $count++;
                }
            });
            $this->info("Granted {$plan->name} for {$days} days to {$count} user(s).");

            return self::SUCCESS;
        }

        $user = User::where('id', $this->argument('user'))->orWhere('email', $this->argument('user'))->first();
        if (!$user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $subscriptions->grant($user, $plan, $days);
        $this->info("Granted {$plan->name} for {$days} days to {$user->email}.");

        return self::SUCCESS;
    }
}
