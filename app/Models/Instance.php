<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Instance extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'user_id',
        'warmup',
        'name',
        'instance_name',
        'status',
        'channel',
        'telegram_token',
        'telegram_username',
        'telegram_secret', 'instagram_token', 'instagram_id', 'instagram_username',
    ];

    protected $hidden = ['telegram_token', 'telegram_secret', 'instagram_token'];

    /**
     * Attribute casting.
     * Keeps status as string and timestamps as Carbon instances.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'telegram_token' => 'encrypted',
        'instagram_token' => 'encrypted',
        'disconnected_at' => 'datetime',
        'down_alerted_at' => 'datetime',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }

    public function aiSessions()
    {
        return $this->hasMany('App\Models\AiSession', 'instance_id', 'id');
    }

    /**
     * Model boot: auto-fill logic on create.
     * Generates `instance_name` when the client leaves it blank.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($instance) {
            if (empty($instance->instance_name)) {
                // Unique slug: name-userid-timestamp
                $instance->instance_name = Str::slug($instance->name) . '-' . $instance->user_id . '-' . time();
            }
        });
    }

    /**
     * Whether this row represents a connected session.
     */
    public function isTelegram(): bool
    {
        return $this->channel === 'telegram';
    }

    public function isInstagram(): bool
    {
        return $this->channel === 'instagram';
    }

    /** True for numbers served by the Evolution API (the default channel). */
    public function isWhatsapp(): bool
    {
        return !in_array($this->channel, ['telegram', 'instagram'], true);
    }

    /** whatsapp | telegram | instagram */
    public function channelKey(): string
    {
        return $this->isWhatsapp() ? 'whatsapp' : (string) $this->channel;
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isMine()
    {
        return $this->user_id === Auth::user()->id;
    }
}
