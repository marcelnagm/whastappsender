<?php

namespace App\Services\Rag;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;

/**
 * Vector store inside the application database: vectors saved as JSON, similarity computed in PHP over the chunks
 * of ONE account (hard filter on user_id). Fine up to a few thousand chunks per account (config rag.max_chunks);
 * a dedicated vector database (pgvector, Qdrant) can replace it behind the same two methods.
 */
class DatabaseVectorStore
{
    /** @param array<int,array{content:string,vector:float[]}> $items */
    public function replace(KnowledgeSource $source, string $embedderId, array $items): void
    {
        KnowledgeChunk::where('source_id', $source->id)->delete();
        foreach (array_chunk($items, 100, true) as $part) {
            $rows = [];
            foreach ($part as $pos => $it) {
                $rows[] = ['source_id' => $source->id, 'user_id' => $source->user_id, 'position' => $pos, 'content' => $it['content'],
                    'embedding' => json_encode($it['vector']), 'embedder' => $embedderId, 'created_at' => now(), 'updated_at' => now()];
            }
            KnowledgeChunk::insert($rows);
        }
    }

    /** @return array<int,array{content:string,score:float,source:string,source_id:int}> best first */
    public function search(int $userId, string $embedderId, array $vector, int $k): array
    {
        $top = [];
        KnowledgeChunk::where('user_id', $userId)->where('embedder', $embedderId)->select(['id', 'source_id', 'content', 'embedding'])
            ->lazyById(500)->each(function ($c) use (&$top, $vector, $k) {
                $score = Vectors::dot($vector, (array) json_decode($c->embedding, true));
                $top[] = ['id' => $c->id, 'score' => $score, 'content' => $c->content, 'source_id' => $c->source_id];
                if (count($top) > $k * 4) {
                    usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);
                    $top = array_slice($top, 0, $k);
                }
            });
        usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($top, 0, $k);
        $titles = KnowledgeSource::whereIn('id', array_column($top, 'source_id'))->pluck('title', 'id');

        return array_map(fn ($t) => ['content' => $t['content'], 'score' => round($t['score'], 4), 'source' => (string) ($titles[$t['source_id']] ?? ''), 'source_id' => $t['source_id']], $top);
    }

    public function count(int $userId, ?string $embedderId = null): int
    {
        return KnowledgeChunk::where('user_id', $userId)->when($embedderId, fn ($q) => $q->where('embedder', $embedderId))->count();
    }
}
