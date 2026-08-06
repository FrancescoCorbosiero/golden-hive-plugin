<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Includes\Jobs;

use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../../../../includes/jobs/cron-expr.php';

/**
 * Regression coverage for the day-of-week "7 = Sunday" handling and the
 * dom/dow OR semantics of the next-run walker.
 *
 * The historical bug: 7→0 was folded onto the RANGE ENDPOINTS before
 * validation, so '0-7' became the range 0-0 (Sunday only — a job silently
 * firing 1/7th as often as scheduled) and '1-7' became the inverted range
 * 1-0 (hard validation error on a perfectly standard expression).
 */
final class CronExprTest extends TestCase
{
    public function testDowRangeZeroToSevenMeansEveryDay(): void
    {
        $parsed = gh_cron_parse( '0 0 * * 0-7' );
        $this->assertIsArray( $parsed );
        $this->assertSame( [ 0, 1, 2, 3, 4, 5, 6 ], $parsed['dow'] );
    }

    public function testDowRangeOneToSevenMeansMondayThroughSunday(): void
    {
        $parsed = gh_cron_parse( '0 0 * * 1-7' );
        $this->assertIsArray( $parsed, 'l\'espressione standard 1-7 (lun-dom) deve essere accettata' );
        $this->assertSame( [ 0, 1, 2, 3, 4, 5, 6 ], $parsed['dow'] );
    }

    public function testDowRangeFiveToSevenWrapsSundayIn(): void
    {
        $parsed = gh_cron_parse( '0 0 * * 5-7' );
        $this->assertIsArray( $parsed );
        $this->assertSame( [ 0, 5, 6 ], $parsed['dow'], 'ven-dom = venerdi, sabato E domenica' );
    }

    public function testDowLiteralSevenIsSunday(): void
    {
        $parsed = gh_cron_parse( '0 0 * * 7' );
        $this->assertIsArray( $parsed );
        $this->assertSame( [ 0 ], $parsed['dow'] );
    }

    public function testDowEightIsRejected(): void
    {
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( '0 0 * * 8' ) );
    }

    public function testNonDowFieldsStillEnforceTheirBounds(): void
    {
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( '60 0 * * *' ) );
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( '0 24 * * *' ) );
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( '0 0 32 * *' ) );
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( '0 0 * 13 *' ) );
    }

    public function testGarbageTokensAreRejectedNotCoercedToZero(): void
    {
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( 'mon 3 * * *' ) );
        $this->assertInstanceOf( WP_Error::class, gh_cron_parse( '0 0 * * abc' ) );
    }

    public function testNextRunIsStrictlyAfterFrom(): void
    {
        // 2026-01-05 is a Monday. From exactly 03:00:00 the next '0 3 * * *'
        // match must be the following day, never the current minute.
        $from = gmmktime( 3, 0, 0, 1, 5, 2026 );
        $next = gh_cron_next_run( '0 3 * * *', $from );
        $this->assertSame( gmmktime( 3, 0, 0, 1, 6, 2026 ), $next );
    }

    public function testNextRunDomDowRestrictedIsOrSemantics(): void
    {
        // Standard cron: when BOTH dom and dow are restricted, either match
        // fires. '0 0 1 * 1' from Tue 2026-01-06 → next Monday (Jan 12)
        // comes before the next 1st-of-month (Feb 1).
        $from = gmmktime( 12, 0, 0, 1, 6, 2026 );
        $next = gh_cron_next_run( '0 0 1 * 1', $from );
        $this->assertSame( gmmktime( 0, 0, 0, 1, 12, 2026 ), $next );
    }

    public function testNextRunHonorsFullWeekDowRangeDaily(): void
    {
        // With the endpoint-folding bug '0 30 * * 0-7' only fired on
        // Sundays; it must fire daily at 00:30.
        $from = gmmktime( 0, 0, 0, 1, 6, 2026 ); // Tuesday
        $next = gh_cron_next_run( '30 0 * * 0-7', $from );
        $this->assertSame( gmmktime( 0, 30, 0, 1, 6, 2026 ), $next );
    }
}
