<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Schedule;

use HiveSync\Workflow\Schedule\Drainer;
use PHPUnit\Framework\TestCase;

/**
 * The drain loop's stopping rules. A fake clock advances by the time
 * each fake slice "takes"; fake jobs need N slices to finish.
 *
 * The regression this guards: one 25s slice per job per 5-minute tick.
 * A run needing 8 slices took 40 minutes of wall clock for 3 minutes of
 * work, and the job's next slots were skipped meanwhile.
 */
final class DrainerTest extends TestCase
{
    private float $clock = 0.0;
    /** @var array<int, int> job id → slices still needed */
    private array $remaining = [];
    /** @var int[] job ids in the order slices ran */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->clock     = 0.0;
        $this->remaining = [];
        $this->ran       = [];
    }

    /**
     * @return array{passes: int, slices: int, stopped: string, results: array}
     */
    private function drain( float $budget, float $sliceCost = 25.0, ?callable $keepGoing = null ): array
    {
        return ( new Drainer( $budget, 8.0 ) )->drain(
            function (): array {
                $due = [];
                foreach ( $this->remaining as $id => $left ) {
                    if ( $left > 0 ) $due[] = [ 'id' => $id ];
                }
                return $due;
            },
            function ( array $job, float $left ) use ( $sliceCost ): ?array {
                $this->clock += $sliceCost;
                $this->ran[] = $job['id'];
                $this->remaining[ $job['id'] ]--;
                return [ 'status' => $this->remaining[ $job['id'] ] > 0 ? 'continue' : 'done' ];
            },
            $keepGoing ?? static fn(): bool => true,
            fn(): float => $this->clock,
        );
    }

    public function testNothingDueDoesNothing(): void
    {
        $out = $this->drain( 240 );
        $this->assertSame( 0, $out['slices'] );
        $this->assertSame( 'nothing_due', $out['stopped'] );
    }

    public function testChainsSlicesUntilTheRunFinishes(): void
    {
        $this->remaining = [ 7 => 4 ];
        $out = $this->drain( 240 );
        $this->assertSame( 4, $out['slices'] );
        $this->assertSame( 'drained', $out['stopped'] );
        $this->assertSame( 'done', $out['results'][7]['status'] );
    }

    public function testStopsWhenTheBudgetIsSpentAndLeavesTheRestForTheNextHeartbeat(): void
    {
        // 25s slices, 60s budget, never start with < 8s left:
        // t=0 run, t=25 run, t=50 run (10s left ≥ 8), t=75 stop.
        $this->remaining = [ 1 => 10 ];
        $out = $this->drain( 60 );
        $this->assertSame( 3, $out['slices'] );
        $this->assertSame( 'budget', $out['stopped'] );
        $this->assertSame( 7, $this->remaining[1] );
    }

    public function testFirstSliceAlwaysRunsEvenOnATinyBudget(): void
    {
        $this->remaining = [ 1 => 5 ];
        $out = $this->drain( 3 );
        $this->assertSame( 1, $out['slices'] );
        $this->assertSame( 'budget', $out['stopped'] );
    }

    public function testRunningJobsShareTheBudgetRoundRobin(): void
    {
        $this->remaining = [ 1 => 3, 2 => 3 ];
        $this->drain( 240 );
        $this->assertSame( [ 1, 2, 1, 2, 1, 2 ], $this->ran );
    }

    public function testLostLeaseOrMemoryPressureHaltsBeforeTheNextSlice(): void
    {
        $this->remaining = [ 1 => 5 ];
        $calls = 0;
        $out = $this->drain( 240, 25, function () use ( &$calls ): bool {
            return ++$calls <= 2;
        } );
        $this->assertSame( 2, $out['slices'] );
        $this->assertSame( 'halted', $out['stopped'] );
    }

    public function testJobsThatDeclineOnRecheckDoNotSpin(): void
    {
        $passes = 0;
        $out = ( new Drainer( 240, 8 ) )->drain(
            function () use ( &$passes ): array {
                $passes++;
                return [ [ 'id' => 1 ] ];
            },
            static fn( array $job, float $left ): ?array => null,
            static fn(): bool => true,
            fn(): float => $this->clock,
        );
        $this->assertSame( 0, $out['slices'] );
        $this->assertSame( 1, $passes );
    }

    public function testSecondsLeftIsHandedToTheSlice(): void
    {
        $this->remaining = [ 1 => 1 ];
        $seen = [];
        ( new Drainer( 40, 8 ) )->drain(
            function (): array { return $this->remaining[1] > 0 ? [ [ 'id' => 1 ] ] : []; },
            function ( array $job, float $left ) use ( &$seen ): ?array {
                $seen[] = $left;
                $this->remaining[1]--;
                return [ 'status' => 'done' ];
            },
            static fn(): bool => true,
            fn(): float => $this->clock,
        );
        $this->assertSame( [ 40.0 ], $seen );
    }
}
