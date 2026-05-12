<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Contact;

class WhatsappJob extends Model
{
    use HasFactory;



    /**
     * Table backing this Eloquent model.
     */
    protected $table = 'whatsapp_job';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'endpoint',
        'status',
        'payload',
        'resposta',
        'message_id',
        'instance_id',
        'campaign_id',
        'campaign_item_id',
        'evolution_status',
        'erro_mensagem',
        'tentativas',
        'user_id',
        'contact_id'

    ];

    /**
     * Casts JSON columns to PHP arrays for easier access.
     */
    protected $casts = [
        'payload' => 'array',
        'resposta' => 'array',
        'status' => 'string', // Keep aligned with DB enum/text column
    ];


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    /**
     * Query scope for pending jobs.
     * Usage: WhatsappJob::pending()->get();
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pendente');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function campaign()
    {
        return $this->hasOne('App\Models\Campaign', 'id', 'campaign_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function campaignItem()
    {
        return $this->hasOne('App\Models\CampaignItem', 'id', 'campaign_item_id');
    }

    public static function getDeliveryRate($user_id)
    {
        $stats = \App\Models\WhatsappJob::where('user_id', $user_id)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN evolution_status IN ("DELIVERED", "READ", "PLAYED", "delivered", "read", "played") THEN 1 ELSE 0 END) as delivered_count
            ')
            ->first();

        if (!$stats || $stats->total == 0) return 0;

        return round(($stats->delivered_count / $stats->total) * 100, 1);
    }

    public static function getErrorRate($user_id)
    {
        // 1. Denominator: all jobs for this user
        $total = \App\Models\WhatsappJob::where('user_id', $user_id)->count();

        if ($total === 0) {
            return 0;
        }

        // 2. Numerator: rows failed internally or marked error by Evolution
        $errors = \App\Models\WhatsappJob::where('user_id', $user_id)
            ->where(function ($query) {
                $query->where('status', 'erro')
                    ->orWhere('evolution_status', 'error');
            })
            ->count();

        // 3. Error rate percentage
        return round(($errors / $total) * 100, 1);
    }
}
