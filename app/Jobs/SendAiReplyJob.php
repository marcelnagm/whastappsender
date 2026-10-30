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
                'error' => 'Instance does not match AI session',
            ]);
            return;
        }

        if ((int) $contact->user_id !== (int) $instance->user_id) {
            $aiMessage->update([
                'status' => 'error',
                'error' => 'Contact does not belong to this instance owner',
            ]);
            return;
        }

        if ($contact->status === 'no-whatsapp') {
            $aiMessage->update([
                'status' => 'error',
                'error' => 'Contact marked as no-whatsapp',
            ]);
            return;
        }

        if ($instance->isTelegram()) {
            $this->sendViaTelegram($aiMessage, $instance, $contact);

            return;
        }
        if ($instance->isInstagram()) {
            $this->sendViaInstagram($aiMessage, $instance, $contact);

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
            Log::error('SendAiReplyJob failed: ' . $e->getMessage());
            $aiMessage->update([
                'status' => 'error',
                'error' => mb_substr($e->getMessage(), 0, 600),
            ]);
        }
    }

    /** Answers through the bot that received the message and records it in the CRM chat. */
    private function sendViaTelegram(AiMessage $aiMessage, Instance $instance, Contact $contact): void
    {
        try {
            $chatId = substr((string) $contact->contact, 3); // "tg:<id>"
            if (!str_starts_with((string) $contact->contact, 'tg:') || $chatId === '') {
                throw new \RuntimeException('Contact is not a Telegram chat.');
            }

            $client = \App\Services\Telegram\TelegramClient::for($instance);
            $client->typing($chatId);
            $id = $client->sendMessage($chatId, (string) $aiMessage->content);
            $messageId = $chatId . ':' . $id;

            $aiMessage->update(['status' => 'sent', 'channel_message_id' => $messageId]);
            if ($aiMessage->session) {
                $aiMessage->session->update(['last_outbound_at' => now()]);
            }

            $chat = \App\Models\Chat::where('instance_id', $instance->id)->where('remote_jid', $contact->contact)->first();
            if ($chat) {
                $message = \App\Models\ChatMessage::create([
                    'chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id,
                    'message_id' => $messageId, 'from_me' => true, 'type' => 'text', 'body' => $aiMessage->content,
                    'status' => 'sent', 'message_timestamp' => now(),
                ]);
                app(\App\Services\Chat\ChatMessageRecorder::class)->touchChat($chat, $message, true);
            }
        } catch (\Throwable $e) {
            Log::error('SendAiReplyJob (telegram) failed: ' . $e->getMessage());
            $aiMessage->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 600)]);
        }
    }

    /** Answers through the Instagram account that received the message (inside the reply window). */
    private function sendViaInstagram(AiMessage $aiMessage, Instance $instance, Contact $contact): void
    {
        try {
            if (!str_starts_with((string) $contact->contact, 'ig:')) {
                throw new \RuntimeException('Contact is not an Instagram chat.');
            }
            $igsid = substr((string) $contact->contact, 3);
            $chat = \App\Models\Chat::where('instance_id', $instance->id)->where('remote_jid', $contact->contact)->first();
            if ($chat && !\App\Services\Instagram\InstagramWindow::isOpen($chat)) {
                throw new \RuntimeException(\App\Services\Instagram\InstagramWindow::closedMessage());
            }

            $client = \App\Services\Instagram\InstagramClient::for($instance);
            $client->typing($igsid);
            $ids = $client->sendText($igsid, (string) $aiMessage->content);
            \App\Services\Instagram\InstagramInbound::rememberSent($ids);
            $messageId = \App\Services\Instagram\InstagramInbound::msgId($ids[0] ?? '');

            $aiMessage->update(['status' => 'sent', 'channel_message_id' => $messageId]);
            if ($aiMessage->session) {
                $aiMessage->session->update(['last_outbound_at' => now()]);
            }

            if ($chat) {
                $message = \App\Models\ChatMessage::create([
                    'chat_id' => $chat->id, 'instance_id' => $instance->id, 'user_id' => $instance->user_id,
                    'message_id' => $messageId, 'from_me' => true, 'type' => 'text', 'body' => $aiMessage->content,
                    'status' => 'sent', 'message_timestamp' => now(),
                ]);
                app(\App\Services\Chat\ChatMessageRecorder::class)->touchChat($chat, $message, true);
            }
        } catch (\Throwable $e) {
            Log::error('SendAiReplyJob (instagram) failed: ' . $e->getMessage());
            $aiMessage->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 600)]);
        }
    }
}
