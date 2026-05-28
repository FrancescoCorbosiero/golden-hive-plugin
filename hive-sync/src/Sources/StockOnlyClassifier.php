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
     * Per-split() diagnostic snapshot. Populated by split() so the
     * runner can surface to Storico / the run report WHY the
     * classifier sent items to updateFull instead of unchanged /
     * updateStock — turns "always updateFull" black-box behaviour into
     * "10666 items routed to updateFull because of: description
     * (8000), name (2000), status (666)" with a handful of example
     * SKUs + the actual feed vs Woo values that diverged.
     *
     * Shape: [
     *   'reasons'  => string $field => int $count,
     *   'examples' => array<int, array{sku:string,field:string,feed:string,woo:string,reason:string}>
     * ]
     *
     * Reset at the start of each split() call.
     */
    public static array $lastDiagnostics = [ 'reasons' => [], 'examples' => [] ];

    private const EXAMPLE_CAP = 10;

    /**
     * Comparable scalar, non-stock feed fields. A mutation in any of
     * these routes the row to updateFull. Other feed keys are ignored
     * (treated as unknown — the classifier doesn't have an opinion).
     * Names mirror the keys ParentScalarLookup exposes so the
     * classifier reads them straight off the pre-loaded snapshot.
     */
    private const COMPARABLE_FIELDS = [ 'name', 'sku', 'description', 'short_description', 'status' ];

    /**
     * Three-way classification. $variationLookup + $parentTermSlugs +
     * $parentScalars are the outputs of VariationLookup +
     * ParentScalarLookup — together they let the classifier do its
     * job without a single wc_get_product() hydration per item.
     *
     * @param array<int, array<string, array{regular_price:string, sale_price:string, stock:?int, stock_status:string}>> $variationLookup
     * @param array<int, array<string, true>> $parentTermSlugs
     * @param array<int, array{name:string,description:string,short_description:string,status:string,sku:string,regular_price:string,sale_price:string,stock:?int,stock_status:string,is_variable:bool}> $parentScalars
     * @return string 'unchanged' | 'updateStock' | 'updateFull'
     */
    public static function classify(
        FeedItem $item,
        array $variationLookup = [],
        array $parentTermSlugs = [],
        array $parentScalars   = [],
        ?array &$reasonOut = null,
    ): string {
        // diff() stamps _existing_id on every item it routes here, so
        // trust that and skip the per-item wc_get_product_id_by_sku
        // roundtrip. Fallback to the SKU lookup only when missing
        // (back-compat for callers that build a FeedItem by hand —
        // e.g. the isStockOnlyChange() shim).
        $pid = (int) ( $item->data['_existing_id'] ?? 0 );
        if ( $pid <= 0 ) {
            if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
                $reasonOut = [ 'field' => '_no_woo_runtime', 'feed' => '', 'woo' => '' ];
                return 'updateFull';
            }
            $pid = (int) \wc_get_product_id_by_sku( $item->sku );
            if ( $pid <= 0 ) {
                $reasonOut = [ 'field' => '_sku_lookup_miss', 'feed' => $item->sku, 'woo' => '' ];
                return 'updateFull';
            }
        }

        // Pre-loaded scalar snapshot keeps the per-item path to zero
        // wc_get_product() hydrations — the difference between this
        // running for 10k items in <1s and blowing the 25s deadline.
        // Fallback hydration only fires on a real lookup miss (the
        // parent vanished between split() and here, or a caller
        // didn't pass the lookup at all).
        $scalar = $parentScalars[ $pid ] ?? null;
        if ( $scalar === null ) {
            if ( ! function_exists( 'wc_get_product' ) ) {
                $reasonOut = [ 'field' => '_no_woo_runtime', 'feed' => '', 'woo' => '' ];
                return 'updateFull';
            }
            $product = \wc_get_product( $pid );
            if ( ! $product ) {
                $reasonOut = [ 'field' => '_wc_get_product_null', 'feed' => '', 'woo' => '' ];
                return 'updateFull';
            }
            $scalar = self::scalarFromProduct( $product );
        }

        // Phase 1: scalar non-stock comparison. Any mismatch → full
        // update, no point in checking stock (the full pipeline writes
        // everything). Both sides go through normalizeFor() so
        // round-trip artifacts (wp_kses_post stripping disallowed
        // tags on save, CRLF→LF normalization, leading/trailing
        // whitespace) don't surface as phantom diffs. Without this
        // every SF item ends up in updateFull on every run because
        // get_description returns the wp_kses_post'd form while the
        // feed carries the raw form.
        foreach ( $item->data as $k => $v ) {
            if ( in_array( $k, self::STOCK_KEYS, true ) ) continue;
            if ( ! is_scalar( $v ) ) continue;
            if ( ! in_array( $k, self::COMPARABLE_FIELDS, true ) ) continue;
            $cur = $scalar[ $k ] ?? '';
            if ( ! is_scalar( $cur ) ) continue;
            $feedNorm = self::normalizeFor( (string) $k, (string) $v );
            $wooNorm  = self::normalizeFor( (string) $k, (string) $cur );
            if ( $feedNorm !== $wooNorm ) {
                // Surface the actual divergence: which field, what the
                // feed had, what Woo has. split() aggregates these into
                // a histogram + a few examples so the operator can see
                // exactly why "every item is updateFull" instead of
                // guessing — the original SF bug was a single
                // normalization mismatch on `description` that masked
                // itself as a generic "diff says full update".
                $reasonOut = [
                    'field' => (string) $k,
                    'feed'  => self::truncate( $feedNorm ),
                    'woo'   => self::truncate( $wooNorm ),
                ];
                return 'updateFull';
            }
        }

        // Phase 2: stock/price comparison. Variable vs simple branches
        // on the type read from the pre-loaded scalar snapshot, so we
        // never hydrate the WC_Product just to call is_type().
        if ( $scalar['is_variable'] ) {
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
                    $reasonOut = [ 'field' => '_new_variation_sku', 'feed' => $vsku, 'woo' => '' ];
                    return 'updateFull';
                }
                if ( ! self::variationMatches( $var_data, $existing ) ) {
                    return 'updateStock';
                }
            }
            // Size discontinued upstream — Woo has a variation that the
            // feed no longer carries. The SF bridge's full-update path
            // zeroes orphan-variation stock; the fast-patch path doesn't.
            // Route to updateFull so the zeroing fires.
            foreach ( $existingMap as $existingSku => $_ ) {
                if ( ! isset( $incomingSkus[ $existingSku ] ) ) {
                    $reasonOut = [ 'field' => '_orphan_variation_in_woo', 'feed' => '', 'woo' => $existingSku ];
                    return 'updateFull';
                }
            }

            // Broken-parent detection: the parent's pa_taglia term set
            // must cover every incoming size, otherwise Woo can't link
            // variations to the parent and renders them as "Qualsiasi
            // Taglia" (admin) / hidden (storefront). Route to updateFull
            // so the bridge re-writes the parent's _product_attributes
            // meta + term set.
            //
            // Comparison is slug-based (sanitize_title) because the
            // term taxonomy is what Woo uses internally for linking.
            // For pure-integer sizes sanitize_title is a no-op; for
            // sizes with spaces / decimals / letters the slug form
            // ("38 2/3" → "38-2-3") is the one stored.
            $parentSlugSet = $parentTermSlugs[ $pid ] ?? null;
            if ( is_array( $parentSlugSet ) ) {
                foreach ( $variations as $var_data ) {
                    if ( ! is_array( $var_data ) ) continue;
                    $attrs = $var_data['attributes'] ?? [];
                    if ( ! is_array( $attrs ) ) continue;
                    foreach ( $attrs as $taxKey => $value ) {
                        // Only enforce coverage for the variation
                        // attribute. Other pa_* slots are facet-only
                        // and don't gate variation linking.
                        if ( ! is_string( $taxKey ) || $taxKey !== 'pa_taglia' ) continue;
                        $expected = function_exists( 'sanitize_title' )
                            ? \sanitize_title( (string) $value )
                            : strtolower( (string) $value );
                        if ( $expected === '' ) continue;
                        if ( ! isset( $parentSlugSet[ $expected ] ) ) {
                            $reasonOut = [
                                'field' => '_pa_taglia_missing_on_parent',
                                'feed'  => $expected,
                                'woo'   => implode( ',', array_slice( array_keys( $parentSlugSet ), 0, 5 ) ),
                            ];
                            return 'updateFull';
                        }
                    }
                }
            }
            return 'unchanged';
        }

        // Simple product: compare parent-level price + stock fields
        // against the pre-loaded snapshot.
        return self::simpleMatches( $item->data, $scalar ) ? 'unchanged' : 'updateStock';
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
        // Circuit breaker. Default 500 because in production this
        // classifier blows the 25s tick budget for large catalogs:
        // ParentScalarLookup pulls every parent's post_content
        // (descriptions can be multi-KB on SF — Italian copy with
        // HTML) and classify() runs wp_kses_post on BOTH sides per
        // item to normalize the wp_filter_post_kses round-trip. That
        // wp_kses_post pass is the per-item bottleneck — 10k items ×
        // ~1ms each ≈ 10s+ just for normalization, before
        // ParentScalarLookup's LEFT-JOIN-heavy SQL even runs. Cron
        // (default PHP 30s timeout, ~256MB memory) dies during diff
        // for catalogs that big; the import never advances past
        // tick 1 (the symptom: cron stays in CONTINUE forever,
        // sometimes leaves no usable run row at all because the
        // fatal happens before finish() can record it).
        //
        // At the 500-item threshold the classifier short-circuits to
        // all-full — large catalogs go straight through the full
        // pipeline like they did before the 3-way diff fix. Slower
        // (every existing SKU re-routes through the bridge), but
        // KNOWN-GOOD: the bridge's gh_sf_update_product /
        // rp_rc_gs_update_product paths are idempotent, so the
        // re-syncs do no net harm. Smaller catalogs (≤500) still
        // benefit from the 3-way classifier (unchanged / stock /
        // full), which is the optimization's sweet spot anyway —
        // that's where the wc_get_product hydration overhead used
        // to bite.
        //
        // Operators with measurably fast normalization (object
        // cache, smaller descriptions) can raise the threshold via
        // the filter.
        $threshold = (int) apply_filters( 'hive_sync/diff/stock_classifier_threshold', 500 );
        if ( $threshold > 0 && count( $update ) > $threshold ) {
            return [ array_values( array_filter( $update, fn( $i ) => $i instanceof FeedItem ) ), [], [] ];
        }

        // Three batched lookups in ~four SQLs total — parent scalars
        // + product_type, variation meta, parent pa_taglia term set.
        // Together these eliminate every WC_Product hydration the
        // classifier used to do per item.
        $parentIds = [];
        foreach ( $update as $item ) {
            if ( ! $item instanceof FeedItem ) continue;
            $pid = (int) ( $item->data['_existing_id'] ?? 0 );
            if ( $pid > 0 ) $parentIds[] = $pid;
        }
        $parentScalars    = ParentScalarLookup::load( $parentIds );
        $variationLookup  = VariationLookup::mapParentsToVariations( $parentIds );
        $parentTermSlugs  = VariationLookup::loadParentTaxonomyTermSlugs( $parentIds, 'pa_taglia' );

        // Reset diagnostics for this split() call so the runner reads
        // a fresh histogram per diff invocation.
        self::$lastDiagnostics = [ 'reasons' => [], 'examples' => [] ];

        $full = $stock = $unchanged = [];
        $deadlineHit = false;
        foreach ( $update as $item ) {
            if ( ! $item instanceof FeedItem ) continue;
            if ( $deadlineHit ) {
                $full[] = $item;
                self::recordReason( '_classifier_deadline_hit', '', '', $item );
                continue;
            }
            if ( $ctx !== null && $ctx->isOverDeadline() ) {
                $deadlineHit = true;
                $full[] = $item;
                self::recordReason( '_classifier_deadline_hit', '', '', $item );
                continue;
            }
            $reason  = null;
            $verdict = self::classify( $item, $variationLookup, $parentTermSlugs, $parentScalars, $reason );
            if ( $verdict === 'unchanged' )       $unchanged[] = $item;
            elseif ( $verdict === 'updateStock' ) $stock[]     = $item;
            else {
                $full[] = $item;
                if ( is_array( $reason ) ) {
                    self::recordReason(
                        (string) ( $reason['field'] ?? '_unknown' ),
                        (string) ( $reason['feed']  ?? '' ),
                        (string) ( $reason['woo']   ?? '' ),
                        $item,
                    );
                }
            }
        }
        return [ $full, $stock, $unchanged ];
    }

    /**
     * Aggregate one updateFull reason into the diagnostic snapshot.
     * Counts every occurrence by field name; captures the first
     * EXAMPLE_CAP examples with the actual (truncated) feed vs Woo
     * values so the operator can see the divergence without enabling
     * per-item logging.
     */
    private static function recordReason( string $field, string $feed, string $woo, FeedItem $item ): void
    {
        $field = $field === '' ? '_unknown' : $field;
        self::$lastDiagnostics['reasons'][ $field ] = ( self::$lastDiagnostics['reasons'][ $field ] ?? 0 ) + 1;
        if ( count( self::$lastDiagnostics['examples'] ) < self::EXAMPLE_CAP ) {
            self::$lastDiagnostics['examples'][] = [
                'sku'   => $item->sku,
                'field' => $field,
                'feed'  => self::truncate( $feed ),
                'woo'   => self::truncate( $woo ),
            ];
        }
    }

    private static function truncate( string $value, int $max = 120 ): string
    {
        if ( strlen( $value ) <= $max ) return $value;
        return substr( $value, 0, $max - 1 ) . '…';
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
     * Simple-product field-by-field comparison against the pre-loaded
     * scalar snapshot. Returns true when feed and Woo are byte-equal
     * across all four stock-tier fields.
     *
     * @param array<string, mixed> $data
     * @param array{regular_price:string,sale_price:string,stock:?int,stock_status:string} $scalar
     */
    private static function simpleMatches( array $data, array $scalar ): bool
    {
        if ( array_key_exists( 'regular_price', $data ) ) {
            if ( ! self::priceEquals( (string) $data['regular_price'], (string) $scalar['regular_price'] ) ) {
                return false;
            }
        }
        if ( array_key_exists( 'sale_price', $data ) ) {
            if ( ! self::priceEquals( (string) $data['sale_price'], (string) $scalar['sale_price'] ) ) {
                return false;
            }
        }
        if ( array_key_exists( 'stock_quantity', $data ) ) {
            $a = (int) $data['stock_quantity'];
            $b = $scalar['stock'] === null ? -1 : (int) $scalar['stock'];
            if ( $a !== $b ) return false;
        }
        if ( array_key_exists( 'stock_status', $data ) ) {
            if ( (string) $data['stock_status'] !== (string) $scalar['stock_status'] ) return false;
        }
        return true;
    }

    /**
     * Build a scalar snapshot for the cache-miss fallback path —
     * mirrors the shape ParentScalarLookup::load() produces so the
     * classifier has one code path regardless of where the snapshot
     * came from.
     *
     * @return array{name:string,description:string,short_description:string,status:string,sku:string,regular_price:string,sale_price:string,stock:?int,stock_status:string,is_variable:bool}
     */
    private static function scalarFromProduct( \WC_Product $product ): array
    {
        $stockRaw = $product->get_stock_quantity();
        return [
            'name'              => (string) $product->get_name(),
            'description'       => (string) $product->get_description(),
            'short_description' => (string) $product->get_short_description(),
            'status'            => (string) $product->get_status(),
            'sku'               => (string) $product->get_sku(),
            'regular_price'     => (string) $product->get_regular_price(),
            'sale_price'        => (string) $product->get_sale_price(),
            'stock'             => $stockRaw === null ? null : (int) $stockRaw,
            'stock_status'      => (string) $product->get_stock_status(),
            'is_variable'       => $product->is_type( 'variable' ),
        ];
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
