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
        // Reset the in-memory transient store between tests.
        $store = &hsync_test_transient_store();
        $store = [];
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
}
