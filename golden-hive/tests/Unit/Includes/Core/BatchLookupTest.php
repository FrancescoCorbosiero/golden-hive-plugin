<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Includes\Core;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../includes/core/batch-lookup.php';

/**
 * In-memory wpdb double: records every prepared query and serves canned
 * result rows in insertion order. The helpers under test rely on the SQL
 * ORDER BY for tie-breaking, so fixtures are provided pre-sorted exactly
 * as MySQL would return them — the PHP-side reduction logic (chunking,
 * dedup, first-row-wins, casing) is what these tests pin down.
 */
final class RecordingWpdb
{
    public string $prefix   = 'wp_';
    public string $posts    = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';

    /** @var array<int, array{sql: string, args: array}> */
    public array $queries = [];

    /** @var array<int, array<int, object>> canned result sets, one per get_results call */
    public array $resultSets = [];

    private int $call = 0;

    public function prepare( string $sql, ...$args ): array
    {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        return [ 'sql' => $sql, 'args' => $args ];
    }

    /** @param array{sql: string, args: array} $prepared */
    public function get_results( $prepared ): array
    {
        $this->queries[] = $prepared;
        return $this->resultSets[ $this->call++ ] ?? [];
    }
}

final class BatchLookupTest extends TestCase
{
    private mixed $previousWpdb = null;

    private function useWpdb( RecordingWpdb $wpdb ): RecordingWpdb
    {
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = $wpdb;
        return $wpdb;
    }

    protected function tearDown(): void
    {
        if ( $this->previousWpdb !== null ) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        } else {
            unset( $GLOBALS['wpdb'] );
        }
        parent::tearDown();
    }

    private static function row( string $sku, int $id ): object
    {
        return (object) [ 'sku' => $sku, 'product_id' => $id ];
    }

    public function testSkuMapReturnsRequestedCasingAndLowestId(): void
    {
        $wpdb = $this->useWpdb( new RecordingWpdb() );
        // Pre-sorted by product_id ASC, as the query's ORDER BY guarantees.
        // 'abc' requested lowercase, stored uppercase (ci collation match);
        // 'DUP' present twice — the lowest id must win deterministically.
        $wpdb->resultSets[0] = [
            self::row( 'ABC', 10 ),
            self::row( 'DUP', 11 ),
            self::row( 'DUP', 99 ),
            self::row( 'XYZ', 42 ),
        ];

        $map = gh_sku_to_id_map( [ 'abc', 'DUP', 'XYZ', 'missing', '', 'abc' ] );

        $this->assertSame( [ 'abc' => 10, 'DUP' => 11, 'XYZ' => 42 ], $map );
        $this->assertCount( 1, $wpdb->queries, 'un solo chunk → una sola query' );
        // Blanks and duplicates must not reach the IN() list.
        $this->assertSame( [ 'abc', 'DUP', 'XYZ', 'missing' ], $wpdb->queries[0]['args'] );
    }

    public function testSkuMapChunksAt500(): void
    {
        $wpdb = $this->useWpdb( new RecordingWpdb() );
        $skus = [];
        for ( $i = 0; $i < 501; $i++ ) {
            $skus[] = 'SKU-' . $i;
        }

        gh_sku_to_id_map( $skus );

        $this->assertCount( 2, $wpdb->queries );
        $this->assertCount( 500, $wpdb->queries[0]['args'] );
        $this->assertCount( 1, $wpdb->queries[1]['args'] );
    }

    public function testSkuMapEmptyInputMakesNoQueries(): void
    {
        $wpdb = $this->useWpdb( new RecordingWpdb() );
        $this->assertSame( [], gh_sku_to_id_map( [ '', '' ] ) );
        $this->assertCount( 0, $wpdb->queries );
    }

    public function testBatchGetMetaFirstRowWinsPerKey(): void
    {
        $wpdb = $this->useWpdb( new RecordingWpdb() );
        // Pre-sorted by meta_id ASC: the first stored value per (post, key)
        // wins — mirroring get_post_meta( $id, $key, true ) semantics.
        $wpdb->resultSets[0] = [
            (object) [ 'post_id' => 7, 'meta_key' => '_stock', 'meta_value' => '5' ],
            (object) [ 'post_id' => 7, 'meta_key' => '_stock', 'meta_value' => '999' ],
            (object) [ 'post_id' => 7, 'meta_key' => '_sale_price', 'meta_value' => '119' ],
            (object) [ 'post_id' => 9, 'meta_key' => '_stock', 'meta_value' => '0' ],
        ];

        $meta = gh_batch_get_meta( [ 7, 9, 7, 0 ], [ '_stock', '_sale_price' ] );

        $this->assertSame( '5', $meta[7]['_stock'] );
        $this->assertSame( '119', $meta[7]['_sale_price'] );
        $this->assertSame( '0', $meta[9]['_stock'] );
        $this->assertArrayNotHasKey( '_sale_price', $meta[9] );
        // Args: 2 deduped ids (0 filtered out) + 2 meta keys.
        $this->assertSame( [ 7, 9, '_stock', '_sale_price' ], $wpdb->queries[0]['args'] );
    }

    public function testBatchGetMetaChunksIdsAt1000(): void
    {
        $wpdb = $this->useWpdb( new RecordingWpdb() );
        $ids  = range( 1, 1001 );

        gh_batch_get_meta( $ids, [ '_sku' ] );

        $this->assertCount( 2, $wpdb->queries );
        // 1000 ids + key in the first chunk, 1 id + key in the second.
        $this->assertCount( 1001, array_filter(
            array_merge( $wpdb->queries[0]['args'], $wpdb->queries[1]['args'] ),
            'is_int'
        ) );
        $this->assertSame( '_sku', end( $wpdb->queries[0]['args'] ) );
        $this->assertSame( '_sku', end( $wpdb->queries[1]['args'] ) );
    }

    public function testBatchGetMetaEmptyInputsMakeNoQueries(): void
    {
        $wpdb = $this->useWpdb( new RecordingWpdb() );
        $this->assertSame( [], gh_batch_get_meta( [], [ '_sku' ] ) );
        $this->assertSame( [], gh_batch_get_meta( [ 1 ], [] ) );
        $this->assertCount( 0, $wpdb->queries );
    }
}
