<?php

namespace App\Services\Rag;

/** Finds the passages of one account that best answer a question. */
class Retriever
{
    public function __construct(private EmbedderFactory $factory, private VectorStore $store)
    {
    }

    /**
     * @return array{embedder:string,min_score:float,hits:array<int,array{content:string,score:float,source:string,source_id:int}>}
     *         hits are best first and ALREADY above the threshold; "best" is the top score even when nothing passed
     *
     * @throws RagException
     */
    public function search(int $userId, string $question, ?int $k = null): array
    {
        $embedder = $this->factory->for($userId);
        $min = (float) config('rag.min_score.' . $embedder->kind(), 0.2);
        $question = trim($question);
        if ($question === '' || $this->store->count($userId, $embedder->id()) === 0) {
            return ['embedder' => $embedder->id(), 'min_score' => $min, 'best' => null, 'hits' => []];
        }
        [$vec] = $embedder->embed([mb_substr($question, 0, 2000)]);
        $all = $this->store->search($userId, $embedder->id(), $vec, $k ?? (int) config('rag.top_k', 4));

        return ['embedder' => $embedder->id(), 'min_score' => $min, 'best' => $all[0]['score'] ?? null, 'hits' => array_values(array_filter($all, fn ($h) => $h['score'] >= $min))];
    }
}
