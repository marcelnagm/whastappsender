<?php

namespace App\Services\Queue;

/**
 * Decides how the slots of one send batch are shared between users and WhatsApp sessions.
 *
 * Pure PHP (no framework, no database) so it can be unit tested on its own.
 *
 * The rule is max-min fairness over the *queue occupancy*: every slot goes to whoever
 * currently has the least in the queue (messages already waiting + slots granted in this batch),
 * divided by their capacity. A user with a small backlog gets all of it and the leftover is
 * redistributed to the others, so one big campaign can no longer hog the batch.
 *
 * Capacity (weight):
 *  - "session": the number of connected sessions of the user. A user with 3 numbers sends
 *               roughly 3x more than a user with 1 (each number has its own anti-ban pacing).
 *  - "user":    every user weighs 1, regardless of how many sessions they have.
 */
final class FairBatchPlanner
{
    public const MODE_SESSION = 'session';
    public const MODE_USER = 'user';

    /**
     * @param array<int,array{
     *     user_id:int,
     *     pending:int,
     *     oldest_id:int,
     *     inflight:int,
     *     sessions:array<int,int>
     * }> $users  sessions = instance_id => messages of this user already waiting on that session
     *
     * @return array<int,array<int,int>> user_id => ordered list of instance ids, one entry per granted slot
     */
    public function allocate(array $users, int $batchSize, string $mode = self::MODE_SESSION): array
    {
        $state = [];
        foreach ($users as $u) {
            if ($u['pending'] <= 0 || empty($u['sessions'])) {
                continue; // nothing to send, or no connected session to send from
            }

            $state[$u['user_id']] = [
                'weight' => $mode === self::MODE_USER ? 1 : count($u['sessions']),
                'inflight' => max(0, (int) $u['inflight']),
                'pending' => (int) $u['pending'],
                'oldest' => (int) $u['oldest_id'],
                'sessions' => $u['sessions'],
                'picked' => array_fill_keys(array_keys($u['sessions']), 0),
                'picks' => [],
            ];
        }

        for ($slot = 0; $slot < $batchSize; $slot++) {
            $best = null;
            foreach ($state as $userId => $s) {
                if (count($s['picks']) >= $s['pending']) {
                    continue; // backlog exhausted
                }
                if ($best === null || $this->hasLessThan($s, $userId, $state[$best], $best)) {
                    $best = $userId;
                }
            }

            if ($best === null) {
                break; // nobody has anything left
            }

            $instanceId = $this->emptiestSession($state[$best]);
            $state[$best]['picks'][] = $instanceId;
            $state[$best]['picked'][$instanceId]++;
        }

        $result = [];
        foreach ($state as $userId => $s) {
            if ($s['picks']) {
                $result[$userId] = $s['picks'];
            }
        }

        return $result;
    }

    /**
     * Interleaves the granted slots so the queue (a plain FIFO) serves everyone smoothly:
     * a user with 40 messages and another with 10 end up as A A A A B A A A A B ... instead of
     * 40 x A followed by 10 x B.
     *
     * @param array<int,array<int,int>> $picks   output of allocate()
     * @param array<int,array<int,int>> $jobIds  user_id => that user's oldest pending job ids
     *
     * @return array<int,array{user_id:int,job_id:int,instance_id:int}>
     */
    public function sequence(array $picks, array $jobIds): array
    {
        $entries = [];

        foreach ($picks as $userId => $instances) {
            $ids = array_values($jobIds[$userId] ?? []);
            $n = min(count($instances), count($ids));

            for ($k = 0; $k < $n; $k++) {
                $entries[] = [
                    'key' => (2 * $k + 1) / (2 * $n), // position of the k-th of n messages along the batch
                    'user_id' => $userId,
                    'job_id' => $ids[$k],
                    'instance_id' => $instances[$k],
                ];
            }
        }

        usort($entries, fn ($a, $b) => [$a['key'], $a['user_id']] <=> [$b['key'], $b['user_id']]);

        return array_map(fn ($e) => [
            'user_id' => $e['user_id'],
            'job_id' => $e['job_id'],
            'instance_id' => $e['instance_id'],
        ], $entries);
    }

    /** True when $a deserves the next slot before $b (lower occupancy per unit of capacity). */
    private function hasLessThan(array $a, int $aId, array $b, int $bId): bool
    {
        // (occupancy_a / weight_a) < (occupancy_b / weight_b), kept in integers
        $left = ($a['inflight'] + count($a['picks'])) * $b['weight'];
        $right = ($b['inflight'] + count($b['picks'])) * $a['weight'];

        if ($left !== $right) {
            return $left < $right;
        }

        // Tie: whoever has been waiting the longest, then the lowest user id (deterministic)
        if ($a['oldest'] !== $b['oldest']) {
            return $a['oldest'] < $b['oldest'];
        }

        return $aId < $bId;
    }

    /** Session of the user with the fewest messages waiting (existing + granted now). */
    private function emptiestSession(array $s): int
    {
        $best = null;
        $bestLoad = null;

        foreach ($s['sessions'] as $instanceId => $waiting) {
            $load = $waiting + $s['picked'][$instanceId];
            if ($best === null || $load < $bestLoad || ($load === $bestLoad && $instanceId < $best)) {
                $best = $instanceId;
                $bestLoad = $load;
            }
        }

        return (int) $best;
    }
}
