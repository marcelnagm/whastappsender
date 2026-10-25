<?php

namespace App\Console\Commands;

use App\Models\CampaignItem;
use App\Models\WhatsappJob;
use App\Services\AI\GroqConversationGenerator;
use App\Services\Queue\FairQueueSelector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class envioQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:queue
        {--batch= : Messages to dispatch (default: whatsapp_queue.batch_size)}
        {--mode= : session | user | fifo (default: whatsapp_queue.fairness)}
        {--dry-run : Show how the batch would be shared without dispatching anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a batch of pending WhatsApp send jobs, shared fairly between users and sessions';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(FairQueueSelector $selector)
    {
        if (Cache::has('system_panic_mode')) {

            return 1;
        }

        $batch = (int) ($this->option('batch') ?: config('whatsapp_queue.batch_size', 50));
        $mode = $this->option('mode') ?: null;

        if ($this->option('dry-run')) {
            return $this->dryRun($selector, $batch, $mode);
        }

        // Shared fairly between users and sessions, already in dispatch order (see FairBatchPlanner)
        $jobs = $selector->select($batch, $mode);

        if ($jobs->isEmpty()) {
            $this->warn('No pending jobs found in the database.');
            return Command::SUCCESS;
        }

        $this->info('Found ' . $jobs->count() . ' job(s). Dispatching...');

        foreach ($jobs as $job) {
            $campaignItem = $job->campaignItem;
            $contact = $job->contact;

            if ($campaignItem && $contact && (bool) ($campaignItem->welcome_enabled ?? false)) {
                $payload = is_array($job->payload) ? $job->payload : null;

                if (!$payload || empty($payload['text'])) {
                    $message = $this->nextWelcomeMessage($campaignItem);
                    $job->payload = [
                        'number' => $contact->contact,
                        'text' => $message,
                    ];
                    $job->save();
                }

                Redis::sadd("welcome:pending_reply:user:{$job->user_id}", $contact->contact);
                Redis::hset("welcome:contact_campaign:user:{$job->user_id}", $contact->contact, (string) $campaignItem->id);
                Redis::expire("welcome:pending_reply:user:{$job->user_id}", 60 * 60 * 24 * 15);
                Redis::expire("welcome:contact_campaign:user:{$job->user_id}", 60 * 60 * 24 * 15);
            }

            // instance_id was chosen by the selector (the session that should send this message)
            $job->update(['status' => 'fila']);
            \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('disparos');
        }
        return Command::SUCCESS;
    }

    private function dryRun(FairQueueSelector $selector, int $batch, ?string $mode): int
    {
        $rows = $selector->describe($batch, $mode);

        if (!$rows) {
            $this->warn('Nothing to dispatch.');

            return Command::SUCCESS;
        }

        $names = \App\Models\User::whereIn('id', array_column($rows, 'user_id'))->pluck('name', 'id');

        $this->table(
            ['User', 'Sessions', 'Pending', 'Already queued', 'Selected', 'Per session'],
            array_map(fn ($r) => [
                ($names[$r['user_id']] ?? '?') . " (#{$r['user_id']})",
                $r['sessions'],
                $r['pending'],
                $r['inflight'],
                $r['selected'],
                collect($r['per_session'])->map(fn ($n, $id) => "#{$id}: {$n}")->implode(', '),
            ], $rows)
        );
        $this->info('Batch size ' . $batch . ', total selected ' . array_sum(array_column($rows, 'selected')) . '. Nothing was dispatched (--dry-run).');

        return Command::SUCCESS;
    }

    private function nextWelcomeMessage(CampaignItem $campaignItem): string
    {
        $poolKey = "welcome:pool:campaign_item:{$campaignItem->id}";

        if ((int) Redis::llen($poolKey) === 0) {
            $messages = app(GroqConversationGenerator::class)->generate(
                50,
                'whatsapp first contact',
                "Generate 50 short English first-contact WhatsApp messages, including good-morning variations. Return only a JSON array."
            );

            if (!is_array($messages) || empty($messages)) {
                $messages = [trim((string) $campaignItem->text)];
            }

            foreach ($messages as $message) {
                $value = trim((string) $message);
                if ($value === '') {
                    continue;
                }
                Redis::rpush($poolKey, $value);
            }
            Redis::expire($poolKey, 60 * 60 * 24 * 7);
        }

        $message = Redis::lpop($poolKey);
        if ($message) {
            Redis::rpush($poolKey, $message);
        }

        return trim((string) $message) ?: trim((string) $campaignItem->text);
    }
}
