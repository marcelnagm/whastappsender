<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Chat\EvolutionChatClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sends one message written in the chat UI through the Evolution API.
 * Single attempt on purpose: a retry after a timeout could deliver the same message twice.
 * The agent can retry manually from the UI when it fails.
 */
class SendChatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public int $chatMessageId)
    {
    }

    public function handle(EvolutionChatClient $client, ChatMessageRecorder $recorder): void
    {
        $message = ChatMessage::with(['chat', 'instance'])->find($this->chatMessageId);

        if (!$message || !$message->from_me || $message->message_id || !$message->chat || !$message->instance) {
            return;
        }

        $chat = $message->chat;
        $instance = $message->instance->instance_name;
        $number = $chat->sendNumber();
        $quoted = $this->quotedPayload($message, $chat);

        try {
            $response = match ($message->type) {
                'image', 'video', 'document' => $client->sendMedia(
                    $instance,
                    $number,
                    $message->type,
                    $message->mimetype ?: 'application/octet-stream',
                    $this->readBase64($message),
                    $message->file_name ?: 'file',
                    $message->body,
                    $quoted
                ),
                'audio' => $client->sendAudio($instance, $number, $this->readBase64($message), $quoted),
                default => $client->sendText($instance, $number, (string) $message->body, $quoted),
            };

            $this->markSent($message, $chat, EvolutionChatClient::extractMessageId($response));
        } catch (\Throwable $e) {
            Log::error('SendChatMessageJob failed: ' . $e->getMessage(), ['chat_message_id' => $message->id]);

            $message->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 600)]);
            Chat::where('id', $chat->id)->where('last_message_id', $message->id)->update(['last_message_status' => 'error']);
        }
    }

    private function markSent(ChatMessage $message, Chat $chat, ?string $waId): void
    {
        try {
            $message->update(['status' => 'sent', 'error' => null, 'message_id' => $waId]);
        } catch (QueryException $e) {
            // The webhook was faster and already created this message: keep that row, drop ours.
            $existing = ChatMessage::where('instance_id', $message->instance_id)->where('message_id', $waId)->first();
            if ($existing) {
                $existing->update(['client_id' => $message->client_id]);
                Chat::where('id', $chat->id)->where('last_message_id', $message->id)->update(['last_message_id' => $existing->id]);
                $message->delete();

                return;
            }
            throw $e;
        }

        Chat::where('id', $chat->id)->where('last_message_id', $message->id)->update(['last_message_status' => 'sent']);
    }

    private function readBase64(ChatMessage $message): string
    {
        if (!$message->media_path || !Storage::exists($message->media_path)) {
            throw new \RuntimeException('Attachment file not found.');
        }

        return base64_encode(Storage::get($message->media_path));
    }

    private function quotedPayload(ChatMessage $message, Chat $chat): ?array
    {
        if (!$message->quoted_message_id) {
            return null;
        }

        $original = ChatMessage::where('instance_id', $message->instance_id)
            ->where('message_id', $message->quoted_message_id)
            ->first();

        return [
            'key' => [
                'id' => $message->quoted_message_id,
                'remoteJid' => $chat->remote_jid,
                'fromMe' => (bool) optional($original)->from_me,
            ],
            'message' => ['conversation' => (string) ($message->quoted_preview ?? '')],
        ];
    }
}
