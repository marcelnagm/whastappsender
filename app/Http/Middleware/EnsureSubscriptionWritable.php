<?php

namespace App\Http\Middleware;

use App\Exceptions\PlanLimitException;
use App\Services\Billing\Entitlements;
use Closure;
use Illuminate\Http\Request;

/**
 * Accounts blocked for non-payment become read-only: GET/HEAD still work (data, invoices, billing page),
 * everything that changes something is refused until the open invoice is paid.
 */
class EnsureSubscriptionWritable
{
    public function __construct(private Entitlements $entitlements)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$request->isMethodSafe() && !$this->isExempt($request) && $this->entitlements->isBlocked($user)) {
            throw PlanLimitException::blocked();
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        // Logging out, changing language and the billing screens must keep working to be able to pay
        return $request->is('billing*', 'logout', 'language/*', 'api/v1/auth/logout');
    }
}
