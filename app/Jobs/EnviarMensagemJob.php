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
    public $tries = 3;
    public $backoff = 60;

    public function __construct(WhatsappJob $jobModel)
    {
        $this->jobModel = $jobModel;
    }

    public function handle()
    {
        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $globalApiKey = $config['apikey'];
        $job = $this->jobModel;

        try {
            $user = $job->user()->first();
            if (!$user || !$user->phone) {
                $this->registrarErro("Usuário ou Phone (Instância) não configurado.");
                return;
            }

            $instance = $user->phone;
            $item = $job->campaignItem()->first();
            $contact = $job->contact()->first();
            if ($contact->status === "no-whatsapp") {
                $this->jobModel->update([
                    'status' => 'erro',
                    'tentativas' => -3,
                    'erro_mensagem' => 'Contato sem Whatsapp - ENCERRANDO'
                ]);
            }

            $payload = $item->generate($job->contact_id);
            $numeroDestino = $contact->contact;

            // PASSO 1: HUMANIZAÇÃO
            $presenceType = (rand(0, 10) > 3) ? 'composing' : 'recording';
            Http::withHeaders(['apikey' => $globalApiKey])
                ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                    "number" => $numeroDestino,
                    "presence" => $presenceType,
                    "delay" => rand(1500, 3000)
                ]);
            if (env('APP_ENV') !== 'local')
                sleep(rand(4, 9));

            // PASSO 2: ENVIO REAL
            $endpoint = ltrim($job->endpoint, '/');
            $urlFinal = "{$baseUrl}/{$endpoint}{$instance}";

            $response = Http::withHeaders([
                'apikey' => $globalApiKey,
                'Content-Type' => 'application/json'
            ])->timeout(35)->post($urlFinal, $payload);

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
            } else {
                $status = $response->status();
                $body = $response->json();

                // --- LÓGICA DE SANITIZAÇÃO: DESATIVAR CONTATO INEXISTENTE ---
                if ($status === 400) {

                    $exists = $body['response']['message'][0]['exists'] ?? true;
                    Log::warning("Contato ID {$contact->id} desativado: Número não existe no WhatsApp.");
                    if ($exists === false) {
                        $contact->status = 'no-whatsapp';
                        $contact->save(); // Assume que sua tabela contacts tem coluna 'active' ou similar
                        Log::warning("Contato ID {$contact->id} desativado: Número não existe no WhatsApp.");
                        $this->registrarErro("Número Inexistente (Contato Desativado)");
                        return; // Encerra sem retentar
                    }
                }

                $this->registrarErro("API Erro: " . $status . " - " . $response->body());
            }

            // PASSO 3: COOLDOWN
            if (env('APP_ENV') !== 'local')
                sleep(rand(25, 50));
        } catch (\Exception $e) {
            $this->registrarErro("Exception: " . $e->getMessage());
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
