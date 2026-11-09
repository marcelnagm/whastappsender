<?php

namespace App\Services\Rag;

use Illuminate\Support\Facades\Http;

/** OpenAI-compatible /embeddings endpoint (OpenAI, Mistral...). The key is never logged or returned. */
class HostedEmbedder implements Embedder
{
    public function __construct(private string $provider, private string $baseUrl, private string $model, private string $apiKey)
    {
    }

    public function id(): string
    {
        return $this->provider . ':' . $this->model;
    }

    public function kind(): string
    {
        return 'hosted';
    }

    public function embed(array $texts): array
    {
        $out = [];
        foreach (array_chunk(array_values($texts), 64) as $batch) {
            try {
                $resp = Http::timeout((int) config('ai.timeout', 40))->acceptJson()->asJson()->withoutRedirecting()
                    ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                    ->post(rtrim($this->baseUrl, '/') . '/embeddings', ['model' => $this->model, 'input' => array_map(fn ($t) => mb_substr((string) $t, 0, 8000), $batch)]);
            } catch (\Throwable $e) {
                throw new RagException($this->scrub($e->getMessage()));
            }
            if (!$resp->successful()) {
                $detail = $resp->json('error.message') ?? $resp->json('message') ?? 'HTTP ' . $resp->status();
                throw new RagException($this->scrub(is_string($detail) ? $detail : 'HTTP ' . $resp->status()));
            }
            $data = collect($resp->json('data') ?? [])->sortBy('index')->pluck('embedding')->all();
            if (count($data) !== count($batch)) {
                throw new RagException(__('The embedding service returned an unexpected answer.'));
            }
            foreach ($data as $vec) {
                $out[] = Vectors::normalize(array_map('floatval', (array) $vec));
            }
        }

        return $out;
    }

    private function scrub(string $msg): string
    {
        return mb_substr(str_replace($this->apiKey, '••••', $msg), 0, 240);
    }
}
