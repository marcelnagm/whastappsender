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
                    if(env('APP_DEBUG')) Log::alert(json_encode($this->payload));
                    $this->handleNewMessage($instanceName, $this->payload['data'] ?? []);
                    break;

                case 'messages.update':
                    if(env('APP_DEBUG')) Log::alert(json_encode($this->payload));
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
        // 1. Validação Básica
        if ($data['key']['fromMe'] ?? false) {
            return;
        }
        $remoteJid = $data['key']['remoteJid'] ?? null;
        $senderPn = $data['key']['senderPn'] ?? null;
        if ($remoteJid && str_contains($remoteJid, '@lid') && $senderPn) {
            $this->syncLidWithContact($remoteJid, $senderPn);
        }

        // 2. Resolução do ID Real (Usa o senderPn se for LID)
        $whatsappId = $this->resolveContactId($data);

        if (!$whatsappId) {
            \Illuminate\Support\Facades\Log::warning("Webhook recebido sem identificador válido.");
            return;
        }

        // 3. Extração do Texto (Simplificada para o que você recebe)
        $text = $data['message']['conversation']
            ?? $data['message']['extendedTextMessage']['text']
            ?? '';

        // 4. Lógica de Negócio: Comando #sair
        if (strtolower(trim($text)) === '#sair') {
            $this->processOptOut($instanceName, $whatsappId);
        }
    }
 /**
     * Trata o status da mensagem vindo da Evolution v2.3
     */
    private function handleMessageStatus(array $data)
    {
        // Normaliza para lidar com múltiplos updates ou objeto único
        
        $update = isset($data['key']) ? [$data] : (is_array($data) ? $data : []);
        
        Log::error(json_encode($update));
        
        if($update['remoteJid'] === "status@broadcast"){
         if(env('APP_DEBUG') ) Log::info('Broadcast')  ;
         return;
        }

            $msgId = $update['keyId'] ?? null;
            
            // Prioriza o status numérico do campo 'status' ou 'update.status'
            $numericStatus = $update['status'] ?? $update['update']['status'] ?? null;

            if ($msgId && $numericStatus !== null) {
                $this->updateJobStatus($msgId,  $numericStatus);
            }
        
    }

    /**
     * Mapeia o Integer do Baileys para a String do seu Banco
     */
    private function updateJobStatus(string $msgId,  $status)
    {
        

        

        // Log de debug para validar a entrada (Remova em produção após validar)
        // Log::debug("Atualizando Message ID: $msgId | Status Numérico: $status | Mapeado para: $finalStatus");

        WhatsappJob::where('message_id', $msgId)->update([
            'evolution_status' => $status,
            'updated_at' => now()
        ]);
    }

    /**
     * Sincroniza o LID recebido com o número de telefone real no banco de dados.
     */
    private function syncLidWithContact($lid, $senderPn)
    {
        // Extrai apenas os números (ex: 559591234567)

        if(str_contains($remoteJid, '@g')) return;

        $cleanNumber = explode('@', $senderPn)[0];


        if(env('APP_DEBUG')) Log::alert("Atualizado contato $cleanNumber : $lid");
        \App\Models\Contact::where('contact', $cleanNumber)
            ->update([
                'lid' => $lid,
                'updated_at' => now()
            ]);

        \Illuminate\Support\Facades\Log::info("LID Vinculado: Contato {$cleanNumber} agora mapeado para {$lid}");
    }

    private function processOptOut($instanceName, $whatsappId)
    {
        // Atualiza o banco de dados usando o número real (independente se veio de um LID)
        $affected = \App\Models\Contact::where('contact', $whatsappId)
            ->update(['ignore_me' => 1]);
        Log::alert("opt-out: $whatsappId");
        if ($affected) {
            // Alimenta o Redis para que o sistema de disparo ignore este número instantaneamente
            \Illuminate\Support\Facades\Redis::sadd("blacklist:instance:{$instanceName}", $whatsappId);

            if(env('APP_DEBUG')) \Illuminate\Support\Facades\Log::info("Opt-out processado com sucesso para: {$whatsappId}");
        }
    }

    private function resolveContactId($data)
    {
        $remoteJid = $data['key']['remoteJid'] ?? '';
        $senderPn = $data['key']['senderPn'] ?? null;
        if(env('APP_DEBUG')) Log::error("SENDER $senderPn - $remoteJid");
        // Lógica Analítica: Se é LID e tem PN, o PN é a nossa chave primária no Contact
        if (str_contains($remoteJid, '@lid') && $senderPn) {
            return explode('@', $senderPn)[0];
        }

        // Fallback para JID comum (@s.whatsapp.net)
        return explode('@', $remoteJid)[0];
    }
}
