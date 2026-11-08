<?php

namespace App\Services\Pipeline;

use App\Models\Deal;
use App\Models\DealCheck;
use App\Models\PipelineStage;

/**
 * Playbook of a stage: the steps the team agrees to do while a deal is there. Items are plain lines saved on the
 * stage; a deal ticks them one by one. A stage can require them all before the deal moves forward.
 */
class StageChecklist
{
    public static function hash(string $item): string
    {
        return md5(mb_strtolower(trim($item)));
    }

    /** "one item per line" text -> clean list (max 20 items of 160 characters, no duplicates). */
    public static function parse(?string $text): array
    {
        $lines = array_map(fn ($l) => mb_substr(trim($l), 0, 160), preg_split('/\R/', (string) $text));

        return array_slice(array_values(array_unique(array_filter($lines, fn ($l) => $l !== ''))), 0, 20);
    }

    /** @return array<int,array{hash:string,title:string,done:bool}> */
    public function state(Deal $deal): array
    {
        $stage = PipelineStage::find($deal->stage_id);
        $items = $stage ? (array) $stage->checklist : [];
        $done = DealCheck::where('deal_id', $deal->id)->where('stage_id', $deal->stage_id)->pluck('item_hash')->flip();

        return array_map(fn ($t) => ['hash' => self::hash($t), 'title' => $t, 'done' => isset($done[self::hash($t)])], $items);
    }

    public function set(Deal $deal, string $hash, bool $done, ?int $by): bool
    {
        if (!collect($this->state($deal))->contains('hash', $hash)) {
            return false;
        }
        $key = ['deal_id' => $deal->id, 'stage_id' => $deal->stage_id, 'item_hash' => $hash];
        $done ? DealCheck::firstOrCreate($key, ['done_by' => $by]) : DealCheck::where($key)->delete();

        return true;
    }

    /** Titles still open in the deal's current stage. */
    public function pending(Deal $deal): array
    {
        return array_values(array_map(fn ($i) => $i['title'], array_filter($this->state($deal), fn ($i) => !$i['done'])));
    }

    /** Moving on is refused while a required checklist has open items; sending a deal to "lost" is always allowed. */
    public function guard(Deal $deal, PipelineStage $target): void
    {
        if ($deal->stage_id === $target->id || $target->kind === 'lost') {
            return;
        }
        $current = PipelineStage::find($deal->stage_id);
        if (!$current || !$current->checklist_required) {
            return;
        }
        $pending = $this->pending($deal);
        abort_if($pending !== [], 422, __('Finish the checklist of ":stage" first: :items', ['stage' => $current->name, 'items' => implode('; ', $pending)]));
    }

    /** @return array{done:int,total:int} */
    public function progress(Deal $deal): array
    {
        $s = $this->state($deal);

        return ['done' => count(array_filter($s, fn ($i) => $i['done'])), 'total' => count($s)];
    }
}
