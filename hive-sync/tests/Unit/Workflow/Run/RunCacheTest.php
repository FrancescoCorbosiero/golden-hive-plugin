<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Workflow\Run\RunCache;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for RunCache binary-safety.
 *
 * The bug: RunCache stored raw gzcompress() output in a WP transient.
 * Without a persistent object cache that lands in `wp_options`, a utf8mb4
 * text column, where $wpdb truncates the value at the first byte that is
 * not valid UTF-8. gzcompress output is binary and invalid UTF-8 from its
 * second byte, so the blob was truncated to ~1 byte on write and
 * gzuncompress() failed on read — RunCache::get() returned null on EVERY
 * resume tick. The cache silently never persisted.
 *
 * That was invisible while a source's fetch+diff was cheap enough to
 * recompute each tick, but the bespoke StockFirmati diff loads the whole
 * catalog's variation+scalar snapshots and can't fit one 25s tick. With
 * the cache dead, every tick re-ran fetch+diff, tripped the deadline, and
 * processed nothing: the silent "0/N, 0%" run that never advanced.
 *
 * The shims in tests/wp-stubs.php model that exact utf8mb4 truncation, so
 * these tests fail if the base64 wrapper is ever removed.
 */
final class RunCacheTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the in-memory transient / option stores between tests.
        hsync_test_reset_wp_stubs();
    }

    protected function tearDown(): void
    {
        hsync_test_reset_wp_stubs();
    }

    // ─── Sliding expiry ───────────────────────────────────────────
    //
    // The TTL used to be fixed from tick 1: a run longer than 2h lost its
    // cache mid-run and re-fetched + re-diffed the whole feed, restarting
    // its queue from index 0.

    public function testTouchExtendsTheExpiryWithoutRewritingThePayload(): void
    {
        RunCache::set( 5, [], 4, $this->sampleDiff() );
        $options = &hsync_test_option_store();
        $store   = &hsync_test_transient_store();
        $blob    = $store['hsync_run_cache_5'];

        // Pretend the entry was written long ago and is about to expire.
        $options['_transient_timeout_hsync_run_cache_5'] = time() + 10;
        RunCache::touch( 5 );

        $this->assertGreaterThan( time() + 3600, $options['_transient_timeout_hsync_run_cache_5'] );
        $this->assertSame( $blob, $store['hsync_run_cache_5'], 'payload untouched' );
        $this->assertNotNull( RunCache::get( 5 ) );
    }

    public function testTouchOnAMissingEntryWritesNothing(): void
    {
        RunCache::touch( 6 );
        $this->assertSame( [], hsync_test_option_store() );
        $this->assertSame( [], hsync_test_transient_store() );
    }

    public function testTouchWithAnObjectCacheReSetsTheItemWithAFreshTtl(): void
    {
        hsync_test_ext_cache( true );
        RunCache::set( 7, [], 4, $this->sampleDiff() );
        $ttls = &hsync_test_transient_ttls();
        $ttls['hsync_run_cache_7'] = 1; // as if set with a nearly-spent TTL

        RunCache::touch( 7 );

        $this->assertGreaterThan( 3600, $ttls['hsync_run_cache_7'] );
        $this->assertNotNull( RunCache::get( 7 ) );
        $this->assertSame( [], hsync_test_option_store(), 'no timeout row with an object cache' );
    }

    private function sampleDiff(): Diff
    {
        // Multi-KB non-ASCII HTML description + raw rows — representative
        // of a real SF item, and guarantees the compressed blob is
        // genuinely binary (invalid UTF-8).
        $desc = str_repeat( '<p>Scarpa élégante in pèlle — qualità àèìòù ©®™ €.</p>', 80 );
        $mk = static fn( string $sku ): FeedItem => new FeedItem(
            sku: $sku,
            data: [
                'sku'           => $sku,
                'type'          => 'variable',
                'description'   => $desc,
                '_hsync_flavor' => 'stockfirmati',
                '_existing_id'  => 1234,
                'variations'    => [
                    [ 'sku' => $sku . '-42', 'regular_price' => '120', 'sale_price' => '87', 'stock_quantity' => 3 ],
                    [ 'sku' => $sku . '-43', 'regular_price' => '120', 'sale_price' => '87', 'stock_quantity' => 0 ],
                ],
            ],
            raw: [ [ 'RECORD_TYPE' => 'PRODUCT', 'SKU' => $sku, 'Description_ITA' => $desc ] ],
        );

        return new Diff(
            new:       [ $mk( 'NEW-1' ) ],
            update:    [ $mk( 'UPD-1' ), $mk( 'UPD-2' ) ],
            unchanged: [ $mk( 'UNCH-1' ) ],
        );
    }

    public function testDiffSurvivesUtf8mb4TransientRoundTrip(): void
    {
        $diff = $this->sampleDiff();

        RunCache::set( 1, [ 'a warning' ], 4, $diff );
        $hydrated = RunCache::get( 1 );

        // THE regression: before base64-wrapping, the binary blob was
        // truncated on write and this returned null.
        $this->assertNotNull( $hydrated, 'cache must survive a utf8mb4 transient store' );
        $this->assertInstanceOf( Diff::class, $hydrated['diff'] );
        $this->assertSame( 4, $hydrated['fetched_count'] );
        $this->assertSame( [ 'a warning' ], $hydrated['warnings'] );

        $restored = $hydrated['diff'];
        $this->assertCount( 1, $restored->new );
        $this->assertCount( 2, $restored->update );
        $this->assertCount( 1, $restored->unchanged );
        $this->assertSame( 'UPD-1', $restored->update[0]->sku );
        $this->assertSame( 'stockfirmati', $restored->update[0]->data['_hsync_flavor'] );
        $this->assertSame( 1234, $restored->update[0]->data['_existing_id'] );
    }

    public function testStoredValueIsSevenBitAsciiSoItCannotBeTruncated(): void
    {
        RunCache::set( 7, [], 1, $this->sampleDiff() );

        $stored = get_transient( 'hsync_run_cache_7' );
        $this->assertIsString( $stored );
        $this->assertNotSame( '', $stored );
        // base64 output is 7-bit ASCII → valid UTF-8 → a utf8mb4 column
        // keeps it verbatim. (A raw gzcompress blob would not be.)
        $this->assertSame( 1, preg_match( '//u', $stored ), 'stored value must be valid UTF-8' );
        $this->assertSame( $stored, hsync_test_longest_valid_utf8_prefix( $stored ), 'stored value must not be truncatable' );
    }

    public function testRawGzcompressWouldHaveBeenDestroyedByTheSameStore(): void
    {
        // Control: demonstrates the store model is faithful — the OLD
        // approach (store raw gzcompress) is truncated to an
        // unrecoverable stub by the very same utf8mb4 column.
        $blob = gzcompress( serialize( $this->sampleDiff() ), 6 );
        $this->assertNotFalse( $blob );

        set_transient( 'legacy_raw', $blob );
        $readBack = get_transient( 'legacy_raw' );

        $this->assertNotSame( $blob, $readBack, 'raw binary must be truncated by a utf8mb4 column' );
        $this->assertFalse( @gzuncompress( (string) $readBack ), 'truncated raw blob is unrecoverable' );
    }

    public function testClearRemovesEntry(): void
    {
        RunCache::set( 9, [], 1, $this->sampleDiff() );
        $this->assertNotNull( RunCache::get( 9 ) );

        RunCache::clear( 9 );
        $this->assertNull( RunCache::get( 9 ) );
    }

    public function testMissReturnsNull(): void
    {
        $this->assertNull( RunCache::get( 4242 ) );
    }

    /**
     * The `missing` bucket must survive the round-trip.
     *
     * Resumed ticks rebuild the processing queue from the cached Diff and
     * index into it POSITIONALLY. The sweep items sit at the head of that
     * queue, so a cache that drops them hands tick 2 a queue shorter than
     * the one tick 1's cursor was minted against: the retires are skipped
     * and the run closes 'done' having hidden nothing — the original bug,
     * reintroduced through the back door.
     */
    public function testMissingBucketSurvivesTheRoundTrip(): void
    {
        $diff = new Diff(
            new:         [],
            update:      [],
            unchanged:   [],
            updateStock: [],
            missing:     [
                new FeedItem( sku: 'GONE-1', data: [ '_existing_id' => 11, '_hsync_sweep_action' => 'retire' ] ),
                new FeedItem( sku: 'BACK-1', data: [ '_existing_id' => 22, '_hsync_sweep_action' => 'restore' ] ),
            ],
        );

        RunCache::set( 77, [], 0, $diff, 0, [ 'retire_missing' => true, 'retire_mode' => 'hidden' ] );
        $got = RunCache::get( 77 );

        $this->assertNotNull( $got );
        $this->assertCount( 2, $got['diff']->missing );
        $this->assertSame( 'GONE-1', $got['diff']->missing[0]->sku );
        $this->assertSame( 22, $got['diff']->missing[1]->data['_existing_id'] );
        // The flags the resume compares against to decide whether the
        // cached queue still matches the options it was asked for.
        $this->assertTrue( $got['retire_missing'] );
        $this->assertSame( 'hidden', $got['retire_mode'] );
    }
}
