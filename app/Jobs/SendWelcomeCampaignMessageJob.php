<?php

namespace App\Jobs;

use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\User;
use App\Services\AI\GroqConversationGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SendWelcomeCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $campaignItemId;
    protected int $contactId;
    protected int $userId;

    public function __construct(int $campaignItemId, int $contactId, int $userId)
    {
        $this->campaignItemId = $campaignItemId;
        $this->contactId = $contactId;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        if (Cache::has('system_panic_mode')) {
            return;
        }

        $campaignItem = CampaignItem::find($this->campaignItemId);
        $contact = Contact::where('id', $this->contactId)
            ->where('user_id', $this->userId)
            ->first();
        $user = User::find($this->userId);

        if (!$campaignItem || !$contact || !$user) {
            return;
        }

        $instance = $user->getInstanceActive();
        if (!$instance) {
            return;
        }

        $message = $this->nextWelcomeMessage($campaignItem);

        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $apiKey = $config['apikey'];

        try {
            $presenceType = rand(0, 1) ? 'composing' : 'recording';
            Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/chat/sendPresence/{$instance->instance_name}", [
                'number' => $contact->contact,
                'presence' => $presenceType,
                'delay' => rand(1500, 3000),
            ]);

            if (config('app.env') !== 'local') {
                usleep(500000);
            }

            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(35)->post("{$baseUrl}/message/sendText/{$instance->instance_name}", [
                'number' => $contact->contact,
                'text' => $message,
            ]);

            if (!$response->successful()) {
                Log::warning('Initial welcome send failed', [
                    'campaign_item_id' => $this->campaignItemId,
                    'contact_id' => $this->contactId,
                    'status' => $response->status(),
                ]);
                return;
            }

            Redis::sadd("welcome:pending_reply:user:{$this->userId}", $contact->contact);
            Redis::hset("welcome:contact_campaign:user:{$this->userId}", $contact->contact, (string) $campaignItem->id);
            Redis::expire("welcome:pending_reply:user:{$this->userId}", 60 * 60 * 24 * 15);
            Redis::expire("welcome:contact_campaign:user:{$this->userId}", 60 * 60 * 24 * 15);
        } catch (\Throwable $e) {
            Log::error('Error sending initial welcome', [
                'campaign_item_id' => $this->campaignItemId,
                'contact_id' => $this->contactId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nextWelcomeMessage(CampaignItem $campaignItem): string
    {
        $poolKey = "welcome:pool:campaign_item:{$campaignItem->id}";

        if ((int) Redis::llen($poolKey) === 0) {
            $messages = app(GroqConversationGenerator::class)->generate(
                50,
                'whatsapp first contact',
                'Generate 50 short first-contact sales messages for WhatsApp (in English).
The goal is to open the conversation loop and get a reply from the lead.

Sales copy guidelines:
1. Required structure: Greeting + hook (pain or benefit) + low-friction question (e.g. "Does this make sense for you?", "Are you the right person for this?", "Can we chat for one minute?").
2. Tone: Human, direct, consultative. Do not sound like generic telemarketing or overuse emojis.
3. Morning variations: Use business-focused morning hooks (e.g. "Hi [Name], I was looking at your space and noticed...").

Required data shape:
Return ONLY a JSON array of objects with keys: "categoria" (e.g. "cold", "follow-up", "morning") and "mensagem" (the outreach text).

Strict output rules:
No introductions, conclusions, or code fences (like ```json). Output must start with [ and end with ], as valid JSON for immediate parsing.'
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
