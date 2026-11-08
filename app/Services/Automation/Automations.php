<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\Chat;
use App\Models\ChatTag;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\Chat\ChatAssignment;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Tasks\FollowUps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "When X happens, do Y". The engine is fired by the places where things happen (a deal is created / moved, a tag is applied,
 * a customer writes) and never breaks them: any failure is logged and the original action goes on.
 *
 * Loops are cut two ways: an automation runs once per subject within one chain (an automation that moves a deal cannot
 * trigger itself through the move) and chains stop at a depth of 3.
 */
class Automations
{
    public const TRIGGERS = ['deal.created', 'deal.stage', 'deal.won', 'deal.lost', 'tag.added', 'message.keyword', 'proposal.accepted', 'proposal.declined', 'deal.stale', 'bot.handoff', 'contact.birthday'];
    public const ACTIONS = ['create_task', 'add_tag', 'assign', 'move_deal', 'notify'];
    public const MAX_DEPTH = 3;
    public const MAX_ACTIONS = 5;
    public const MAX_RULES = 30;

    private static int $depth = 0;
    /** @var array<string,bool> */
    private static array $seen = [];

    /**
     * @param array{deal?:Deal,chat?:Chat,stage_id?:int,tag_id?:int,text?:string} $ctx
     */
    public function fire(string $event, User|int $owner, array $ctx): void
    {
        $ownerId = $owner instanceof User ? $owner->id : $owner;
        app(\App\Services\Webhooks\OutboundWebhooks::class)->emit($event, $ownerId, $ctx);
        if (self::$depth >= self::MAX_DEPTH) {
            return;
        }
        try {
            $rules = Automation::where('user_id', $ownerId)->where('active', true)->where('trigger', $event)->orderBy('id')->get();
        } catch (\Throwable $e) {
            return;
        }
        if ($rules->isEmpty()) {
            return;
        }

        $subject = isset($ctx['deal']) ? 'deal #' . $ctx['deal']->id : (isset($ctx['contact']) ? 'contact #' . $ctx['contact']->id : (isset($ctx['chat']) ? 'chat #' . $ctx['chat']->id : 'event'));

        foreach ($rules as $rule) {
            if (!$this->matches($rule, $ctx)) {
                continue;
            }
            $key = $rule->id . '|' . $subject;
            if (isset(self::$seen[$key])) {
                continue;
            }
            self::$seen[$key] = true;
            self::$depth++;
            try {
                $this->run($rule, $ownerId, $ctx, $subject);
            } catch (\Throwable $e) {
                Log::warning('Automation failed: ' . $e->getMessage(), ['automation_id' => $rule->id]);
            } finally {
                self::$depth--;
                if (self::$depth === 0) {
                    self::$seen = [];
                }
            }
        }
    }

    public function matches(Automation $rule, array $ctx): bool
    {
        $c = (array) $rule->conditions;
        switch ($rule->trigger) {
            case 'deal.stage':
                return empty($c['stage_id']) || (int) $c['stage_id'] === (int) ($ctx['stage_id'] ?? 0);
            case 'tag.added':
                return empty($c['tag_id']) || (int) $c['tag_id'] === (int) ($ctx['tag_id'] ?? 0);
            case 'message.keyword':
                $text = mb_strtolower((string) ($ctx['text'] ?? ''));
                foreach ((array) ($c['keywords'] ?? []) as $k) {
                    if ($k !== '' && str_contains($text, mb_strtolower($k))) {
                        return true;
                    }
                }

                return false;
            default:
                return true;
        }
    }

    private function run(Automation $rule, int $ownerId, array $ctx, string $subject): void
    {
        $owner = User::findOrFail($ownerId);
        $done = 0;
        $errors = [];
        foreach (array_slice((array) $rule->actions, 0, self::MAX_ACTIONS) as $a) {
            try {
                $this->perform($a, $owner, $ctx);
                $done++;
            } catch (\Throwable $e) {
                $errors[] = ($a['type'] ?? '?') . ': ' . $e->getMessage();
            }
        }
        $rule->forceFill(['runs' => $rule->runs + 1, 'last_run_at' => now()])->save();
        DB::table('automation_runs')->insert([
            'user_id' => $ownerId, 'automation_id' => $rule->id, 'subject' => $subject,
            'status' => $errors ? ($done ? 'partial' : 'error') : 'ok', 'detail' => $errors ? mb_substr(implode(' | ', $errors), 0, 500) : null, 'created_at' => now(),
        ]);
    }

