<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Services\Team\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records who changed something sensitive (deletions, team, integrations, settings, exports): never the content, only
 * who, what, where and when. It runs after the response so it can not slow down or break the request.
 */
class AuditTrail
{
    /** Route-name prefixes whose non-GET calls are logged (any DELETE is logged too). */
    public const PREFIXES = ['team.', 'webhooks-out.', 'bookings.', 'hours.', 'sla.', 'csat.', 'contacts.bulk', 'contacts.import', 'companies.', 'sequences.', 'kb.', 'automations.', 'audience.', 'proposals.', 'pipeline.products', 'approvals.', 'billing.', 'profile.', 'api-tokens.', 'tokens.'];
    /** Route-name prefixes that are logged even on GET (data leaving the system). */
    public const ALWAYS = ['export.'];

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $route = $request->route();
            $user = $request->user();
            $name = $route?->getName();
            if (!$route || !$user || !$name || $response->getStatusCode() >= 400) {
                return;
            }
            $mutating = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
            $hit = $mutating && ($request->isMethod('DELETE') || $this->starts($name, self::PREFIXES));
            if (!$hit && !$this->starts($name, self::ALWAYS)) {
                return;
            }
            $actor = app(TeamContext::class)->member() ?? $user;
            $subject = collect($route->parameters())->first(fn ($v) => is_scalar($v));
            AuditLog::create([
                'user_id' => $user->id, 'actor_id' => $actor->id, 'actor_name' => mb_substr((string) $actor->name, 0, 120), 'action' => mb_substr($name, 0, 80),
                'method' => $request->method(), 'path' => mb_substr('/' . ltrim($request->path(), '/'), 0, 200), 'subject' => $subject !== null ? mb_substr((string) $subject, 0, 40) : null,
                'ip' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit trail failed: ' . $e->getMessage());
        }
    }

    private function starts(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($name, $p)) {
                return true;
            }
        }

        return false;
    }
}
