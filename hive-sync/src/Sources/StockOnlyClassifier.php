<?php
declare(strict_types=1);

namespace HiveSync\Sources;

use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\FeedItem;

/**
 * Decide for each update-bucket item whether it's actually changed
 * vs the existing Woo product and, if so, whether the changes are
 * stock-only (→ fast-patch) or include catalog fields (→ full pipeline).
 *
 * Returns a 3-way verdict per item:
 *   - 'unchanged'   — feed values match Woo values; nothing to write
 *   - 'updateStock' — scalar fields match, price/stock differ
 *   - 'updateFull'  — at least one non-stock scalar field differs
 *
 * The `unchanged` case is what makes the diff idempotent: re-running
 * an import against an unmodified feed must produce zero writes. Prior
 * to this, every existing SKU bucketed into `update` regardless of
 * actual delta — re-runs reported 4 full / 435 stock updates on a
 * stable catalog and wrote no-op values back over themselves every
 * cycle. The fast-patch was a hidden idempotency hole.
 *
 * Conservative defaults: when we can't determine the answer (SKU not
 * found in Woo, WC functions unavailable, comparison ambiguous,
 * deadline tripped), the caller is expected to treat the item as
 * `updateFull` to avoid silent data loss.
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

    /**
     * Three-way classification. $variationLookup is the output of
     * VariationLookup::mapParentsToVariations() — needed to compare
     * per-variation stock without N×wc_get_product hydrations.
     *
     * @param array<int, array<string, array{regular_price:string, sale_price:string, stock:?int, stock_status:string}>> $variationLookup
     * @return string 'unchanged' | 'updateStock' | 'updateFull'
     */
    public static function classify(FeedItem $item, array $variationLookup = []): string
    {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) return 'updateFull';
        $pid = \wc_get_product_id_by_sku( $item->sku );
        if ( ! $pid ) return 'updateFull';
        $product = \wc_get_product( $pid );
        if ( ! $product ) return 'updateFull';

        // Phase 1: scalar non-stock comparison. Any mismatch → full update,
        // no point in checking stock (the full pipeline writes everything).
        // Both sides go through normalizeFor() so round-trip artifacts
        // (wp_kses_post stripping disallowed tags on save, CRLF→LF
        // normalization, leading/trailing whitespace) don't surface as
        // phantom diffs. Without this every SF item ends up in
        // updateFull on every run because get_description returns the
        // wp_kses_post'd form while the feed carries the raw form.
        foreach ( $item->data as $k => $v ) {
            if ( in_array( $k, self::STOCK_KEYS, true ) ) continue;
            if ( ! is_scalar( $v ) ) continue;
            $getter = self::COMPARABLE_FIELDS[ $k ] ?? null;
            if ( $getter === null ) continue;
            $cur = $product->{$getter}();
            if ( ! is_scalar( $cur ) ) continue;
            if ( self::normalizeFor( (string) $k, (string) $v ) !== self::normalizeFor( (string) $k, (string) $cur ) ) {
                return 'updateFull';
            }
        }

        // Phase 2: stock/price comparison. Differs from before — now we
        // actually check whether the feed differs from Woo, so a re-run
        // on a stable feed produces zero writes (the user-visible fix
        // for "4 full / 435 stock at every run with no actual diff").
        if ( $product->is_type( 'variable' ) ) {
            $variations = $item->data['variations'] ?? null;
            if ( ! is_array( $variations ) || $variations === [] ) {
                // Can't compare per-variation without the incoming
                // payload. Conservative: assume stock differs.
                return 'updateStock';
            }
            $existingMap = $variationLookup[ $pid ] ?? null;
            if ( $existingMap === null ) {
                // Lookup miss for this parent — fall back to wc_get_product
                // hydration per variation. Slower but correct, and rare
                // (only hit when the lookup ran before this parent existed
                // or the parent was just created mid-tick).
                $existingMap = self::loadVariationsViaWcFallback( $pid );
            }
            $incomingSkus = [];
            foreach ( $variations as $var_data ) {
                if ( ! is_array( $var_data ) ) continue;
                $vsku = (string) ( $var_data['sku'] ?? '' );
                if ( $vsku === '' ) continue;
                $incomingSkus[ $vsku ] = true;
                $existing = $existingMap[ $vsku ] ?? null;
                if ( $existing === null ) {
                    // New size in the feed that doesn't exist in Woo yet —
                    // fast-patch can't create variations, so this needs
                    // the full pipeline to spin up the new variation.
                    return 'updateFull';
                }
                if ( ! self::variationMatches( $var_data, $existing ) ) {
                    return 'updateStock';
                }
            }
            // Size discontinued upstream — Woo has a variation that the
            // feed no longer carries. The SF bridge's full-update path
            // zeroes orphan-variation stock (line 469 of feed-stockfirmati);
            // the fast-patch path doesn't. Route these to updateFull so
            // the zeroing fires. For GS the bridge has no zeroing logic
            // either way; routing to updateFull is a harmless no-op
            // (full pipeline re-writes the same variation state).
            foreach ( $existingMap as $existingSku => $_ ) {
                if ( ! isset( $incomingSkus[ $existingSku ] ) ) {
                    return 'updateFull';
                }
            }
            return 'unchanged';
        }

        // Simple product: compare parent-level fields directly.
        return self::simpleMatches( $item->data, $product ) ? 'unchanged' : 'updateStock';
    }

    /**
     * Convenience wrapper for sources whose backend diff returns one
     * flat `update` bucket — splits it into [updateFull, updateStock,
     * unchanged]. The 3-way return is the contract that makes the diff
     * idempotent: callers populate Diff->unchanged so re-runs on
     * stable feeds report zero work.
     *
     * Deadline-aware: when isOverDeadline trips mid-loop, the remaining
     * items default to updateFull (safe: full pipeline handles every
     * change correctly; we only LOSE the fast-patch + unchanged
     * optimization, never lose data).
     *
     * @param FeedItem[] $update
     * @return array{0: FeedItem[], 1: FeedItem[], 2: FeedItem[]} [full, stock, unchanged]
     */
    public static function split( array $update, ?Context $ctx = null ): array
    {
        // Circuit breaker: when the update bucket is too large to
        // classify within ANY reasonable tick budget, short-circuit
        // to all-full. The threshold is a heuristic — large enough
        // that routine refresh runs still get the optimization,
        // small enough that first-time imports don't stall.
        $threshold = (int) apply_filters( 'hive_sync/diff/stock_classifier_threshold', 500 );
        if ( $threshold > 0 && count( $update ) > $threshold ) {
            return [ array_values( array_filter( $update, fn( $i ) => $i instanceof FeedItem ) ), [], [] ];
        }

        // Batch-load every parent's variation meta in ONE SQL — replaces
        // N×wc_get_product() hydration. This is the perf win that makes
        // 3-way classification affordable for variable-product catalogs.
        $parentIds = [];
        foreach ( $update as $item ) {
            if ( ! $item instanceof FeedItem ) continue;
            $pid = (int) ( $item->data['_existing_id'] ?? 0 );
            if ( $pid > 0 ) $parentIds[] = $pid;
        }
        $variationLookup = VariationLookup::mapParentsToVariations( $parentIds );

        $full = $stock = $unchanged = [];
        $deadlineHit = false;
        foreach ( $update as $item ) {
            if ( ! $item instanceof FeedItem ) continue;
            if ( $deadlineHit ) {
                $full[] = $item;
                continue;
            }
            if ( $ctx !== null && $ctx->isOverDeadline() ) {
                $deadlineHit = true;
                $full[] = $item;
                continue;
            }
            $verdict = self::classify( $item, $variationLookup );
            if ( $verdict === 'unchanged' )       $unchanged[] = $item;
            elseif ( $verdict === 'updateStock' ) $stock[]     = $item;
            else                                  $full[]      = $item;
        }
        return [ $full, $stock, $unchanged ];
    }

    /**
     * Back-compat shim for any code path still calling the old
     * boolean classifier. New code should use classify().
     */
    public static function isStockOnlyChange( FeedItem $item ): bool
    {
        $verdict = self::classify( $item );
        // The old name meant "not a full update" — both unchanged and
        // updateStock satisfied that. Preserve the semantics so any
        // remaining caller doesn't accidentally route unchanged items
        // through the full pipeline.
        return $verdict !== 'updateFull';
    }

    /**
     * Simple-product field-by-field comparison. Returns true when feed
     * and Woo are byte-equal across all four stock-tier fields.
     */
    private static function simpleMatches( array $data, \WC_Product $product ): bool
    {
        if ( array_key_exists( 'regular_price', $data ) ) {
            if ( ! self::priceEquals( (string) $data['regular_price'], (string) $product->get_regular_price() ) ) {
                return false;
            }
        }
        if ( array_key_exists( 'sale_price', $data ) ) {
            if ( ! self::priceEquals( (string) $data['sale_price'], (string) $product->get_sale_price() ) ) {
                return false;
            }
        }
        if ( array_key_exists( 'stock_quantity', $data ) ) {
            $a = (int) $data['stock_quantity'];
            $b = $product->get_stock_quantity();
            $b = $b === null ? -1 : (int) $b;
            if ( $a !== $b ) return false;
        }
        if ( array_key_exists( 'stock_status', $data ) ) {
            if ( (string) $data['stock_status'] !== (string) $product->get_stock_status() ) return false;
        }
        return true;
    }

    /**
     * Per-variation comparison against a pre-loaded meta snapshot.
     * Returns true when nothing meaningful differs.
     *
     * @param array{regular_price?:mixed, sale_price?:mixed, stock_quantity?:mixed, stock_status?:mixed} $incoming
     * @param array{regular_price:string, sale_price:string, stock:?int, stock_status:string} $existing
     */
    private static function variationMatches( array $incoming, array $existing ): bool
    {
        if ( array_key_exists( 'regular_price', $incoming ) ) {
            if ( ! self::priceEquals( (string) $incoming['regular_price'], $existing['regular_price'] ) ) return false;
        }
        if ( array_key_exists( 'sale_price', $incoming ) ) {
            if ( ! self::priceEquals( (string) $incoming['sale_price'], $existing['sale_price'] ) ) return false;
        }
        if ( array_key_exists( 'stock_quantity', $incoming ) ) {
            $a = (int) $incoming['stock_quantity'];
            $b = $existing['stock'] === null ? -1 : (int) $existing['stock'];
            if ( $a !== $b ) return false;
        }
        if ( array_key_exists( 'stock_status', $incoming ) ) {
            if ( (string) $incoming['stock_status'] !== $existing['stock_status'] ) return false;
        }
        return true;
    }

    /**
     * Canonicalize a scalar field value so feed-side vs Woo-side
     * comparison ignores artifacts that don't represent a real change:
     *
     *   - Line endings normalized to \n (CSV upstreams often use CRLF;
     *     wp_insert_post can normalize on save but doesn't guarantee
     *     either form across versions).
     *   - Outer whitespace trimmed.
     *   - description / short_description go through wp_kses_post —
     *     matches what wp_filter_post_kses applies during save when
     *     the running context lacks unfiltered_html (the typical
     *     WP-Cron case). Both sides through the same filter means
     *     wp_kses_post(feed) === wp_kses_post(get_description()) when
     *     the input is semantically the same, regardless of which
     *     side was stored under which capability.
     *
     * Idempotent: normalize(normalize(x)) === normalize(x).
     */
    private static function normalizeFor( string $field, string $value ): string
    {
        $value = str_replace( [ "\r\n", "\r" ], "\n", $value );
        $value = trim( $value );
        if ( $field === 'description' || $field === 'short_description' ) {
            if ( function_exists( 'wp_kses_post' ) ) {
                $value = \wp_kses_post( $value );
                // wp_kses_post can leave trailing whitespace after
                // stripping disallowed tags — re-trim defensively
                // so a stripped <script> at end-of-string doesn't
                // leave a phantom space behind.
                $value = trim( $value );
            }
        }
        return $value;
    }

    /**
     * Tolerant numeric comparison for Woo price strings. "" === "" is
     * the no-sale case; one-side-empty is a real change; otherwise
     * compare as floats with sub-cent tolerance (Woo rounds at 2dp).
     */
    private static function priceEquals( string $a, string $b ): bool
    {
        $aEmpty = $a === '';
        $bEmpty = $b === '';
        if ( $aEmpty && $bEmpty ) return true;
        if ( $aEmpty !== $bEmpty ) return false;
        return abs( (float) $a - (float) $b ) < 0.005;
    }

    /**
     * Fallback: load variation meta via wc_get_product when the batch
     * lookup missed (rare). Returns the same shape as VariationLookup
     * so the caller doesn't need a special path.
     *
     * @return array<string, array{regular_price:string, sale_price:string, stock:?int, stock_status:string}>
     */
    private static function loadVariationsViaWcFallback( int $parentId ): array
    {
        $out = [];
        if ( ! function_exists( 'wc_get_product' ) ) return $out;
        $parent = \wc_get_product( $parentId );
        if ( ! $parent || ! $parent->is_type( 'variable' ) ) return $out;
        foreach ( $parent->get_children() as $vid ) {
            $v = \wc_get_product( (int) $vid );
            if ( ! $v || ! $v->is_type( 'variation' ) ) continue;
            $sku = (string) $v->get_sku();
            if ( $sku === '' ) continue;
            $stockRaw = $v->get_stock_quantity();
            $out[ $sku ] = [
                'regular_price' => (string) $v->get_regular_price(),
                'sale_price'    => (string) $v->get_sale_price(),
                'stock'         => $stockRaw === null ? null : (int) $stockRaw,
                'stock_status'  => (string) $v->get_stock_status(),
            ];
        }
        return $out;
    }
}
