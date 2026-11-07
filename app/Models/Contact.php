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
    protected $fillable = ['name', 'contact', 'email', 'user_id', 'ignore_me', 'lid', 'status', 'score', 'profile_url', 'linked_contact_id', 'deactivated_reason', 'deactivated_at', 'score_breakdown', 'score_updated_at', 'score_locked', 'consent_status', 'consent_source', 'consent_at', 'consent_revoked_at', 'custom', 'company_id'];


    /** Contacts that may receive campaigns: when the account requires consent, only those with a recorded opt-in. */
    public function scopeWithConsent($query, int $userId)
    {
        if (User::whereKey($userId)->value('consent_required')) {
            $query->where($query->getModel()->getTable() . '.consent_status', 'granted');
        }

        return $query;
    }

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
    protected $casts = [
        'deactivated_at' => 'datetime',
        'score_breakdown' => 'array',
        'score_updated_at' => 'datetime',
        'score_locked' => 'boolean',
        'consent_at' => 'datetime',
        'consent_revoked_at' => 'datetime',
        'custom' => 'array',
    ];

    protected static function booted(): void
    {
        // a contact only joins a company of its own account; the deals of the person follow
        static::saving(function (Contact $c) {
            if ($c->company_id && !\App\Models\Company::where('id', $c->company_id)->where('user_id', $c->user_id)->exists()) {
                $c->company_id = null;
            }
        });
        static::saved(function (Contact $c) {
            if ($c->wasChanged('company_id')) {
                \App\Models\Deal::where('contact_id', $c->id)->where('user_id', $c->user_id)->update(['company_id' => $c->company_id]);
            }
        });
        // a contact created already with consent (import, API) leaves its proof
        static::created(function (Contact $c) {
            if ($c->consent_status === 'granted') {
                app(\App\Services\Consent\Consent::class)->log($c, 'granted', $c->consent_source ?: 'api', $c->consent_source === 'import' ? __('Confirmed by the account when importing the list') : null);
            }
        });

        // a score typed by a person (form, API, import) is a decision: the automatic score never overwrites it.
        // The scorer writes with query-builder updates, which do not fire this event.
        static::saving(function (Contact $c) {
            if ($c->isDirty('score') && !$c->isDirty('score_locked') && ($c->exists || (int) $c->score !== 0)) {
                $c->score_locked = true;
            }

            // a person put the contact back to "ativo": the automatic deactivation no longer applies
            if ($c->exists && $c->isDirty('status') && $c->status === 'ativo' && !$c->isDirty('deactivated_reason') && $c->deactivated_reason) {
                $c->deactivated_reason = null;
                $c->deactivated_at = null;
            }
        });

        // one e-mail address on a WhatsApp contact and on an e-mail conversation = the same person
        static::saved(function (Contact $c) {
            if ($c->wasChanged('email') && $c->email && !str_contains((string) $c->contact, ':')) {
                \App\Services\Channels\ContactLinks::linkByEmail($c);
            }
        });
    }

    public function scopeTier($query, string $tier)
    {
        $t = config('contacts.score.tiers');

        return match ($tier) {
            'hot' => $query->where('score', '>=', $t['hot']),
            'warm' => $query->where('score', '>=', $t['warm'])->where('score', '<', $t['hot']),
            'cold' => $query->where('score', '>=', $t['cold'])->where('score', '<', $t['warm']),
            'frozen' => $query->where(fn ($w) => $w->whereNull('score')->orWhere('score', '<', $t['cold'])),
            default => $query,
        };
    }

    public function scoreTier(): string
    {
        return \App\Services\Contacts\ContactScorer::tier((int) $this->score);
    }

    public function addScore($score){
        $this->score += $score;
    }

}
