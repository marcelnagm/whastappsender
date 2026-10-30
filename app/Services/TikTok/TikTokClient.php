<?php

namespace App\Services\TikTok;

use App\Models\Instance;
use App\Services\Instagram\InstagramClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * TikTok Business Messaging API for one connected account. Tokens travel in headers and are scrubbed from errors.
 * TikTok answers HTTP 200 with `code != 0` on failure, so success is decided by the body, not the status.
 */
class TikTokClient
{
    public function __construct(private Instance $instance)
    {
    }

    public static function for(Instance $instance): self
    {
        return new self($instance);
    }

    // ---- app level (OAuth) --------------------------------------------------------------------------------------

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/') . '/tiktok/callback';
    }

    public static function authUrl(string $state): string
    {
        return config('tiktok.auth_url') . '?' . http_build_query([
            'app_id' => config('tiktok.app_id'), 'state' => $state, 'redirect_uri' => self::redirectUri(),
        ]);
    }

    private static function appCall(string $path, array $params): array
    {
        try {
            $response = Http::timeout((int) config('tiktok.timeout', 30))->acceptJson()->asJson()->post(config('tiktok.api_url') . $path, $params);

            return self::unwrapStatic($response);
        } catch (TikTokException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TikTokException(self::scrubStatic($e->getMessage()));
        }
    }

    private static function scrubStatic(string $text): string
    {
        foreach (array_filter([config('tiktok.app_secret')]) as $secret) {
            $text = str_replace($secret, '[secret]', $text);
        }

        return $text;
    }

    /** @return array<string,mixed> */
    private static function unwrapStatic($response): array
    {
        $json = (array) $response->json();
        $code = $json['code'] ?? $json['error_code'] ?? 0;
        if (!$response->successful() || ((int) $code !== 0 && $code !== 'ok')) {
            throw new TikTokException(self::scrubStatic((string) ($json['message'] ?? $json['error_description'] ?? ('HTTP ' . $response->status()))));
        }

        return (array) ($json['data'] ?? $json);
    }

    /** Exchanges the code TikTok sent to the redirect URL for tokens. @return array<string,mixed> */
    public static function exchange(string $code): array
    {
        return self::appCall(config('tiktok.paths.token'), [
            'client_id' => config('tiktok.app_id'), 'client_secret' => config('tiktok.app_secret'),
            'grant_type' => 'authorization_code', 'auth_code' => $code, 'code' => $code, 'redirect_uri' => self::redirectUri(),
        ]);
    }

    /** Stores a token response on the instance (access + rotated refresh token and their expiry). */
    public static function storeTokens(Instance $instance, array $t): void
    {
        $instance->forceFill(array_filter([
            'tiktok_token' => $t['access_token'] ?? null,
            'tiktok_refresh_token' => $t['refresh_token'] ?? null,
            'tiktok_token_expires_at' => isset($t['expires_in']) ? now()->addSeconds((int) $t['expires_in']) : null,
            'tiktok_refresh_expires_at' => isset($t['refresh_token_expires_in']) ? now()->addSeconds((int) $t['refresh_token_expires_in']) : null,
        ], fn ($v) => $v !== null))->save();
    }

    /** Refreshes when the access token is about to expire (tokens last about a day, refresh tokens about a month). */
    public function refreshIfNeeded(bool $force = false): void
    {
        $i = $this->instance;
        if (!$force && $i->tiktok_token_expires_at && $i->tiktok_token_expires_at->gt(now()->addMinutes(10))) {
            return;
        }
        if (!$i->tiktok_refresh_token || ($i->tiktok_refresh_expires_at && $i->tiktok_refresh_expires_at->lte(now()))) {
            throw new TikTokException(__('TikTok authorization expired. Connect the account again.'));
        }

        $t = self::appCall(config('tiktok.paths.refresh'), [
            'client_id' => config('tiktok.app_id'), 'client_secret' => config('tiktok.app_secret'),
            'grant_type' => 'refresh_token', 'refresh_token' => $i->tiktok_refresh_token,
        ]);
        self::storeTokens($i, $t);
    }

    // ---- account level ------------------------------------------------------------------------------------------

    private function scrub(string $text): string
    {
        foreach (array_filter([$this->instance->tiktok_token, $this->instance->tiktok_refresh_token, config('tiktok.app_secret')]) as $secret) {
            $text = str_replace($secret, '[token]', $text);
        }

        return $text;
    }

    private function http(): PendingRequest
    {
        $this->refreshIfNeeded();

        return Http::timeout((int) config('tiktok.timeout', 30))->acceptJson()
            ->withHeaders(['Access-Token' => (string) $this->instance->tiktok_token, 'x-user' => (string) $this->instance->tiktok_token]);
    }

    private function call(string $path, array $params): array
    {
        try {
            $body = ['business_id' => $this->instance->tiktok_open_id] + $params;

            return self::unwrapStatic($this->http()->asJson()->post(config('tiktok.api_url') . $path, $body));
        } catch (TikTokException $e) {
            throw new TikTokException($this->scrub($e->getMessage()));
        } catch (\Throwable $e) {
            throw new TikTokException($this->scrub($e->getMessage()));
        }
    }

    /** @return string[] ids (one per part; TikTok limits a text to 6000 characters) */
    public function sendText(string $conversationId, string $text): array
    {
        $ids = [];
        foreach (InstagramClient::chunk($text, (int) config('tiktok.max_text', 6000)) as $part) {
            $d = $this->call(config('tiktok.paths.send'), [
                'recipient_type' => 'CONVERSATION', 'recipient' => $conversationId, 'message_type' => 'TEXT', 'text' => ['body' => $part],
            ]);
            $ids[] = (string) ($d['message']['message_id'] ?? '');
        }

        return $ids;
    }

    /** Uploads a JPG/PNG (max 3 MB) and sends it. @return string message id */
    public function sendImage(string $conversationId, string $binary, string $fileName): string
    {
        if (strlen($binary) > (int) config('tiktok.max_image_bytes')) {
            throw new TikTokException(__('TikTok accepts JPG or PNG images up to 3 MB.'));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new TikTokException(__('TikTok accepts JPG or PNG images up to 3 MB.'));
        }

        try {
            $upload = self::unwrapStatic($this->http()->attach('file', $binary, $fileName ?: 'image')->post(
                config('tiktok.api_url') . config('tiktok.paths.upload'),
                ['business_id' => $this->instance->tiktok_open_id, 'media_type' => 'IMAGE']
            ));
        } catch (TikTokException $e) {
            throw new TikTokException($this->scrub($e->getMessage()));
        } catch (\Throwable $e) {
            throw new TikTokException($this->scrub($e->getMessage()));
        }

        $mediaId = (string) ($upload['media_id'] ?? '');
        if ($mediaId === '') {
            throw new TikTokException('Image upload failed.');
        }

        $d = $this->call(config('tiktok.paths.send'), [
            'recipient_type' => 'CONVERSATION', 'recipient' => $conversationId, 'message_type' => 'IMAGE', 'image' => ['media_id' => $mediaId],
        ]);

        return (string) ($d['message']['message_id'] ?? '');
    }

    public function typing(string $conversationId): void
    {
        try {
            $this->call(config('tiktok.paths.send'), [
                'recipient_type' => 'CONVERSATION', 'recipient' => $conversationId, 'message_type' => 'SENDER_ACTION', 'sender_action' => 'TYPING',
            ]);
        } catch (\Throwable $e) {
            // cosmetic
        }
    }

    /** Display name of the person in a conversation (best effort). */
    public function customerName(string $conversationId): ?string
    {
        try {
            $d = $this->call(config('tiktok.paths.messages'), ['conversation_id' => $conversationId]);
            foreach ((array) ($d['participants'] ?? []) as $p) {
                if (($p['role'] ?? '') === 'PERSONAL_ACCOUNT' && !empty($p['display_name'])) {
                    return mb_substr((string) $p['display_name'], 0, 120);
                }
            }
        } catch (\Throwable $e) {
            // optional
        }

        return null;
    }

    /** @return string binary @throws TikTokException */
    public function download(string $conversationId, string $messageId, string $mediaId, string $type = 'IMAGE'): string
    {
        $d = $this->call(config('tiktok.paths.download'), [
            'conversation_id' => $conversationId, 'message_id' => $messageId, 'media_id' => $mediaId, 'media_type' => $type,
        ]);
        $url = (string) ($d['download_url'] ?? '');
        if ($url === '') {
            throw new TikTokException('Media not available.');
        }

        try {
            $response = $this->http()->get($url);
        } catch (\Throwable $e) {
            throw new TikTokException($this->scrub($e->getMessage()));
        }
        if (!$response->successful() || strlen($response->body()) > (int) config('tiktok.max_download_bytes')) {
            throw new TikTokException('File download failed.');
        }

        return $response->body();
    }
}
