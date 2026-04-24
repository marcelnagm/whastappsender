<?php

namespace App\Jobs;

use App\Models\AiMessage;
use App\Models\Contact;
use App\Models\Instance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        protected int $aiMessageId,
        protected int $instanceId,
        protected int $contactId
    ) {
    }

    public function handle(): void
    {
        $aiMessage = AiMessage::find($this->aiMessageId);
        $instance = Instance::find($this->instanceId);
        $contact = Contact::find($this->contactId);

        if (!$aiMessage || !$instance || !$contact) {
            return;
        }

        if ($aiMessage->session && (int) $aiMessage->session->instance_id !== (int) $instance->id) {
            $aiMessage->update([
                'status' => 'error',
                'error' => 'Instancia divergente da sessao de IA',
            ]);
            return;
        }

        if ((int) $contact->user_id !== (int) $instance->user_id) {
            $aiMessage->update([
                'status' => 'error',
                'error' => 'Contato nao pertence ao dono da instancia',
            ]);
            return;
        }

        if ($contact->status === 'no-whatsapp') {
            $aiMessage->update([
                'status' => 'error',
                'error' => 'Contato marcado como no-whatsapp',
            ]);
            return;
        }

        $config = config('services.whatsapp');
        $baseUrl = "{$config['protocol']}://{$config['url']}:{$config['port']}";
        $globalApiKey = $config['apikey'];
        $number = $contact->contact;

        try {
            Http::withHeaders(['apikey' => $globalApiKey])
                ->post("{$baseUrl}/chat/sendPresence/{$instance->instance_name}", [
                    'number' => $number,
                    'presence' => 'composing',
                    'delay' => rand(1200, 2800),
                ]);

            $response = Http::withHeaders([
                'apikey' => $globalApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(35)->post("{$baseUrl}/message/sendText/{$instance->instance_name}", [
                'number' => $number,
                'text' => $aiMessage->content,
            ]);

            if ($response->failed()) {
                $aiMessage->update([
                    'status' => 'error',
                    'error' => mb_substr($response->body(), 0, 600),
                    'raw_payload' => ['response' => $response->json()],
                ]);
                return;
            }

            $data = $response->json();
            $remoteId = $data['key']['id'] ?? ($data['message']['key']['id'] ?? ($data['response']['key']['id'] ?? null));

            $aiMessage->update([
                'status' => 'sent',
                'channel_message_id' => $remoteId,
                'raw_payload' => $data,
            ]);

            if ($aiMessage->session) {
                $aiMessage->session->update(['last_outbound_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::error('Falha no SendAiReplyJob: ' . $e->getMessage());
            $aiMessage->update([
                'status' => 'error',
                'error' => mb_substr($e->getMessage(), 0, 600),
            ]);
        }
    }
}
