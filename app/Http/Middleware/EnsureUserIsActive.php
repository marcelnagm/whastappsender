<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Account is disabled.',
            ], 403);
        }

        return $next($request);
    }
}
