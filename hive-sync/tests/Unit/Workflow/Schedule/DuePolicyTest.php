<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Schedule;

use HiveSync\Workflow\Schedule\DuePolicy;
use HiveSync\Workflow\Schedule\RunState;
use PHPUnit\Framework\TestCase;

final class DuePolicyTest extends TestCase
{
    private const NOW = 1_790_000_000;

    /** @param array<string, mixed> $over */
    private static function job( array $over = [] ): array
    {
        return $over + [
            'id'          => 1,
            'enabled'     => true,
            'cron_expr'   => '*/30 * * * *',
            'next_run_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 600 ),
            'run_state'   => null,
            'run_control' => null,
        ];
    }

    private static function running( string $trigger = RunState::TRIGGER_SCHEDULE, ?string $lastSlice = null ): array
    {
        $s = RunState::begin( $trigger, null, self::NOW - 300, [ 'runnable_type' => 'source.import', 'runnable_ref' => 'json/gs', 'config' => [] ] );
        $s['last_slice_at'] = $lastSlice;
        return $s;
    }

    public function testScheduledSlotIsDueOnlyOnceItArrives(): void
    {
        $this->assertFalse( DuePolicy::isDue( self::job(), self::NOW ) );
        $this->assertTrue( DuePolicy::isDue( self::job( [ 'next_run_at' => gmdate( 'Y-m-d H:i:s', self::NOW ) ] ), self::NOW ) );
        $this->assertFalse( DuePolicy::isDue( self::job( [ 'next_run_at' => null ] ), self::NOW ) );
    }

    public function testDisabledJobNeverStartsOnItsOwn(): void
    {
        $this->assertFalse( DuePolicy::isDue( self::job( [ 'enabled' => false, 'next_run_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ) ] ), self::NOW ) );
    }

    public function testRunInFlightContinuesImmediatelyRegardlessOfTheSlot(): void
    {
        // THE fix: next_run_at is the next slot (in the future) while the
        // run is going — continuation doesn't wait for it.
        $this->assertTrue( DuePolicy::isDue( self::job( [ 'run_state' => self::running() ] ), self::NOW ) );
    }

    public function testDisablingAJobPausesItsScheduledRun(): void
    {
        $this->assertFalse( DuePolicy::isDue( self::job( [ 'enabled' => false, 'run_state' => self::running() ] ), self::NOW ) );
    }

    public function testManualRunFinishesEvenOnADisabledJob(): void
    {
        $this->assertTrue( DuePolicy::isDue( self::job( [ 'enabled' => false, 'run_state' => self::running( RunState::TRIGGER_MANUAL ) ] ), self::NOW ) );
    }

    public function testRunRequestStartsEvenADisabledJob(): void
    {
        $this->assertTrue( DuePolicy::isDue( self::job( [ 'enabled' => false, 'run_control' => 'run' ] ), self::NOW ) );
        // …and resumes a paused run.
        $this->assertTrue( DuePolicy::isDue( self::job( [ 'enabled' => false, 'run_state' => self::running(), 'run_control' => 'run' ] ), self::NOW ) );
    }

    public function testPendingStopIsNeverDue(): void
    {
        $job = self::job( [ 'run_state' => self::running(), 'run_control' => 'stop' ] );
        $this->assertFalse( DuePolicy::isDue( $job, self::NOW ) );
        $this->assertTrue( DuePolicy::hasPendingStop( $job ) );
        $this->assertFalse( DuePolicy::hasPendingStop( self::job( [ 'run_control' => 'stop' ] ) ), 'nothing in flight to stop' );
    }

    public function testDispatchOrderRequestsThenRunsThenSlots(): void
    {
        $jobs = [
            self::job( [ 'id' => 1, 'next_run_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 30 ) ] ),
            self::job( [ 'id' => 2, 'run_state' => self::running( RunState::TRIGGER_SCHEDULE, '2026-09-25 03:00:10' ) ] ),
            self::job( [ 'id' => 3, 'run_control' => 'run', 'enabled' => false ] ),
            self::job( [ 'id' => 4, 'run_state' => self::running( RunState::TRIGGER_SCHEDULE, '2026-09-25 03:00:05' ) ] ),
            self::job( [ 'id' => 5, 'next_run_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 90 ) ] ),
            self::job( [ 'id' => 6 ] ), // not due
        ];
        $ids = array_map( static fn( array $j ): int => $j['id'], DuePolicy::select( $jobs, self::NOW ) );
        // 3 = run request; 4 then 2 = least recently served first; 5 then 1 = oldest slot first.
        $this->assertSame( [ 3, 4, 2, 5, 1 ], $ids );
    }
}
