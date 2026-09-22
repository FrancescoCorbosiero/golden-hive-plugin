<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

/**
 * Applies (and undoes) the retire decision MissingSweeper makes.
 *
 * ─── Reversible by construction ─────────────────────────────────────
 *
 * Before the first write on a product, the pre-retire shape (post
 * status, catalog visibility, parent stock status) is snapshotted into
 * `_hsync_missing_prev`. restore() replays it and clears the markers.
 * Nothing here deletes, trashes, or unpublishes irreversibly — a wrong
 * retire costs one sync to undo, which is what makes it safe to run on
 * a cron every two hours.
 *
 * Variation stock quantities are deliberately NOT snapshotted. They are
 * the one thing the feed rewrites on sight: the run that restores a
 * relisted SKU also re-stocks it from the supplier's current numbers,
 * and replaying the quantities a delisted product had weeks ago would
 * briefly publish stock that doesn't exist. Zero is the honest value
 * for a product the supplier isn't listing.
 *
 * ─── Variable products ──────────────────────────────────────────────
 *
 * Setting `stock_status` on a variable parent is theatre: WC recomputes
 * it from the children on the next sync, and the storefront reads the
 * children anyway. So the children are zeroed first and the parent is
 * synced after — the same shape fastStockPatch uses, for the same
 * reason.
 *
 * ─── Idempotence ────────────────────────────────────────────────────
 *
 * Every write is conditional on the current value differing. A product
 * already in the target shape costs reads and no writes, so a cron that
 * sweeps a settled catalog every two hours doesn't churn post_modified
 * (which would reshuffle "latest products" listings) or invalidate
 * caches for nothing.
 */
final class ProductRetirer
{
    public const MISSING_SINCE = '_hsync_missing_since';
    public const MISSING_MODE  = '_hsync_missing_mode';
    public const MISSING_PREV  = '_hsync_missing_prev';

    /**
     * Hide a product that the feed stopped listing.
     *
     * @param string $mode 'outofstock' | 'hidden' | 'draft'
     * @return array{ok: bool, changed: bool, error?: string}
     */
    public static function retire( int $pid, string $mode ): array
    {
        if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
            return [ 'ok' => false, 'changed' => false, 'error' => 'wc_unavailable' ];
        }
        $product = \wc_get_product( $pid );
        if ( ! $product ) {
            return [ 'ok' => false, 'changed' => false, 'error' => 'product_not_found' ];
        }

        $mode = MissingSweeper::normalizeMode( $mode );

        // Snapshot once, on the FIRST retire only. Re-snapshotting on a
        // mode change would capture the already-retired shape and make
        // restore a no-op — the product would stay hidden forever with
        // a marker claiming it was restorable.
        if ( (string) \get_post_meta( $pid, self::MISSING_PREV, true ) === '' ) {
            \update_post_meta( $pid, self::MISSING_PREV, \wp_json_encode( [
                'status'     => (string) $product->get_status(),
                'visibility' => (string) $product->get_catalog_visibility(),
            ] ) );
        }

        $changed = self::zeroStock( $product, $pid );

        // Catalog visibility: 'hidden' and 'draft' both take the product
        // off the shop loop. Keeping it explicit for draft too means a
        // later republish-by-hand doesn't silently resurface a product
        // the feed still isn't listing.
        if ( $mode === 'hidden' || $mode === 'draft' ) {
            if ( $product->get_catalog_visibility() !== 'hidden' ) {
                $product->set_catalog_visibility( 'hidden' );
                $changed = true;
            }
        }
        if ( $mode === 'draft' && $product->get_status() !== 'draft' ) {
            $product->set_status( 'draft' );
            $changed = true;
        }

        if ( $changed ) {
            $product->save();
        }

        // Markers are written even on a no-op save: they're what tells
        // the next sweep "already handled, under this mode" and what
        // restore keys on. A retire whose markers didn't land would be
        // redone every single run.
        \update_post_meta( $pid, self::MISSING_SINCE, \current_time( 'mysql' ) );
        \update_post_meta( $pid, self::MISSING_MODE, $mode );

