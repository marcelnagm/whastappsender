<?php

namespace App\Jobs;

use App\Models\KnowledgeSource;
use App\Services\Rag\Ingestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IngestKnowledgeSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $sourceId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(Ingestor $ingestor): void
    {
        if ($source = KnowledgeSource::find($this->sourceId)) {
            $ingestor->ingest($source);
        }
    }
}
