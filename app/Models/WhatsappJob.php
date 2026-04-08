<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Contact;

class WhatsappJob extends Model
{
    use HasFactory;



    /**
     * Tabela associada ao blueprint fornecido.
     */
    protected $table = 'whatsapp_job';

    /**
     * Atributos que podem ser preenchidos em massa.
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
     * Casting de tipos para facilitar a manipulação do JSON no Laravel.
     * Sem isso, 'payload' e 'resposta' virão como strings, dificultando o loop.
     */
    protected $casts = [
        'payload' => 'array',
        'resposta' => 'array',
        'status' => 'string', // Garante consistência com o ENUM
    ];


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    /**
     * Escopo para facilitar a busca no Comando de envio.
     * Uso: WhatsappJob::pendentes()->get();
     */
    public function scopePendentes($query)
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
                SUM(CASE WHEN evolution_status IN ("DELIVERED", "READ", "PLAYED", "delivered", "read", "played") THEN 1 ELSE 0 END) as entregues
            ')
            ->first();

        if (!$stats || $stats->total == 0) return 0;

        return round(($stats->entregues / $stats->total) * 100, 1);
    }

    public static function getErrorRate($user_id)
    {
        // 1. Total de jobs do usuário (denominador)
        $total = \App\Models\WhatsappJob::where('user_id', $user_id)->count();

        if ($total === 0) {
            return 0;
        }

        // 2. Total de erros aplicando a lógica OU (status ou evolution_status)
        $errors = \App\Models\WhatsappJob::where('user_id', $user_id)
            ->where(function ($query) {
                $query->where('status', 'erro')
                    ->orWhere('evolution_status', 'error');
            })
            ->count();

        // 3. Retorna apenas o valor calculado: (erros / total) * 100
        return round(($errors / $total) * 100, 1);
    }
}