        if ( $product->is_type( 'variable' ) && class_exists( '\\WC_Product_Variable' ) ) {
            \WC_Product_Variable::sync( $pid );
        }

        return [ 'ok' => true, 'changed' => $changed ];
    }

    /**
     * Undo a retire: the SKU is back in the feed.
     *
     * Stock is deliberately left alone — the import pass that follows in
     * this same run writes the supplier's current quantities. Restoring
     * visibility is this method's whole job.
     *
     * @return array{ok: bool, changed: bool, error?: string}
     */
    public static function restore( int $pid ): array
    {
        if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
            return [ 'ok' => false, 'changed' => false, 'error' => 'wc_unavailable' ];
        }
        $product = \wc_get_product( $pid );
        if ( ! $product ) {
            // The product is gone (deleted by hand between runs). Clear
            // the markers so the sweep stops reporting it every run.
            self::clearMarkers( $pid );
            return [ 'ok' => false, 'changed' => false, 'error' => 'product_not_found' ];
        }

        $prev = \get_post_meta( $pid, self::MISSING_PREV, true );
        $prev = is_string( $prev ) && $prev !== '' ? json_decode( $prev, true ) : null;
        if ( ! is_array( $prev ) ) $prev = [];

        // Fall back to the sane published shape rather than refusing to
        // restore: a lost snapshot must not leave a product the supplier
        // is actively selling stuck as a hidden draft.
        $status     = (string) ( $prev['status'] ?? 'publish' );
        $visibility = (string) ( $prev['visibility'] ?? 'visible' );
        if ( ! in_array( $status, [ 'publish', 'private', 'draft', 'pending', 'future' ], true ) ) {
            $status = 'publish';
        }
        if ( ! in_array( $visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
            $visibility = 'visible';
        }

        $changed = false;
        if ( $product->get_status() !== $status ) {
            $product->set_status( $status );
            $changed = true;
        }
        if ( $product->get_catalog_visibility() !== $visibility ) {
            $product->set_catalog_visibility( $visibility );
            $changed = true;
        }
        if ( $changed ) {
            $product->save();
        }

        self::clearMarkers( $pid );

        return [ 'ok' => true, 'changed' => $changed ];
    }

    /**
     * Zero the sellable stock. Variations first for variable products —
     * the parent's own stock_status is recomputed from them.
     */
    private static function zeroStock( \WC_Product $product, int $pid ): bool
    {
        $changed = false;

        if ( $product->is_type( 'variable' ) ) {
            $children = array_map( 'intval', $product->get_children() );
            if ( $children && function_exists( '_prime_post_caches' ) ) {
                \_prime_post_caches( $children, false, true );
            }
            foreach ( $children as $cid ) {
                $v = \wc_get_product( $cid );
                if ( ! $v || ! $v->is_type( 'variation' ) ) continue;
                $touched = false;
                if ( $v->get_stock_status() !== 'outofstock' ) {
                    $v->set_stock_status( 'outofstock' );
                    $touched = true;
                }
                if ( $v->get_manage_stock() && (int) $v->get_stock_quantity() !== 0 ) {
                    $v->set_stock_quantity( 0 );
                    $touched = true;
                }
                if ( $touched ) {
                    $v->save();
                    $changed = true;
                }
            }
            // The parent must not carry its own stock — Woo aggregates.
            if ( $product->get_manage_stock() ) {
                $product->set_manage_stock( false );
                $changed = true;
            }
            if ( $product->get_stock_status() !== 'outofstock' ) {
                $product->set_stock_status( 'outofstock' );
                $changed = true;
            }
            return $changed;
        }

        if ( $product->get_stock_status() !== 'outofstock' ) {
            $product->set_stock_status( 'outofstock' );
            $changed = true;
        }
        if ( $product->get_manage_stock() && (int) $product->get_stock_quantity() !== 0 ) {
            $product->set_stock_quantity( 0 );
            $changed = true;
        }
        return $changed;
    }

    private static function clearMarkers( int $pid ): void
    {
        \delete_post_meta( $pid, self::MISSING_SINCE );
        \delete_post_meta( $pid, self::MISSING_MODE );
        \delete_post_meta( $pid, self::MISSING_PREV );
    }
}
