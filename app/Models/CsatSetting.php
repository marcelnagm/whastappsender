<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsatSetting extends Model
{
    protected $fillable = ['user_id', 'enabled', 'scale', 'question', 'thanks', 'expire_hours'];

    protected $casts = ['enabled' => 'boolean', 'expire_hours' => 'integer'];

    public static function for(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId], ['enabled' => false, 'scale' => 'csat', 'expire_hours' => 24]);
    }

    public function range(): array
    {
        return $this->scale === 'nps' ? [0, 10] : [1, 5];
    }

    public function questionText(): string
    {
        if (trim((string) $this->question) !== '') {
            return $this->question;
        }

        return $this->scale === 'nps'
            ? __('How likely are you to recommend us to a friend? Reply with a number from 0 to 10.')
            : __('How do you rate our service? Reply with a number from 1 (very bad) to 5 (excellent).');
    }

    public function thanksText(): string
    {
        return trim((string) $this->thanks) !== '' ? $this->thanks : __('Thank you for your feedback!');
    }
}
