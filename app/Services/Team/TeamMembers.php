<?php

namespace App\Services\Team;

use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Creates, changes and removes the sub-users of an account. Shared by the web screen, the REST API and the MCP tools. */
class TeamMembers
{
    /** @throws \Illuminate\Validation\ValidationException|\App\Exceptions\PlanLimitException */
    public function create(User $owner, array $data): User
    {
        abort_if($owner->isMember(), 403, 'Only the account owner has a team.');

        $data = Validator::make($data, [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email|unique:users,username',
            'password' => 'required|string|min:8|max:100',
            'role' => ['required', Rule::in(TeamRoles::roles())],
        ])->validate();

        app(Entitlements::class)->authorizeUsage($owner, 'team_members');

        $member = new User(['name' => $data['name'], 'email' => $data['email'], 'username' => $data['email'], 'password' => $data['password'], 'locale' => $owner->locale]);
        $member->forceFill(['parent_user_id' => $owner->id, 'team_role' => $data['role'], 'active' => true])->save();

        return $member;
    }

    /** @throws \Illuminate\Validation\ValidationException */
    public function update(User $member, array $data): User
    {
        $data = Validator::make($data, [
            'name' => 'sometimes|string|max:120',
            'password' => 'nullable|string|min:8|max:100',
            'role' => ['sometimes', Rule::in(TeamRoles::roles())],
            'active' => 'sometimes|boolean',
        ])->validate();

        if (isset($data['name'])) {
            $member->name = $data['name'];
        }
        if (!empty($data['password'])) {
            $member->password = $data['password'];
        }
        if (isset($data['role'])) {
            $member->team_role = $data['role'];
        }
        if (array_key_exists('active', $data)) {
            $member->active = (bool) $data['active'];
        }
        $member->save();

        if (!$member->active || isset($data['role'])) {
            $member->tokens()->delete(); // API tokens issued under the old role / while enabled stop working
        }

        return $member;
    }

    public function remove(User $member): void
    {
        $member->tokens()->delete();
        $member->delete();
    }

    public function present(User $m): array
    {
        return [
            'id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->team_role,
            'role_label' => TeamRoles::labels()[$m->team_role] ?? $m->team_role,
            'active' => (bool) $m->active, 'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    /** The member of this owner, or 404. */
    public function find(User $owner, int $id): User
    {
        return User::where('parent_user_id', $owner->id)->findOrFail($id);
    }
}
