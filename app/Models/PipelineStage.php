<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    public const KINDS = ['open', 'won', 'lost'];

    protected $fillable = ['user_id', 'pipeline_id', 'name', 'kind', 'color', 'position'];

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class, 'stage_id')->orderBy('position')->orderBy('id');
    }
}
