<?php

namespace Tests\Unit;

use App\Services\Queue\FairBatchPlanner;
use PHPUnit\Framework\TestCase;

class FairBatchPlannerTest extends TestCase
{
    private FairBatchPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new FairBatchPlanner();
    }

    /** @param array<int,int> $sessions instance_id => already waiting */
    private function user(int $id, int $pending, array $sessions, int $inflight = 0, ?int $oldest = null): array
    {
        return ['user_id' => $id, 'pending' => $pending, 'oldest_id' => $oldest ?? $id, 'inflight' => $inflight, 'sessions' => $sessions];
    }

    private function counts(array $allocation): array
    {
        return array_map('count', $allocation);
    }

    public function test_single_user_gets_the_whole_batch(): void
    {
        $result = $this->planner->allocate([$this->user(1, 1000, [10 => 0])], 50);

        $this->assertSame([1 => 50], $this->counts($result));
    }

    public function test_equal_users_split_the_batch_evenly(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 1000, [10 => 0]),
            $this->user(2, 1000, [20 => 0]),
        ], 50);

        $this->assertSame([1 => 25, 2 => 25], $this->counts($result));
    }

    public function test_big_campaign_cannot_starve_small_users_even_if_it_is_older(): void
    {
        // The legacy FIFO would have given all 50 slots to user 1 (oldest rows).
        $result = $this->planner->allocate([
            $this->user(1, 100000, [10 => 0], 0, 1),
            $this->user(2, 10, [20 => 0], 0, 90000),
            $this->user(3, 10, [30 => 0], 0, 90001),
        ], 50);

        $counts = $this->counts($result);
        $this->assertSame(10, $counts[2]);
        $this->assertSame(10, $counts[3]);
        $this->assertSame(30, $counts[1]);
    }

    public function test_leftover_of_a_small_backlog_goes_to_the_others(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 10, [10 => 0]),
            $this->user(2, 1000, [20 => 0]),
        ], 50);

        $this->assertSame([1 => 10, 2 => 40], $this->counts($result));
    }

    public function test_never_allocates_more_than_the_backlog_or_the_batch(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 3, [10 => 0]),
            $this->user(2, 4, [20 => 0]),
        ], 50);

        $this->assertSame([1 => 3, 2 => 4], $this->counts($result));
        $this->assertSame([], $this->planner->allocate([$this->user(1, 5, [10 => 0])], 0));
    }

    public function test_many_users_differ_by_at_most_one_slot(): void
    {
        $users = [];
        foreach (range(1, 7) as $id) {
            $users[] = $this->user($id, 500, [$id * 10 => 0]);
        }

        $counts = $this->counts($this->planner->allocate($users, 50));

        $this->assertSame(50, array_sum($counts));
        $this->assertLessThanOrEqual(1, max($counts) - min($counts));
    }

    public function test_session_mode_gives_users_with_more_sessions_a_proportional_share(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 1000, [10 => 0, 11 => 0, 12 => 0]), // 3 numbers
            $this->user(2, 1000, [20 => 0]),                   // 1 number
        ], 50, FairBatchPlanner::MODE_SESSION);

        $counts = $this->counts($result);
        $this->assertSame(50, array_sum($counts));
        $this->assertGreaterThan($counts[2] * 2, $counts[1]);
        $this->assertLessThan($counts[2] * 4, $counts[1]);
    }

    public function test_user_mode_ignores_how_many_sessions_a_user_has(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 1000, [10 => 0, 11 => 0, 12 => 0]),
            $this->user(2, 1000, [20 => 0]),
        ], 50, FairBatchPlanner::MODE_USER);

        $this->assertSame([1 => 25, 2 => 25], $this->counts($result));
    }

    public function test_messages_already_waiting_count_against_their_owner(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 1000, [10 => 40], 40), // earlier batch still queued
            $this->user(2, 1000, [20 => 0], 0),
        ], 50);

        $this->assertSame([1 => 5, 2 => 45], $this->counts($result));
    }

    public function test_users_without_a_connected_session_are_skipped(): void
    {
        $result = $this->planner->allocate([
            $this->user(1, 500, []),
            $this->user(2, 500, [20 => 0]),
        ], 50);

        $this->assertSame([2 => 50], $this->counts($result));
    }

    public function test_slots_are_balanced_across_the_sessions_of_one_user(): void
    {
        $result = $this->planner->allocate([$this->user(1, 1000, [10 => 0, 11 => 0])], 10);

        $this->assertSame([5, 5], array_values(array_count_values($result[1])));
        // consecutive messages alternate between numbers (better for anti-ban pacing)
        $this->assertSame([10, 11, 10, 11, 10, 11, 10, 11, 10, 11], $result[1]);
    }

    public function test_session_that_already_has_a_queue_gets_fewer_new_messages(): void
    {
        $result = $this->planner->allocate([$this->user(1, 1000, [10 => 6, 11 => 0], 6)], 10);

        $perSession = array_count_values($result[1]);
        $this->assertSame(8, $perSession[11]);
        $this->assertSame(2, $perSession[10]);
    }

    public function test_allocation_is_deterministic_and_ties_go_to_the_oldest_backlog(): void
    {
        $users = [
            $this->user(5, 100, [50 => 0], 0, 900),
            $this->user(3, 100, [30 => 0], 0, 100), // waiting longer
        ];

        $a = $this->planner->allocate($users, 1);
        $b = $this->planner->allocate(array_reverse($users), 1);

        $this->assertSame([3 => [30]], $a);
        $this->assertSame($a, $b);
    }

    public function test_sequence_interleaves_users_instead_of_serving_them_one_after_the_other(): void
    {
        $picks = $this->planner->allocate([
            $this->user(1, 40, [10 => 0]),
            $this->user(2, 10, [20 => 0]),
        ], 50);

        $sequence = $this->planner->sequence($picks, [1 => range(1, 40), 2 => range(101, 110)]);

        $this->assertCount(50, $sequence);

        $letters = implode('', array_map(fn ($e) => $e['user_id'] === 1 ? 'A' : 'B', $sequence));
        $this->assertStringNotContainsString('AAAAAA', $letters, 'user 2 must not wait behind a long run of user 1');
        $this->assertStringNotContainsString('BB', $letters);
    }

    public function test_sequence_keeps_each_users_oldest_first_and_uses_every_job_once(): void
    {
        $picks = $this->planner->allocate([
            $this->user(1, 6, [10 => 0, 11 => 0]),
            $this->user(2, 4, [20 => 0]),
        ], 10);

        $sequence = $this->planner->sequence($picks, [1 => [11, 12, 13, 14, 15, 16], 2 => [21, 22, 23, 24]]);

        $this->assertCount(10, $sequence);
        $this->assertCount(10, array_unique(array_column($sequence, 'job_id')));

        foreach ([1, 2] as $userId) {
            $ids = array_values(array_map(fn ($e) => $e['job_id'], array_filter($sequence, fn ($e) => $e['user_id'] === $userId)));
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $ids, "jobs of user {$userId} must stay oldest first");
        }
    }
}
