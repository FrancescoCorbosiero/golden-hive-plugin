<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Workflow\Run\MissingSweeper;
use PHPUnit\Framework\TestCase;

/**
 * The sweep is the only operation in the runner that acts on the ABSENCE
 * of data, so the tests that matter are the ones proving it does NOT act
 * when the absence is unexplained.
 */
final class MissingSweeperTest extends TestCase
{
    /** @return array<int, array{sku: string, status: string, retired_since: string, retired_mode: string}> */
    private function owned( array $spec ): array
    {
        $out = [];
        foreach ( $spec as $pid => $row ) {
            $out[ (int) $pid ] = [
                'sku'           => (string) ( $row['sku'] ?? '' ),
                'status'        => (string) ( $row['status'] ?? 'publish' ),
                'retired_since' => (string) ( $row['retired_since'] ?? '' ),
                'retired_mode'  => (string) ( $row['retired_mode'] ?? '' ),
            ];
        }
        return $out;
    }

    /** @param FeedItem[] $items @return array<int, string> */
    private function skus( array $items ): array
    {
        return array_map( static fn( FeedItem $i ): string => $i->sku, $items );
    }

    // ─── The bug this whole class exists to fix ──────────────────────

    public function testSkuThatVanishedFromTheFeedIsQueuedForRetire(): void
    {
        $r = MissingSweeper::decide(
            [ 'IN-FEED-1', 'IN-FEED-2' ],
            $this->owned( [
                10 => [ 'sku' => 'IN-FEED-1' ],
                11 => [ 'sku' => 'IN-FEED-2' ],
                12 => [ 'sku' => 'DELISTED' ],
            ] ),
            'hidden'
        );

        $this->assertFalse( $r['aborted'] );
        $this->assertSame( [ 'DELISTED' ], $this->skus( $r['retire'] ) );
        $this->assertSame( 12, $r['retire'][0]->data['_existing_id'] );
        $this->assertSame(
            MissingSweeper::ACTION_RETIRE,
            $r['retire'][0]->data[ MissingSweeper::ACTION ]
        );
    }

    public function testSkuCasingDifferenceIsNotADelisting(): void
    {
        $r = MissingSweeper::decide(
            [ 'abc-123' ],
            $this->owned( [ 10 => [ 'sku' => 'ABC-123' ] ] ),
            'hidden'
        );
        $this->assertSame( [], $r['retire'] );
    }

    // ─── Guards ──────────────────────────────────────────────────────

    public function testEmptyFeedAbortsInsteadOfRetiringTheWholeCatalog(): void
    {
        $r = MissingSweeper::decide(
            [],
            $this->owned( [
                10 => [ 'sku' => 'A' ],
                11 => [ 'sku' => 'B' ],
            ] ),
            'hidden'
        );

        $this->assertTrue( $r['aborted'] );
        $this->assertSame( 'feed_empty', $r['reason'] );
        $this->assertSame( [], $r['retire'] );
    }

    public function testMassDelistingTripsTheRatioGuard(): void
    {
        // 30 owned, 20 gone (66%) — well over the 35% default.
        $owned = [];
        $feed  = [];
        for ( $i = 1; $i <= 30; $i++ ) {
            $owned[ $i ] = [ 'sku' => 'SKU-' . $i ];
            if ( $i > 20 ) $feed[] = 'SKU-' . $i;
        }

        $r = MissingSweeper::decide( $feed, $this->owned( $owned ), 'hidden' );

        $this->assertTrue( $r['aborted'] );
        $this->assertSame( 'ratio_guard', $r['reason'] );
        $this->assertSame( [], $r['retire'] );
        // The count AND the SKUs survive the abort. A guard that says
        // "20 products would go" and won't say which ones leaves no way
        // to tell a truncated feed from a real backlog except by
        // disabling the guard and finding out.
        $this->assertSame( 20, $r['would_retire'] );
        $this->assertCount( 20, $r['sample'] );
        $this->assertContains( 'SKU-1', $r['sample'] );
    }

    public function testSampleIsCappedForTransport(): void
    {
        $owned = [];
        for ( $i = 1; $i <= 300; $i++ ) $owned[ $i ] = [ 'sku' => 'SKU-' . $i ];

        $r = MissingSweeper::decide( [ 'SKU-1' ], $this->owned( $owned ), 'hidden', [ 'max_ratio' => 1.0 ] );

        $this->assertCount( 299, $r['retire'] );
        $this->assertCount( 60, $r['sample'] );
    }

