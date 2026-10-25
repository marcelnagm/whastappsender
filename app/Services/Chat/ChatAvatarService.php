<?php

namespace App\Services\Chat;

use App\Jobs\FetchChatAvatarJob;
use App\Models\Chat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a local copy of each contact's WhatsApp profile picture.
 *
 * Why a local copy: the picture URLs Evolution returns point to the WhatsApp CDN, are signed and
 * expire after a few days, so storing only the URL leaves broken images behind.
 */
class ChatAvatarService
{
    public const REFRESH_DAYS = 7;       // look again for a changed picture
    public const RETRY_MINUTES = 30;     // after a temporary failure (instance offline, timeout...)
    public const MAX_BYTES = 3 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(private EvolutionChatClient $evolution)
    {
    }

    public function isDue(Chat $chat): bool
    {
        return (bool) $chat->phone
            && (!$chat->avatar_next_check_at || $chat->avatar_next_check_at->lte(now()));
    }

    /**
     * Queues a background fetch when the picture was never fetched or is due for a refresh.
     * The cache lock prevents a burst of webhooks / list reloads from queueing the same chat many times.
     */
    public function queueIfDue(Chat $chat): void
    {
        if (!$this->isDue($chat)) {
            return;
        }

        if (!Cache::add('chat-avatar:' . $chat->id, 1, 300)) {
            return;
        }

        FetchChatAvatarJob::dispatch($chat->id)->onQueue('default');
    }

    /**
     * @return string 'stored' | 'none' (contact has no picture) | 'error' (will retry soon)
     */
    public function refresh(Chat $chat): string
    {
        $instance = $chat->instance;
        if (!$instance || !$chat->phone) {
            return 'error';
        }

        $found = $this->evolution->fetchProfilePicture($instance->instance_name, $chat->phone);

        if ($found['status'] === 'none') {
            $this->forget($chat);
            $chat->update(['avatar_checked_at' => now(), 'avatar_next_check_at' => now()->addDays(self::REFRESH_DAYS)]);

            return 'none';
        }

        if ($found['status'] !== 'ok' || !$this->download($chat, (string) $found['url'])) {
            $chat->update(['avatar_next_check_at' => now()->addMinutes(self::RETRY_MINUTES)]);

            return 'error';
        }

        return 'stored';
    }

    private function download(Chat $chat, string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SAMA-Envios)'])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning("Chat avatar download failed for chat {$chat->id}: " . $e->getMessage());

            return false;
        }

        if (!$response->successful()) {
            return false;
        }

        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();
        $ext = self::EXTENSIONS[$mime] ?? null;

        // Only real images of a sane size are kept (the URL comes from a remote service)
        if (!$ext || $body === '' || strlen($body) > self::MAX_BYTES) {
            return false;
        }

        $path = "chat-avatars/{$chat->instance_id}/{$chat->id}.{$ext}";
        if ($chat->avatar_path && $chat->avatar_path !== $path) {
            Storage::delete($chat->avatar_path);
        }
        Storage::put($path, $body);

        $chat->update([
            'avatar_path' => $path,
            'avatar_checked_at' => now(),
            'avatar_next_check_at' => now()->addDays(self::REFRESH_DAYS),
        ]);

        return true;
    }

    private function forget(Chat $chat): void
    {
        if ($chat->avatar_path) {
            Storage::delete($chat->avatar_path);
            $chat->avatar_path = null;
        }
    }

    public static function mimeForPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return array_search($ext, self::EXTENSIONS, true) ?: 'application/octet-stream';
    }
}
