<?php

namespace App\Services\Channels;

use App\Models\Instance;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Services\TikTok\TikTokClient;
use App\Services\TikTok\TikTokException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Connect, renew and remove Telegram bots, Instagram accounts and TikTok accounts.
 * Used by the settings pages and by the REST API / agent tools, so both behave the same.
 */
class ChannelAccounts
{
    // ---- Telegram ----------------------------------------------------------------------------------------------

    /** @throws ChannelException */
    public function connectTelegram(User $user, string $token): Instance
    {
        try {
            $me = (new TelegramClient($token))->getMe();
        } catch (TelegramException $e) {
            throw new ChannelException(__('Telegram rejected this token: :error', ['error' => $e->getMessage()]));
        }

        $username = (string) ($me['username'] ?? '');
        if ($username === '' || Instance::where('channel', 'telegram')->where('telegram_username', $username)->exists()) {
            throw new ChannelException(__('This bot is already connected.'));
        }

        app(Entitlements::class)->authorizeUsage($user, 'instances');

        $instance = $user->instances()->create([
            'name' => (string) ($me['first_name'] ?? $username),
            'instance_name' => 'tg-' . $user->id . '-' . Str::lower(Str::random(8)),
            'status' => 'connected',
            'channel' => 'telegram',
            'telegram_token' => $token,
            'telegram_username' => $username,
            'telegram_secret' => bin2hex(random_bytes(24)),
        ]);

        try {
            $this->registerTelegramWebhook($instance);
        } catch (ChannelException $e) {
            $instance->delete();

            throw $e;
        }

        return $instance;
    }

    /** @throws ChannelException */
    public function registerTelegramWebhook(Instance $instance): void
    {
        $url = rtrim((string) config('app.url'), '/') . route('webhooks.telegram', ['instance' => $instance->id], false);

        try {
            if (!str_starts_with($url, 'https://')) {
                throw new TelegramException(__('Telegram only delivers messages to https addresses. Set APP_URL to your public https domain.'));
            }
            TelegramClient::for($instance)->setWebhook($url, (string) $instance->telegram_secret);
        } catch (TelegramException $e) {
            throw new ChannelException(__('Could not register the webhook: :error', ['error' => $e->getMessage()]));
        }
    }

    // ---- Instagram ----------------------------------------------------------------------------------------------

    /** @throws ChannelException */
    public function connectInstagram(User $user, string $token): Instance
    {
        if (!config('instagram.app_secret') || !config('instagram.verify_token')) {
            throw new ChannelException(__('Instagram is not configured on this server (INSTAGRAM_APP_SECRET and INSTAGRAM_VERIFY_TOKEN).'));
        }

        $client = new InstagramClient($token);
        try {
            $me = $client->me();
        } catch (InstagramException $e) {
            throw new ChannelException(__('Instagram rejected this token: :error', ['error' => $e->getMessage()]));
        }

        $igId = (string) ($me['user_id'] ?? $me['id'] ?? '');
        if ($igId === '' || Instance::where('channel', 'instagram')->where('instagram_id', $igId)->exists()) {
            throw new ChannelException(__('This Instagram account is already connected.'));
        }

        app(Entitlements::class)->authorizeUsage($user, 'instances');

        $username = (string) ($me['username'] ?? $igId);
        $instance = $user->instances()->create([
            'name' => (string) ($me['name'] ?? $username) ?: $username,
            'instance_name' => 'ig-' . $user->id . '-' . Str::lower(Str::random(8)),
            'status' => 'connected',
            'channel' => 'instagram',
            'instagram_token' => $token,
            'instagram_id' => $igId,
            'instagram_username' => $username,
        ]);

        try {
            $client->subscribe();
        } catch (InstagramException $e) {
            $instance->delete();

            throw new ChannelException(__('Could not subscribe the account to messages: :error', ['error' => $e->getMessage()]));
        }

        return $instance;
    }

    // ---- TikTok (OAuth) --------------------------------------------------------------------------------------------

