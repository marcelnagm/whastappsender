<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Contact;
use App\Models\User;

class SyncContactsTest extends Command
{
    protected $signature = 'sync:test {user_id} {instance}';
    protected $description = 'Raw contact sync test via terminal';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $instance = $this->argument('instance');
        $apiKey = "B67D461E-C9C1-4198-966D-0F672D626998";
        $url = "http://localhost:8080/chat/findContacts/{$instance}";

        $this->info("Starting request to: $url");

        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/json'
        ])->post($url, ['where' => (object)[]]);

        if ($response->failed()) {
            $this->error("API error: " . $response->status());
            $this->line($response->body());
            return 1;
        }

        $contacts = $response->json();
        
        // Evolution 2.3 may wrap rows under `data` / `contacts`; adjust parsing if your payload differs.
        // When the API returns a bare array, `json()` already gives a PHP list.
        $this->info("Contacts received: " . count($contacts));

        foreach ($contacts as $contact) {
            $jid = $contact['remoteJid'] ?? $contact['id'] ?? null;

            if (!$jid || str_contains($jid, '@g.us')) continue;

            $c = Contact::updateOrCreate(
                ['lid' => $jid, 'user_id' => $userId],
                [
                    'name' => $contact['pushName'] ?? $contact['name'] ?? 'Unnamed',
                    'phone' => explode('@', $jid)[0],
                    'profile_url' => $contact['profilePicUrl'] ?? null,
                ]
            );

            $this->line("Synced: " . ($contact['pushName'] ?? $jid));
        }

        $this->info("Finished successfully.");
        return 0;
    }
}