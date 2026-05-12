<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'ai_enabled',
        'ai_mode',
        'ai_model',
        'ai_temperature',
        'ai_max_tokens',
        'ai_system_prompt',
        'ai_business_hours_only',
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'active'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'ai_enabled' => 'boolean',
        'ai_temperature' => 'decimal:2',
        'ai_max_tokens' => 'integer',
        'ai_business_hours_only' => 'boolean',
    ];

    /**
     * Transient accessor: resolves the active WhatsApp instance for legacy `$user->phone` usage.
     */
    public function getInstanceActive()
    {
        // First connected instance (randomized when several exist)
        $activeInstance = $this->instances()
            ->where('status', 'connected')
            ->inRandomOrder()
            ->first();

        // Return the model or null so callers can null-check safely
        return $activeInstance ? $activeInstance : null;
    }


    /**
     * Always encrypt password when it is updated.
     *
     * @param $value
     * @return string
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
        return $this;
    }


    /**
     * Check if user is active
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->active;
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function instances()
    {
        return $this->hasMany('App\Models\Instance', 'user_id', 'id');
    }

    public function aiSessions()
    {
        return $this->hasMany('App\Models\AiSession', 'user_id', 'id');
    }

    public function aiRules()
    {
        return $this->hasMany('App\Models\AiRule', 'user_id', 'id');
    }
}
