<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\Rag\Ingestor;
use App\Services\Rag\SiteCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Reads the website of a "site" source and indexes what it found. */
class CrawlKnowledgeSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $sourceId)
    {
    }

    public function handle(SiteCrawler $crawler, Ingestor $ingestor): void
    {
        $source = KnowledgeSource::find($this->sourceId);
        if (!$source || $source->type !== 'site') {
            return;
        }
        KnowledgeSource::whereKey($source->id)->toBase()->update(['status' => 'indexing', 'error' => null]);
        try {
            $r = $crawler->crawl((string) $source->uri);
            $source->forceFill(['content' => $r['text']])->save();
        } catch (\Throwable $e) {
            KnowledgeSource::whereKey($source->id)->toBase()->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 250)]);

            return;
        }
        $ingestor->ingest($source->fresh());
    }
}
