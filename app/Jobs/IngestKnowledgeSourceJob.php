<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\Rag\Ingestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IngestKnowledgeSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $sourceId)
    {
    }

    public function handle(Ingestor $ingestor): void
    {
        if ($source = KnowledgeSource::find($this->sourceId)) {
            $ingestor->ingest($source);
        }
    }
}
