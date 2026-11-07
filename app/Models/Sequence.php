<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $fillable = ['user_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function steps()
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position');
    }

    public function enrollments()
    {
        return $this->hasMany(SequenceEnrollment::class);
    }
}
