<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Instance extends Model
{
    use HasFactory;

    /**
     * Atributos que podem ser preenchidos em massa.
     */
    protected $fillable = [
        'user_id',
        'warmup',
        'name',
        'instance_name',
        'status'
    ];

    /**
     * Conversão de tipos (Casting).
     * Garante que status seja tratado como string e datas como Carbon.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relacionamento: Uma instância pertence a um único Usuário.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Boot do Model: Lógica automática na criação.
     * Aqui garantimos que o 'instance_name' seja gerado se estiver vazio.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($instance) {
            if (empty($instance->instance_name)) {
                // Gera um nome único: nome-do-usuario-id-timestamp
                $instance->instance_name = Str::slug($instance->name) . '-' . $instance->user_id . '-' . time();
            }
        });
    }

    /**
     * Helper: Verifica se a instância está conectada.
     */
    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isMine()
    {
        return $this->user_id === Auth::user()->id;
    }
}
