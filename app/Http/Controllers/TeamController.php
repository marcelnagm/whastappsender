<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Team\TeamContext;
use App\Services\Team\TeamMembers;
use App\Services\Team\TeamRoles;
use Illuminate\Http\Request;

/** Sub-users: people who work for this account with a limited role. */
class TeamController extends Controller
{
    public function __construct(private TeamMembers $team)
    {
    }

    public function index(Request $request)
    {
        $owner = $request->user();
        $members = User::where('parent_user_id', $owner->id)->orderBy('name')->get();

        return view('team.index', [
            'members' => $members,
            'roles' => TeamRoles::labels(),
            'descriptions' => TeamRoles::descriptions(),
            'me' => TeamContext::actor($request),
        ]);
    }

    public function store(Request $request)
    {
        $this->team->create($request->user(), $request->only(['name', 'email', 'password', 'role']));

        return redirect()->route('team.index')->with('success', __('Team member added.'));
    }

    public function update(Request $request, int $member)
    {
        $m = $this->team->find($request->user(), $member);
        abort_if($this->isSelf($request, $m), 422, __('You cannot change your own access.'));
        $this->team->update($m, $request->only(['name', 'password', 'role']) + ['active' => $request->boolean('active')]);

        return redirect()->route('team.index')->with('success', __('Team member updated.'));
    }

    public function destroy(Request $request, int $member)
    {
        $m = $this->team->find($request->user(), $member);
        abort_if($this->isSelf($request, $m), 422, __('You cannot change your own access.'));
        $this->team->remove($m);

        return redirect()->route('team.index')->with('success', __('Team member removed.'));
    }

    private function isSelf(Request $request, User $m): bool
    {
        $actor = TeamContext::actor($request);

        return $actor && (int) $actor->id === (int) $m->id;
    }
}
