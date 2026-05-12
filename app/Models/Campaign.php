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
    
    
    public function __toString()
    {
        return "Campaign ({$this->id}) {$this->name}";
    }
    
    public function getSuccessRate()
{
    $stats = \App\Models\WhatsappJob::where('campaign_id', $this->id)
        ->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "processado" THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status = "erro" THEN 1 ELSE 0 END) as error_count
        ')
        ->first();

    if (!$stats || $stats->total == 0) {
        return 0;
    }

    // Success rate: (successful sends / total) * 100
    return round(($stats->success_count / $stats->total) * 100, 2);
}

    public function delete()
    {
        foreach ($this->campaignItems()->get() as $item) {
            $item->delete();
        }

        return parent::delete();
    }

    public function summary()
    {
        return WhatsappJob::where('campaign_id',$this->id)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(case when status = 'error' or evolution_status='error' then 1 end) as errors")
            ->selectRaw("count(case when evolution_status = 'SERVER_ACK' then 1 end) as sent")
            ->selectRaw("count(case when evolution_status = 'DELIVERED_ACK' then 1 end) as delivered")
            ->selectRaw("count(case when evolution_status = 'READ' then 1 end) as read_count")
            
            ->first();
        
    }




}
