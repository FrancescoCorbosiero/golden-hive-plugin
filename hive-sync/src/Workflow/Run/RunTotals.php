<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

/**
 * Whole-run result counters for a multi-tick import. Pure.
 *
 * Each ImportRunner tick counts only its own slice (created, updated, …)
 * — the Importa tab's JS sums them across ticks. Nothing summed them for
 * the wp_hsync_runs row: items_done and the stored summary were the LAST
 * tick's slice, so a 400-item run showed "Done 12" in the Storico and the
 * cockpit's "Ultimo import" counted one slice. The cursor now carries the
 * running totals (`cursor.totals`), so the row is whole-run on every tick
 * and a retried tick recomputes from the cursor it was given — it can't
 * double count.
 *
 * The envelope's `summary` stays per-tick: the JS keeps summing it.
 */
final class RunTotals
{
    /** Result counters: incremented per item, summed across ticks. */
    public const KEYS = [
        'created', 'updated', 'recreated', 'stock_patched',
        'skipped', 'failed', 'pre_blocked', 'post_blocked',
        'retired', 'restored',
    ];

    /** Counters that mean "a product was written" → items_done. */
    private const DONE_KEYS = [ 'created', 'updated', 'recreated', 'stock_patched', 'retired', 'restored' ];

    /**
     * Totals carried by a resume cursor (zeros on a fresh run).
     *
     * @param array<string, mixed>|null $cursor
     * @return array<string, int>
     */
    public static function fromCursor( ?array $cursor ): array
    {
        $carried = is_array( $cursor['totals'] ?? null ) ? $cursor['totals'] : [];
        $out = [];
        foreach ( self::KEYS as $k ) {
            $out[ $k ] = max( 0, (int) ( $carried[ $k ] ?? 0 ) );
        }
        return $out;
    }

    /**
     * @param array<string, int>   $carried
     * @param array<string, mixed> $tickSummary
     * @return array<string, int>
     */
    public static function add( array $carried, array $tickSummary ): array
    {
        $out = [];
        foreach ( self::KEYS as $k ) {
            $out[ $k ] = (int) ( $carried[ $k ] ?? 0 ) + (int) ( $tickSummary[ $k ] ?? 0 );
        }
        return $out;
    }

    /**
     * The tick's summary with its result counters replaced by the run
     * totals. Diff-level keys (fetched, new, update, …) pass through.
     * A counter absent from the summary is only added when non-zero, so
     * optional sections (the sweep's retired/restored) keep their shape.
     *
     * @param array<string, mixed> $summary
     * @param array<string, int>   $totals
     * @return array<string, mixed>
     */
    public static function apply( array $summary, array $totals ): array
    {
        foreach ( self::KEYS as $k ) {
            $v = (int) ( $totals[ $k ] ?? 0 );
            if ( array_key_exists( $k, $summary ) || $v !== 0 ) {
                $summary[ $k ] = $v;
            }
        }
        return $summary;
    }

    /**
     * @param array<string, int> $totals
     */
    public static function done( array $totals ): int
    {
        $n = 0;
        foreach ( self::DONE_KEYS as $k ) $n += (int) ( $totals[ $k ] ?? 0 );
        return $n;
    }
}
