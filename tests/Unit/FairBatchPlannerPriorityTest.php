<?php

namespace Tests\Unit;

use App\Services\Queue\FairBatchPlanner;
use PHPUnit\Framework\TestCase;

class FairBatchPlannerPriorityTest extends TestCase
{
    private function user(int $id, int $priority, int $pending = 1000): array
    {
        return ['user_id' => $id, 'pending' => $pending, 'oldest_id' => $id, 'inflight' => 0, 'sessions' => [$id * 10 => 0], 'priority' => $priority];
    }

    public function test_weighted_priority_gives_each_plan_a_proportional_share(): void
    {
        $a = (new FairBatchPlanner())->allocate([$this->user(1, 1), $this->user(2, 4)], 50, FairBatchPlanner::MODE_SESSION, FairBatchPlanner::PRIORITY_WEIGHTED);

        $this->assertSame(50, count($a[1]) + count($a[2]));
        $this->assertSame(10, count($a[1]));
        $this->assertSame(40, count($a[2]));
    }

    public function test_weighted_never_starves_the_lowest_plan(): void
    {
        $a = (new FairBatchPlanner())->allocate([$this->user(1, 1), $this->user(2, 8)], 20, FairBatchPlanner::MODE_SESSION);

        $this->assertGreaterThanOrEqual(2, count($a[1]));
        $this->assertGreaterThan(count($a[1]), count($a[2]));
    }

    public function test_strict_serves_the_highest_priority_first_and_passes_on_the_leftover(): void
    {
        $planner = new FairBatchPlanner();

        $a = $planner->allocate([$this->user(1, 1), $this->user(2, 4)], 50, FairBatchPlanner::MODE_SESSION, FairBatchPlanner::PRIORITY_STRICT);
        $this->assertSame(50, count($a[2]));
        $this->assertArrayNotHasKey(1, $a);

        $b = $planner->allocate([$this->user(1, 1), $this->user(2, 4, 12)], 50, FairBatchPlanner::MODE_SESSION, FairBatchPlanner::PRIORITY_STRICT);
        $this->assertSame(12, count($b[2]));
        $this->assertSame(38, count($b[1]));
    }

    public function test_without_priority_behaviour_is_the_old_one(): void
    {
        $users = [['user_id' => 1, 'pending' => 100, 'oldest_id' => 1, 'inflight' => 0, 'sessions' => [10 => 0]],
            ['user_id' => 2, 'pending' => 100, 'oldest_id' => 2, 'inflight' => 0, 'sessions' => [20 => 0]]];
        $a = (new FairBatchPlanner())->allocate($users, 10);

        $this->assertSame(5, count($a[1]));
        $this->assertSame(5, count($a[2]));
    }

    public function test_priority_multiplies_with_sessions(): void
    {
        $two = ['user_id' => 1, 'pending' => 1000, 'oldest_id' => 1, 'inflight' => 0, 'sessions' => [10 => 0, 11 => 0], 'priority' => 1];
        $a = (new FairBatchPlanner())->allocate([$two, $this->user(2, 2)], 40, FairBatchPlanner::MODE_SESSION);

        $this->assertSame(20, count($a[1])); // 2 sessions x priority 1
        $this->assertSame(20, count($a[2])); // 1 session x priority 2
    }
}
