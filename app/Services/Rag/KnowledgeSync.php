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

    /** The product catalogue as one source, rebuilt from the active products. Accounts follow product changes once it exists. */
    public function products(int $userId, bool $force = false): ?KnowledgeSource
    {
        $source = KnowledgeSource::where('user_id', $userId)->where('type', 'products')->first();
        if (!$source && !$force) {
            return null;
        }
        $lines = \App\Models\Product::where('user_id', $userId)->where('active', true)->orderBy('name')->get()
            ->map(fn ($p) => '- ' . $p->name . ($p->sku ? ' (' . $p->sku . ')' : '') . ': R$ ' . number_format((float) $p->price, 2, ',', '.') . ($p->unit ? ' / ' . $p->unit : ''));
        $source = $source ?: new KnowledgeSource(['user_id' => $userId, 'type' => 'products', 'uri' => 'products']);
        $source->fill(['title' => __('Products and prices'), 'content' => $lines->isEmpty() ? '' : __('Products and prices') . ":\n" . $lines->implode("\n"), 'status' => 'pending'])->save();
        IngestKnowledgeSourceJob::dispatch($source->id)->delay(now()->addSeconds(20));   // many edits in a row index once

        return $source;
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
