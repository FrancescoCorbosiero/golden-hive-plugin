<?php
declare(strict_types=1);

namespace HiveSync\KicksDb;

use HiveSync\Core\Repo\KicksDbCacheRepository;

/**
 * Refresh per-variant prices on KicksDB-tracked products via the
 * batch /stockx/prices endpoint (50 SKU per call).
 *
 * Mirrors the legacy gh_kicksdb_refresh_pricing flow but uses hive-sync
 * primitives end-to-end:
 *
 *   1. Pick candidate SKUs:  parent products in Woo that ALSO have a
 *      live (non-_miss) row in wp_hsync_kicksdb_cache. The cache is
 *      the natural "tracked" set — anything we've ever successfully
 *      enriched lives there.
 *
 *   2. Sort by `_hsync_kicksdb_last_price_sync` (NULLs first, oldest
 *      first) so each tick rotates through the catalog instead of
 *      starving the tail. Without rotation a job with max_per_tick=500
 *      on a 1000-SKU catalog would never reach SKUs 501-1000.
 *
 *   3. Batch /stockx/prices in chunks of 50, with 200ms gaps between
 *      chunks (Client::batchGetPrices handles this).
 *
 *   4. For each tracked SKU, extract per-EU-size lowest_ask filtered
 *      to `type === 'standard'` (skip express_shipping etc., per the
 *      legacy normalizer's GOTCHA), apply the tiered markup + VAT, and
 *      set_regular_price on each matching variation.
 *
 *   5. WC_Product_Variable::sync($pid) at the end of each SKU so
 *      Woo's parent price range reflects the variation update. Write
 *      _hsync_kicksdb_last_price_sync = now so this SKU rotates to the
 *      back of the queue.
 *
 * Idempotency: if the calculated new price matches the current variant
 * price within €0.01, the variant is left alone (no save() — avoids
 * needless post_modified churn). Also: the meta is touched even when
 * nothing changed, so a no-op SKU still moves down the priority list.
 *
 * Cooperative deadline: caller passes a Unix-timestamp deadline; the
 * loop yields status=continue when reached. The cron tick reschedules
 * on the next firing, picking up the next slice via the rotation
 * order.
 */
final class PriceRefresher
{
    public function __construct(
        private readonly Client $client,
        private readonly KicksDbCacheRepository $cache,
        private readonly MarkupCalculator $calc,
        private readonly string $market = 'IT',
    ) {}

    /**
     * Run one slice. Returns { status, processed, updated, unchanged,
     * skipped, errors, details }.
     */
    public function run( int $maxPerTick, int $deadlineUnixTs ): array
    {
        if ( ! $this->client->isConfigured() ) {
            return $this->emptyResult( 'skipped', 'no_api_key' );
        }
        if ( ! function_exists( 'wc_get_product' ) ) {
            return $this->emptyResult( 'failed', 'woo_unavailable' );
        }

        $candidates = $this->candidateRows( max( 50, $maxPerTick ) );
        if ( ! $candidates ) {
            return $this->emptyResult( 'done', 'no_tracked_skus' );
        }

        $skus    = array_values( array_unique( array_column( $candidates, 'sku' ) ) );
        $skuToId = [];
        foreach ( $candidates as $row ) {
            $skuToId[ (string) $row['sku'] ] = (int) $row['pid'];
        }

        $prices = $this->client->batchGetPrices( $skus );

        $processed = $updated = $unchanged = $skipped = $errors = 0;
        $details   = [];

        foreach ( $skus as $sku ) {
            if ( time() >= $deadlineUnixTs ) {
                // Cooperative yield. Remaining SKUs naturally get picked
                // up next tick because they still have the oldest
                // last_sync timestamp.
                return [
                    'status'    => 'continue',
                    'processed' => $processed,
                    'updated'   => $updated,
                    'unchanged' => $unchanged,
                    'skipped'   => $skipped,
                    'errors'    => $errors,
                    'remaining' => count( $skus ) - $processed,
                    'details'   => array_slice( $details, 0, 50 ),
                ];
            }
            $processed++;
            $pid = $skuToId[ $sku ] ?? 0;
            if ( $pid <= 0 ) {
                $skipped++;
                $details[] = [ 'sku' => $sku, 'action' => 'skipped', 'reason' => 'parent_not_found' ];
                continue;
            }
            if ( empty( $prices[ $sku ] ) ) {
                $skipped++;
                $details[] = [ 'sku' => $sku, 'pid' => $pid, 'action' => 'skipped', 'reason' => 'no_price_data' ];
                continue;
            }
            $result = $this->patchProduct( $sku, $pid, (array) $prices[ $sku ] );
            $details[] = $result;
            match ( $result['action'] ?? 'failed' ) {
                'updated'   => $updated++,
                'unchanged' => $unchanged++,
                'failed'    => $errors++,
                default     => $skipped++,
            };
        }

        return [
            'status'    => 'done',
            'processed' => $processed,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'remaining' => 0,
            'details'   => array_slice( $details, 0, 50 ),
        ];
    }

    /**
     * One-shot rebuild of cache health metrics. Useful for the
     * cockpit / KicksDB tile.
     */
    public function trackedCount(): int
    {
        return $this->cache->count();
    }

