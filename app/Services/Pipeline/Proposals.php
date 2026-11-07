<?php

namespace App\Services\Pipeline;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\PipelineStage;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\Automation\Automations;
use Illuminate\Support\Str;

/** Quotes built from the items of a deal, answered by the customer on a public page. */
class Proposals
{
    public function __construct(private DealItems $items)
    {
    }

    public function createFor(Deal $deal, ?int $createdBy = null, ?int $validDays = 15): Proposal
    {
        $items = $this->snapshot($deal);
        abort_if(!$items, 422, __('Add items to the deal before making a proposal.'));

        $number = 1 + (int) Proposal::where('user_id', $deal->user_id)->max('number');
        $owner = User::find($deal->user_id);

        return Proposal::create([
            'user_id' => $deal->user_id, 'deal_id' => $deal->id, 'number' => $number, 'token' => Str::lower(Str::random(40)),
            'title' => $deal->title, 'intro' => null, 'terms' => null, 'items' => $items, 'total' => array_sum(array_column($items, 'total')),
            'valid_until' => $validDays ? now()->addDays($validDays)->toDateString() : null, 'status' => 'draft', 'created_by' => $createdBy ?? $owner?->id,
        ]);
    }

    /** Copies the current items of the deal again (only while the customer has not answered). */
    public function refresh(Proposal $p): Proposal
    {
        abort_if($p->isAnswered(), 422, __('This proposal was already answered.'));
        $items = $this->snapshot(Deal::findOrFail($p->deal_id));
        abort_if(!$items, 422, __('Add items to the deal before making a proposal.'));
        $p->update(['items' => $items, 'total' => array_sum(array_column($items, 'total'))]);

        return $p;
    }

    public function markSent(Proposal $p): void
    {
        if ($p->status === 'draft') {
            $p->update(['status' => 'sent', 'sent_at' => now()]);
        }
    }

    /** First opening by the customer (not by the owner previewing). */
    public function markViewed(Proposal $p): void
    {
        if (in_array($p->status, ['draft', 'sent'], true)) {
            $p->update(['status' => 'viewed', 'viewed_at' => now()]);
            $this->notify($p, __('Proposal #:n was opened by the customer', ['n' => $p->number]));
        }
    }

    public function accept(Proposal $p, string $name, ?string $ip): void
    {
        $this->answer($p, 'accepted', ['answered_by' => mb_substr(trim($name), 0, 120)], $ip);
        $deal = Deal::find($p->deal_id);
        if ($deal) {
            // the deal is not closed on its own: a person confirms the sale, but the automations can do it
            app(Automations::class)->fire('proposal.accepted', $p->user_id, ['deal' => $deal]);
        }
        $this->notify($p, __('Proposal #:n was accepted by :name', ['n' => $p->number, 'name' => $p->answered_by]));
    }

    public function decline(Proposal $p, ?string $reason, ?string $ip): void
    {
        $this->answer($p, 'declined', ['decline_reason' => $reason ? mb_substr(trim($reason), 0, 300) : null], $ip);
        $deal = Deal::find($p->deal_id);
        if ($deal) {
            app(Automations::class)->fire('proposal.declined', $p->user_id, ['deal' => $deal]);
        }
        $this->notify($p, __('Proposal #:n was declined', ['n' => $p->number]));
    }

    private function answer(Proposal $p, string $status, array $extra, ?string $ip): void
    {
        abort_if($p->isAnswered(), 409, __('This proposal was already answered.'));
        abort_if($p->isExpired(), 410, __('This proposal has expired.'));
        $p->update($extra + ['status' => $status, 'answered_at' => now(), 'answered_ip' => $ip ? mb_substr($ip, 0, 64) : null]);
    }

    private function notify(Proposal $p, string $message): void
    {
        try {
            $deal = Deal::find($p->deal_id);
            $who = User::find($deal?->assigned_to ?: $p->user_id) ?: User::find($p->user_id);
            $who?->notify(new AutomationNotice($message . ' · ' . $p->title, route('proposals.show', $p->id)));
        } catch (\Throwable $e) {
            // a notice must never break the customer's answer
        }
    }

    /** @return array<int,array{name:string,unit_price:float,quantity:float,discount_pct:float,total:float}> */
    private function snapshot(Deal $deal): array
    {
        return $deal->items()->get()->map(fn (DealItem $i) => ['name' => $i->name, 'unit_price' => (float) $i->unit_price, 'quantity' => (float) $i->quantity,
            'discount_pct' => (float) $i->discount_pct, 'total' => $i->total()])->all();
    }
}