    public function testRatioGuardDoesNotApplyToSmallCatalogs(): void
    {
        // 5 owned, 4 gone. Absolute churn this small is ordinary.
        $r = MissingSweeper::decide(
            [ 'SKU-1' ],
            $this->owned( [
                1 => [ 'sku' => 'SKU-1' ],
                2 => [ 'sku' => 'SKU-2' ],
                3 => [ 'sku' => 'SKU-3' ],
                4 => [ 'sku' => 'SKU-4' ],
                5 => [ 'sku' => 'SKU-5' ],
            ] ),
            'hidden'
        );

        $this->assertFalse( $r['aborted'] );
        $this->assertCount( 4, $r['retire'] );
    }

    public function testRatioGuardIsOperatorOverridable(): void
    {
        $owned = [];
        for ( $i = 1; $i <= 30; $i++ ) $owned[ $i ] = [ 'sku' => 'SKU-' . $i ];

        $r = MissingSweeper::decide(
            [ 'SKU-1' ],
            $this->owned( $owned ),
            'hidden',
            [ 'max_ratio' => 1.0 ]
        );

        $this->assertFalse( $r['aborted'] );
        $this->assertCount( 29, $r['retire'] );
    }

    public function testZeroRatioFallsBackToTheDefaultInsteadOfDisarming(): void
    {
        // "0" reads as zero tolerance, never as "no limit". Treating it
        // as no-limit would hand the strictest-looking setting the
        // loosest behaviour.
        $owned = [];
        for ( $i = 1; $i <= 30; $i++ ) $owned[ $i ] = [ 'sku' => 'SKU-' . $i ];

        $r = MissingSweeper::decide(
            [ 'SKU-1' ],
            $this->owned( $owned ),
            'hidden',
            [ 'max_ratio' => 0 ]
        );

        $this->assertTrue( $r['aborted'] );
        $this->assertSame( 'ratio_guard', $r['reason'] );
    }

    public function testRestoresSurviveTheRatioGuard(): void
    {
        $owned = [];
        $feed  = [ 'BACK-IN-STOCK' ];
        for ( $i = 1; $i <= 30; $i++ ) $owned[ $i ] = [ 'sku' => 'SKU-' . $i ];
        $owned[99] = [ 'sku' => 'BACK-IN-STOCK', 'status' => 'draft', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'hidden' ];

        $r = MissingSweeper::decide( $feed, $this->owned( $owned ), 'hidden' );

        $this->assertTrue( $r['aborted'] );
        $this->assertSame( [], $r['retire'] );
        $this->assertSame( [ 'BACK-IN-STOCK' ], $this->skus( $r['restore'] ) );
    }

    // ─── Steady state: a settled catalog writes nothing ──────────────

    public function testAlreadyRetiredUnderTheSameModeIsNotRequeued(): void
    {
        $r = MissingSweeper::decide(
            [ 'STILL-HERE' ],
            $this->owned( [
                10 => [ 'sku' => 'STILL-HERE' ],
                11 => [ 'sku' => 'GONE', 'status' => 'draft', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'hidden' ],
            ] ),
            'hidden'
        );

        $this->assertSame( [], $r['retire'] );
        $this->assertSame( [], $r['restore'] );
    }

    public function testModeChangeRequeuesAlreadyRetiredProducts(): void
    {
        $r = MissingSweeper::decide(
            [ 'STILL-HERE' ],
            $this->owned( [
                10 => [ 'sku' => 'STILL-HERE' ],
                11 => [ 'sku' => 'GONE', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'outofstock' ],
            ] ),
            'draft'
        );

        $this->assertSame( [ 'GONE' ], $this->skus( $r['retire'] ) );
    }

    // ─── Restore ─────────────────────────────────────────────────────

    public function testRelistedSkuIsRestored(): void
    {
        $r = MissingSweeper::decide(
            [ 'BACK' ],
            $this->owned( [
                10 => [ 'sku' => 'BACK', 'status' => 'draft', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'draft' ],
            ] ),
            'draft'
        );

        $this->assertSame( [ 'BACK' ], $this->skus( $r['restore'] ) );
        $this->assertSame(
            MissingSweeper::ACTION_RESTORE,
            $r['restore'][0]->data[ MissingSweeper::ACTION ]
        );
    }

    public function testHandRolledDraftWithNoMarkerIsNeverTouched(): void
    {
        // The operator parked this one themselves. No marker, no claim.
        $r = MissingSweeper::decide(
            [ 'PARKED' ],
            $this->owned( [ 10 => [ 'sku' => 'PARKED', 'status' => 'draft' ] ] ),
            'hidden'
        );

        $this->assertSame( [], $r['restore'] );
        $this->assertSame( [], $r['retire'] );
    }

    // ─── Bucket plumbing ─────────────────────────────────────────────

