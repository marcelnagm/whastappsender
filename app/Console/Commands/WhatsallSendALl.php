<?php

namespace App\Console\Commands;

use App\Models\WhatsappJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http; // Certifique-se de importar o Http

class WhatsallSendALl extends Command
{
    protected $signature = 'whatsapp:disparar:all';
    protected $description = 'Dispara mensagens em lotes de 50 usando Chunking para alta performance';

    public function handle()
    {
        $this->info("Iniciando lote de processamento...");

        // chunkById é mais performático para tabelas grandes e evita pular registros
        WhatsappJob::whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->orderBy('id')
            ->chunkById(50, function ($jobs) {
                foreach ($jobs as $job) {
                    $this->processarJob($job);

                    // Intervalo de segurança anti-ban
                    sleep(rand(15, 35));
                }

                // Forçamos a limpeza de memória após cada lote de 50
                $this->info("Lote de 50 finalizado. Memória atual: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB");
            });

        $this->info("Processamento finalizado. O Kernel chamará novamente em 5 minutos.");
    }

    private function processarJob($job)
    {
        $this->info("------------------------------------------------");
        $this->info("Processando: {$job->contato} (Tentativa " . ($job->tentativas + 1) . "/3)");

        try {
            $user = $job->user()->first();
            if (!$user) {
                throw new \Exception("Usuário não encontrado para o Job ID: {$job->id}");
            }

            $instance = $user->phone;
            $apikey = env('WHATSAPP_APIKEY');

            // Extração da Base URL
            $parsedUrl = parse_url($job->endpoint);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            if (isset($parsedUrl['port'])) $baseUrl .= ":{$parsedUrl['port']}";

            // PASSO 1: Presença (Simulação Humana)
            Http::withHeaders(['apikey' => $apikey])
                ->post("{$baseUrl}/chat/sendPresence/{$instance}", [
                    "number" => $job->contato,
                    "presence" => "composing"
                ]);

            sleep(rand(3, 6));

            // PASSO 2: Envio Real (Aqui você pode injetar a lógica de ExtendedMessage/Preview se desejar)
            $response = Http::withHeaders([
                'apikey' => $apikey,
                'Content-Type' => 'application/json'
            ])->post("{$job->endpoint}{$instance}", $job->payload);

            if ($response->successful()) {
                $dados = $response->json();
                $remoteId = $dados['key']['id'] ?? ($dados['message']['key']['id'] ?? null);

                $job->update([
                    'status' => 'processado',
                    'message_id' => $remoteId,
                    'evolution_status' => 'sent',
                    'resposta' => $dados,
                    'erro_mensagem' => null
                ]);

                $this->info("SUCESSO: ID {$remoteId}");
            } else {
                $this->registrarFalha($job, $response->body());
            }
        } catch (\Exception $e) {
            $this->registrarFalha($job, $e->getMessage());
        }
    }

    private function registrarFalha($job, $mensagem)
    {
        $novaTentativa = $job->tentativas + 1;
        $job->update([
            'status' => 'erro',
            'tentativas' => $novaTentativa,
            'erro_mensagem' => substr($mensagem, 0, 255)
        ]);

        $this->error("FALHA: {$job->contato} (" . ($novaTentativa) . "/3)");
    }
}
