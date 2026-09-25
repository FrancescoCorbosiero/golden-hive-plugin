<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Schedule;

use HiveSync\Workflow\Schedule\JobSchedule;
use PHPUnit\Framework\TestCase;

/**
 * The slot model behind "the history doesn't match the cron".
 *
 * Runs start ON slots, next_run_at is planned when a run STARTS (anchored
 * on the slot it serves), and slots that pass while a run is still going
 * are skipped and counted — never replayed back-to-back off the grid.
 */
final class JobScheduleTest extends TestCase
{
    private function utc(): JobSchedule
    {
        return new JobSchedule( new \DateTimeZone( 'UTC' ) );
    }

    private static function at( int $h, int $m, int $s = 0 ): int
    {
        return gmmktime( $h, $m, $s, 9, 25, 2026 );
    }

    public function testPlanIsAnchoredOnTheServedSlotNotOnNow(): void
    {
        // Slot 10:00 picked up at 10:01 by the heartbeat → next 10:30.
        $this->assertSame( self::at( 10, 30 ), $this->utc()->planAfter( '*/30 * * * *', self::at( 10, 0 ), self::at( 10, 1 ) ) );
    }

    public function testRunStartingMoreThanAnIntervalLateCoalescesTheMissedSlots(): void
    {
        // Slot 10:00 only served at 11:07 (cron was down): 10:30 and 11:00
        // are folded into this run; the plan is the first future slot.
        $this->assertSame( self::at( 11, 30 ), $this->utc()->planAfter( '*/30 * * * *', self::at( 10, 0 ), self::at( 11, 7 ) ) );
    }

    public function testManualRunPlansFromNow(): void
    {
        $this->assertSame( self::at( 10, 30 ), $this->utc()->planAfter( '*/30 * * * *', null, self::at( 10, 7 ) ) );
    }

    public function testNoCronPlansNothing(): void
    {
        $this->assertNull( $this->utc()->planAfter( '', self::at( 10, 0 ), self::at( 10, 1 ) ) );
        $this->assertNull( $this->utc()->planAfter( null, null, self::at( 10, 1 ) ) );
        $this->assertNull( $this->utc()->planAfter( 'not a cron', null, self::at( 10, 1 ) ) );
    }

    public function testRunFinishingBeforeItsNextSlotSkipsNothing(): void
    {
        $this->assertSame(
            [ 'next' => self::at( 10, 30 ), 'skipped' => 0 ],
            $this->utc()->afterRun( '*/30 * * * *', self::at( 10, 30 ), self::at( 10, 3 ) )
        );
    }

    public function testOverrunningRunSkipsAndCountsTheSlotsItCovered(): void
    {
        // Started 10:00, planned 10:30, finished 11:05: 10:30 and 11:00
        // passed while it ran. Next run on the grid at 11:30 — not at
        // 11:05, which is what "drift" looked like in the history.
        $this->assertSame(
            [ 'next' => self::at( 11, 30 ), 'skipped' => 2 ],
            $this->utc()->afterRun( '*/30 * * * *', self::at( 10, 30 ), self::at( 11, 5 ) )
        );
    }

    public function testSlotReachedExactlyAtFinishCountsAsMissed(): void
    {
        $this->assertSame(
            [ 'next' => self::at( 11, 0 ), 'skipped' => 1 ],
            $this->utc()->afterRun( '*/30 * * * *', self::at( 10, 30 ), self::at( 10, 30, 0 ) )
        );
    }

    public function testMissingPlanIsArmedFromNow(): void
    {
        $this->assertSame(
            [ 'next' => self::at( 10, 30 ), 'skipped' => 0 ],
            $this->utc()->afterRun( '*/30 * * * *', null, self::at( 10, 12 ) )
        );
        $this->assertSame( [ 'next' => null, 'skipped' => 0 ], $this->utc()->afterRun( '', null, self::at( 10, 12 ) ) );
    }

    public function testDenseCronOverLongOverrunStopsCountingButStillLandsInTheFuture(): void
    {
        // Every minute for ~12 hours: the count is capped, the next slot
        // is still the first one after now.
        $r = $this->utc()->afterRun( '* * * * *', self::at( 0, 1 ), self::at( 12, 0 ) );
        $this->assertSame( JobSchedule::MAX_COUNTED_SKIPS, $r['skipped'] );
        $this->assertSame( self::at( 12, 1 ), $r['next'] );
    }

    public function testSlotsAreReadInTheSiteZone(): void
    {
        // "Ogni giorno alle 00:00" on a Rome shop = 22:00 UTC in summer.
        $rome = new JobSchedule( new \DateTimeZone( 'Europe/Rome' ) );
        $this->assertSame(
            gmmktime( 22, 0, 0, 9, 25, 2026 ),
            $rome->planAfter( '0 0 * * *', gmmktime( 22, 0, 0, 9, 24, 2026 ), gmmktime( 22, 1, 0, 9, 24, 2026 ) )
        );
    }

    public function testUtcStringRoundTrip(): void
    {
        $ts = self::at( 4, 30, 15 );
        $this->assertSame( '2026-09-25 04:30:15', JobSchedule::formatUtc( $ts ) );
        $this->assertSame( $ts, JobSchedule::parseUtc( '2026-09-25 04:30:15' ) );
        $this->assertNull( JobSchedule::parseUtc( '' ) );
        $this->assertNull( JobSchedule::parseUtc( null ) );
        $this->assertNull( JobSchedule::parseUtc( 'yesterday' ) );
        $this->assertNull( JobSchedule::formatUtc( null ) );
    }
}
