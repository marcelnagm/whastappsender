<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContactForm extends Model
{
    protected $fillable = ['user_id', 'name', 'title', 'description', 'fields', 'submit_label', 'success_message', 'redirect_url', 'accent_color',
        'allowed_origins', 'instance_id', 'reply_channels', 'ai_reply', 'active'];

    protected $casts = ['fields' => 'array', 'reply_channels' => 'array', 'ai_reply' => 'boolean', 'active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (ContactForm $f) {
            $f->public_key = $f->public_key ?: Str::lower(Str::random(24));
        });
    }

    public function submissions()
    {
        return $this->hasMany(ContactFormSubmission::class);
    }

    public function scriptUrl(): string
    {
        return url('/f/' . $this->public_key . '.js');
    }

    public function snippet(): string
    {
        return '<script src="' . $this->scriptUrl() . '" async></script>';
    }

    /** @return string[] domains (lowercase, no scheme), empty = any site */
    public function origins(): array
    {
        return array_values(array_filter(array_map(
            fn ($l) => Str::lower(trim(preg_replace('#^https?://#i', '', trim($l)), " /\t")),
            preg_split('/[\r\n,]+/', (string) $this->allowed_origins)
        )));
    }
}
