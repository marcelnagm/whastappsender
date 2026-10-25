<?php

namespace App\Http\Middleware;

use App\Services\Billing\Entitlements;
use Closure;
use Illuminate\Http\Request;

/** Route middleware: plan.feature:chat — the user's plan must include the feature. */
class RequireFeature
{
    public function __construct(private Entitlements $entitlements)
    {
    }

    public function handle(Request $request, Closure $next, string $feature)
    {
        if ($user = $request->user()) {
            // Blocked accounts stay read-only: they may still look at what their plan includes
            $readOnlyAccess = $request->isMethodSafe()
                && $this->entitlements->isBlocked($user)
                && $this->entitlements->plan($user)->hasFeature($feature);

            if (!$readOnlyAccess) {
                $this->entitlements->authorizeFeature($user, $feature);
            }
        }

        return $next($request);
    }
}
