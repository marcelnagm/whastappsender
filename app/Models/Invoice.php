<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const OPEN = 'open';
    public const PAID = 'paid';
    public const VOID = 'void';

    protected $fillable = [
        'user_id', 'subscription_id', 'plan_id', 'number', 'status', 'cycle', 'amount_cents', 'credit_cents', 'currency',
        'description', 'period_start', 'period_end', 'due_at', 'paid_at', 'payment_method', 'gateway',
        'gateway_invoice_id', 'gateway_payload',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'gateway_payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at && $this->due_at->isPast();
    }

    public function amount(): float
    {
        return $this->amount_cents / 100;
    }

    public static function nextNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $last = static::where('number', 'like', $prefix . '%')->orderByDesc('number')->value('number');
        $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
