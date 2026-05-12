<?php

namespace App\Console\Commands;

use App\Models\CampaignItem;
use App\Models\WhatsappJob;
use App\Services\AI\GroqConversationGenerator;
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
    protected $signature = 'whatsapp:queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch pending WhatsApp send jobs to the queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (Cache::has('system_panic_mode')) {
            
            return 1;
        }

        $jobs = WhatsappJob::with(['campaignItem', 'contact'])
            ->whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->orderBy('id', 'asc')
             ->limit(env('WHATSAPP_BATCH_SIZE'))
            ->get();

        if ($jobs->isEmpty()) {
            $this->warn('No pending jobs found in the database.');
            return;
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

            $job->update(['status' => 'fila']);
            \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('disparos');
        }
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
