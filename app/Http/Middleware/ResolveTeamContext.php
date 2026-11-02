<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Team\TeamContext;
use App\Services\Team\TeamRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Runs for every web and API request. When the signed-in user is a team member the request continues as the account
 * owner (so every existing query, plan limit and channel keeps working unchanged) after checking that the member's role
 * allows the route. Routes not listed in TeamRoles are closed to members.
 */
class ResolveTeamContext
{
    public function __construct(private TeamContext $context)
    {
    }

    public function handle(Request $request, Closure $next, string $guard = 'web')
    {
        $this->context->set(null);

        $user = $request->user($guard);
        if (!$user || !$user->parent_user_id) {
            return $next($request);
        }

        $owner = User::find($user->parent_user_id);
        if (!$owner || $owner->parent_user_id || !$owner->active || !$user->active || !TeamRoles::valid($user->team_role)) {
            return $this->deny($request, __('Your access to this account is disabled.'), 403);
        }

        $required = TeamRoles::requiredFor($request);
        if ($required === null || ($required !== 'any' && !$this->allowed($user, $required))) {
            return $this->deny($request, __('Your role does not allow this action.'), 403);
        }

        $this->context->set($user, $owner);
        $guards = array_unique([$guard, 'web', 'sanctum']);
        foreach ($guards as $g) {
            Auth::guard($g)->setUser($owner);
        }
        $request->setUserResolver(fn () => $owner);

        try {
            return $next($request);
        } finally {
            // guards live as long as the process (queue workers, tests): hand them back as they were
            foreach ($guards as $g) {
                $g === $guard ? Auth::guard($g)->setUser($user) : Auth::guard($g)->forgetUser();
            }
        }
    }

    private function allowed(User $user, string|array $required): bool
    {
        foreach ((array) $required as $p) {
            if (TeamRoles::allows($user->team_role, $p)) {
                return true;
            }
        }

        return false;
    }

    private function deny(Request $request, string $message, int $status)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        abort($status, $message);
    }
}
