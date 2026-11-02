<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use App\Models\CampaignItem;

/**
 * The campaign message sent to whoever signs up on the site. Chosen by the administrator (Admin > Signup welcome);
 * WHATSAPP_MESSAGE_UP in .env is only the fallback for installs that never saved the setting. Saving "none" turns it off.
 */
class SignupWelcome
{
    public const KEY = 'signup_welcome_item_id';

    /** Id of the chosen message; null = disabled or not configured. */
    public function itemId(): ?int
    {
        $value = AppSetting::has(self::KEY) ? AppSetting::get(self::KEY) : config('services.whatsapp.message_up');

        return $value !== null && $value !== '' && (int) $value > 0 ? (int) $value : null;
    }

    public function item(): ?CampaignItem
    {
        $id = $this->itemId();

        return $id ? CampaignItem::find($id) : null;
    }

    public function usesEnvFallback(): bool
    {
        return !AppSetting::has(self::KEY) && $this->itemId() !== null;
    }

    public function save(?int $itemId): void
    {
        AppSetting::put(self::KEY, $itemId ? (string) $itemId : '');
    }
}
