<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    protected $fillable = ['user_id', 'deal_id', 'number', 'token', 'title', 'intro', 'terms', 'items', 'total', 'valid_until', 'status', 'sent_at', 'viewed_at',
        'answered_at', 'answered_by', 'answered_ip', 'decline_reason', 'created_by'];

    protected $casts = ['items' => 'array', 'total' => 'decimal:2', 'valid_until' => 'date', 'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'answered_at' => 'datetime'];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function isAnswered(): bool
    {
        return in_array($this->status, ['accepted', 'declined'], true);
    }

    public function isExpired(): bool
    {
        return !$this->isAnswered() && $this->valid_until && $this->valid_until->lt(now()->startOfDay());
    }

    public function url(): string
    {
        return route('proposals.public.show', $this->token);
    }
}
