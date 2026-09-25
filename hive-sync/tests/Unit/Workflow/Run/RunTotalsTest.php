<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Run;

use HiveSync\Workflow\Run\RunTotals;
use PHPUnit\Framework\TestCase;

/**
 * The Storico's "Done" column showed the LAST tick's slice of a multi-
 * tick run (e.g. 12 for a run that wrote 400 products). The cursor now
 * carries whole-run totals.
 */
final class RunTotalsTest extends TestCase
{
    public function testTotalsAccumulateAcrossTicksThroughTheCursor(): void
    {
        $t1 = RunTotals::add( RunTotals::fromCursor( null ), [ 'created' => 3, 'updated' => 10, 'failed' => 1 ] );
        $cursor = [ 'index' => 14, 'run_id' => 9, 'totals' => $t1 ];
        $t2 = RunTotals::add( RunTotals::fromCursor( $cursor ), [ 'updated' => 5, 'stock_patched' => 40 ] );

        $this->assertSame( 3, $t2['created'] );
        $this->assertSame( 15, $t2['updated'] );
        $this->assertSame( 40, $t2['stock_patched'] );
        $this->assertSame( 1, $t2['failed'] );
        $this->assertSame( 58, RunTotals::done( $t2 ) );
    }

    public function testDoneCountsEveryKindOfWriteButNotSkipsOrFailures(): void
    {
        $this->assertSame( 21, RunTotals::done( [
            'created' => 1, 'updated' => 2, 'recreated' => 3, 'stock_patched' => 4, 'retired' => 5, 'restored' => 6,
            'skipped' => 100, 'failed' => 100, 'pre_blocked' => 100,
        ] ) );
    }

    public function testApplyReplacesCountersAndKeepsDiffNumbers(): void
    {
        $summary = [ 'fetched' => 900, 'new' => 3, 'created' => 1, 'updated' => 0, 'failed' => 0 ];
        $out = RunTotals::apply( $summary, RunTotals::add( RunTotals::fromCursor( null ), [ 'created' => 7, 'updated' => 2 ] ) );
        $this->assertSame( 900, $out['fetched'] );
        $this->assertSame( 3, $out['new'] );
        $this->assertSame( 7, $out['created'] );
        $this->assertSame( 2, $out['updated'] );
        // Optional sections keep their shape: no zero `retired` invented.
        $this->assertArrayNotHasKey( 'retired', $out );
    }

    public function testGarbageInTheCursorCannotGoNegative(): void
    {
        $t = RunTotals::fromCursor( [ 'totals' => [ 'created' => -5, 'updated' => 'x' ] ] );
        $this->assertSame( 0, $t['created'] );
        $this->assertSame( 0, $t['updated'] );
    }
}
