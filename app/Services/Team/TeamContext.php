<?php

namespace App\Services\Team;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is really signed in. A member signs in with their own login, but everything the application does (data, plan limits,
 * channels) runs on the account owner: the middleware swaps the authenticated user for the owner and keeps the member
 * here, so permissions, signatures and audit use the real person.
 */
class TeamContext
{
    private ?User $actor = null;
    private ?User $owner = null;

    public function set(?User $actor, ?User $owner = null): void
    {
        $this->actor = $actor;
        $this->owner = $owner;
    }

    public function isMember(): bool
    {
        return $this->actor !== null && $this->owner !== null;
    }

    /** The person who signed in (the member), or null when the account owner is acting. */
    public function member(): ?User
    {
        return $this->isMember() ? $this->actor : null;
    }

    public function role(): ?string
    {
        return $this->isMember() ? $this->actor->team_role : null;
    }

    /** True for the owner / any non-member user, and for members whose role includes the permission. */
    public function can(string|array $permission): bool
    {
        if (!$this->isMember()) {
            return true;
        }

        foreach ((array) $permission as $p) {
            if (TeamRoles::allows($this->actor->team_role, $p)) {
                return true;
            }
        }

        return false;
    }

    /** The real person behind the request (member when there is one, otherwise the signed-in user). */
    public static function actor(?Request $request = null): ?User
    {
        $ctx = app(self::class);

        return $ctx->member() ?? ($request ?? request())->user();
    }
}
