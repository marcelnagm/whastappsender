<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An organization (B2B account) that groups contacts and deals. */
class Company extends Model
{
    protected $fillable = ['user_id', 'name', 'domain', 'phone', 'industry', 'notes'];

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }
}
