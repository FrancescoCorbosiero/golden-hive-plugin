<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Sources\OwnedProductLookup;

/**
 * The fifth bucket: products the feed STOPPED returning.
 *
 * ─── The hole this closes ───────────────────────────────────────────
 *
 * Every bucket in Diff is built by walking the feed: `new` is a feed row
 * with no product, `update` / `updateStock` are feed rows whose product
 * drifted, `unchanged` is a feed row that matches. All four are keyed on
 * "the feed mentioned this SKU". A SKU the supplier DELISTS is mentioned
 * by nothing, so it lands in no bucket, so no code path ever touches the
 * product again — it stays published, in stock, and orderable forever,
 * however many times the sync runs.
 *
 * That's the "hive-sync doesn't hide products that left the catalog"
 * failure: not a broken retire, but a retire that was never reachable.
 * The diff was blind on media (see MediaHealer) and blind on absence.
 * This is the absence half.
 *
 * ─── Why the sweep is set-difference and not "last seen" ────────────
 *
 * The obvious implementation stamps `_hsync_last_seen` on every item the
 * feed returns and retires whatever falls behind. It also writes ~5k meta
 * rows per run on a settled catalog — and "a stable feed produces zero
 * writes" is the property the whole bucket architecture exists to buy.
 * The set difference costs ONE extra query (OwnedProductLookup) and keeps
 * the steady state at zero writes.
 *
 * ─── The guards, and why each one is load-bearing ───────────────────
 *
 * A sweep is the only operation here that acts on the ABSENCE of data,
 * so every way the feed can under-report is a way to wipe a live catalog.
 * Hence:
 *
 *   - empty feed      → abort. A 200-with-empty-body, an auth cookie that
 *                       expired, a supplier maintenance window: all look
 *                       like "every product is gone".
 *   - ratio guard     → abort when more than `max_ratio` of the owned
 *                       catalog would go at once (default 35%). A feed
 *                       truncated halfway reads as a mass delisting; a
 *                       real delisting of a third of the catalog in one
 *                       run is rare enough to be worth a human look.
 *                       Skipped under `MIN_CATALOG_FOR_RATIO` products,
 *                       where small absolute churn is normal.
 *   - provenance      → only products this source demonstrably created.
 *                       See OwnedProductLookup.
 *
 * An abort is never silent: it returns a reason the runner surfaces as a
 * run warning. A sweep that quietly declines to run is indistinguishable
 * from the bug it was added to fix.
 *
 * ─── Restore is not optional ────────────────────────────────────────
 *
 * Suppliers relist. A retire with no inverse turns a two-day stockout
 * into a permanently dead product, and the operator finds out months
 * later. So the same pass that retires the newly-absent also restores
 * anything it previously retired whose SKU is back in the feed —
 * BEFORE the import writes to it, so the product is visible again in the
 * same run that re-stocks it.
 */
final class MissingSweeper
{
    /** Stamped on swept items so the runner can dispatch retire vs restore. */
    public const ACTION = '_hsync_sweep_action';

    public const ACTION_RETIRE  = 'retire';
    public const ACTION_RESTORE = 'restore';

    /** Fraction of the owned catalog that may be retired in one run. */
    public const DEFAULT_MAX_RATIO = 0.35;

    /** Below this many owned products the ratio guard doesn't apply. */
    public const MIN_CATALOG_FOR_RATIO = 20;

