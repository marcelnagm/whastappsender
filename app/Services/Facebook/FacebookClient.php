<?php

namespace App\Services\Facebook;

use App\Models\Instance;
use App\Services\Instagram\InstagramClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Messenger Platform API for one Facebook Page. The Page token is sent as a header and scrubbed from every error. */
class FacebookClient
{
    public function __construct(private string $token)
    {
    }

    public static function for(Instance $instance): self
    {
        return new self((string) $instance->facebook_token);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('facebook.timeout', 30))->withToken($this->token)->acceptJson();
    }

    private function url(string $path): string
    {
        return config('facebook.graph_url') . '/' . config('facebook.api_version') . '/' . ltrim($path, '/');
    }

    private function scrub(string $text): string
    {
        return $this->token === '' ? $text : str_replace($this->token, '[token]', $text);
    }

    /** @throws FacebookException */
    private function request(string $method, string $path, array $params = []): array
    {
        try {
            $http = $this->http();
            $response = $method === 'GET'
                ? $http->get($this->url($path), $params)
                : ($method === 'DELETE' ? $http->delete($this->url($path), $params) : $http->asJson()->post($this->url($path), $params));

            $json = (array) $response->json();
            if (!$response->successful() || isset($json['error'])) {
                throw new FacebookException($this->scrub((string) ($json['error']['message'] ?? ('HTTP ' . $response->status()))));
            }

            return $json;
        } catch (FacebookException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new FacebookException($this->scrub($e->getMessage()));
        }
    }

    /** The Page the token belongs to. @return array{id?:string,name?:string} */
    public function me(): array
    {
        return $this->request('GET', 'me', ['fields' => 'id,name']);
    }

    public function subscribe(): void
    {
        $this->request('POST', 'me/subscribed_apps', ['subscribed_fields' => 'messages,message_echoes,message_reads']);
    }

    public function unsubscribe(): void
    {
        $this->request('DELETE', 'me/subscribed_apps');
    }

    /** Name of someone who wrote to the Page (best effort). */
    public function profile(string $psid): array
    {
        try {
            $p = $this->request('GET', $psid, ['fields' => 'name,first_name,last_name']);
            $p['name'] ??= trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));

            return $p;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return string[] ids of the messages created (one per part) */
    public function sendText(string $psid, string $text): array
    {
        $ids = [];
        foreach (InstagramClient::chunk($text, (int) config('facebook.max_text', 1900)) as $part) {
            $ids[] = (string) ($this->request('POST', 'me/messages', [
                'messaging_type' => 'RESPONSE', 'recipient' => ['id' => $psid], 'message' => ['text' => $part],
            ])['message_id'] ?? '');
        }

        return $ids;
    }

    /** @param string $type image | video | audio | document @return string message id */
    public function sendAttachment(string $psid, string $type, string $url): string
    {
        $json = $this->request('POST', 'me/messages', [
            'messaging_type' => 'RESPONSE', 'recipient' => ['id' => $psid],
            'message' => ['attachment' => ['type' => $type === 'document' ? 'file' : $type, 'payload' => ['url' => $url, 'is_reusable' => false]]],
        ]);

        return (string) ($json['message_id'] ?? '');
    }

    public function typing(string $psid): void
    {
        try {
            $this->request('POST', 'me/messages', ['recipient' => ['id' => $psid], 'sender_action' => 'typing_on']);
        } catch (\Throwable $e) {
            // cosmetic
        }
    }

    /** @return string binary @throws FacebookException */
    public function download(string $url): string
    {
        try {
            $response = Http::timeout((int) config('facebook.timeout', 30))->get($url);
        } catch (\Throwable $e) {
            throw new FacebookException($this->scrub($e->getMessage()));
        }
        if (!$response->successful() || strlen($response->body()) > (int) config('facebook.max_download_bytes')) {
            throw new FacebookException('File download failed.');
        }

        return $response->body();
    }
}