    /** @throws ChannelException */
    public function tiktokAuthUrl(User $user): string
    {
        if (!config('tiktok.app_id') || !config('tiktok.app_secret')) {
            throw new ChannelException(__('TikTok is not configured on this server (TIKTOK_APP_ID and TIKTOK_APP_SECRET).'));
        }

        $state = Crypt::encryptString(json_encode(['u' => $user->id, 'exp' => now()->addMinutes(15)->timestamp, 'n' => Str::random(12)]));

        return TikTokClient::authUrl($state);
    }

    /** User id the OAuth state was issued for, or null when forged / expired. */
    public function tiktokStateUser(?string $state): ?int
    {
        try {
            $s = json_decode(Crypt::decryptString((string) $state), true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($s) && (int) ($s['exp'] ?? 0) >= time() ? (int) ($s['u'] ?? 0) : null;
    }

    /**
     * @return array{0:Instance,1:string} the instance and "created" | "renewed"
     * @throws ChannelException
     */
    public function connectTikTok(User $user, string $code): array
    {
        try {
            $tokens = TikTokClient::exchange($code);
        } catch (TikTokException $e) {
            throw new ChannelException(__('TikTok rejected the authorization: :error', ['error' => $e->getMessage()]));
        }

        $openId = (string) ($tokens['open_id'] ?? $tokens['business_id'] ?? '');
        if ($openId === '' || empty($tokens['access_token'])) {
            throw new ChannelException(__('TikTok rejected the authorization: :error', ['error' => 'no account returned']));
        }

        $existing = Instance::where('channel', 'tiktok')->where('tiktok_open_id', $openId)->first();
        if ($existing && (int) $existing->user_id !== (int) $user->id) {
            throw new ChannelException(__('This TikTok account is already connected.'));
        }
        if ($existing) { // authorising again renews the tokens
            TikTokClient::storeTokens($existing, $tokens);
            $existing->update(['status' => 'connected']);

            return [$existing, 'renewed'];
        }

        app(Entitlements::class)->authorizeUsage($user, 'instances');

        $name = (string) ($tokens['display_name'] ?? $tokens['username'] ?? 'TikTok ' . substr($openId, -6));
        $instance = $user->instances()->create([
            'name' => $name,
            'instance_name' => 'tt-' . $user->id . '-' . Str::lower(Str::random(8)),
            'status' => 'connected',
            'channel' => 'tiktok',
            'tiktok_open_id' => $openId,
            'tiktok_username' => $name,
        ]);
        TikTokClient::storeTokens($instance, $tokens);

        return [$instance, 'created'];
    }

    // ---- common ------------------------------------------------------------------------------------------------------

    /** Renews tokens / webhook of a Telegram, Instagram or TikTok account. @throws ChannelException */
    public function renew(Instance $instance): void
    {
        try {
            if ($instance->isTelegram()) {
                $this->registerTelegramWebhook($instance);
            } elseif ($instance->isInstagram()) {
                $client = InstagramClient::for($instance);
                if ($new = $client->refresh()) {
                    $instance->update(['instagram_token' => $new]);
                    $client = new InstagramClient($new);
                }
                $client->subscribe();
            } elseif ($instance->isTiktok()) {
                TikTokClient::for($instance)->refreshIfNeeded(true);
            } else {
                throw new ChannelException(__('Only Telegram, Instagram and TikTok accounts can be renewed here.'));
            }
        } catch (InstagramException | TikTokException $e) {
            throw new ChannelException(__('Could not renew the connection: :error', ['error' => $e->getMessage()]));
        }
    }

    /** Removes a Telegram, Instagram or TikTok account (best-effort cleanup at the provider). */
    public function remove(Instance $instance): void
    {
        try {
            if ($instance->isTelegram()) {
                TelegramClient::for($instance)->deleteWebhook();
            } elseif ($instance->isInstagram()) {
                InstagramClient::for($instance)->unsubscribe();
            }
        } catch (\Throwable $e) {
            // the token may already be revoked: remove it anyway
        }

        $instance->delete();
    }
}
