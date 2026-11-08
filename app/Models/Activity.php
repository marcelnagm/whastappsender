<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something done with a person outside the chat: a call, a meeting, a visit, an e-mail sent from elsewhere. */
class Activity extends Model
{
    public const TYPES = ['call' => 'bi-telephone', 'meeting' => 'bi-people', 'email' => 'bi-envelope', 'visit' => 'bi-geo-alt', 'other' => 'bi-pencil'];

    protected $fillable = ['user_id', 'contact_id', 'deal_id', 'type', 'outcome', 'notes', 'duration_min', 'occurred_at', 'created_by'];

    protected $casts = ['occurred_at' => 'datetime'];

    public static function labels(): array
    {
        return ['call' => __('Call'), 'meeting' => __('Meeting'), 'email' => __('E-mail'), 'visit' => __('Visit'), 'other' => __('Other')];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
