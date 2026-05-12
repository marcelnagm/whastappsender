<?php

namespace App\Jobs;

use App\Models\WhatsappJob;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

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
        if (Cache::has('system_panic_mode')) {

            return 1;
        }

        if (!$this->isAllowedNow()) {
            // 1. Agenda o retorno para a fila
            $this->release(
                $this->secondsUntilNextWindow() + rand(60, 600)
            );

            // 2. Encerra o handle() sem tentar retornar o resultado do release
            return;
        }


        try {
            $user = $job->user()->first();
            $instance = $user->getInstanceActive();
            if (!$user || !$instance) {
                $this->registrarErro("User or instance phone not configured.", null);
                return;
            }


            $contact = $job->contact()->first();
            if (!$contact) {
                $this->registrarErro("Contact not found for this job.", $instance);
                return;
            }
            if ($contact->status === "no-whatsapp") {
                $this->jobModel->update([
                    'status' => 'erro',
                    'tentativas' => -3,
                    'erro_mensagem' => 'Contact has no WhatsApp — stopping'
                ]);
            }

            $payload = is_array($job->payload) ? $job->payload : null;
            if (!$payload) {
                $item = $job->campaignItem()->first();
                if (!$item) {
                    $this->registrarErro("CampaignItem not found for this job.", $instance);
                    return;
                }
                $payload = $item->generate($job->contact_id);
            }
            $numeroDestino = $contact->contact;

            // PASSO 1: HUMANIZAÇÃO
            $presenceType = (rand(0, 10) > 3) ? 'composing' : 'recording';
            Http::withHeaders(['apikey' => $globalApiKey])
                ->post("{$baseUrl}/chat/sendPresence/{$instance->instance_name}", [
                    "number" => $numeroDestino,
                    "presence" => $presenceType,
                    "delay" => rand(1500, 3000)
                ]);
            if (config('app.env') !== 'local')
                sleep(rand(4, 9));

            // PASSO 2: ENVIO REAL
            $endpoint = ltrim($job->endpoint, '/');
            $urlFinal = "{$baseUrl}/{$endpoint}{$instance->instance_name}";

            $response = Http::withHeaders([
                'apikey' => $globalApiKey,
                'Content-Type' => 'application/json'
            ])->timeout(35)->post($urlFinal, $payload);

            if ($response->successful()) {
                $dados = $response->json();
                $remoteId = $dados['key']['id'] ?? ($dados['message']['key']['id'] ?? ($dados['response']['key']['id'] ?? null));

                $job->update([
                    'instance_id' => $instance->id,
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
                    Log::warning("Contact ID {$contact->id} disabled: number does not exist on WhatsApp.");
                    if ($exists === false) {
                        $contact->status = 'no-whatsapp';
                        $contact->save(); // Assume que sua tabela contacts tem coluna 'active' ou similar
                        Log::warning("Contact ID {$contact->id} disabled: number does not exist on WhatsApp.");
                        $this->registrarErro("Invalid number (contact marked inactive)", $instance);
                        return; // Encerra sem retentar
                    }
                }

                $this->registrarErro("API error: " . $status . " - " . $response->body(), $instance);
            }

            // PASSO 3: COOLDOWN
            if (config('app.env') !== 'local')
                sleep(rand(25, 50));
        } catch (\Exception $e) {
            $this->registrarErro("Exception: " . $e->getMessage(), $instance);
            throw $e;
        }
    }

    private function registrarErro($mensagem, $instance)
    {
        $this->jobModel->update([
            'instance_id' => $instance ? $instance->id : null,
            'status' => 'erro',
            'tentativas' => $this->jobModel->tentativas + 1,
            'erro_mensagem' => substr($mensagem, 0, 255)
        ]);

        Log::error("Job ID {$this->jobModel->id} failed: {$mensagem}");
    }

    public function isAllowedNow(): bool
    {
        // Força o fuso horário se necessário (ex: 'America/Boa_Vista')
        $now = Carbon::now('America/Sao_Paulo');

        if ($now->isWeekend()) return false;

        $start = Carbon::createFromTime(9, 0, 0, 'America/Sao_Paulo');
        $end = Carbon::createFromTime(19, 0, 0, 'America/Sao_Paulo');

        return $now->between($start, $end);
    }

    public function secondsUntilNextWindow(): int
    {
        $now = Carbon::now('America/Sao_Paulo');
        $nextWindow = Carbon::now('America/Sao_Paulo')->setTime(8, 0, 0);

        if ($now->hour >= 18) {
            $nextWindow->addDay();
        }

        if ($nextWindow->isSaturday()) {
            $nextWindow->addDays(2);
        } elseif ($nextWindow->isSunday()) {
            $nextWindow->addDay();
        }

        return $now->diffInSeconds($nextWindow);
    }
}
