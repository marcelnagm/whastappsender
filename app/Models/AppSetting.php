<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Key/value settings edited by the administrator. */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function has(string $key): bool
    {
        return self::tableExists() && static::where('key', $key)->exists();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::tableExists()) {
            return $default; // before "php artisan migrate": keep working from .env
        }

        $row = static::where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private static function tableExists(): bool
    {
        return Schema::hasTable('app_settings');
    }
}
