<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Campaign
 *
 * @property $id
 * @property $name
 * @property $user_id
 * @property $created_at
 * @property $updated_at
 *
 * @property CampaignItem[] $campaignItems
 * @property User $user
 * @package App
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Campaign extends Model
{

    protected $table = 'campaign';

    static $rules = [
		'name' => 'required',
    ];

    protected $perPage = 20;

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['name','user_id'];


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function campaignItems()
    {
        return $this->hasMany('App\Models\CampaignItem', 'campaign_id', 'id');
    }
    
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }
    

}
