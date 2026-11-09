<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightSchedule extends Model
{
    protected $fillable = ['user_id', 'enabled', 'frequency', 'business_type', 'period_days', 'last_run_at', 'last_error'];
    protected $casts = ['enabled' => 'boolean', 'last_run_at' => 'datetime'];

    /** Weekly reports go out on Mondays, monthly ones on the 1st; never twice on the same day. */
    public function isDue(?\Illuminate\Support\Carbon $now = null): bool
    {
        $now = $now ?: now();
        if (!$this->enabled || ($this->last_run_at && $this->last_run_at->isSameDay($now))) {
            return false;
        }

        return $this->frequency === 'monthly' ? $now->day === 1 : $now->isMonday();
    }
}
