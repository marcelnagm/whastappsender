<?php

namespace App\Services\Billing;

use App\Exceptions\PlanLimitException;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;

/**
 * Answers "what may this user do?" from the plan and subscription state.
 *
 * - BILLING_ENABLED=false -> everybody unlimited (nothing changes for existing installs).
 * - Administrators are never limited.
 * - A suspended subscription (overdue past the grace days) is blocked: no features, no new usage.
 */
class Entitlements
{
    /** limit key => counter metric (monthly) or live count */
    public const MONTHLY = [
        'messages_per_month' => 'messages',
        'ai_replies_per_month' => 'ai_replies',
    ];
    public const LIVE = ['instances', 'contacts'];

    public function enabled(): bool
    {
        return (bool) config('billing.enabled');
    }

    public function isUnlimited(User $user): bool
    {
        return !$this->enabled() || $user->isAdmin();
    }

    public function subscription(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->whereIn('status', Subscription::liveStatuses())
            ->orderByDesc('id')
            ->first();
    }

    public function plan(User $user): Plan
    {
        $sub = $this->subscription($user);

        if ($sub && ($sub->grantsAccess() || $sub->isSuspended())) {
            return $sub->plan;
        }

        return Plan::free();
    }

    public function isBlocked(User $user): bool
    {
        if ($this->isUnlimited($user)) {
            return false;
        }

        $sub = $this->subscription($user);

        return (bool) ($sub && $sub->isSuspended());
    }

    /** free | pending | trialing | active | past_due | suspended */
    public function status(User $user): string
    {
        return $this->subscription($user)->status ?? 'free';
    }

    public function can(User $user, string $feature): bool
    {
        if ($this->isUnlimited($user)) {
            return true;
        }

        return !$this->isBlocked($user) && $this->plan($user)->hasFeature($feature);
    }

    /** @return int|null null = unlimited */
    public function limit(User $user, string $key): ?int
    {
        return $this->isUnlimited($user) ? null : $this->plan($user)->limit($key);
    }

    public function used(User $user, string $key): int
    {
        return match (true) {
            $key === 'instances' => Instance::where('user_id', $user->id)->count(),
            $key === 'contacts' => Contact::where('user_id', $user->id)->count(),
            isset(self::MONTHLY[$key]) => (int) UsageCounter::where('user_id', $user->id)
                ->where('metric', self::MONTHLY[$key])
                ->where('period', now()->format('Y-m'))
                ->value('used'),
            default => 0,
        };
    }

    /** @return int|null null = unlimited */
    public function remaining(User $user, string $key): ?int
    {
        if ($this->isBlocked($user)) {
            return 0;
        }

        $limit = $this->limit($user, $key);

        return $limit === null ? null : max(0, $limit - $this->used($user, $key));
    }

    public function allows(User $user, string $key, int $add = 1): bool
    {
        $remaining = $this->remaining($user, $key);

        return $remaining === null || $remaining >= $add;
    }

    /** @throws PlanLimitException */
    public function authorizeFeature(User $user, string $feature): void
    {
        if ($this->isBlocked($user)) {
            throw PlanLimitException::blocked();
        }

        if (!$this->can($user, $feature)) {
            throw PlanLimitException::feature($feature);
        }
    }

    /** @throws PlanLimitException */
    public function authorizeUsage(User $user, string $key, int $add = 1): void
    {
        if ($this->isBlocked($user)) {
            throw PlanLimitException::blocked();
        }

        if (!$this->allows($user, $key, $add)) {
            throw PlanLimitException::limit($key);
        }
    }

    /** Adds to a monthly counter ('messages', 'ai_replies'). Unlimited users are counted too (reports). */
    public function consume(User $user, string $metric, int $amount = 1): void
    {
        $period = now()->format('Y-m');

        $counter = UsageCounter::firstOrCreate(
            ['user_id' => $user->id, 'metric' => $metric, 'period' => $period],
            ['used' => 0]
        );

        UsageCounter::where('id', $counter->id)->increment('used', $amount);
    }

    /** For the billing screen: key => [used, limit(null=unlimited)] */
    public function usageSummary(User $user): array
    {
        $out = [];
        foreach (array_merge(self::LIVE, array_keys(self::MONTHLY)) as $key) {
            $out[$key] = ['used' => $this->used($user, $key), 'limit' => $this->limit($user, $key)];
        }

        return $out;
    }
}
