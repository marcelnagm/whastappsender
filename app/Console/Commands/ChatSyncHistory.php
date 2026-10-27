<?php

namespace App\Console\Commands;

use App\Jobs\SyncChatHistoryJob;
use App\Models\Instance;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Chat\EvolutionChatClient;
use App\Services\Chat\HistorySyncStatus;
use Illuminate\Console\Command;

class ChatSyncHistory extends Command
{
    protected $signature = 'chat:sync-history {instance : instance id or instance_name}';

    protected $description = 'Import the chat history of one number right now (no queue) and show what Evolution has stored';

    public function handle(EvolutionChatClient $client, ChatMessageRecorder $recorder): int
    {
        $arg = $this->argument('instance');
        $instance = Instance::where('id', $arg)->orWhere('instance_name', $arg)->first();
        if (!$instance) {
            $this->error('Instance not found.');

            return self::FAILURE;
        }

        $this->line("Instance {$instance->id} ({$instance->instance_name}), status: {$instance->status}");

        try {
            $chats = $client->findChats($instance->instance_name);
            $individual = array_filter($chats, fn ($r) => preg_match('/@(s\.whatsapp\.net|lid)$/', (string) ($r['remoteJid'] ?? ($r['id'] ?? ''))));
            $this->line('Evolution findChats: ' . count($chats) . ' chat(s), ' . count($individual) . ' individual.');
            $sample = $client->findAllMessages($instance->instance_name, 1, 5);
            $this->line('Evolution findMessages (any chat): ' . count($sample) . ' record(s) on the first page.');
        } catch (\Throwable $e) {
            $this->error('Evolution error: ' . $e->getMessage());

            return self::FAILURE;
        }

        HistorySyncStatus::queued($instance->id);
        (new SyncChatHistoryJob($instance->id))->handle($client, $recorder);

        $st = HistorySyncStatus::get($instance->id);
        $this->info("{$st['state']}: {$st['chats_done']} conversation(s), {$st['messages']} message(s) (source: " . ($st['source'] ?? '-') . ').');

        return $st['state'] === 'done' ? self::SUCCESS : self::FAILURE;
    }
}