    public function testApplyPutsRestoresAheadOfRetires(): void
    {
        $base = new Diff(
            new: [ new FeedItem( sku: 'N1', data: [] ) ],
            update: [],
            unchanged: [],
            updateStock: [],
        );

        $decision = MissingSweeper::decide(
            [ 'BACK' ],
            $this->owned( [
                10 => [ 'sku' => 'BACK', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'hidden' ],
                11 => [ 'sku' => 'GONE' ],
            ] ),
            'hidden'
        );

        $diff = MissingSweeper::apply( $base, $decision );

        $this->assertSame( [ 'BACK', 'GONE' ], $this->skus( $diff->missing ) );
        // The feed-side buckets are handed through untouched.
        $this->assertCount( 1, $diff->new );
        // `missing` counts catalog rows, not feed rows — it must stay
        // out of the fetched-vs-classified reconciliation.
        $this->assertSame( 1, $diff->totalCount() );
    }

    public function testApplyIsANoOpWhenNothingWasSwept(): void
    {
        $base = new Diff( new: [ new FeedItem( sku: 'N1', data: [] ) ] );
        $diff = MissingSweeper::apply( $base, [ 'retire' => [], 'restore' => [] ] );
        $this->assertSame( $base, $diff );
    }

    // ─── Modes ───────────────────────────────────────────────────────

    public function testUnknownModeFallsBackToHidden(): void
    {
        $this->assertSame( 'hidden', MissingSweeper::normalizeMode( null ) );
        $this->assertSame( 'hidden', MissingSweeper::normalizeMode( 'delete' ) );
        $this->assertSame( 'hidden', MissingSweeper::normalizeMode( '' ) );
        $this->assertSame( 'draft', MissingSweeper::normalizeMode( 'DRAFT' ) );
        $this->assertSame( 'outofstock', MissingSweeper::normalizeMode( ' outofstock ' ) );
    }

    public function testProductsWithoutASkuAreNeverSwept(): void
    {
        $r = MissingSweeper::decide(
            [ 'A' ],
            $this->owned( [
                10 => [ 'sku' => 'A' ],
                11 => [ 'sku' => '' ],
            ] ),
            'hidden'
        );
        $this->assertSame( [], $r['retire'] );
    }

    public function testEmptyCatalogIsNotAnAbort(): void
    {
        $r = MissingSweeper::decide( [ 'A', 'B' ], [], 'hidden' );
        $this->assertFalse( $r['aborted'] );
        $this->assertSame( [], $r['retire'] );
        $this->assertSame( 0, $r['owned'] );
    }

    // ─── Restore-only: what keeps a retire from being one-way ────────

    /**
     * Turning `retire_missing` back OFF must not strand the products an
     * earlier run hid.
     *
     * Without this pass the trap is silent and permanent: the SKU
     * returns, the ordinary import re-stocks it and syncs post_status
     * from the feed payload, and `catalog_visibility = hidden` is left
     * behind because nothing in the feed path resets it. The product is
     * live and in stock and invisible in the shop, and there is nothing
     * in any report pointing at it.
     */
    public function testRestoreStillHappensWhenTheSweepIsOff(): void
    {
        $r = MissingSweeper::decide(
            [ 'BACK' ],
            $this->owned( [
                10 => [ 'sku' => 'BACK', 'status' => 'draft', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'draft' ],
            ] ),
            'hidden',
            [],
            false          // retire disabled
        );

        $this->assertSame( [ 'BACK' ], $this->skus( $r['restore'] ) );
    }

    public function testRestoreOnlyPassNeverRetires(): void
    {
        $r = MissingSweeper::decide(
            [ 'STILL-HERE' ],
            $this->owned( [
                10 => [ 'sku' => 'STILL-HERE' ],
                11 => [ 'sku' => 'GONE' ],
                12 => [ 'sku' => 'ALSO-GONE', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'outofstock' ],
            ] ),
            'hidden',
            [],
            false
        );

        $this->assertSame( [], $r['retire'] );
        $this->assertSame( [], $r['restore'] );
        $this->assertFalse( $r['aborted'] );
    }

    /**
     * A mass-delisting that would trip the circuit breaker must not also
     * withhold the restores — those are the products the feed is
     * actively selling right now.
     */
    public function testRestoreOnlyPassIgnoresTheRatioGuard(): void
    {
        $owned = [];
        for ( $i = 1; $i <= 30; $i++ ) $owned[ $i ] = [ 'sku' => 'SKU-' . $i ];
        $owned[99] = [ 'sku' => 'BACK', 'retired_since' => '2026-01-01 00:00:00', 'retired_mode' => 'hidden' ];

        $r = MissingSweeper::decide( [ 'BACK' ], $this->owned( $owned ), 'hidden', [], false );

        $this->assertFalse( $r['aborted'] );
        $this->assertSame( [ 'BACK' ], $this->skus( $r['restore'] ) );
    }
}
