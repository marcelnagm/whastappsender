<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    protected $fillable = ['user_id', 'pipeline_id', 'stage_id', 'contact_id', 'chat_id', 'assigned_to', 'title', 'value', 'status', 'lost_reason',
        'expected_close_date', 'source', 'origin', 'position', 'closed_at'];

    protected $casts = ['value' => 'decimal:2', 'expected_close_date' => 'date', 'closed_at' => 'datetime'];

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
