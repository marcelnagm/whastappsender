<?php

namespace App\Services\Queue;

use App\Models\Instance;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Models\WhatsappJob;
use Illuminate\Support\Collection;

/**
 * Picks the next batch of WhatsappJob rows to dispatch, shared fairly between users and sessions.
 * See FairBatchPlanner for the rule. Read-only: the caller marks the rows as queued.
 */
class FairQueueSelector
{
    public const MODE_FIFO = 'fifo';

    public function __construct(private FairBatchPlanner $planner, private Entitlements $entitlements)
    {
    }

    /**
     * @return Collection<int,WhatsappJob> in dispatch order. Each row has instance_id already set
     *         (not saved) to the session that should send it.
     */
    public function select(int $batchSize, ?string $mode = null): Collection
    {
        $mode = $mode ?: (string) config('whatsapp_queue.fairness', FairBatchPlanner::MODE_SESSION);

        if ($mode === self::MODE_FIFO) {
            return $this->eligible()->with(['campaignItem', 'contact'])->orderBy('id')->limit($batchSize)->get();
        }

        $plan = $this->plan($batchSize, $mode);
        if (!$plan['picks']) {
            return collect();
        }

        $jobsByUser = [];
        $models = [];
        foreach ($plan['picks'] as $userId => $instances) {
            $rows = $this->eligible()
                ->with(['campaignItem', 'contact'])
                ->where('user_id', $userId)
                ->orderBy('id')
                ->limit(count($instances))
                ->get();

            foreach ($rows as $row) {
                $models[$row->id] = $row;
            }
            $jobsByUser[$userId] = $rows->pluck('id')->all();
        }

        $ordered = $this->planner->sequence($plan['picks'], $jobsByUser);

        return collect($ordered)->map(function (array $entry) use ($models) {
            $job = $models[$entry['job_id']];
            $job->instance_id = $entry['instance_id'];

            return $job;
        })->values();
    }

    /**
     * Distribution that select() would use, for `whatsapp:queue --dry-run`.
     *
     * @return array<int,array{user_id:int,sessions:int,pending:int,inflight:int,selected:int,per_session:array<int,int>}>
     */
    public function describe(int $batchSize, ?string $mode = null): array
    {
        $mode = $mode ?: (string) config('whatsapp_queue.fairness', FairBatchPlanner::MODE_SESSION);
        if ($mode === self::MODE_FIFO) {
            $rows = $this->eligible()->orderBy('id')->limit($batchSize)->get(['user_id']);

            return $rows->groupBy('user_id')->map(fn ($g, $userId) => [
                'user_id' => (int) $userId, 'sessions' => 0, 'pending' => 0, 'inflight' => 0,
                'selected' => $g->count(), 'per_session' => [],
            ])->values()->all();
        }

        $plan = $this->plan($batchSize, $mode);
        $out = [];
        foreach ($plan['users'] as $u) {
            $picks = $plan['picks'][$u['user_id']] ?? [];
            $out[] = [
                'user_id' => $u['user_id'],
                'sessions' => count($u['sessions']),
                'pending' => $u['pending'],
                'inflight' => $u['inflight'],
                'selected' => count($picks),
                'per_session' => array_count_values($picks),
            ];
        }

        return $out;
    }

    /**
     * @return array{users:array,picks:array<int,array<int,int>>}
     */
    private function plan(int $batchSize, string $mode): array
    {
        $demand = $this->eligible()
            ->selectRaw('user_id, COUNT(*) as pending, MIN(id) as oldest_id')
            ->groupBy('user_id')
            ->get();

        if ($demand->isEmpty()) {
            return ['users' => [], 'picks' => []];
        }

        $userIds = $demand->pluck('user_id')->all();

        // Sessions able to send right now
        $sessions = Instance::where('status', 'connected')
            ->where('channel', 'whatsapp')
            ->whereIn('user_id', $userIds)
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->groupBy('user_id');

        // Messages already waiting in the queue, per user and per session
        $window = max(1, (int) config('whatsapp_queue.inflight_window_hours', 24));
        $waiting = WhatsappJob::where('status', 'fila')
            ->where('updated_at', '>=', now()->subHours($window))
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, instance_id, COUNT(*) as total')
            ->groupBy('user_id', 'instance_id')
            ->get()
            ->groupBy('user_id');

        $owners = User::whereIn('id', $userIds)->get()->keyBy('id');

        $users = [];
        foreach ($demand as $row) {
            $userId = (int) $row->user_id;
            $userSessions = [];
            foreach ($sessions->get($userId, collect()) as $instance) {
                $userSessions[(int) $instance->id] = 0;
            }

            $inflight = 0;
            foreach ($waiting->get($userId, collect()) as $w) {
                $inflight += (int) $w->total;
                if ($w->instance_id !== null && isset($userSessions[(int) $w->instance_id])) {
                    $userSessions[(int) $w->instance_id] += (int) $w->total;
                }
            }

            // Plan: blocked accounts send nothing; the monthly message quota caps what can still be queued
            $pending = (int) $row->pending;
            $owner = $owners->get($userId);
            if ($owner) {
                $remaining = $this->entitlements->remaining($owner, 'messages_per_month');
                if ($remaining !== null) {
                    $pending = min($pending, max(0, $remaining - $inflight));
                }
            }
            if ($pending === 0) {
                continue;
            }

            $users[] = [
                'user_id' => $userId,
                'pending' => $pending,
                'oldest_id' => (int) $row->oldest_id,
                'inflight' => $inflight,
                'sessions' => $userSessions,
            ];
        }

        return ['users' => $users, 'picks' => $this->planner->allocate($users, $batchSize, $mode)];
    }

    /** Same eligibility rule the command always used. */
    private function eligible()
    {
        return WhatsappJob::query()
            ->whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3);
    }
}