    private function perform(array $a, User $owner, array $ctx): void
    {
        $deal = $ctx['deal'] ?? null;
        $chat = $ctx['chat'] ?? ($deal && $deal->chat_id ? Chat::where('user_id', $owner->id)->find($deal->chat_id) : null);
        $contactId = $chat?->contact_id ?? $deal?->contact_id;

        switch ($a['type'] ?? '') {
            case 'create_task':
                $assignee = $this->assignee($owner, $a['assignee'] ?? 'owner', $chat, $deal);
                $hours = max(0, min(24 * 90, (int) ($a['due_hours'] ?? 24)));
                app(FollowUps::class)->create($owner, $owner, [
                    'title' => (string) ($a['title'] ?? __('Follow up')), 'assigned_to' => $assignee, 'due_at' => now()->addHours($hours)->format('Y-m-d\TH:i'),
                    'chat_id' => $chat?->id, 'contact_id' => $contactId, 'deal_id' => $deal?->id,
                ]);
                break;

            case 'add_tag':
                abort_unless($chat, 422, __('No conversation to tag.'));
                $tag = ChatTag::where('user_id', $owner->id)->findOrFail((int) ($a['tag_id'] ?? 0));
                $changes = $chat->tags()->syncWithoutDetaching([$tag->id]);
                if ($changes['attached']) {
                    $this->fire('tag.added', $owner, ['chat' => $chat, 'tag_id' => $tag->id] + ($deal ? ['deal' => $deal] : []));
                }
                break;

            case 'assign':
                abort_unless($chat, 422, __('No conversation to assign.'));
                $who = $this->assignee($owner, $a['assignee'] ?? 'owner', null, null);
                app(ChatAssignment::class)->assign($chat, $who);
                if ($deal) {
                    $deal->forceFill(['assigned_to' => $who])->save();
                }
                break;

            case 'move_deal':
                abort_unless($deal, 422, __('No deal to move.'));
                $stage = PipelineStage::where('user_id', $owner->id)->where('pipeline_id', $deal->pipeline_id)->findOrFail((int) ($a['stage_id'] ?? 0));
                if ($stage->id !== $deal->stage_id) {
                    app(SalesPipeline::class)->move($deal->fresh(), $stage);
                }
                break;

            case 'notify':
                $who = User::find($this->assignee($owner, $a['assignee'] ?? 'owner', $chat, $deal));
                abort_unless($who, 422);
                $who->notify(new AutomationNotice(mb_substr((string) ($a['message'] ?? __('An automation ran.')), 0, 200) . ($deal ? ' · ' . $deal->title : ''), route('pipeline.index')));
                break;

            default:
                throw new \InvalidArgumentException('unknown action');
        }
    }

    /** "owner" | "assignee" (whoever has the conversation / deal) | a team member's id; anything else falls back to the owner. */
    private function assignee(User $owner, mixed $who, ?Chat $chat, ?Deal $deal): int
    {
        if ($who === 'assignee') {
            return (int) ($chat?->assigned_to ?: $deal?->assigned_to ?: $owner->id);
        }
        if (is_numeric($who) && app(ChatAssignment::class)->people($owner)->contains('id', (int) $who)) {
            return (int) $who;
        }

        return $owner->id;
    }

    // ---- what the form sends -> a clean rule ---------------------------------------------------------------------------

    /** @return array{conditions:array,actions:array} */
    public function clean(User $owner, string $trigger, array $in): array
    {
        $cond = [];
        if ($trigger === 'deal.stage' && !empty($in['stage_id']) && PipelineStage::where('user_id', $owner->id)->whereKey((int) $in['stage_id'])->exists()) {
            $cond['stage_id'] = (int) $in['stage_id'];
        }
        if ($trigger === 'tag.added' && !empty($in['tag_id']) && ChatTag::where('user_id', $owner->id)->whereKey((int) $in['tag_id'])->exists()) {
            $cond['tag_id'] = (int) $in['tag_id'];
        }
        if ($trigger === 'message.keyword') {
            $cond['keywords'] = array_slice(array_values(array_unique(array_filter(array_map(fn ($k) => mb_substr(trim($k), 0, 40), preg_split('/[,\n]+/', (string) ($in['keywords'] ?? '')))))), 0, 10);
        }

        $people = app(ChatAssignment::class)->people($owner)->pluck('id')->all();
        $actions = [];
        foreach (array_slice((array) ($in['actions'] ?? []), 0, self::MAX_ACTIONS) as $a) {
            $type = (string) ($a['type'] ?? '');
            $who = (string) ($a['assignee'] ?? 'owner');
            $who = in_array($who, ['owner', 'assignee'], true) || in_array((int) $who, $people, true) ? $who : 'owner';
            $clean = match ($type) {
                'create_task' => trim((string) ($a['title'] ?? '')) === '' ? null
                    : ['type' => $type, 'title' => mb_substr(trim($a['title']), 0, 160), 'due_hours' => max(0, min(24 * 90, (int) ($a['due_hours'] ?? 24))), 'assignee' => $who],
                'add_tag' => !empty($a['tag_id']) && ChatTag::where('user_id', $owner->id)->whereKey((int) $a['tag_id'])->exists() ? ['type' => $type, 'tag_id' => (int) $a['tag_id']] : null,
                'assign' => in_array($who, ['owner'], true) || in_array((int) $who, $people, true) ? ['type' => $type, 'assignee' => $who === 'assignee' ? 'owner' : $who] : null,
                'move_deal' => !empty($a['stage_id']) && PipelineStage::where('user_id', $owner->id)->whereKey((int) $a['stage_id'])->exists() ? ['type' => $type, 'stage_id' => (int) $a['stage_id']] : null,
                'notify' => ['type' => $type, 'message' => mb_substr(trim((string) ($a['message'] ?? '')) ?: __('An automation ran.'), 0, 200), 'assignee' => $who],
                default => null,
            };
            if ($clean) {
                $actions[] = $clean;
            }
        }

        return ['conditions' => $cond, 'actions' => $actions];
    }
}
