<?php
declare(strict_types=1);

namespace HiveSync\Sources;

use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\FeedItem;

/**
 * Decide whether an "update" item from a feed differs from the existing
 * Woo product ONLY in the price/stock fields. When yes, the item can
 * follow the fast-stock-patch path in ImportRunner (skips media,
 * taxonomy, full upsert).
 *
 * Conservative by design: when we can't determine the answer (SKU not
 * found in Woo, WC functions unavailable, comparison ambiguous) the
 * caller is expected to treat it as "full update" to avoid silent data
 * loss on description / category / etc. changes.
 */
final class StockOnlyClassifier
{
    /** Fields treated as "stock deltas" — changes here alone go to fast-patch. */
    public const STOCK_KEYS = [ 'regular_price', 'sale_price', 'stock_quantity', 'stock_status' ];

    /**
     * Comparable scalar, non-stock fields the bridge typically writes.
     * If a feed mutates one of these, the row is full-update territory.
     * Anything outside this allowlist is ignored (treated as unknown).
     *
     * Map: feed-key → WC_Product getter.
     */
    private const COMPARABLE_FIELDS = [
        'name'              => 'get_name',
        'sku'               => 'get_sku',
        'description'       => 'get_description',
        'short_description' => 'get_short_description',
        'status'            => 'get_status',
    ];

    public static function isStockOnlyChange( FeedItem $item ): bool
    {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) return false;
        $pid = \wc_get_product_id_by_sku( $item->sku );
        if ( ! $pid ) return false;
        $product = \wc_get_product( $pid );
        if ( ! $product ) return false;

        foreach ( $item->data as $k => $v ) {
            if ( in_array( $k, self::STOCK_KEYS, true ) ) continue;
            if ( ! is_scalar( $v ) ) continue;
            $getter = self::COMPARABLE_FIELDS[ $k ] ?? null;
            if ( $getter === null ) continue;
            $cur = $product->{$getter}();
            if ( ! is_scalar( $cur ) ) continue;
            if ( (string) $cur !== (string) $v ) return false;
        }
        return true;
    }

    /**
     * Convenience wrapper for sources whose backend diff returns one
     * flat `update` bucket — splits it into [updateFull, updateStock].
     *
     * Deadline-aware: each iteration calls wc_get_product() (full
     * meta hydration, ~10-30ms). On a 2k-update bucket that's 20-60s
     * — easy to exceed the diff's slice of the tick budget. When
     * isOverDeadline trips mid-loop, the remaining items default to
     * full-update (safe: full pipeline handles every change correctly;
     * we only LOSE the fast-patch optimization, never lose data).
     *
     * @param FeedItem[] $update
     * @return array{0: FeedItem[], 1: FeedItem[]}
     */
    public static function split( array $update, ?Context $ctx = null ): array
    {
        // Circuit breaker: when the update bucket is too large to
        // classify within ANY reasonable tick budget (wc_get_product
        // hydration at ~20ms × N items), short-circuit to all-full.
        // The threshold is a heuristic — large enough that routine
        // refresh runs (small update buckets) still get the
        // fast-patch optimization, small enough that first-time
        // imports against existing catalogs don't stall on the diff.
        // Configurable via filter for operators with measurably
        // faster wc_get_product (e.g. object-cache-backed).
        $threshold = (int) apply_filters( 'hive_sync/diff/stock_classifier_threshold', 500 );
        if ( $threshold > 0 && count( $update ) > $threshold ) {
            return [ array_values( array_filter( $update, fn( $i ) => $i instanceof FeedItem ) ), [] ];
        }

        $full = $stock = [];
        $deadlineHit = false;
        foreach ( $update as $item ) {
            if ( ! $item instanceof FeedItem ) continue;
            if ( $deadlineHit ) {
                $full[] = $item;
                continue;
            }
            // Check deadline before each WC hydration — the call itself
            // is the expensive bit, so checking after wouldn't help.
            if ( $ctx !== null && $ctx->isOverDeadline() ) {
                $deadlineHit = true;
                $full[] = $item;
                continue;
            }
            if ( self::isStockOnlyChange( $item ) ) $stock[] = $item;
            else                                    $full[]  = $item;
        }
        return [ $full, $stock ];
    }
}
