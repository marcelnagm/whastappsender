<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class Contact
 *
 * @property $id
 * @property $name
 * @property $contact
 * @property $user_id
 * @property $created_at
 * @property $updated_at
 *
 * @property User $user
 * @package App
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Contact extends Model
{

    static $rules = [
        'name' => 'required',
        'contact' => 'required',
    ];

    protected $perPage = 20;

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'contact', 'email', 'user_id', 'ignore_me', 'lid', 'status', 'score', 'profile_url'];


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function contactFormat()
    {

        return str_ireplace(['-', '+', ' '], '', $this->contact);
    }

    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    public function whatsappjobs() // Sugiro plural para hasMany
    {
        return $this->hasMany('App\Models\WhatsappJob', 'contact_id', 'id');
    }


    public function syncFromEvolution()
    {
        // Previne requisições desnecessárias se o número for inválido
        if (empty($this->contact)) {
            return false;
        }

        try {
            $config = config('services.whatsapp');
            $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
            $apiKey = $config['apikey'];
            $instance = $this->user()->first()->phone;
            // dd($instance);
            // Endpoint para buscar informações do número/perfil
            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json'
            ])->post("{$baseUrl}/chat/fetchProfilePictureUrl/{$instance}", [
                'number' => $this->contact
            ]);

            // Se retornar 200/201 e tiver a URL
            if ($response->successful()) {
                $data = $response->json();

                // A Evolution v2.3 costuma retornar { "profilePictureUrl": "..." } ou { "url": "..." }
                $url = $data['profilePictureUrl'] ?? $data['url'] ?? null;

                if ($url && $this->profile_url !== $url) {
                    $this->profile_url = $url;
                    $this->status = 'ativo'; // Se tem foto, o número é válido
                    $this->save();
                    return true;
                }
            }

            // Se o 404 persisti
        } catch (\Exception $e) {
            dd($e);
            Log::error("Falha ao sincronizar contato #{$this->id} com Evolution: " . $e->getMessage());
        }

        return false;
    }
    public function addScore($score){
        $this->score += $score;
    }

}
