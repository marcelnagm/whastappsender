<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Chat\EvolutionChatClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Imports existing conversations stored by Evolution (findChats / findMessages)
 * so the chat is not empty the first time it is opened.
 */
class SyncChatHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $instanceId)
    {
    }

    public function handle(EvolutionChatClient $client, ChatMessageRecorder $recorder): void
    {
        $instance = Instance::find($this->instanceId);
        if (!$instance) {
            return;
        }

        $maxChats = (int) config('chat.history.max_chats', 50);
        $perChat = (int) config('chat.history.per_chat', 30);

        try {
            $chats = $client->findChats($instance->instance_name);
        } catch (\Throwable $e) {
            Log::error('SyncChatHistoryJob findChats failed: ' . $e->getMessage());

            return;
        }

        $imported = 0;
        foreach ($chats as $row) {
            if ($imported >= $maxChats) {
                break;
            }

            $jid = (string) ($row['remoteJid'] ?? ($row['id'] ?? ''));
            if (!str_ends_with($jid, '@s.whatsapp.net') && !str_ends_with($jid, '@lid')) {
                continue;
            }

            try {
                $records = $client->findMessages($instance->instance_name, $jid, 1, $perChat);
            } catch (\Throwable $e) {
                Log::warning("SyncChatHistoryJob findMessages failed for {$jid}: " . $e->getMessage());
                continue;
            }

            // Oldest first so the chat preview ends on the latest message
            usort($records, fn ($a, $b) => $this->ts($a) <=> $this->ts($b));

            foreach ($records as $record) {
                try {
                    $recorder->recordUpsert($instance->instance_name, (array) $record, true);
                } catch (\Throwable $e) {
                    Log::warning('SyncChatHistoryJob record failed: ' . $e->getMessage());
                }
            }

            $imported++;
        }
    }

    private function ts($record): int
    {
        $v = $record['messageTimestamp'] ?? 0;
        if (is_array($v)) {
            $v = $v['low'] ?? 0;
        }

        return (int) $v;
    }
}
