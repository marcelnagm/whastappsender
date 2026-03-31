<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnviarMensagensWhatsApp extends Command
{
    protected $signature = 'whatsapp:disparar';
    protected $description = 'Disparador profissional com humanização e proteção anti-ban';

    public function handle()
    {
        // 1. Configurações de Infra (Carregamento único fora do loop)
        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $globalApiKey = $config['apikey'];

        if (!$config['url'] || !$globalApiKey) {
            $this->error("Erro Crítico: Configurações de API ausentes.");
            return self::FAILURE;
        }

        // 2. Busca Lote Maior (Aumentamos para 100 para dar vazão)
        $jobs = WhatsappJob::whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->orderBy('id', 'asc') // Primeiro os mais antigos
            ->limit(100)
            ->get();

        if ($jobs->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Iniciando lote de " . $jobs->count() . " mensagens.");

        foreach ($jobs as $index => $job) {
            // A cada 10 mensagens, fazemos uma pausa maior (Respiro do lote)
            if ($index > 0 && $index % 10 == 0) {
                $lotePause = rand(40, 70);
                $this->warn("Pausa de Lote: Aguardando {$lotePause}s para resfriar a instância...");
                sleep($lotePause);
            }

            try {
                // Instância do usuário (Telefone)
                $user = $job->user()->first();
                if (!$user || !$user->phone) {
                    $this->registrarFalha($job, "Usuário sem instância/phone configurado.");
                    continue;
                }

                $instance = $user->phone;
                $contact = $job->contact()->first();

                $numeroDestino = $contact->contact;
                $payload = $item->generate($job->contact_id);



                $this->info("[ID:{$job->id}] Enviando para: {$numeroDestino}");

                // --- PASSO 1: HUMANIZAÇÃO (Simulação de Comportamento) ---
                // Aleatoriedade entre digitar ou apenas visualizar
                $presenceType = (rand(0, 10) > 3) ? 'composing' : 'recording';
                $payload = $item->generate($job->contact_id);

                Http::withHeaders(['apikey' => $globalApiKey])
                    ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                        "number" => $numeroDestino,
                        "presence" => $presenceType,
                        "delay" => rand(1500, 3000) // Delay interno da Evolution
                    ]);

                // Tempo que o "usuário" leva para enviar após começar a digitar
                sleep(rand(4, 8));

                // --- PASSO 2: ENVIO REAL ---
                $endpoint = ltrim($job->endpoint, '/'); // Remove barra duplicada se existir
                $urlFinal = "{$baseUrl}/{$endpoint}{$instance}";

                $response = Http::withHeaders([
                    'apikey' => $globalApiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(60)->post($urlFinal, $payload);

                if ($response->successful()) {
                    $dados = $response->json();

                    // Captura o ID da mensagem para o Webhook futuro
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
                    $this->registrarFalha($job, "API Erro: " . $response->status() . " - " . $response->body());
                }
            } catch (\Exception $e) {
                $this->registrarFalha($job, "Exception: " . $e->getMessage());
                Log::error("Erro no disparo Job {$job->id}: " . $e->getMessage());
            }

            // --- PASSO 3: INTERVALO ENTRE REQUESTS (Anti-Ban) ---
            // Aumentamos a média para segurança total
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
