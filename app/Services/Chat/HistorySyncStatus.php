<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;

/** Progress of the chat history import of one number, kept in the cache so the screen can poll it. */
class HistorySyncStatus
{
    private const TTL_MINUTES = 60;

    /** After this long without news a "queued"/"running" import is considered dead (worker stopped, job lost) */
    private const STALE_SECONDS = 900;

    private static function key(int $instanceId): string
    {
        return "chat-history-sync:status:{$instanceId}";
    }

    public static function get(int $instanceId): array
    {
        $s = Cache::get(self::key($instanceId));
        if (!$s) {
            return ['state' => 'idle'];
        }

        if (in_array($s['state'], ['queued', 'running'], true) && now()->timestamp - $s['updated_at'] > self::STALE_SECONDS) {
            $s['state'] = 'failed';
            $s['error'] = $s['error'] ?? 'no_progress';
        }

        $s['waiting_seconds'] = $s['state'] === 'queued' ? max(0, now()->timestamp - $s['queued_at']) : 0;

        return $s;
    }

    public static function isBusy(int $instanceId): bool
    {
        return in_array(self::get($instanceId)['state'], ['queued', 'running'], true);
    }

    public static function queued(int $instanceId): array
    {
        return self::put($instanceId, ['state' => 'queued', 'queued_at' => now()->timestamp, 'chats_total' => 0, 'chats_done' => 0, 'messages' => 0, 'error' => null]);
    }

    public static function update(int $instanceId, array $fields): array
    {
        return self::put($instanceId, $fields + (Cache::get(self::key($instanceId)) ?: ['queued_at' => now()->timestamp]));
    }

    private static function put(int $instanceId, array $fields): array
    {
        $current = Cache::get(self::key($instanceId)) ?: [];
        $new = array_merge($current, $fields, ['updated_at' => now()->timestamp]);
        Cache::put(self::key($instanceId), $new, now()->addMinutes(self::TTL_MINUTES));

        return $new;
    }
}
