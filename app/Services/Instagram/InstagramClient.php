<?php

namespace App\Services\Instagram;

use App\Models\Instance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Instagram messaging API for one account. The token is sent as a header and scrubbed from every error. */
class InstagramClient
{
    public function __construct(private string $token)
    {
    }

    public static function for(Instance $instance): self
    {
        return new self((string) $instance->instagram_token);
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('instagram.timeout', 30))->withToken($this->token)->acceptJson();
    }

    private function url(string $path): string
    {
        return config('instagram.graph_url') . '/' . config('instagram.api_version') . '/' . ltrim($path, '/');
    }

    private function scrub(string $text): string
    {
        return $this->token === '' ? $text : str_replace($this->token, '[token]', $text);
    }

    /** @throws InstagramException */
    private function unwrap($response): array
    {
        $json = (array) $response->json();
        if (!$response->successful() || isset($json['error'])) {
            $detail = (string) ($json['error']['message'] ?? ('HTTP ' . $response->status()));

            throw new InstagramException($this->scrub($detail));
        }

        return $json;
    }

    /** @throws InstagramException */
    private function request(string $method, string $path, array $params = []): array
    {
        try {
            $http = $this->http();
            $response = $method === 'GET'
                ? $http->get($this->url($path), $params)
                : ($method === 'DELETE' ? $http->delete($this->url($path), $params) : $http->asJson()->post($this->url($path), $params));

            return $this->unwrap($response);
        } catch (InstagramException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InstagramException($this->scrub($e->getMessage()));
        }
    }

    /** @return array{user_id?:string,id?:string,username?:string,name?:string} */
    public function me(): array
    {
        return $this->request('GET', 'me', ['fields' => 'user_id,username,name']);
    }

    /** Subscribes the account to the message webhooks of the app. */
    public function subscribe(): void
    {
        $this->request('POST', 'me/subscribed_apps', ['subscribed_fields' => 'messages,messaging_seen']);
    }

    public function unsubscribe(): void
    {
        $this->request('DELETE', 'me/subscribed_apps');
    }

    /** Long-lived tokens last 60 days; refreshing gives a new 60 days. */
    public function refresh(): ?string
    {
        try {
            $response = Http::timeout((int) config('instagram.timeout', 30))->acceptJson()
                ->get(config('instagram.graph_url') . '/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $this->token]);

            return $this->unwrap($response)['access_token'] ?? null;
        } catch (InstagramException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InstagramException($this->scrub($e->getMessage()));
        }
    }

    /** Name and username of someone who wrote to the account (best effort). */
    public function profile(string $igsid): array
    {
        try {
            return $this->request('GET', $igsid, ['fields' => 'name,username']);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Splits at paragraph / line / word boundaries so no part exceeds the 1000-character limit.
     *
     * @return string[]
     */
    public static function chunk(string $text, ?int $max = null): array
    {
        $max ??= (int) config('instagram.max_text', 950);
        $text = trim($text);
        $parts = [];
        while (mb_strlen($text) > $max) {
            $slice = mb_substr($text, 0, $max);
            $cut = max(mb_strrpos($slice, "\n\n") ?: 0, mb_strrpos($slice, "\n") ?: 0, mb_strrpos($slice, ' ') ?: 0);
            $cut = $cut > $max * 0.4 ? $cut : $max;
            $parts[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    /** @return string[] ids of the messages created (one per part) */
    public function sendText(string $igsid, string $text): array
    {
        $ids = [];
        foreach (self::chunk($text) as $part) {
            $ids[] = (string) ($this->request('POST', 'me/messages', ['recipient' => ['id' => $igsid], 'message' => ['text' => $part]])['message_id'] ?? '');
        }

        return $ids;
    }

    /** @param string $type image | video | audio | document @return string message id */
    public function sendAttachment(string $igsid, string $type, string $url): string
    {
        $igType = $type === 'document' ? 'file' : $type;
        $json = $this->request('POST', 'me/messages', [
            'recipient' => ['id' => $igsid],
            'message' => ['attachment' => ['type' => $igType, 'payload' => ['url' => $url]]],
        ]);

        return (string) ($json['message_id'] ?? '');
    }

    public function typing(string $igsid): void
    {
        try {
            $this->request('POST', 'me/messages', ['recipient' => ['id' => $igsid], 'sender_action' => 'typing_on']);
        } catch (\Throwable $e) {
            // cosmetic
        }
    }

    /** @return string binary @throws InstagramException */
    public function download(string $url): string
    {
        $max = (int) config('instagram.max_download_bytes');
        try {
            $response = Http::timeout((int) config('instagram.timeout', 30))->get($url);
        } catch (\Throwable $e) {
            throw new InstagramException($this->scrub($e->getMessage()));
        }
        if (!$response->successful() || strlen($response->body()) > $max) {
            throw new InstagramException('File download failed.');
        }

        return $response->body();
    }
}
