<?php

namespace App\Services\Telegram;

use App\Models\Instance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Telegram Bot API for one bot. The token never appears in exception messages or logs. */
class TelegramClient
{
    public function __construct(private string $token)
    {
    }

    public static function for(Instance $instance): self
    {
        return new self((string) $instance->telegram_token);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('telegram.timeout', 30))->acceptJson();
    }

    private function url(string $method): string
    {
        return config('telegram.api_url') . '/bot' . $this->token . '/' . $method;
    }

    /** @throws TelegramException */
    private function unwrap($response): array
    {
        $json = (array) $response->json();
        if (!$response->successful() || empty($json['ok'])) {
            $detail = (string) ($json['description'] ?? ('HTTP ' . $response->status()));

            throw new TelegramException($this->scrub($detail));
        }

        return (array) ($json['result'] ?? []);
    }

    private function scrub(string $text): string
    {
        return str_replace($this->token, '[token]', $text);
    }

    /** @throws TelegramException */
    public function call(string $method, array $params = []): array
    {
        try {
            return $this->unwrap($this->http()->asJson()->post($this->url($method), $params));
        } catch (TelegramException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TelegramException($this->scrub($e->getMessage()));
        }
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => config('telegram.allowed_updates'),
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    public function webhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /** @return string Telegram message id */
    public function sendMessage(string $chatId, string $text, ?int $replyTo = null): string
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => false];
        if ($replyTo) {
            $params['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
        }

        return (string) ($this->call('sendMessage', $params)['message_id'] ?? '');
    }

    public function typing(string $chatId): void
    {
        try {
            $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        } catch (\Throwable $e) {
            // cosmetic
        }
    }

    /**
     * @param string $type image | video | audio | document
     * @return string Telegram message id
     */
    public function sendFile(string $type, string $chatId, string $binary, string $fileName, ?string $caption = null, ?string $mime = null, ?int $replyTo = null): string
    {
        // voice notes only accept OGG/Opus; other audio goes as a regular audio file
        $voice = $type === 'audio' && in_array($mime, ['audio/ogg', 'audio/opus', 'audio/ogg; codecs=opus'], true);
        [$method, $field] = match (true) {
            $type === 'image' => ['sendPhoto', 'photo'],
            $type === 'video' => ['sendVideo', 'video'],
            $voice => ['sendVoice', 'voice'],
            $type === 'audio' => ['sendAudio', 'audio'],
            default => ['sendDocument', 'document'],
        };

        $params = ['chat_id' => $chatId];
        if ($caption !== null && $caption !== '' && !$voice) {
            $params['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($replyTo) {
            $params['reply_parameters'] = json_encode(['message_id' => $replyTo, 'allow_sending_without_reply' => true]);
        }

        try {
            $response = $this->http()->attach($field, $binary, $fileName ?: 'file')->post($this->url($method), $params);

            return (string) ($this->unwrap($response)['message_id'] ?? '');
        } catch (TelegramException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TelegramException($this->scrub($e->getMessage()));
        }
    }

    /**
     * Downloads a file the customer sent (Bot API limit: 20 MB).
     *
     * @return array{binary:string,path:string}
     * @throws TelegramException
     */
    public function download(string $fileId, ?int $knownSize = null): array
    {
        $max = (int) config('telegram.max_download_bytes');
        if ($knownSize !== null && $knownSize > $max) {
            throw new TelegramException('File too large to download.');
        }

        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = (string) ($file['file_path'] ?? '');
        if ($path === '' || (isset($file['file_size']) && (int) $file['file_size'] > $max)) {
            throw new TelegramException('File not available.');
        }

        try {
            $response = $this->http()->get(config('telegram.api_url') . '/file/bot' . $this->token . '/' . $path);
        } catch (\Throwable $e) {
            throw new TelegramException($this->scrub($e->getMessage()));
        }
        if (!$response->successful() || strlen($response->body()) > $max) {
            throw new TelegramException('File download failed.');
        }

        return ['binary' => $response->body(), 'path' => $path];
    }
}
