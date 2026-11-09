<?php

namespace App\Services\AI;

use App\Models\AiProfile;
use App\Models\AiUsage;
use Illuminate\Support\Facades\DB;

/** One row per call to an AI provider, so the owner (and the plan) can see where the tokens go. Never breaks the caller. */
class AiUsageLog
{
    public static function record(int $userId, string $kind, ?AiProfile $profile, string $provider, ?string $model, ?int $in, ?int $out): void
    {
        try {
            AiUsage::create(['user_id' => $userId, 'kind' => $kind, 'ai_profile_id' => $profile?->id, 'provider' => $provider, 'model' => $model,
                'tokens_in' => $in, 'tokens_out' => $out, 'created_at' => now()]);
        } catch (\Throwable $e) {
            // usage reporting is best effort
        }
    }

    /** @return array{calls:int,tokens_in:int,tokens_out:int,by_kind:array<string,array{calls:int,tokens:int}>,by_model:array} for the current month */
    public static function month(int $userId): array
    {
        $rows = AiUsage::where('user_id', $userId)->where('created_at', '>=', now()->startOfMonth())
            ->select('kind', 'provider', 'model', DB::raw('count(*) as calls'), DB::raw('coalesce(sum(tokens_in),0) as tin'), DB::raw('coalesce(sum(tokens_out),0) as tout'))
            ->groupBy('kind', 'provider', 'model')->get();

        $by = [];
        foreach ($rows as $r) {
            $by[$r->kind] = ['calls' => ($by[$r->kind]['calls'] ?? 0) + $r->calls, 'tokens' => ($by[$r->kind]['tokens'] ?? 0) + $r->tin + $r->tout];
        }

        return ['calls' => (int) $rows->sum('calls'), 'tokens_in' => (int) $rows->sum('tin'), 'tokens_out' => (int) $rows->sum('tout'), 'by_kind' => $by,
            'by_model' => $rows->map(fn ($r) => ['kind' => $r->kind, 'model' => $r->provider . '/' . $r->model, 'calls' => (int) $r->calls, 'tokens' => (int) ($r->tin + $r->tout)])->sortByDesc('tokens')->values()->all()];
    }
}