    /**
     * Decide what to retire and what to restore. Pure — no WordPress, no
     * SQL — so the whole guard matrix is unit-testable.
     *
     * @param string[] $feedSkus  Every SKU the fetch returned, BEFORE any
     *                            narrowing (options.skus / limit). The
     *                            comparison is only sound against the
     *                            complete feed.
     * @param array<int, array{sku: string, status: string, retired_since: string, retired_mode: string}> $owned
     *                            Catalog side, from OwnedProductLookup.
     * @param string $mode        Retire mode currently configured.
     * @param array{max_ratio?: float} $opts
     * @param bool   $retireEnabled false = restore-only pass. The sweep is
     *                            switched off, but products it hid
     *                            earlier must still come back when their
     *                            SKU returns, or turning the option off
     *                            strands them hidden forever.
     *
     * @return array{
     *   retire: FeedItem[], restore: FeedItem[], owned: int,
     *   aborted: bool, reason: string, ratio: float, would_retire: int
     * }
     */
    public static function decide(
        array $feedSkus,
        array $owned,
        string $mode,
        array $opts = [],
        bool $retireEnabled = true
    ): array {
        $mode     = self::normalizeMode( $mode );
        $maxRatio = isset( $opts['max_ratio'] ) ? (float) $opts['max_ratio'] : self::DEFAULT_MAX_RATIO;
        // A zero or negative threshold reads like "zero tolerance", not
        // "no limit" — and the arithmetic below would silently give it
        // the second meaning. Fall back to the default rather than
        // disarming the circuit breaker on a value the operator almost
        // certainly meant as the strictest setting. Disabling it is
        // spelled `1` (retire up to the whole catalog), which cannot be
        // misread.
        if ( $maxRatio <= 0 ) $maxRatio = self::DEFAULT_MAX_RATIO;

        $empty = [
            'retire'       => [],
            'restore'      => [],
            'owned'        => count( $owned ),
            'aborted'      => false,
            'reason'       => '',
            'ratio'        => 0.0,
            'would_retire' => 0,
        ];

        // Match case-insensitively: the fast-patch path already resolves
        // variation SKUs with a lowercase fallback because suppliers are
        // inconsistent about casing between exports. A casing flip must
        // not read as a delisting.
        $present = [];
        foreach ( $feedSkus as $sku ) {
            if ( ! is_string( $sku ) || $sku === '' ) continue;
            $present[ strtolower( $sku ) ] = true;
        }

        // Guard 1 — an empty feed is a broken fetch until proven
        // otherwise. "The supplier delisted their entire catalog" and
        // "the token expired" produce identical input here; only one of
        // them is worth acting on, and it isn't the common one.
        if ( ! $present ) {
            return [ 'aborted' => true, 'reason' => 'feed_empty' ] + $empty;
        }
        if ( ! $owned ) {
            return $empty;
        }

        $retire  = [];
        $restore = [];

        foreach ( $owned as $pid => $row ) {
            $pid = (int) $pid;
            $sku = (string) ( $row['sku'] ?? '' );
            if ( $pid <= 0 || $sku === '' ) continue;

            $inFeed       = isset( $present[ strtolower( $sku ) ] );
            $retiredSince = (string) ( $row['retired_since'] ?? '' );
            $retiredMode  = (string) ( $row['retired_mode'] ?? '' );

            if ( $inFeed ) {
                // Back from the dead. Only products WE retired are
                // restored — a draft the operator parked by hand has no
                // marker and is none of our business.
                if ( $retiredSince !== '' ) {
                    $restore[] = self::item( $sku, $pid, self::ACTION_RESTORE );
                }
                continue;
            }

            // Absent from the feed. Nothing to do when the sweep is
            // off — this pass is then running for the restores alone.
            if ( ! $retireEnabled ) {
                continue;
            }

            // Skip the ones already retired under
            // the mode in force — that's the steady state, and it must
            // cost zero writes. A mode CHANGE re-queues them, so
            // switching "solo out of stock" → "nascondi" converges on
            // the next run instead of applying only to future delistings.
            if ( $retiredSince !== '' && $retiredMode === $mode ) {
                continue;
            }
            $retire[] = self::item( $sku, $pid, self::ACTION_RETIRE );
        }

        $ratio = count( $owned ) > 0 ? count( $retire ) / count( $owned ) : 0.0;

        // Guard 2 — mass-delisting circuit breaker. Restores survive it:
        // making a product visible again is safe under every failure mode
        // this guard is defending against, and withholding them would
        // strand products the feed is actively selling.
        if (
            $maxRatio < 1
            && count( $owned ) >= self::MIN_CATALOG_FOR_RATIO
            && $ratio > $maxRatio
        ) {
            return [
                'retire'       => [],
                'restore'      => $restore,
                'owned'        => count( $owned ),
                'aborted'      => true,
                'reason'       => 'ratio_guard',
                'ratio'        => $ratio,
                'would_retire' => count( $retire ),
            ];
        }

        return [
            'retire'       => $retire,
            'restore'      => $restore,
            'owned'        => count( $owned ),
            'aborted'      => false,
            'reason'       => '',
            'ratio'        => $ratio,
            'would_retire' => count( $retire ),
        ];
    }

    /**
     * Production wiring: catalog side from SQL, feed side from the caller.
     *
     * @param string[]                 $feedSkus
     * @param array{max_ratio?: float} $opts
     * @return array{retire: FeedItem[], restore: FeedItem[], owned: int, aborted: bool, reason: string, ratio: float, would_retire: int}
     */
    public static function forProvenance(
        array $feedSkus,
        string $provenanceKey,
        string $mode,
        array $opts = [],
        bool $retireEnabled = true
    ): array {
        // The restore-only pass asks a much narrower question ("which of
        // the ones I hid are back?"), so it gets the narrow query. On a
        // store that never enabled the sweep it returns nothing and the
        // whole pass costs one indexed lookup.
        $owned = $retireEnabled
            ? OwnedProductLookup::forProvenance( $provenanceKey )
            : OwnedProductLookup::retiredForProvenance( $provenanceKey );

        return self::decide( $feedSkus, $owned, $mode, $opts, $retireEnabled );
    }

    /**
     * Fold the decision into the Diff's `missing` bucket. Restores come
     * first so a relisted product is visible again before the import
     * re-stocks it — the runner preserves this order into its queue.
     *
     * @param array{retire: FeedItem[], restore: FeedItem[]} $decision
     */
    public static function apply( Diff $diff, array $decision ): Diff
    {
        $missing = array_merge(
            array_values( $decision['restore'] ?? [] ),
            array_values( $decision['retire'] ?? [] )
        );
        if ( ! $missing ) return $diff;

        return new Diff(
            new:         $diff->new,
            update:      $diff->update,
            unchanged:   $diff->unchanged,
            updateStock: $diff->updateStock,
            missing:     $missing,
        );
    }

    /**
     * Retire modes, weakest to strongest:
     *
     *   outofstock  stock 0 + "esaurito". Still browsable, still indexed.
     *   hidden      the above + catalog_visibility=hidden: out of the
     *               shop loop and out of search, URL still resolves.
     *   draft       the above + post_status=draft: gone from the site.
     *
     * `hidden` is the default because it's what "oscurare un prodotto"
     * means to an operator — off the storefront — while keeping the
     * permalink alive for customers holding a link and for order history.
     */
    public static function normalizeMode( mixed $raw ): string
    {
        $mode = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
        return in_array( $mode, [ 'outofstock', 'hidden', 'draft' ], true )
            ? $mode
            : 'hidden';
    }

    private static function item( string $sku, int $pid, string $action ): FeedItem
    {
        return new FeedItem(
            sku:  $sku,
            data: [ '_existing_id' => $pid, self::ACTION => $action ],
            raw:  [],
        );
    }
}
