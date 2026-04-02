<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\Contact;
use App\Models\WhatsappJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShoulphpdQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class ProcessEvolutionWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        $event = $this->payload['event'] ?? null;
        $instanceName = $this->payload['instance'] ?? null;

        try {
            switch ($event) {
                case 'connection.update':
                    $this->handleInstanceUpdate($instanceName, $this->payload['data'] ?? []);
                    break;

                case 'messages.upsert':
                    $this->handleNewMessage($instanceName, $this->payload['data'] ?? []);
                    break;

                case 'messages.update':
                    $this->handleMessageStatus($this->payload['data'] ?? []);
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Falha no Job Webhook [{$event}]: " . $e->getMessage());
            throw $e; // Garante que o Job tente novamente se for erro de banco
        }
    }

    private function handleInstanceUpdate($name, $data)
    {
        $state = $data['state'] ?? null;
        $status = ($state === 'open') ? 'connected' : 'disconnected';

        Instance::where('instance_name', $name)->update(['status' => $status]);
    }

    private function handleNewMessage($instanceName, $data)
    {
        // O seu JSON mostra que $data é o objeto da mensagem diretamente.
        // Não existe um array 'message' para iterar.
        $msg = $data;

        // 1. Verificação de segurança: ignorar mensagens enviadas pela própria instância
        if ($msg['key']['fromMe'] ?? false) {
            return;
        }

        $remoteJid = $msg['key']['remoteJid'] ?? null;
        if (!$remoteJid) {
            return;
        }

        // Extrai o número (ex: 5595981110695)
        // Nota: Seu JID veio como @s.us.whatsapp.net, o explode lida com isso.
        $whatsappId = explode('@', $remoteJid)[0];

        // 2. Mapeamento de texto conforme o seu JSON específico (message -> conversation)
        $text = $msg['message']['conversation']
            ?? $msg['message']['extendedTextMessage']['text']
            ?? $msg['message']['buttonsResponseMessage']['selectedDisplayText']
            ?? '';

        if (strtolower(trim($text)) === '#sair') {
            // Atualiza o banco de dados
            \App\Models\Contact::where('contact', $whatsappId)->update(['ignore_me' => 1]);

            // Alimenta a Blacklist no Redis para performance
            \Illuminate\Support\Facades\Redis::sadd("blacklist:instance:{$instanceName}", $whatsappId);

            \Illuminate\Support\Facades\Log::info("Opt-out processado para: {$whatsappId}");
        }
    }

    private function handleMessageStatus($data)
    {
        foreach ($data as $update) {
            $msgId = $update['key']['id'] ?? null;
            $status = strtolower($update['update']['status'] ?? '');

            if ($msgId && $status) {
                $job = WhatsappJob::where('message_id', $msgId)->first();
                if ($job) {
                    $job->update(['evolution_status' => $status]);
                    // Lógica de score omitida aqui por brevidade, mas segue o mesmo padrão
                }
            }
        }
    }
}
