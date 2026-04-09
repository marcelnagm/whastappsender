<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnviarMensagensWhatsApp extends Command
{
    protected $signature = 'whatsapp:disparar';
    protected $description = 'Disparador profissional com humanização e proteção anti-ban';

    public function handle()
    {

        if (Cache::has('system_panic_mode')) {
            
            return 1;
        }
        // 1. Configurações de Infra
        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $globalApiKey = $config['apikey'];

        if (!$config['url'] || !$globalApiKey) {
            $this->error("Erro Crítico: Configurações de API ausentes.");
            return self::FAILURE;
        }

        // 2. Busca Lote
        $jobs = WhatsappJob::with(['campaignItem', 'user', 'contact']) // Eager loading para evitar N+1
            ->whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->orderBy('id', 'asc')
            ->limit(100)
            ->get();

        if ($jobs->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Iniciando lote de " . $jobs->count() . " mensagens.");

        foreach ($jobs as $index => $job) {
            if ($index > 0 && $index % 10 == 0) {
                $lotePause = rand(40, 70);
                $this->warn("Pausa de Lote: Aguardando {$lotePause}s...");
                sleep($lotePause);
            }

            try {
                $user = $job->user;
                if (!$user || !$user->phone) {
                    $this->registrarFalha($job, "Usuário sem instância configurada.");
                    continue;
                }

                $instance = $user->phone;
                $contact = $job->contact;
                $numeroDestino = $contact->contact;

                // CORREÇÃO: Acessando o CampaignItem através do Job
                $campaignItem = $job->campaignItem; 
                
                if (!$campaignItem) {
                    $this->registrarFalha($job, "Job sem CampaignItem vinculado.");
                    continue;
                }

                // Linha 60: Substituído $item por $campaignItem
                $payload = $campaignItem->generate($job->contact_id);

                $this->info("[ID:{$job->id}] Enviando para: {$numeroDestino}");

                // --- PASSO 1: HUMANIZAÇÃO ---
                $presenceType = (rand(0, 10) > 3) ? 'composing' : 'recording';
                
                // Chamada de presença (Opcional: você já gerou o payload acima, não precisa repetir na linha 69)
                Http::withHeaders(['apikey' => $globalApiKey])
                    ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                        "number" => $numeroDestino,
                        "presence" => $presenceType,
                        "delay" => rand(1500, 3000)
                    ]);

                sleep(rand(4, 8));

                // --- PASSO 2: ENVIO REAL ---
                $endpoint = ltrim($job->endpoint, '/');
                $urlFinal = "{$baseUrl}/{$endpoint}{$instance}";

                $response = Http::withHeaders([
                    'apikey' => $globalApiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(60)->post($urlFinal, $payload);

                if ($response->successful()) {
                    $dados = $response->json();
                    $remoteId = $dados['key']['id'] ?? ($dados['message']['key']['id'] ?? ($dados['response']['key']['id'] ?? null));

                    $job->update([
                        'status' => 'processado',
                        'message_id' => $remoteId,
                        'evolution_status' => 'sent',
                        'resposta' => $dados,
                        'erro_mensagem' => null,
                        'tentativas' => $job->tentativas + 1
                    ]);

                    $this->info("SUCESSO: ID {$remoteId}");
                } else {
                    $this->registrarFalha($job, "API Erro: " . $response->status());
                }
            } catch (\Exception $e) {
                $this->registrarFalha($job, "Exception: " . $e->getMessage());
                Log::error("Erro no disparo Job {$job->id}: " . $e->getMessage());
            }

            $pause = rand(20, 45);
            $this->comment("Aguardando {$pause}s...");
            sleep($pause);
        }

        $this->info('Lote finalizado.');
        return self::SUCCESS;
    }

    private function registrarFalha($job, $mensagem)
    {
        $novaTentativa = $job->tentativas + 1;
        $job->update([
            'status' => 'erro',
            'tentativas' => $novaTentativa,
            'erro_mensagem' => substr($mensagem, 0, 255)
        ]);
        $this->error("FALHA: {$job->id} - Tentativa {$novaTentativa}/3");
    }
}