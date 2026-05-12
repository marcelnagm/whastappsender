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

    public function whatsappjobs()
    {
        return $this->hasMany('App\Models\WhatsappJob', 'contact_id', 'id');
    }

    public function aiSessions()
    {
        return $this->hasMany('App\Models\AiSession', 'contact_id', 'id');
    }


    public function syncFromEvolution()
    {
        // Skip Evolution calls when the number is empty
        if (empty($this->contact)) {
            return false;
        }

        try {
            $config = config('services.whatsapp');
            $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
            $apiKey = $config['apikey'];
            $instance = $this->user()->first()->getInstanceActive();
            // dd($instance);
            // Evolution endpoint: profile picture / number metadata
            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json'
            ])->post("{$baseUrl}/chat/fetchProfilePictureUrl/{$instance}", [
                'number' => $this->contact
            ]);

            // Success path
            if ($response->successful()) {
                $data = $response->json();

                // Evolution v2.3 may return { "profilePictureUrl": "..." } or { "url": "..." }
                $url = $data['profilePictureUrl'] ?? $data['url'] ?? null;

                if ($url && $this->profile_url !== $url) {
                    $this->profile_url = $url;
                    $this->status = 'ativo'; // Photo implies a valid WhatsApp user
                    $this->save();
                    return true;
                }
            }

            // Non-success responses fall through
        } catch (\Exception $e) {
            dd($e);
            Log::error("Failed to sync contact #{$this->id} with Evolution: " . $e->getMessage());
        }

        return false;
    }
    public function addScore($score){
        $this->score += $score;
    }

}
