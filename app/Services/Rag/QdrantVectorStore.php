<?php

namespace App\Services\Rag;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Vectors in a Qdrant server (HTTP API). The text of every chunk stays in the app database too (counts, deletion,
 * re-indexing), the DB row just holds an empty embedding. One Qdrant collection per vector size, every point carries
 * user_id / embedder / source_id and every search is filtered by user_id and embedder: accounts never mix.
 */
class QdrantVectorStore extends DatabaseVectorStore
{
    private function http(): PendingRequest
    {
        $c = config('rag.qdrant');
        $h = Http::baseUrl(rtrim((string) $c['url'], '/'))->acceptJson()->asJson()->timeout(30);

        return $c['api_key'] ? $h->withHeaders(['api-key' => $c['api_key']]) : $h;
    }

    private function collection(int $dims): string
    {
        return config('rag.qdrant.collection') . '_' . $dims;
    }

    private function ensureCollection(int $dims): void
    {
        $name = $this->collection($dims);
        if ($this->http()->get("/collections/{$name}")->successful()) {
            return;
        }
        $this->check($this->http()->put("/collections/{$name}", ['vectors' => ['size' => $dims, 'distance' => 'Cosine']]));
        foreach (['user_id' => 'integer', 'source_id' => 'integer', 'embedder' => 'keyword'] as $field => $type) {
            $this->http()->put("/collections/{$name}/index", ['field_name' => $field, 'field_schema' => $type]);
        }
    }

    private function check($response)
    {
        if ($response->failed()) {
            throw new RagException(__('Vector database error: :e', ['e' => mb_substr($response->body(), 0, 200)]));
        }

        return $response;
    }

    public function replace(KnowledgeSource $source, string $embedderId, array $items): void
    {
        $this->forget($source->id);
        if (!$items) {
            return;
        }
        $dims = count(reset($items)['vector']);
        $this->ensureCollection($dims);

        $points = [];
        foreach ($items as $pos => $it) {
            $chunk = KnowledgeChunk::create(['source_id' => $source->id, 'user_id' => $source->user_id, 'position' => $pos, 'content' => $it['content'], 'embedding' => '[]', 'embedder' => $embedderId]);
            $points[] = ['id' => $chunk->id, 'vector' => $it['vector'], 'payload' => ['user_id' => (int) $source->user_id, 'source_id' => (int) $source->id, 'embedder' => $embedderId, 'content' => $it['content']]];
        }
        foreach (array_chunk($points, 64) as $part) {
            $this->check($this->http()->put($this->pointsUrl($dims) . '?wait=true', ['points' => $part]));
        }
    }

    private function pointsUrl(int $dims): string
    {
        return '/collections/' . $this->collection($dims) . '/points';
    }

    public function search(int $userId, string $embedderId, array $vector, int $k): array
    {
        $dims = count($vector);
        $r = $this->http()->post($this->pointsUrl($dims) . '/search', [
            'vector' => $vector, 'limit' => $k, 'with_payload' => true,
            'filter' => ['must' => [['key' => 'user_id', 'match' => ['value' => $userId]], ['key' => 'embedder', 'match' => ['value' => $embedderId]]]],
        ]);
        if ($r->status() === 404) {
            return [];
        }
        $hits = (array) $this->check($r)->json('result', []);
        $titles = KnowledgeSource::whereIn('id', array_column(array_column($hits, 'payload'), 'source_id'))->pluck('title', 'id');

        return array_map(fn ($h) => ['content' => (string) ($h['payload']['content'] ?? ''), 'score' => round((float) $h['score'], 4),
            'source' => (string) ($titles[$h['payload']['source_id'] ?? 0] ?? ''), 'source_id' => (int) ($h['payload']['source_id'] ?? 0)], $hits);
    }

    public function forget(int $sourceId): void
    {
        $dims = $this->dimsOf($sourceId);
        KnowledgeChunk::where('source_id', $sourceId)->delete();
        foreach ($dims as $d) {
            // a missing collection simply means there was nothing to delete
            $this->http()->post($this->pointsUrl($d) . '/delete?wait=true', ['filter' => ['must' => [['key' => 'source_id', 'match' => ['value' => $sourceId]]]]]);
        }
    }

    /** Vector sizes that may hold points of this source: the sizes of the embedders in use (hashed 512 or the hosted ones). */
    private function dimsOf(int $sourceId): array
    {
        return array_values(array_unique([512, 1536, 1024]));
    }
}
