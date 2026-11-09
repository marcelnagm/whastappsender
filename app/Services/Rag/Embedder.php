<?php

namespace App\Services\Rag;

interface Embedder
{
    /** Short stable id stored with every vector ("basic", "openai:text-embedding-3-small"): vectors of different ids never mix. */
    public function id(): string;

    /** "basic" (built-in, no key) or "hosted": picks the similarity threshold. */
    public function kind(): string;

    /**
     * @param string[] $texts
     * @return array<int,float[]> one L2-normalised vector per text, same order
     *
     * @throws RagException
     */
    public function embed(array $texts): array;
}
