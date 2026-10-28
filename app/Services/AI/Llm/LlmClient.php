<?php

namespace App\Services\AI\Llm;

use App\Models\AiProfile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the provider of an AI profile. One method per wire protocol (see config/ai.php).
 * Never logs or returns the API key.
 */
class LlmClient
{
    /**
     * @param array<int, array{role:string,content:string}> $messages user / assistant turns, oldest first (no system)
     * @return array{reply:string,tokens_in:?int,tokens_out:?int,raw:array}
     *
     * @throws LlmException
     */
    public function chat(AiProfile $profile, string $systemPrompt, array $messages): array
    {
        $provider = $profile->providerConfig();
        if (!$provider) {
            throw new LlmException(__('Unknown AI provider.'));
        }

        $key = $this->apiKey($profile, $provider);
        $base = $this->baseUrl($profile, $provider);

        if (!empty($provider['editable_url'])) {
            UrlGuard::assertAllowed($base);
        }

        $messages = $this->cleanTurns($messages);
        if (!$messages) {
            throw new LlmException(__('Nothing to answer.'));
        }

        try {
            return match ($provider['protocol']) {
                'anthropic' => $this->anthropic($profile, $base, $key, $systemPrompt, $messages),
                'gemini' => $this->gemini($profile, $base, $key, $systemPrompt, $messages),
                default => $this->openai($profile, $provider, $base, $key, $systemPrompt, $messages),
            };
        } catch (LlmException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LlmException($this->scrub($e->getMessage(), $key));
        }
    }

    // ---- protocols ---------------------------------------------------------------------------------------------

    private function openai(AiProfile $p, array $provider, string $base, ?string $key, string $system, array $messages): array
    {
        $body = [
            'model' => $p->model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            ($provider['max_tokens_param'] ?? 'max_tokens') => (int) $p->max_tokens,
            'stream' => false,
        ];

        // reasoning families (o-series, gpt-5) only accept the default temperature
        if (!($p->provider === 'openai' && preg_match('/^(o\d|gpt-5)/i', $p->model))) {
            $body['temperature'] = (float) $p->temperature;
        }

        $headers = $key ? ['Authorization' => 'Bearer ' . $key] : [];
        if ($p->provider === 'openrouter') {
            $headers['X-Title'] = config('app.name', 'SAMA Envios');
        }

        $json = $this->send($this->http()->withHeaders($headers), $base . '/chat/completions', $body, $key);

        $text = $json['choices'][0]['message']['content'] ?? '';
        if (is_array($text)) {   // some servers return content parts
            $text = collect($text)->pluck('text')->implode('');
        }

        return $this->result((string) $text, $json['usage']['prompt_tokens'] ?? null, $json['usage']['completion_tokens'] ?? null, $json);
    }

    private function anthropic(AiProfile $p, string $base, ?string $key, string $system, array $messages): array
    {
        // the Messages API wants user first and strictly alternating roles
        $turns = [];
        foreach ($messages as $m) {
            if ($turns && end($turns)['role'] === $m['role']) {
                $turns[count($turns) - 1]['content'] .= "\n" . $m['content'];
            } else {
                $turns[] = $m;
            }
        }
        while ($turns && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }
        if (!$turns) {
            throw new LlmException(__('Nothing to answer.'));
        }

        $json = $this->send(
            $this->http()->withHeaders(['x-api-key' => (string) $key, 'anthropic-version' => '2023-06-01']),
            $base . '/messages',
            [
                'model' => $p->model,
                'max_tokens' => (int) $p->max_tokens,
                'temperature' => min(1.0, (float) $p->temperature),   // Anthropic accepts 0..1
                'system' => $system,
                'messages' => $turns,
            ],
            $key
        );

        $text = collect($json['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');

        return $this->result($text, $json['usage']['input_tokens'] ?? null, $json['usage']['output_tokens'] ?? null, $json);
    }

    private function gemini(AiProfile $p, string $base, ?string $key, string $system, array $messages): array
    {
        $json = $this->send(
            $this->http()->withHeaders(['x-goog-api-key' => (string) $key]),
            $base . '/models/' . rawurlencode($p->model) . ':generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => array_map(fn ($m) => [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ], $messages),
                'generationConfig' => [
                    'temperature' => min(2.0, (float) $p->temperature),
                    'maxOutputTokens' => (int) $p->max_tokens,
                ],
            ],
            $key
        );

        $text = collect($json['candidates'][0]['content']['parts'] ?? [])->pluck('text')->implode('');

        return $this->result($text, $json['usageMetadata']['promptTokenCount'] ?? null, $json['usageMetadata']['candidatesTokenCount'] ?? null, $json);
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('ai.timeout', 40))->acceptJson()->asJson()->withoutRedirecting();
    }

    private function send(PendingRequest $http, string $url, array $body, ?string $key): array
    {
        $response = $http->post($url, $body);
        $this->ensureOk($response, $key);

        return (array) $response->json();
    }

    private function ensureOk(Response $response, ?string $key): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = $response->json('error.message') ?? $response->json('error') ?? $response->json('message') ?? $response->body();
        $detail = is_string($detail) ? $detail : json_encode($detail);

        throw new LlmException($this->scrub('HTTP ' . $response->status() . ': ' . mb_substr((string) $detail, 0, 300), $key));
    }

    private function result(string $text, $in, $out, array $raw): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new LlmException(__('The model returned an empty answer.'));
        }

        return ['reply' => $text, 'tokens_in' => $in !== null ? (int) $in : null, 'tokens_out' => $out !== null ? (int) $out : null, 'raw' => $raw];
    }

    /** Only user/assistant turns with text, in a valid role. */
    private function cleanTurns(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $content = trim((string) ($m['content'] ?? ''));
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            if ($content !== '') {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }

    private function apiKey(AiProfile $profile, array $provider): ?string
    {
        $key = trim((string) $profile->api_key);
        if ($key === '' && !empty($provider['key_env'])) {
            $key = trim((string) env($provider['key_env']));
        }

        if ($key === '' && ($provider['needs_key'] ?? true)) {
            throw new LlmException(__('No API key for :provider. Add yours in the agent settings.', ['provider' => $provider['label'] ?? $profile->provider]));
        }

        return $key === '' ? null : $key;
    }

    private function baseUrl(AiProfile $profile, array $provider): string
    {
        $url = !empty($provider['editable_url']) && trim((string) $profile->base_url) !== '' ? trim($profile->base_url) : (string) ($provider['base_url'] ?? '');
        $url = rtrim($url, '/');

        if ($url === '') {
            throw new LlmException(__('Endpoint URL is required for this provider.'));
        }

        // people paste "http://host:11434": the OpenAI-compatible API lives under /v1
        if (!empty($provider['editable_url']) && ($provider['protocol'] ?? '') === 'openai' && !preg_match('#/v\d+$#', $url) && !preg_match('#/v\d+/#', $url)) {
            $url .= '/v1';
        }

        return $url;
    }

    private function scrub(string $message, ?string $key): string
    {
        return $key ? str_replace($key, '***', $message) : $message;
    }
}
