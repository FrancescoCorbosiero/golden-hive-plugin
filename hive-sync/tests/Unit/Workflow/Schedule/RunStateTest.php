<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Schedule;

use HiveSync\Workflow\Schedule\RunState;
use HiveSync\Workflow\Schedule\SchedulerMigration;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    public function testSnapshotFreezesTheJobWithoutRuntimeKeys(): void
    {
        $snap = RunState::snapshotOf( [
            'runnable_type' => 'source.import',
            'runnable_ref'  => 'csv/sf-prod',
            'config'        => [ 'options' => [ 'mapping_slug' => 'sf-default' ], '_resume_cursor' => [ 'index' => 9 ] ],
        ] );
        $this->assertSame( 'csv/sf-prod', $snap['runnable_ref'] );
        $this->assertSame( [ 'options' => [ 'mapping_slug' => 'sf-default' ] ], $snap['config'] );
    }

    public function testAdvanceFoldsASliceIn(): void
    {
        $s = RunState::begin( RunState::TRIGGER_SCHEDULE, gmmktime( 4, 30, 0, 9, 25, 2026 ), gmmktime( 4, 30, 20, 9, 25, 2026 ), [] );
        $this->assertTrue( RunState::isRunning( $s ) );
        $this->assertFalse( RunState::isManual( $s ) );
        $this->assertSame( '2026-09-25 04:30:00', $s['slot_at'] );
        $this->assertSame( 0, RunState::runId( $s ) );

        $s = RunState::advance( $s, [
            'status'   => 'continue',
            'run_id'   => 582,
            'cursor'   => [ 'index' => 40, 'run_id' => 582 ],
            'progress' => [ 'done' => 40, 'total' => 310 ],
        ], gmmktime( 4, 31, 0, 9, 25, 2026 ) );

        $this->assertSame( 1, $s['slices'] );
        $this->assertSame( 582, RunState::runId( $s ) );
        $this->assertSame( [ 'done' => 40, 'total' => 310 ], $s['progress'] );
        $this->assertSame( '2026-09-25 04:31:00', $s['last_slice_at'] );

        // A terminal slice drops the cursor but keeps what we know.
        $s = RunState::advance( $s, [ 'status' => 'done', 'run_id' => 582 ], gmmktime( 4, 32, 0, 9, 25, 2026 ) );
        $this->assertNull( $s['cursor'] );
        $this->assertSame( 2, $s['slices'] );
        $this->assertSame( [ 'done' => 40, 'total' => 310 ], $s['progress'] );
    }

    public function testRunIdFallsBackToTheCursor(): void
    {
        $this->assertSame( 77, RunState::runId( [ 'phase' => 'running', 'cursor' => [ 'run_id' => 77 ] ] ) );
        $this->assertSame( 0, RunState::runId( null ) );
    }

    public function testCadenceIsStrippedFromLegacySeedLabels(): void
    {
        $this->assertSame( 'GS — Sync catalogo', SchedulerMigration::stripCadence( 'GS — Sync catalogo (idempotente, ogni 2h)' ) );
        $this->assertSame( 'KicksDB — Refresh prezzi tracked', SchedulerMigration::stripCadence( 'KicksDB — Refresh prezzi tracked (ogni 6h)' ) );
        $this->assertSame( 'SF — Sync catalogo (copia)', SchedulerMigration::stripCadence( 'SF — Sync catalogo (idempotente, ogni 2h) (copia)' ) );
        // Other parentheticals are the operator's words — untouched.
        $this->assertSame( 'SF — solo sneakers (test)', SchedulerMigration::stripCadence( 'SF — solo sneakers (test)' ) );
        $this->assertSame( '(ogni 2h)', SchedulerMigration::stripCadence( '(ogni 2h)' ), 'never blank a label' );
    }

    public function testLegacyCursorBecomesAResumableRunState(): void
    {
        $state = SchedulerMigration::legacyRunState(
            [ 'runnable_type' => 'source.import', 'runnable_ref' => 'csv/sf-prod', 'config' => [ 'options' => [] ], 'last_run_at' => '2026-09-25 03:05:30' ],
            [ 'index' => 120, 'run_id' => 579 ],
            [ 'started_at' => '2026-09-25 00:00:28', 'items_total' => 900 ],
            gmmktime( 3, 10, 0, 9, 25, 2026 ),
        );
        $this->assertTrue( RunState::isRunning( $state ) );
        $this->assertSame( 579, RunState::runId( $state ) );
        $this->assertSame( [ 'index' => 120, 'run_id' => 579 ], $state['cursor'] );
        $this->assertSame( '2026-09-25 00:00:28', $state['started_at'] );
        $this->assertSame( [ 'done' => 120, 'total' => 900 ], $state['progress'] );
        $this->assertSame( 'csv/sf-prod', $state['snapshot']['runnable_ref'] );
    }
}
