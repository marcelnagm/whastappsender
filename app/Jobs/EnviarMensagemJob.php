<?php

namespace App\Jobs;

use App\Models\WhatsappJob;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnviarMensagemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $jobModel;

    // Tentativas automáticas do Laravel se o Job falhar (Ex: timeout da API)
    public $tries = 3;

    // Tempo de espera entre tentativas automáticas
    public $backoff = 60;

    public function __construct(WhatsappJob $jobModel)
    {
        $this->jobModel = $jobModel;
    }

    public function handle()
    {
        // 1. Configurações de Infra
        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $globalApiKey = $config['apikey'];

        $job = $this->jobModel;

        try {
            // 2. Validação de Instância
            $user = $job->user()->first();

            if (env('APP_DEBUG'))
                Log::info("user: {$user}");
            if (env('APP_DEBUG'))
                Log::info("iNSTANCIA: {$user->phone}");
            if (!$user || !$user->phone) {
                if (env('APP_DEBUG'))
                    Log::error($user);
                $this->registrarErro("Usuário ou Phone (Instância) não configurado.");
                return;
            }

            $instance = $user->phone;
            $item = $job->campaignItem()->first();
            $contact = $job->contact()->first();
            if (env('APP_DEBUG'))
                Log::info("iNSTANCIA: {$instance}");

            $payload = $item->generate($job->contact_id);
            $numeroDestino = $contact->contact;
            if (env('APP_DEBUG'))
                Log::error("numero destin {$numeroDestino}");

            // --- PASSO 1: HUMANIZAÇÃO (Presence) ---
            $presenceType = (rand(0, 10) > 3) ? 'composing' : 'recording';
            if (env('APP_DEBUG'))
                Log::info('Passo 1');
            Http::withHeaders(['apikey' => $globalApiKey])
                ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                    "number" => $numeroDestino,
                    "presence" => $presenceType,
                    "delay" => rand(1500, 3000)
                ]);

            // Delay de "digitação" (4 a 9 segundos)
            sleep(rand(4, 9));


            // --- PASSO 2: ENVIO REAL ---

            if (env('APP_DEBUG'))
                Log::info('Passo 2');
            $endpoint = ltrim($job->endpoint, '/');
            $urlFinal = "{$baseUrl}/{$endpoint}{$instance}";
            if (env('APP_DEBUG'))
                Log::info("URL FINAL: {$urlFinal}");
            $response = Http::withHeaders([
                'apikey' => $globalApiKey,
                'Content-Type' => 'application/json'
            ])->timeout(35)->post($urlFinal, $payload);

            if ($response->successful()) {
                $dados = $response->json();

                // Captura IDs em diferentes níveis de retorno da Evolution
                $remoteId = $dados['key']['id'] ?? ($dados['message']['key']['id'] ?? ($dados['response']['key']['id'] ?? null));

                $job->update([
                    'status' => 'processado',
                    'message_id' => $remoteId,
                    'evolution_status' => 'sent',
                    'resposta' => $dados,
                    'erro_mensagem' => null,
                    'tentativas' => $job->tentativas + 1
                ]);
            } else {
                $this->registrarErro("API Erro: " . $response->status() . " - " . $response->body());
            }

            if (env('APP_DEBUG'))
                Log::info('Passo 3');
            // --- PASSO 3: IN  TERVALO ANTI-BAN (Cooldown) ---
            // Como o Worker processa um por um, este sleep garante a cadência do chip
            $pause = rand(25, 50);
            sleep($pause);
        } catch (\Exception $e) {
            $this->registrarErro("Exception: " . $e->getMessage());
            // Lança a exceção para o Laravel Queue saber que deve tentar novamente (retry)
            throw $e;
        }
    }

    private function registrarErro($mensagem)
    {
        $this->jobModel->update([
            'status' => 'erro',
            'tentativas' => $this->jobModel->tentativas + 1,
            'erro_mensagem' => substr($mensagem, 0, 255)
        ]);

        Log::error("Falha no Job ID {$this->jobModel->id}: {$mensagem}");
    }
}
