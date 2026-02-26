<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Http;

class EnviarMensagensWhatsApp extends Command
{
    // O nome que você usará no terminal para rodar o comando
    protected $signature = 'whatsapp:disparar';
    protected $description = 'Envia as mensagens pendentes na fila do WhatsApp Job';

    public function handle()
    {
        // 1. Busca os registros pendentes (limitando para evitar sobrecarga)
        $jobs = WhatsappJob::where('status', 'pendente')->limit(50)->get();

        if ($jobs->isEmpty()) {
            $this->info('Nenhuma mensagem pendente encontrada.');
            return;
        }

        foreach ($jobs as $job) {
            $this->info("Enviando para: {$job->contato}");
            
            try {
                // 2. Faz o disparo via Guzzle (HTTP Client do Laravel)
                
                $this->info("{$job->endpoint}".env('WHATSAPP_INSTANCIA'));
                $this->info(json_encode($job->payload));
                
                $response = Http::withHeaders([
                    'apikey' => env('WHATSAPP_APIKEY'), // Substitua pelo seu Token da Evolution
                    'Content-Type' => 'application/json'
                ])->post("{$job->endpoint}".env('WHATSAPP_INSTANCIA'), $job->payload);

                // 3. Verifica se a Evolution API aceitou o comando
                if ($response->successful()) {
                    $job->update([
                        'status' => 'processado',
                        'resposta' => $response->json()
                    ]);
                    $this->info("Sucesso: {$job->contato}");
                } else {
                    $job->update([
                        'status' => 'erro',
                        'resposta' => $response->json(),
                        'erro_mensagem' => 'Erro na API Evolution: ' . $response->status()
                    ]);
                    $this->error("Falha na API: {$job->contato}");
                }

            } catch (\Exception $e) {
                // 4. Captura erros de rede ou conexão
                $job->update([
                    'status' => 'erro',
                    'erro_mensagem' => $e->getMessage()
                ]);
                $this->error("Erro de conexão: {$job->contato}");
            }

            // O PULO DO GATO: Simular comportamento humano (Anti-Ban)
            // Aguarda entre 5 a 15 segundos entre cada mensagem
            $delay = rand(5, 15);
            $this->comment("Aguardando {$delay} segundos...");
            sleep($delay);
        }

        $this->info('Processamento concluído.');
    }
}