<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\Team\TeamContext;
use App\Services\Team\TeamMembers;
use App\Services\Team\TeamRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Sub-users of the account (the account owner, or a member with the admin role). */
class TeamController extends ApiController
{
    public function __construct(private TeamMembers $team)
    {
    }

    public function index()
    {
        return $this->success([
            'members' => User::where('parent_user_id', Auth::id())->orderBy('name')->get()->map(fn (User $m) => $this->team->present($m))->values(),
            'roles' => collect(TeamRoles::matrix())->map(fn ($perms, $role) => ['label' => TeamRoles::labels()[$role], 'permissions' => $perms])->all(),
        ]);
    }

    public function store(Request $request)
    {
        $m = $this->team->create(Auth::user(), $request->only(['name', 'email', 'password', 'role']));

        return $this->success($this->team->present($m), 'Team member added.', 201);
    }

    public function update(Request $request, int $member)
    {
        $m = $this->guard($request, $member);
        $data = $request->only(['name', 'password', 'role']);
        if ($request->has('active')) {
            $data['active'] = $request->boolean('active');
        }

        return $this->success($this->team->present($this->team->update($m, $data)), 'Team member updated.');
    }

    public function destroy(Request $request, int $member)
    {
        $this->team->remove($this->guard($request, $member));

        return $this->success(null, 'Team member removed.');
    }

    private function guard(Request $request, int $id): User
    {
        $m = $this->team->find(Auth::user(), $id);
        $actor = TeamContext::actor($request);
        abort_if($actor && (int) $actor->id === (int) $m->id, 422, 'You cannot change your own access.');

        return $m;
    }
}
