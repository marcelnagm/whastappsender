<?php

namespace App\Services\Rag;

use App\Jobs\IngestKnowledgeSourceJob;
use App\Models\KbArticle;
use App\Models\KnowledgeSource;

/** Keeps the knowledge-base articles of the account searchable by the AI: one source per article. */
class KnowledgeSync
{
    /** Imports every active article (the "Import knowledge base" button). @return int articles queued */
    public function importAll(int $userId): int
    {
        $n = 0;
        KbArticle::where('user_id', $userId)->where('active', true)->orderBy('id')->each(function (KbArticle $a) use (&$n) {
            $this->article($a, true);
            $n++;
        });

        return $n;
    }

    /** After an article changes: only accounts that already imported their knowledge base follow its changes. */
    public function article(KbArticle $a, bool $force = false): void
    {
        $uri = 'kb:' . $a->id;
        $source = KnowledgeSource::where('user_id', $a->user_id)->where('type', 'kb')->where('uri', $uri)->first();
        if (!$source && !$force && !KnowledgeSource::where('user_id', $a->user_id)->where('type', 'kb')->exists()) {
            return;
        }
        if (!$a->exists || !$a->active) {
            $source?->delete();

            return;
        }
        $content = trim($a->title . "\n\n" . $a->body . ($a->keywords ? "\n\n" . $a->keywords : ''));
        $source = $source ?: new KnowledgeSource(['user_id' => $a->user_id, 'type' => 'kb', 'uri' => $uri]);
        $source->fill(['title' => $a->title, 'content' => $content, 'status' => 'pending'])->save();
        IngestKnowledgeSourceJob::dispatch($source->id);
    }
}
