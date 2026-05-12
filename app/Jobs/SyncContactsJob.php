<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use App\Models\Contact;
use App\Models\User;
use App\Notifications\SyncCompleted;
use Exception;
use Illuminate\Support\Facades\Log;

class SyncContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $instanceName;

    public function __construct($userId, $instanceName)
    {
        $this->userId = $userId;
        $this->instanceName = $instanceName;
    }

    public function handle()
    {
        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $apiKey = $config['apikey'];
        $url = "{$baseUrl}/chat/findContacts/{$this->instanceName}";

        try {
            // Match your curl --data shape: JSON must be {"where": {}}
            $jsonPayload = json_encode([
                'where' => (object)[]
            ]);

            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json'
            ])
                ->withBody($jsonPayload, 'application/json')
                ->post($url);
        } catch (Exception $ex) {
            Log::error("Evolution connection error: " . $ex->getMessage());
            throw $ex;
        }

        if ($response->failed()) {
            Log::error("Evolution API error {$response->status()}: " . $response->body());
            return;
        }

        $contacts = $response->json();
        $count = 0;

        foreach ($contacts as $contact) {
            // Mapeamento baseado no seu dump da v2.3
            $jid = $contact['remoteJid'] ?? $contact['id'] ?? null;

            if (!$jid || str_contains($jid, '@g.us')) continue;

            Contact::updateOrCreate(
                ['lid' => $jid, 'user_id' => $this->userId],
                [
                    'name' => $contact['pushName'] ?? $contact['name'] ?? 'Sem Nome',
                    'contact' => explode('@', $jid)[0],
                    'profile_url' => $contact['profilePicUrl'] ?? null,
                ]
            );
            $count++;
        }

        $user = User::find($this->userId);
        if ($user) {
            $user->notify(new SyncCompleted($count));
        }
    }
}
