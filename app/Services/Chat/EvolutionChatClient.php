<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Evolution API v2.3 endpoints used by the chat.
 * The instance name is the WhatsApp session number (Instance::instance_name).
 */
class EvolutionChatClient
{
    protected function http(int $timeout = 40): PendingRequest
    {
        $config = config('services.whatsapp');

        return Http::baseUrl("{$config['protocol']}://{$config['url']}:{$config['port']}")
            ->withHeaders(['apikey' => $config['apikey']])
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);
    }

    /**
     * @throws RuntimeException when Evolution answers with an error
     */
    protected function ensure(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException(mb_substr($response->body(), 0, 600) ?: 'Evolution API error ' . $response->status());
        }

        return (array) $response->json();
    }

    public function sendText(string $instance, string $number, string $text, ?array $quoted = null): array
    {
        $payload = ['number' => $number, 'text' => $text];
        if ($quoted) {
            $payload['quoted'] = $quoted;
        }

        return $this->ensure($this->http()->post("/message/sendText/{$instance}", $payload));
    }

    public function sendMedia(
        string $instance,
        string $number,
        string $mediatype,
        string $mimetype,
        string $base64,
        string $fileName,
        ?string $caption = null,
        ?array $quoted = null
    ): array {
        $payload = [
            'number' => $number,
            'mediatype' => $mediatype, // image | video | document
            'mimetype' => $mimetype,
            'media' => $base64,
            'fileName' => $fileName,
        ];
        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($quoted) {
            $payload['quoted'] = $quoted;
        }

        return $this->ensure($this->http(120)->post("/message/sendMedia/{$instance}", $payload));
    }

    /**
     * Sends a voice note (PTT). Evolution converts the audio to OGG/Opus when "encoding" is true.
     */
    public function sendAudio(string $instance, string $number, string $base64, ?array $quoted = null): array
    {
        $payload = ['number' => $number, 'audio' => $base64, 'encoding' => true];
        if ($quoted) {
            $payload['quoted'] = $quoted;
        }

        return $this->ensure($this->http(120)->post("/message/sendWhatsAppAudio/{$instance}", $payload));
    }

    public function sendReaction(string $instance, string $remoteJid, string $messageId, bool $fromMe, string $emoji): array
    {
        return $this->ensure($this->http()->post("/message/sendReaction/{$instance}", [
            'key' => ['remoteJid' => $remoteJid, 'fromMe' => $fromMe, 'id' => $messageId],
            'reaction' => $emoji,
        ]));
    }

    public function deleteForEveryone(string $instance, string $remoteJid, string $messageId, bool $fromMe): array
    {
        return $this->ensure($this->http()->send('DELETE', "/chat/deleteMessageForEveryone/{$instance}", [
            'json' => ['id' => $messageId, 'remoteJid' => $remoteJid, 'fromMe' => $fromMe],
        ]));
    }

    /**
     * @param array<int, array{remoteJid:string,fromMe:bool,id:string}> $keys
     */
    public function markAsRead(string $instance, array $keys): array
    {
        return $this->ensure($this->http(10)->post("/chat/markMessageAsRead/{$instance}", [
            'readMessages' => array_values($keys),
        ]));
    }

    public function sendPresence(string $instance, string $number, string $presence = 'composing', int $delay = 3000): void
    {
        $this->http(5)->post("/chat/sendPresence/{$instance}", [
            'number' => $number,
            'presence' => $presence,
            'delay' => $delay,
        ]);
    }

    /**
     * Downloads a media message and returns ['base64' => ..., 'mimetype' => ..., 'fileName' => ...].
     */
    public function mediaBase64(string $instance, string $messageId): array
    {
        return $this->ensure($this->http(90)->post("/chat/getBase64FromMediaMessage/{$instance}", [
            'message' => ['key' => ['id' => $messageId]],
            'convertToMp4' => false,
        ]));
    }

    /**
     * Checks whether numbers are on WhatsApp and returns the canonical JID
     * (handles the Brazilian 9th digit). Returns null when it could not be checked.
     *
     * @return array{exists:bool,jid:?string,number:?string}|null
     */
    public function checkNumber(string $instance, string $number): ?array
    {
        try {
            $response = $this->http(15)->post("/chat/whatsappNumbers/{$instance}", ['numbers' => [$number]]);
            if ($response->failed()) {
                return null;
            }

            $row = $response->json()[0] ?? null;
            if (!is_array($row)) {
                return null;
            }

            return [
                'exists' => (bool) ($row['exists'] ?? false),
                'jid' => $row['jid'] ?? null,
                'number' => $row['number'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{status:string,url:?string} status: ok (url given) | none (no picture) | error (try again later)
     */
    public function fetchProfilePicture(string $instance, string $number): array
    {
        try {
            $response = $this->http(10)->post("/chat/fetchProfilePictureUrl/{$instance}", ['number' => $number]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'url' => null];
        }

        if ($response->successful()) {
            $data = (array) $response->json();
            $url = $data['profilePictureUrl'] ?? $data['url'] ?? null;

            return $url ? ['status' => 'ok', 'url' => (string) $url] : ['status' => 'none', 'url' => null];
        }

        // Evolution answers 400 when it cannot resolve a picture for that number
        return ['status' => $response->status() === 400 ? 'none' : 'error', 'url' => null];
    }

    public function findChats(string $instance): array
    {
        $data = $this->ensure($this->http(60)->post("/chat/findChats/{$instance}", (object) []));

        return self::isList($data) ? $data : ($data['chats'] ?? $data['records'] ?? []);
    }

    /**
     * @return array<int, array> raw message records (same shape as the messages.upsert "data")
     */
    public function findMessages(string $instance, string $remoteJid, int $page = 1, int $limit = 30): array
    {
        $data = $this->ensure($this->http(60)->post("/chat/findMessages/{$instance}", [
            'where' => ['key' => ['remoteJid' => $remoteJid]],
            'page' => $page,
            'offset' => $limit,
        ]));

        // Evolution v2 returns { messages: { total, pages, currentPage, records: [...] } }
        if (isset($data['messages']['records'])) {
            return $data['messages']['records'];
        }
        if (isset($data['records'])) {
            return $data['records'];
        }

        return self::isList($data) ? $data : [];
    }

    /** @return array<int, array> contact records for one jid (Evolution findContacts) */
    public function findContacts(string $instance, string $remoteJid): array
    {
        $data = $this->ensure($this->http(15)->post("/chat/findContacts/{$instance}", ['where' => ['remoteJid' => $remoteJid]]));

        return self::isList($data) ? $data : ($data['contacts'] ?? $data['records'] ?? []);
    }

    private static function isList(array $data): bool
    {
        return $data === [] || array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * Extracts the WhatsApp message id from a send* response.
     */
    public static function extractMessageId(array $response): ?string
    {
        return $response['key']['id']
            ?? $response['message']['key']['id']
            ?? $response['response']['key']['id']
            ?? null;
    }
}
