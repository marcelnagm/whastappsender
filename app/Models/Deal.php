<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    protected $fillable = ['user_id', 'company_id', 'pipeline_id', 'stage_id', 'contact_id', 'chat_id', 'assigned_to', 'title', 'value', 'status', 'lost_reason',
        'expected_close_date', 'source', 'origin', 'position', 'closed_at', 'billing_cycle', 'renews_at'];

    protected $casts = ['value' => 'decimal:2', 'expected_close_date' => 'date', 'closed_at' => 'datetime', 'stale_notified_at' => 'datetime', 'renews_at' => 'date', 'renewal_notified_at' => 'datetime', 'churned_at' => 'datetime'];

    protected static function booted(): void
    {
        // a subscription won gets its first renewal date
        static::saving(fn (Deal $d) => app(\App\Services\Pipeline\Renewals::class)->schedule($d));
        // a deal moves its person along the lifecycle (opportunity, customer)
        static::saved(function (Deal $d) {
            if ($d->wasRecentlyCreated || $d->wasChanged(['status', 'contact_id'])) {
                app(\App\Services\Contacts\Lifecycle::class)->onDeal($d);
            }
        });
    }

    public function stage()
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function items()
    {
        return $this->hasMany(DealItem::class)->orderBy('id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
