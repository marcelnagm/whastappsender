<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'campaign_id',
        'campaign_item_id',
        'evolution_status', 
        'erro_mensagem',
        'tentativas',
        'user_id'
        
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
    public function campaignItem()
    {
        return $this->hasOne('App\Models\CampaignItem', 'id', 'campaign_item_id');
    }
}