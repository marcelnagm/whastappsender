<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\Chat\ChatMessageRecorder;
use App\Notifications\ChatHistorySynced;
use App\Services\Chat\EvolutionChatClient;
use App\Services\Chat\HistorySyncStatus;
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

        HistorySyncStatus::update($instance->id, ['state' => 'running', 'error' => null]);

        $maxChats = (int) config('chat.history.max_chats', 50);
        $perChat = (int) config('chat.history.per_chat', 200);
        $pageSize = max(10, (int) config('chat.history.page_size', 50));

        try {
            $chats = $client->findChats($instance->instance_name);
        } catch (\Throwable $e) {
            Log::error('SyncChatHistoryJob findChats failed: ' . $e->getMessage());
            $this->finish($instance, 0, 0, $e->getMessage());

            return;
        }

        // newest conversations first: the ones people care about are imported before any limit is hit
        usort($chats, fn ($a, $b) => $this->chatTs($b) <=> $this->chatTs($a));

        $eligible = array_values(array_filter($chats, fn ($r) => $this->isIndividual($r)));
        HistorySyncStatus::update($instance->id, ['chats_total' => min(count($eligible), $maxChats), 'chats_done' => 0, 'source' => 'chats', 'raw_chats' => count($chats)]);

        // Evolution kept no chat list (history sync off, or the number was paired before chats were saved):
        // the stored messages are still there, so rebuild the conversations from them
        if (!$eligible) {
            $this->importFromMessages($instance, $client, $recorder, $maxChats * $perChat, $pageSize, count($chats));

            return;
        }

        $imported = 0;
        $messages = 0;
        foreach ($chats as $row) {
            if ($imported >= $maxChats) {
                break;
            }

            $jid = $this->jidOf($row);
            if (!$this->isIndividual($row)) {
                continue;
            }

            // page by page until the limit or the end of the conversation
            $records = [];
            try {
                for ($page = 1; count($records) < $perChat; $page++) {
                    $batch = $client->findMessages($instance->instance_name, $jid, $page, $pageSize);
                    if (!$batch) {
                        break;
                    }
                    $records = array_merge($records, $batch);
                    if (count($batch) < $pageSize) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("SyncChatHistoryJob findMessages failed for {$jid}: " . $e->getMessage());
                if (!$records) {
                    continue;
                }
            }

            // Oldest first so the chat preview ends on the latest message
            usort($records, fn ($a, $b) => $this->ts($a) <=> $this->ts($b));

            foreach ($records as $record) {
                try {
                    $recorder->recordUpsert($instance->instance_name, (array) $record, true);
                    $messages++;
                } catch (\Throwable $e) {
                    Log::warning('SyncChatHistoryJob record failed: ' . $e->getMessage());
                }
            }

            $imported++;
            HistorySyncStatus::update($instance->id, ['chats_done' => $imported, 'messages' => $messages]);
        }

        $this->finish($instance, $imported, $messages);
    }

    private function importFromMessages(Instance $instance, EvolutionChatClient $client, ChatMessageRecorder $recorder, int $cap, int $pageSize, int $rawChats): void
    {
        HistorySyncStatus::update($instance->id, ['source' => 'messages']);
        $seen = [];
        $messages = 0;

        try {
            for ($page = 1; $messages < $cap; $page++) {
                $batch = $client->findAllMessages($instance->instance_name, $page, $pageSize);
                if (!$batch) {
                    break;
                }

                foreach ($batch as $record) {
                    try {
                        if ($recorder->recordUpsert($instance->instance_name, (array) $record, true)) {
                            $messages++;
                            $seen[(string) ($record['key']['remoteJid'] ?? '')] = true;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('SyncChatHistoryJob record failed: ' . $e->getMessage());
                    }
                }

                HistorySyncStatus::update($instance->id, ['chats_done' => count($seen), 'chats_total' => count($seen), 'messages' => $messages]);

                if (count($batch) < $pageSize) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('SyncChatHistoryJob findMessages (all) failed: ' . $e->getMessage());
            $this->finish($instance, count($seen), $messages, $e->getMessage());

            return;
        }

        $this->finish($instance, count($seen), $messages);
    }

    public function failed(\Throwable $e): void
    {
        if ($instance = Instance::find($this->instanceId)) {
            $this->finish($instance, 0, 0, $e->getMessage());
        }
    }

    private function finish(Instance $instance, int $chats, int $messages, ?string $error = null): void
    {
        HistorySyncStatus::update($instance->id, ['state' => $error ? 'failed' : 'done', 'chats_done' => $chats, 'messages' => $messages, 'error' => $error ? mb_substr($error, 0, 200) : null]);

        if ($user = $instance->user) {
            try {
                $user->notify(new ChatHistorySynced($instance->name ?: $instance->instance_name, $chats, $messages, $error ? mb_substr($error, 0, 200) : null));
            } catch (\Throwable $e) {
                Log::warning('Could not notify history import result: ' . $e->getMessage());
            }
        }
    }

    private function jidOf($row): string
    {
        return (string) ($row['remoteJid'] ?? ($row['key']['remoteJid'] ?? ($row['id'] ?? '')));
    }

    private function isIndividual($row): bool
    {
        $jid = $this->jidOf($row);

        return str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@lid');
    }

    private function chatTs($row): int
    {
        $v = $row['lastMessage']['messageTimestamp'] ?? ($row['updatedAt'] ?? 0);
        if (is_array($v)) {
            $v = $v['low'] ?? 0;
        }

        return is_numeric($v) ? (int) $v : (int) strtotime((string) $v);
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
