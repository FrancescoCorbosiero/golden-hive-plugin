<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FetchResult;

/**
 * Per-run fetch+diff cache. Persists the expensive setup work across
 * ticks so the runner doesn't re-fetch the upstream feed + re-walk the
 * bucket diff on every cooperative-deadline cycle.
 *
 * Without this, a 10k-row SF feed pays ~5-10s of fetch+diff per tick
 * × ~200 ticks for the first import = ~30 minutes of pure repetition
 * that adds nothing to the result.
 *
 * Storage: gzcompressed WP transient (~5-10x compression on serialized
 * FeedItem arrays). 10MB raw → ~1-2MB compressed → fits comfortably in
 * the options table even without an object cache.
 *
 * Invalidation: terminal `done` / `failed` returns from ImportRunner
 * call clear(). Defensive 2-hour TTL covers the case where a run is
 * abandoned (browser closed, JS retry exhausted).
 *
 * Safety: the cache is keyed by the integer run_id from wp_hsync_runs,
 * which is unique per Runner::run() invocation. Two concurrent runs
 * (e.g. admin + cron) get separate cache entries. The cursor contract
 * (resume re-enters loop at the next-unprocessed index) means already-
 * processed items aren't double-touched even when their bucket
 * assignment is cached.
 */
final class RunCache
{
    private const TTL_SECONDS  = 7200; // 2h
    private const KEY_PREFIX   = 'hsync_run_cache_';

    /**
     * Hydrate a previously-stored fetch+diff. Returns null on miss
     * or any deserialization failure (treats corruption as cache miss,
     * NOT as fatal — the caller falls through to a fresh fetch+diff).
     *
     * @return array{warnings: array<int,string>, fetched_count: int, diff: Diff}|null
     */
    public static function get( int $runId ): ?array
    {
        if ( $runId <= 0 ) return null;
        $raw = \get_transient( self::KEY_PREFIX . $runId );
        if ( ! is_string( $raw ) || $raw === '' ) return null;

        $decoded = @gzuncompress( $raw );
        if ( $decoded === false ) return null;

        $payload = @unserialize( $decoded, [ 'allowed_classes' => true ] );
        if ( ! is_array( $payload ) ) return null;
        if ( ! isset( $payload['diff'] ) || ! $payload['diff'] instanceof Diff ) return null;

        return $payload;
    }

    /**
     * @param array<int, string> $warnings
     */
    /**
     * Last set() outcome — surfaced by the runner so a silent
     * set_transient() failure (transient too big for max_allowed_packet,
     * autoload-quota DB hiccup, object-cache backend rejecting the
     * write) doesn't masquerade as a clean re-fetch on the next tick.
     *
     * Without this the runner re-does fetch+diff every tick, trips the
     * 25s deadline before the loop body executes, yields cursor.index=0
     * forever — the "ticks fire, % stuck at 0" symptom.
     *
     * Shape: [
     *   'ok'             => bool,       set_transient() return value
     *   'compressed_kb'  => int,        compressed payload size
     *   'serialized_kb'  => int,        pre-gzip size (debug)
     * ]
     */
    public static array $lastSetResult = [ 'ok' => true, 'compressed_kb' => 0, 'serialized_kb' => 0 ];

    /**
     * @param array<int, string> $warnings
     */
    public static function set( int $runId, array $warnings, int $fetchedCount, Diff $diff ): bool
    {
        if ( $runId <= 0 ) return false;
        $payload = [
            'warnings'      => $warnings,
            'fetched_count' => $fetchedCount,
            'diff'          => $diff,
        ];
        $serialized = serialize( $payload );
        $compressed = @gzcompress( $serialized, 6 );
        if ( $compressed === false ) {
            self::$lastSetResult = [
                'ok'            => false,
                'compressed_kb' => 0,
                'serialized_kb' => (int) ( strlen( $serialized ) / 1024 ),
            ];
            return false;
        }
        $ok = (bool) \set_transient( self::KEY_PREFIX . $runId, $compressed, self::TTL_SECONDS );
        self::$lastSetResult = [
            'ok'            => $ok,
            'compressed_kb' => (int) ( strlen( $compressed ) / 1024 ),
            'serialized_kb' => (int) ( strlen( $serialized ) / 1024 ),
        ];
        return $ok;
    }

    public static function clear( int $runId ): void
    {
        if ( $runId <= 0 ) return;
        \delete_transient( self::KEY_PREFIX . $runId );
    }
}
