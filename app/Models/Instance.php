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
        'tiktok_token', 'tiktok_refresh_token', 'tiktok_open_id', 'tiktok_username', 'tiktok_token_expires_at', 'tiktok_refresh_expires_at',
        'facebook_token', 'facebook_page_id', 'facebook_page_name',
        'email_address', 'email_from_name', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
        'email_last_uid', 'email_uidvalidity', 'email_checked_at', 'email_error', 'webchat_config', 'default_assignee', 'email_signature',
    ];

    protected $hidden = ['telegram_token', 'telegram_secret', 'instagram_token', 'facebook_token', 'tiktok_token', 'tiktok_refresh_token', 'smtp_password', 'imap_password'];

    /**
     * Attribute casting.
     * Keeps status as string and timestamps as Carbon instances.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'webchat_config' => 'array',
        'telegram_token' => 'encrypted',
        'instagram_token' => 'encrypted',
        'facebook_token' => 'encrypted',
        'tiktok_token' => 'encrypted',
        'tiktok_refresh_token' => 'encrypted',
        'smtp_password' => 'encrypted',
        'imap_password' => 'encrypted',
        'email_checked_at' => 'datetime',
        'tiktok_token_expires_at' => 'datetime',
        'tiktok_refresh_expires_at' => 'datetime',
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

    public function isFacebook(): bool
    {
        return $this->channel === 'facebook';
    }

    public function isTiktok(): bool
    {
        return $this->channel === 'tiktok';
    }

    public function isEmail(): bool
    {
        return $this->channel === 'email';
    }

    public function isWebchat(): bool
    {
        return $this->channel === 'webchat';
    }

    public function channelLabel(): string
    {
        return ['telegram' => 'Telegram', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'email' => 'Email', 'webchat' => 'Web chat'][$this->channel] ?? 'WhatsApp';
    }

    /** True for numbers served by the Evolution API (the default channel). */
    public function isWhatsapp(): bool
    {
        return !in_array($this->channel, ['telegram', 'instagram', 'tiktok', 'facebook', 'email', 'webchat'], true);
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
