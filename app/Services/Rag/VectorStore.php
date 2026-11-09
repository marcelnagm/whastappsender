<?php

namespace App\Services\Rag;

use App\Models\KnowledgeSource;

/** Where the vectors of the business knowledge live. Always filtered by account (user_id). */
interface VectorStore
{
    /** @param array<int,array{content:string,vector:float[]}> $items replaces everything the source had */
    public function replace(KnowledgeSource $source, string $embedderId, array $items): void;

    /** @return array<int,array{content:string,score:float,source:string,source_id:int}> best first */
    public function search(int $userId, string $embedderId, array $vector, int $k): array;

    public function count(int $userId, ?string $embedderId = null): int;

    /** Removes every vector of a source (called when the source is deleted). */
    public function forget(int $sourceId): void;
}
