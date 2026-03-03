<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Http;

class EnviarMensagensWhatsApp extends Command
{
    protected $signature = 'whatsapp:disparar';
    protected $description = 'Dispara mensagens capturando message_id para controle de Webhook';

    public function handle()
    {
        // 1. Busca jobs elegíveis (Pendente/Erro com menos de 3 tentativas)
        $jobs = WhatsappJob::whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->limit(50)
            ->get();

        // Contagem de ignorados para feedback no terminal
        $descartadosCount = WhatsappJob::where('tentativas', '>=', 3)
            ->where('status', '!=', 'processado')
            ->count();

        if ($descartadosCount > 0) {
            $this->warn("Aviso: Existem {$descartadosCount} registros ignorados (limite de 3 erros excedido).");
        }

        if ($jobs->isEmpty()) {
            $this->info('Nenhuma mensagem elegível para envio.');
            return;
        }

        foreach ($jobs as $job) {
            $this->info("------------------------------------------------");
            $this->info("Processando: {$job->contato} (Tentativa " . ($job->tentativas + 1) . "/3)");
            
            try {
                
                $instance = $job->user()->first()->phone;
                $apikey = env('WHATSAPP_APIKEY');
                
                // Extração da Base URL de forma dinâmica
                $parsedUrl = parse_url($job->endpoint);
                $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                if(isset($parsedUrl['port'])) $baseUrl .= ":{$parsedUrl['port']}";

                // PASSO 1: Humanização (Presença)
                $this->info("Simulando digitação (presence)...");
                Http::withHeaders(['apikey' => $apikey])
                    ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                        "number" => $job->contato,
                        "presence" => "composing"
                    ]);

                // Delay humano para simular tempo de digitação/anexo
                sleep(rand(3, 6));

                // PASSO 2: Envio Real para a Evolution
                $this->info("Enviando payload...");
                $response = Http::withHeaders([
                    'apikey' => $apikey,
                    'Content-Type' => 'application/json'
                ])->post("{$job->endpoint}{$instance}", $job->payload);

                if ($response->successful()) {
                    $dados = $response->json();
                    
                    // PASSO 3: Captura do ID Único da Mensagem (Essencial para o Webhook)
                    // A Evolution pode retornar em diferentes níveis dependendo do tipo de mensagem
                    $remoteId = $dados['key']['id'] ?? ($dados['message']['key']['id'] ?? null);

                    $job->update([
                        'status' => 'processado',
                        'message_id' => $remoteId, // Coluna indexada que você criou
                        'evolution_status' => 'sent', // Status inicial na rede
                        'resposta' => $dados,
                        'erro_mensagem' => null
                    ]);

                    $this->info("SUCESSO: Mensagem aceita pela API. ID: {$remoteId}");
                } else {
                    $this->registrarFalha($job, $response->body());
                }

            } catch (\Exception $e) {
                $this->registrarFalha($job, $e->getMessage());
            }

            // Intervalo Anti-Ban (Aleatoriedade é vida)
            $pause = rand(15, 35);
            $this->comment("Aguardando {$pause}s para o próximo contato...");
            sleep($pause);
        }

        $this->info('Fim do processamento.');
    }

    private function registrarFalha($job, $mensagem)
    {
        $novaTentativa = $job->tentativas + 1;
        
        $job->update([
            'status' => 'erro',
            'tentativas' => $novaTentativa,
            // Limita a mensagem para evitar erro de truncamento de banco
            'erro_mensagem' => substr($mensagem, 0, 255) 
        ]);

        if ($novaTentativa >= 3) {
            $this->error("LIMITE DE TENTATIVAS: {$job->contato} ignorado.");
        } else {
            $this->warn("FALHA REGISTRADA: {$job->contato} ({$novaTentativa}/3).");
        }
    }
}