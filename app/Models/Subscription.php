<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    public const PENDING = 'pending';
    public const TRIALING = 'trialing';
    public const ACTIVE = 'active';
    public const PAST_DUE = 'past_due';
    public const SUSPENDED = 'suspended';
    public const CANCELED = 'canceled';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'cycle', 'started_at', 'trial_ends_at', 'current_period_start',
        'current_period_end', 'grace_ends_at', 'suspended_at', 'cancel_at_period_end', 'canceled_at', 'ends_at',
        'pending_plan_id', 'pending_cycle', 'gateway', 'gateway_customer_id', 'gateway_subscription_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'grace_ends_at' => 'datetime',
        'suspended_at' => 'datetime',
        'canceled_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function pendingPlan()
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function events()
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    /** Statuses that still count as "the user's subscription" (not finished). */
    public static function liveStatuses(): array
    {
        return [self::PENDING, self::TRIALING, self::ACTIVE, self::PAST_DUE, self::SUSPENDED];
    }

    /** Paid-plan limits and features apply (trial, paid, or in the grace window). */
    public function grantsAccess(): bool
    {
        return in_array($this->status, [self::TRIALING, self::ACTIVE, self::PAST_DUE], true);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::SUSPENDED;
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::liveStatuses(), true);
    }

    public function openInvoice(): ?Invoice
    {
        return $this->invoices()->where('status', Invoice::OPEN)->orderBy('due_at')->first();
    }
}
