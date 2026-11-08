<?php

namespace App\Services\Pipeline;

use App\Models\Deal;
use App\Models\DealFollower;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Services\Team\TeamContext;
use Illuminate\Support\Facades\Auth;

/**
 * People who follow a deal without owning it are told when something important happens: it changes stage, is won
 * or lost, its proposal is answered, a subscription is about to renew. The person who caused the change is not told.
 */
class DealFollowers
{
    /** Events that notify followers and the sentence sent. */
    private function message(string $event, Deal $deal): ?string
    {
        return match ($event) {
            'deal.stage' => __('Deal moved to ":stage": :title', ['stage' => optional($deal->stage)->name, 'title' => $deal->title]),
            'deal.won' => __('Deal won: :title', ['title' => $deal->title]),
            'deal.lost' => __('Deal lost: :title', ['title' => $deal->title]),
            'proposal.accepted' => __('Proposal accepted: :title', ['title' => $deal->title]),
            'proposal.declined' => __('Proposal declined: :title', ['title' => $deal->title]),
            'deal.renewal' => __('Renewal coming up: :title', ['title' => $deal->title]),
            default => null,
        };
    }

    public function follow(Deal $deal, int $userId): void
    {
        DealFollower::firstOrCreate(['deal_id' => $deal->id, 'user_id' => $userId]);
    }

    public function unfollow(Deal $deal, int $userId): void
    {
        DealFollower::where('deal_id', $deal->id)->where('user_id', $userId)->delete();
    }

    /** @return array<int,array{id:int,name:string}> */
    public function list(Deal $deal): array
    {
        $ids = DealFollower::where('deal_id', $deal->id)->pluck('user_id');

        return User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }

    /** Called for every automation event that has a deal. */
    public function onEvent(string $event, array $ctx): void
    {
        $deal = $ctx['deal'] ?? null;
        if (!$deal instanceof Deal || ($text = $this->message($event, $deal)) === null) {
            return;
        }
        $actor = Auth::id() ? (TeamContext::actor()?->id ?? Auth::id()) : null;
        $url = route('pipeline.index', ['pipeline' => $deal->pipeline_id]);
        DealFollower::where('deal_id', $deal->id)->when($actor, fn ($q) => $q->where('user_id', '!=', $actor))->pluck('user_id')->each(function ($id) use ($text, $url) {
            if ($u = User::find($id)) {
                $u->notify(new AutomationNotice($text, $url));
            }
        });
    }
}
