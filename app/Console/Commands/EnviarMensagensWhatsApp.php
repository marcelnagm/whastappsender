<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Http;

class EnviarMensagensWhatsApp extends Command
{
    protected $signature = 'whatsapp:disparar';
    protected $description = 'Dispara mensagens ignorando quem atingiu o limite de 3 erros';

    public function handle()
    {
        // 1. Mudança Estratégica na Query:
        // Buscamos apenas quem é pendente/erro E tem menos de 3 tentativas.
        $jobs = WhatsappJob::whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->limit(50)
            ->get();

        // 2. Verbosity: Informar quantos foram encontrados e se há descartados na fila
        $descartadosCount = WhatsappJob::where('tentativas', '>=', 3)
            ->where('status', '!=', 'processado')
            ->count();

        if ($descartadosCount > 0) {
            $this->warn("Aviso: Existem {$descartadosCount} registros ignorados por excederem 3 erros.");
        }

        if ($jobs->isEmpty()) {
            $this->info('Nenhuma mensagem elegível para envio no momento.');
            return;
        }

        foreach ($jobs as $job) {
            $this->info("------------------------------------------------");
            $this->info("Processando: {$job->contato} (Tentativa " . ($job->tentativas + 1) . "/3)");
            
            try {
                $instance = env('WHATSAPP_INSTANCIA');
                $apikey = env('WHATSAPP_APIKEY');
                
                // Extração da Base URL para o Presence
                $baseUrl = parse_url($job->endpoint, PHP_URL_SCHEME) . '://' . parse_url($job->endpoint, PHP_URL_HOST);
                if($port = parse_url($job->endpoint, PHP_URL_PORT)) $baseUrl .= ":{$port}";

                // Passo 1: Humanização
                $this->info("Simulando digitação...");
                Http::withHeaders(['apikey' => $apikey])
                    ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                        "number" => $job->contato,
                        "presence" => "composing"
                    ]);

                sleep(rand(3, 6));

                // Passo 2: Envio Real
                $this->info("Enviando mídia...");
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
                    $this->info("SUCESSO: {$job->contato}");
                } else {
                    $this->registrarFalha($job, $response->body());
                }

            } catch (\Exception $e) {
                $this->registrarFalha($job, $e->getMessage());
            }

            // Intervalo Anti-Ban
            $pause = rand(15, 30);
            $this->comment("Aguardando {$pause}s para o próximo...");
            sleep($pause);
        }
    }

    /**
     * Registra a falha e incrementa o contador.
     */
    private function registrarFalha($job, $mensagem)
    {
        $novaTentativa = $job->tentativas + 1;
        
        // Se chegar em 3, o status permanece 'erro', mas o comando vai ignorar na próxima query
        $job->update([
            'status' => 'erro',
            'tentativas' => $novaTentativa,
            'erro_mensagem' => substr($mensagem, 0, 255) // Evita erro de truncamento no banco
        ]);

        if ($novaTentativa >= 3) {
            $this->error("LIMITE ATINGIDO: {$job->contato} foi movido para a lista de ignorados.");
        } else {
            $this->warn("FALHA: {$job->contato} terá nova tentativa ({$novaTentativa}/3).");
        }
    }
}