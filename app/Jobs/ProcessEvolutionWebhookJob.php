<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Services\AI\AiAutoReplyService;
use App\Services\AI\AiInboundMessageRecorder;
use App\Services\AI\GroqAgentResponder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        $data = $this->payload['data'] ?? [];

        try {
            switch ($event) {
                case 'messages.update':
                    // Processamento direto de mensagem única conforme seu relato
                    $this->handleMessageStatus($data);
                    break;

                case 'messages.upsert':
                    $this->handleNewMessage($instanceName, $data);
                    break;

                case 'connection.update':
                    $this->handleInstanceUpdate($instanceName, $data);
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Falha no ProcessEvolutionWebhookJob: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Trata o status de UMA única mensagem (Evolution v2.3)
     */
    private function handleMessageStatus(array $data)
    {
if(env("DEBUG_WEBHOOK_STATUS"))
Log::info(json_encode($data));
        // Extração direta do ID e do Status (Caixa Alta)
        $msgId = $data['keyId'] ?? null;
        
        // Na v2.3, o status de update muitas vezes vem dentro de 'update'
        $statusRaw = $data['status'] ?? null;

        if (!$msgId || !$statusRaw) {
            if (config('app.debug')) Log::warning("Webhook Status: Dados incompletos", ['id' => $msgId, 'status' => $statusRaw]);
            return;
        }

        $this->updateJobStatus($msgId, (string) $statusRaw);

        // Sincronização de LID (aproveita o evento de ACK para mapear o contato)
        $remoteJid = $data['key']['remoteJid'] ?? null;
        $senderPn = $data['key']['senderPn'] ?? null;
        if ($remoteJid && str_contains($remoteJid, '@lid') && $senderPn) {
            $this->syncLidWithContact($remoteJid, $senderPn);
        }
    }

    /**
     * Mapeia e persiste o status no banco
     */
    private function updateJobStatus(string $msgId, string $statusRaw)
    {
        $map = [
            'SERVER_ACK'   => 'sent',
            'DELIVERY_ACK' => 'delivered',
            'READ'         => 'read',
            'PLAYED'       => 'played',
            'ERROR'        => 'error'
        ];

        // Normaliza para garantir que espaços ou case não quebrem o mapeamento
        $statusKey = strtoupper(trim($statusRaw));
        $finalStatus =$statusRaw;
        $affected = WhatsappJob::where('message_id', $msgId)->update([
            'evolution_status' => $finalStatus,
            'updated_at' => now()
        ]);

        if ($affected === 0) {
            #Log::warning("ACK Recebido ($statusKey), mas ID não encontrado: $msgId");
        } elseif (config('app.debug')) {
            Log::info("Job Atualizado: $msgId -> $finalStatus");
        }
    }

    private function handleNewMessage($instanceName, $data)
    {
        $this->debugWebhook('Nova mensagem recebida (messages.upsert)', [
            'instance' => $instanceName,
            'remoteJid' => $data['key']['remoteJid'] ?? null,
            'messageId' => $data['key']['id'] ?? null,
            'fromMe' => $data['key']['fromMe'] ?? null,
            'payload' => $data,
        ]);

        if ($this->isGroupMessage($data)) {
            $this->debugWebhook('Mensagem ignorada por ser de grupo', [
                'remoteJid' => $data['key']['remoteJid'] ?? null,
            ]);
            return;
        }

        // if ($data['key']['fromMe'] ?? false) {
        //     $this->debugWebhook('Mensagem ignorada por ser fromMe', [
        //         'remoteJid' => $data['key']['remoteJid'] ?? null,
        //     ]);
        //     return;
        // }

        $whatsappId = $this->resolveContactId($data);
        if (!$whatsappId) {
            $this->debugWebhook('Mensagem ignorada: contato nao resolvido', [
                'remoteJid' => $data['key']['remoteJid'] ?? null,
                'senderPn' => $data['key']['senderPn'] ?? null,
            ]);
            return;
        }

        $text = $data['message']['conversation'] 
              ?? $data['message']['extendedTextMessage']['text'] 
              ?? '';

        app(AiInboundMessageRecorder::class)->record((string) $instanceName, (array) $data, (string) $text);
        $this->debugWebhook('Mensagem inbound registrada para IA', [
            'instance' => $instanceName,
            'whatsappId' => $whatsappId,
            'textPreview' => mb_substr(trim((string) $text), 0, 200),
        ]);

        if (strtolower(trim($text)) === '#sair') {
            $this->processOptOut($instanceName, $whatsappId);
            $this->debugWebhook('Opt-out processado (#sair)', [
                'instance' => $instanceName,
                'whatsappId' => $whatsappId,
            ]);
            return;
        }

        if ($this->handleWelcomeReply((string) $instanceName, (string) $whatsappId, (string) $text)) {
            $this->debugWebhook('Resposta inbound tratada pelo fluxo welcome', [
                'instance' => $instanceName,
                'whatsappId' => $whatsappId,
            ]);
            return;
        }

        $this->debugWebhook('Encaminhando mensagem para fluxo de IA', [
            'instance' => $instanceName,
            'whatsappId' => $whatsappId,
        ]);
        app(AiAutoReplyService::class)->handleInbound((string) $instanceName, (array) $data, (string) $text);
    }

    private function isGroupMessage(array $data): bool
    {
        $remoteJid = $data['key']['remoteJid'] ?? '';
        return str_contains($remoteJid, '@g.us');
    }

    private function handleInstanceUpdate($name, $data)
    {
        $state = $data['state'] ?? null;
        $status = ($state === 'open') ? 'connected' : 'disconnected';
        Instance::where('instance_name', $name)->update(['status' => $status]);
    }

    private function syncLidWithContact($lid, $senderPn)
    {
        if (str_contains($lid, '@g')) return;
        $cleanNumber = explode('@', $senderPn)[0];
        Contact::where('contact', $cleanNumber)->update(['lid' => $lid]);
    }

    private function resolveContactId($data)
    {
        $remoteJid = $data['key']['remoteJid'] ?? '';
        $senderPn = $data['key']['senderPn'] ?? null;
        return (str_contains($remoteJid, '@lid') && $senderPn) 
            ? explode('@', $senderPn)[0] 
            : explode('@', $remoteJid)[0];
    }

    private function processOptOut($instanceName, $whatsappId)
    {
        if (Contact::where('contact', $whatsappId)->update(['ignore_me' => 1])) {
            Redis::sadd("blacklist:instance:{$instanceName}", $whatsappId);
        }
    }

    private function handleWelcomeReply(string $instanceName, string $whatsappId, string $text): bool
    {
        $instance = Instance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            return false;
        }

        $pendingKey = "welcome:pending_reply:user:{$instance->user_id}";
        if (!Redis::sismember($pendingKey, $whatsappId)) {
            return false;
        }

        $contact = Contact::where('user_id', $instance->user_id)
            ->where('contact', $whatsappId)
            ->first();

        if (!$contact) {
            return false;
        }

        $user = User::find($instance->user_id);
        if (!$user) {
            return false;
        }

        $campaignItemId = Redis::hget("welcome:contact_campaign:user:{$instance->user_id}", $whatsappId) ?: null;
        $reply = $this->generateWelcomeReply($user, $text);

        $job = WhatsappJob::create([
            'endpoint' => '/message/sendText/',
            'status' => 'pendente',
            'payload' => [
                'number' => $contact->contact,
                'text' => $reply,
            ],
            'campaign_item_id' => $campaignItemId,
            'user_id' => $instance->user_id,
            'contact_id' => $contact->id,
            'tentativas' => 0,
        ]);

        \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('disparos');

        Redis::srem($pendingKey, $whatsappId);
        Redis::hdel("welcome:contact_campaign:user:{$instance->user_id}", $whatsappId);

        return true;
    }

    private function generateWelcomeReply(User $user, string $text): string
    {
        $result = app(GroqAgentResponder::class)->generateReply($user, [
            [
                'role' => 'user',
                'content' => "Mensagem inbound do lead apos primeiro contato: {$text}. Gere resposta curta, cordial e que avance a conversa.",
            ],
        ]);

        $reply = trim((string) ($result['reply'] ?? ''));
        if ($reply === '') {
            $reply = 'Perfeito! Obrigado por responder. Posso te mostrar os proximos detalhes agora?';
        }

        return $reply;
    }

    private function debugWebhook(string $message, array $context = []): void
    {
        if (!config('services.ai.debug_webhook', false)) {
            return;
        }

        Log::info('[AI_WEBHOOK_DEBUG] ' . $message, $context);
    }
}

