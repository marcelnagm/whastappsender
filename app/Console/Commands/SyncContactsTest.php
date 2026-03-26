<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Contact;
use App\Models\User;

class SyncContactsTest extends Command
{
    protected $signature = 'sync:test {user_id} {instance}';
    protected $description = 'Teste bruto de sincronização de contatos via terminal';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $instance = $this->argument('instance');
        $apiKey = "B67D461E-C9C1-4198-966D-0F672D626998";
        $url = "http://localhost:8080/chat/findContacts/{$instance}";

        $this->info("Iniciando requisição para: $url");

        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/json'
        ])->post($url, ['where' => (object)[]]);

        if ($response->failed()) {
            $this->error("Erro na API: " . $response->status());
            $this->line($response->body());
            return 1;
        }

        $contacts = $response->json();
        
        // Se a Evolution 2.3 retornar um objeto com a chave 'data' ou 'contacts', ajuste aqui:
        // Se for um array direto, o Laravel trata como tal.
        $this->info("Contatos recebidos: " . count($contacts));

        foreach ($contacts as $contact) {
            $jid = $contact['remoteJid'] ?? $contact['id'] ?? null;

            if (!$jid || str_contains($jid, '@g.us')) continue;

            $c = Contact::updateOrCreate(
                ['lid' => $jid, 'user_id' => $userId],
                [
                    'name' => $contact['pushName'] ?? $contact['name'] ?? 'Sem Nome',
                    'phone' => explode('@', $jid)[0],
                    'profile_url' => $contact['profilePicUrl'] ?? null,
                ]
            );

            $this->line("Sincronizado: " . ($contact['pushName'] ?? $jid));
        }

        $this->info("Finalizado com sucesso.");
        return 0;
    }
}