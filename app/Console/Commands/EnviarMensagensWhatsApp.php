<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Http;

class EnviarMensagensWhatsApp extends Command
{
    protected $signature = 'whatsapp:disparar';
    protected $description = 'Dispara mensagens com controle de tentativas (Max 3)';

    public function handle()
    {
        // Busca pendentes ou erros que ainda não atingiram o limite de 3 tentativas
        $jobs = WhatsappJob::whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->limit(50)
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('Nada para processar.');
            return;
        }

        foreach ($jobs as $job) {
            $this->info("Tentativa " . ($job->tentativas + 1) . " para: {$job->contato}");
            
            try {
                $instance = env('WHATSAPP_INSTANCIA');
                $apikey = env('WHATSAPP_APIKEY');
                $baseUrl = parse_url($job->endpoint, PHP_URL_SCHEME) . '://' . parse_url($job->endpoint, PHP_URL_HOST);
                if($port = parse_url($job->endpoint, PHP_URL_PORT)) $baseUrl .= ":{$port}";

                // Humanização: Digitando...
                Http::withHeaders(['apikey' => $apikey])
                    ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                        "number" => $job->contato,
                        "presence" => "composing"
                    ]);

                sleep(rand(3, 6));

                // Disparo
                $response = Http::withHeaders([
                    'apikey' => $apikey,
                    'Content-Type' => 'application/json'
                ])->post("{$job->endpoint}{$instance}", $job->payload);

                if ($response->successful()) {
                    $job->update([
                        'status' => 'processado',
                        'resposta' => $response->json(),
                        'erro_mensagem' => null
                    ]);
                    $this->info("Sucesso: {$job->contato}");
                } else {
                    $this->registrarErro($job, $response->body());
                }

            } catch (\Exception $e) {
                $this->registrarErro($job, $e->getMessage());
            }

            $pause = rand(15, 30);
            $this->comment("Aguardando {$pause}s...");
            sleep($pause);
        }
    }

    private function registrarErro($job, $mensagem)
    {
        $novaTentativa = $job->tentativas + 1;
        $novoStatus = ($novaTentativa >= 3) ? 'falha_critica' : 'erro';

        $job->update([
            'status' => $novoStatus,
            'tentativas' => $novaTentativa,
            'erro_mensagem' => $mensagem
        ]);

        $this->error("Falha ({$novaTentativa}/3): {$job->contato}");
    }
}