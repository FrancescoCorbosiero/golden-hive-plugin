<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Schedule;

use HiveSync\Workflow\Schedule\CronExpr;
use PHPUnit\Framework\TestCase;

/**
 * Locks the three scheduling bugs fixed in CronExpr:
 *  1. garbage tokens silently coerced to 0 in min-0 fields;
 *  2. dom/dow both-restricted matched with AND instead of standard OR;
 *  3. nextRun inclusive of the current minute on exact boundaries
 *     (JobRunner persisted next_run_at = now → immediate re-fire).
 * Plus Vixie '7 = Sunday' parity with the golden-hive parser.
 */
final class CronExprTest extends TestCase
{
    public function testGarbageTokensAreRejectedNotZero(): void
    {
        $this->assertNull( CronExpr::parse( 'mon 3 * * *' ), "'mon' non è il minuto 0" );
        $this->assertNull( CronExpr::parse( '0 0 * * abc' ) );
        $this->assertNull( CronExpr::parse( '0 0 * * 1-x' ) );
        $this->assertNull( CronExpr::parse( '*/x * * * *' ) );
    }

    public function testValidExpressionsStillParse(): void
    {
        $parsed = CronExpr::parse( '*/15 2-4 1,15 * 1-5' );
        $this->assertNotNull( $parsed );
        $this->assertSame( [ 0, 15, 30, 45 ], $parsed['minute'] );
        $this->assertSame( [ 2, 3, 4 ], $parsed['hour'] );
        $this->assertSame( [ 1, 15 ], $parsed['dom'] );
        $this->assertSame( [ 1, 2, 3, 4, 5 ], $parsed['dow'] );
    }

    public function testDowSevenIsSunday(): void
    {
        $this->assertSame( [ 0 ], CronExpr::parse( '0 0 * * 7' )['dow'] ?? null );
        $this->assertSame( [ 0, 1, 2, 3, 4, 5, 6 ], CronExpr::parse( '0 0 * * 0-7' )['dow'] ?? null );
        $this->assertSame( [ 0, 1, 2, 3, 4, 5, 6 ], CronExpr::parse( '0 0 * * 1-7' )['dow'] ?? null );
        $this->assertSame( [ 0, 5, 6 ], CronExpr::parse( '0 0 * * 5-7' )['dow'] ?? null );
        $this->assertNull( CronExpr::parse( '0 0 * * 8' ) );
    }

    public function testNextRunIsStrictlyAfterFrom(): void
    {
        // 2026-01-05 03:00:00 UTC matcha '0 3 * * *' esattamente: il
        // next DEVE essere il giorno dopo, mai il minuto corrente.
        $from = gmmktime( 3, 0, 0, 1, 5, 2026 );
        $this->assertSame(
            gmmktime( 3, 0, 0, 1, 6, 2026 ),
            CronExpr::nextRun( '0 3 * * *', $from )
        );
    }

    public function testNextRunDomDowBothRestrictedIsOr(): void
    {
        // '0 0 1 * 1' = il 1° del mese O di lunedì. Da martedì
        // 2026-01-06, il prossimo lunedì (12/1) precede il prossimo
        // 1° del mese (1/2).
        $from = gmmktime( 12, 0, 0, 1, 6, 2026 );
        $this->assertSame(
            gmmktime( 0, 0, 0, 1, 12, 2026 ),
            CronExpr::nextRun( '0 0 1 * 1', $from )
        );
    }

    public function testNextRunSingleRestrictionStillAnds(): void
    {
        // Solo dow ristretto: '0 9 * * 1' = lunedì alle 9. Da martedì
        // 2026-01-06 → lunedì 12/1 alle 9.
        $from = gmmktime( 0, 0, 0, 1, 6, 2026 );
        $this->assertSame(
            gmmktime( 9, 0, 0, 1, 12, 2026 ),
            CronExpr::nextRun( '0 9 * * 1', $from )
        );
    }
}
