<?php

namespace App\Services\Rag;

use App\Models\KnowledgeSource;

/** Turns a source into searchable passages: chunk, embed, store. Never throws: the outcome is on the source. */
class Ingestor
{
    public function __construct(private Chunker $chunker, private EmbedderFactory $factory, private VectorStore $store)
    {
    }

    /** A website is many pages: each page is chunked on its own and every chunk keeps the page title and address. @return string[] */
    private function chunksFor(KnowledgeSource $source): array
    {
        if ($source->type !== 'site') {
            return $this->chunker->split((string) $source->content);
        }
        $chunks = [];
        foreach (array_filter(explode(SiteCrawler::PAGE_MARK, (string) $source->content), fn ($d) => trim($d) !== '') as $doc) {
            [$head, $body] = array_pad(explode(" ===\n", $doc, 2), 2, '');
            foreach ($this->chunker->split($body) as $c) {
                $chunks[] = '[' . trim($head, "= \n") . "]\n" . $c;
            }
        }

        return $chunks;
    }

    public function ingest(KnowledgeSource $source): KnowledgeSource
    {
        KnowledgeSource::whereKey($source->id)->toBase()->update(['status' => 'indexing', 'error' => null]);

        try {
            $chunks = $this->chunksFor($source);
            if (!$chunks) {
                throw new RagException(__('There is no text to index.'));
            }
            $others = $this->store->count($source->user_id) - $source->chunks()->count();
            if ($others + count($chunks) > (int) config('rag.max_chunks', 5000)) {
                throw new RagException(__('Knowledge limit reached (:n passages). Remove something first.', ['n' => config('rag.max_chunks', 5000)]));
            }

            $embedder = $this->factory->for($source->user_id);
            // the title is part of what is searched ("Return policy" finds its passages), not of what the agent reads
            $vectors = $embedder->embed(array_map(fn ($c) => $source->title . "\n" . $c, $chunks));
            $items = [];
            foreach ($chunks as $i => $text) {
                $items[$i] = ['content' => $text, 'vector' => $vectors[$i]];
            }
            $this->store->replace($source, $embedder->id(), $items);

            KnowledgeSource::whereKey($source->id)->toBase()->update(['status' => 'ready', 'error' => null, 'chunks_count' => count($items), 'embedder' => $embedder->id(), 'last_indexed_at' => now()]);
        } catch (\Throwable $e) {
            KnowledgeSource::whereKey($source->id)->toBase()->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return $source->fresh();
    }
}
