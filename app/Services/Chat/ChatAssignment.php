<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\User;
use App\Services\Team\TeamContext;
use App\Services\Team\TeamRoles;
use Illuminate\Support\Collection;

/** Who handles each conversation: manual choice, or automatic balanced distribution among the team members who work the chat. */
class ChatAssignment
{
    /** The owner and every active member that may use the chat. @return Collection<int,User> */
    public function people(User $owner): Collection
    {
        $members = User::where('parent_user_id', $owner->id)->where('active', true)->orderBy('name')->get()
            ->filter(fn (User $m) => TeamRoles::allows($m->team_role, 'chat'));

        return collect([$owner])->concat($members)->values();
    }

    /** Members that receive new conversations automatically (the owner only when there is nobody else). */
    private function pool(User $owner): Collection
    {
        $people = $this->people($owner);

        return $people->count() > 1 ? $people->slice(1)->values() : $people;
    }

    public function autoAssign(Chat $chat, User $owner): void
    {
        if ($chat->assigned_to || $owner->chat_assign_mode !== 'balanced') {
            return;
        }
        $pool = $this->pool($owner);
        if ($pool->isEmpty()) {
            return;
        }

        $open = Chat::where('user_id', $owner->id)->where('archived', false)->whereIn('assigned_to', $pool->pluck('id'))
            ->selectRaw('assigned_to, count(*) as n, max(assigned_at) as last')->groupBy('assigned_to')->get()->keyBy('assigned_to');

        // fewest open conversations first; on a tie, whoever received one longest ago
        $pick = $pool->sortBy(fn (User $u) => [(int) ($open[$u->id]->n ?? 0), (string) ($open[$u->id]->last ?? ''), $u->id])->first();
        $this->assign($chat, $pick->id);
    }

    /** @param int|null $userId null = unassigned. Only people of the account can receive it. */
    public function assign(Chat $chat, ?int $userId): void
    {
        if ($userId !== null && !$this->people(User::findOrFail($chat->user_id))->contains('id', $userId)) {
            abort(422, __('This person is not part of the team.'));
        }
        $chat->forceFill(['assigned_to' => $userId, 'assigned_at' => $userId ? now() : null])->save();
    }

    /** When the account shows attendants only their own (and unassigned) conversations: the attendant's id, otherwise null. */
    public function restrictedTo(User $owner): ?int
    {
        $member = app(TeamContext::class)->member();

        return $member && $member->team_role === TeamRoles::AGENT && $owner->chat_agents_see === 'assigned' ? (int) $member->id : null;
    }
}