    /**
     * Patch one product's prices. Variable → per-variation by pa_taglia
     * match; Simple → parent regular_price set to the lowest standard
     * price across all sizes.
     *
     * @return array{sku:string, pid:int, action:string, applied?:int, reason?:string}
     */
    private function patchProduct( string $sku, int $pid, array $priceData ): array
    {
        $product = \wc_get_product( $pid );
        if ( ! $product ) {
            return [ 'sku' => $sku, 'pid' => $pid, 'action' => 'failed', 'reason' => 'wc_get_product_null' ];
        }

        // Simple product path — set parent regular_price.
        if ( ! $product->is_type( 'variable' ) ) {
            $best = $this->bestStandardPrice( $priceData );
            if ( $best <= 0 ) {
                return [ 'sku' => $sku, 'pid' => $pid, 'action' => 'skipped', 'reason' => 'no_standard_price' ];
            }
            $newPrice = $this->calc->calculate( $best );
            $current  = (float) $product->get_regular_price();
            $this->stampSync( $pid );
            if ( abs( $newPrice - $current ) < 0.01 ) {
                return [ 'sku' => $sku, 'pid' => $pid, 'action' => 'unchanged' ];
            }
            $product->set_regular_price( (string) $newPrice );
            $product->save();
            return [ 'sku' => $sku, 'pid' => $pid, 'action' => 'updated', 'applied' => 1 ];
        }

        // Variable product path — per-size patching.
        $perSize = $this->extractPerSizePrices( $priceData );
        if ( ! $perSize ) {
            $this->stampSync( $pid );  // rotate to back even on no-op
            return [ 'sku' => $sku, 'pid' => $pid, 'action' => 'skipped', 'reason' => 'no_standard_prices' ];
        }

        $applied = 0;
        foreach ( $product->get_children() as $vid ) {
            $v = \wc_get_product( $vid );
            if ( ! $v || ! $v->is_type( 'variation' ) ) continue;
            $size = (string) $v->get_attribute( 'pa_taglia' );
            if ( $size === '' || ! isset( $perSize[ $size ] ) ) continue;

            $newPrice = $this->calc->calculate( $perSize[ $size ] );
            if ( $newPrice <= 0 ) continue;

            $current = (float) $v->get_regular_price();
            if ( abs( $newPrice - $current ) < 0.01 ) continue;  // already at target

            $v->set_regular_price( (string) $newPrice );
            $v->save();
            $applied++;
        }

        if ( $applied > 0 && class_exists( '\WC_Product_Variable' ) ) {
            \WC_Product_Variable::sync( $pid );
        }
        $this->stampSync( $pid );
        return [
            'sku'     => $sku,
            'pid'     => $pid,
            'action'  => $applied > 0 ? 'updated' : 'unchanged',
            'applied' => $applied,
        ];
    }

    /**
     * Extract per-size lowest_ask. Filters to type='standard' (skip
     * express_shipping et al. per the legacy normalizer's GOTCHA) and
     * keeps MIN(price) per size.
     *
     * @return array<string, float>  size_eu → price
     */
    private function extractPerSizePrices( array $priceData ): array
    {
        $variants = $priceData['variants'] ?? $priceData['data']['variants'] ?? [];
        if ( ! is_array( $variants ) ) return [];
        $out = [];
        foreach ( $variants as $v ) {
            if ( ! is_array( $v ) ) continue;
            $type = (string) ( $v['type'] ?? 'standard' );
            if ( $type !== 'standard' ) continue;
            $size = (string) ( $v['size_eu'] ?? $v['size'] ?? '' );
            if ( $size === '' ) continue;
            $price = (float) ( $v['lowest_ask'] ?? $v['last_sale'] ?? $v['market_price'] ?? $v['price'] ?? 0 );
            if ( $price <= 0 ) continue;
            if ( ! isset( $out[ $size ] ) || $price < $out[ $size ] ) {
                $out[ $size ] = $price;
            }
        }
        return $out;
    }

    private function bestStandardPrice( array $priceData ): float
    {
        $perSize = $this->extractPerSizePrices( $priceData );
        return $perSize ? (float) min( $perSize ) : 0.0;
    }

    /**
     * Pick the next slice of SKUs to refresh — tracked in cache, exist
     * as parent products in Woo, sorted by oldest last-sync first so
     * we rotate through the catalog deterministically.
     *
     * @return array<int, array{sku:string, pid:int}>
     */
    private function candidateRows( int $limit ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $cacheTable = \hsync_table( 'kicksdb_cache' );

        $sql = "
            SELECT pm_sku.meta_value AS sku, p.ID AS pid
            FROM `$cacheTable` c
            INNER JOIN {$wpdb->postmeta} pm_sku
                    ON pm_sku.meta_key = '_sku'
                   AND pm_sku.meta_value = c.sku
            INNER JOIN {$wpdb->posts} p
                    ON p.ID = pm_sku.post_id
                   AND p.post_type = 'product'
                   AND p.post_status IN ('publish', 'draft', 'private')
            LEFT JOIN {$wpdb->postmeta} pm_sync
                    ON pm_sync.post_id = p.ID
                   AND pm_sync.meta_key = '_hsync_kicksdb_last_price_sync'
            WHERE c.market = %s
              AND c.payload NOT LIKE %s
            ORDER BY (pm_sync.meta_value IS NULL) DESC, pm_sync.meta_value ASC
            LIMIT %d
        ";
        // payload NOT LIKE '%\"_miss\":true%' — exclude negative cache.
        $rows = $wpdb->get_results(
            $wpdb->prepare( $sql, $this->market, '%"_miss":true%', $limit ),
            ARRAY_A,
        );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [ 'sku' => (string) $r['sku'], 'pid' => (int) $r['pid'] ];
        }
        return $out;
    }

    private function stampSync( int $pid ): void
    {
        update_post_meta( $pid, '_hsync_kicksdb_last_price_sync', current_time( 'mysql' ) );
    }

    /** @return array<string, mixed> */
    private function emptyResult( string $status, string $reason ): array
    {
        return [
            'status'    => $status,
            'reason'    => $reason,
            'processed' => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'remaining' => 0,
            'details'   => [],
        ];
    }
}
