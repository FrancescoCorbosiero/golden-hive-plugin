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
 * Storage: base64(gzcompress(serialize)) WP transient (~5-10x
 * compression on serialized FeedItem arrays). 10MB raw → ~1-2MB
 * compressed → ~1.4-2.7MB base64 → fits in the options table even
 * without an object cache.
 *
 * Why base64 and not raw gzcompress output: without a persistent object
 * cache, transients live in `wp_options.option_value`, which on a modern
 * install is a utf8mb4 column. `$wpdb` runs every text value through
 * `strip_invalid_text`, which truncates at the first byte that isn't a
 * valid UTF-8 sequence. gzcompress output is binary and invalid UTF-8
 * from its SECOND byte (zlib header `0x78 0x9c…`), so the blob was
 * truncated to ~1 byte on write and `gzuncompress` failed on read —
 * `get()` returned null on EVERY resume tick. The cache silently never
 * persisted. That was harmless while the diff was cheap (each tick just
 * recomputed it within budget), but once a source grew an expensive
 * fetch+diff that can't fit one 25s tick (the bespoke SF diff loads the
 * whole catalog's variation+scalar snapshots), the runner could never
 * advance past item 0: every tick re-ran fetch+diff, tripped the
 * deadline, and processed nothing — the silent "0/N, 0%" run. Base64
 * keeps the stored value 7-bit ASCII so it survives intact.
 *
 * Invalidation: terminal `done` / `failed` returns from ImportRunner
 * call clear(). The TTL is an IDLE timeout: every resumed tick that
 * uses the entry calls touch(), so a run keeps its cache for as long as
 * it keeps ticking, and an abandoned one (browser closed, JS retry
 * exhausted, job deleted) is reclaimed 6h after its last tick.
 *
 * It used to be a fixed 2h from tick 1. Any run longer than that — the
 * nightly SF import, once the cron was chaining slices slowly — lost its
 * cache mid-run: the next tick re-fetched the multi-MB feed, re-ran the
 * whole-catalog SF diff, and restarted the queue from index 0.
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
    /** Idle timeout — extended by every touch(). */
    private const TTL_SECONDS  = 21600; // 6h
    private const KEY_PREFIX   = 'hsync_run_cache_';

    /**
     * Hydrate a previously-stored fetch+diff. Returns null on miss
     * or any deserialization failure (treats corruption as cache miss,
     * NOT as fatal — the caller falls through to a fresh fetch+diff).
     *
     * @return array{warnings: array<int,string>, fetched_count: int, diff: Diff, unchanged_count: int, heal_media?: bool, healed_media?: int}|null
     */
    public static function get( int $runId ): ?array
    {
        if ( $runId <= 0 ) return null;
        $raw = \get_transient( self::KEY_PREFIX . $runId );
        if ( ! is_string( $raw ) || $raw === '' ) return null;

        // Stored as base64(gzcompress(serialize)) so binary survives a
        // utf8mb4 transient (see class docblock). base64_decode(strict)
        // then gzuncompress. Tolerate the pre-fix raw-binary form too —
        // it only ever round-tripped on installs with a binary-safe
        // object cache — by falling back to a direct gzuncompress.
        $compressed = base64_decode( $raw, true );
        $decoded    = $compressed !== false ? @gzuncompress( $compressed ) : false;
        if ( $decoded === false ) {
            $decoded = @gzuncompress( $raw );
        }
        if ( $decoded === false ) return null;

        $payload = @unserialize( $decoded, [ 'allowed_classes' => true ] );
        if ( ! is_array( $payload ) ) return null;
        if ( ! isset( $payload['diff'] ) || ! $payload['diff'] instanceof Diff ) return null;

        // Blob scritto prima dello strip del bucket unchanged: il conteggio
        // vive ancora dentro il Diff.
        if ( ! isset( $payload['unchanged_count'] ) ) {
            $payload['unchanged_count'] = count( $payload['diff']->unchanged );
        }

        return $payload;
    }

    /**
     * @param array<int, string>   $warnings
     * @param int                  $unchangedCount Conteggio del bucket unchanged
     *                                             PRIMA dello strip (il Diff
     *                                             cachato lo porta vuoto).
     * @param array<string, mixed> $extra          Flag per-run che il resume deve
     *                                             ritrovare (es. heal_media). Non
     *                                             puo' sovrascrivere le chiavi
     *                                             canoniche.
     */
    public static function set( int $runId, array $warnings, int $fetchedCount, Diff $diff, int $unchangedCount = 0, array $extra = [] ): void
    {
        if ( $runId <= 0 ) return;
        $payload = [
            'warnings'        => $warnings,
            'fetched_count'   => $fetchedCount,
            'diff'            => $diff,
            'unchanged_count' => $unchangedCount,
        ] + $extra;
        $serialized = serialize( $payload );
        $compressed = @gzcompress( $serialized, 6 );
        if ( $compressed === false ) return; // defensive: fall back to no-cache mode
        // base64 so the binary survives a utf8mb4 transient column (see
        // class docblock — raw gzcompress output is truncated on write).
        \set_transient( self::KEY_PREFIX . $runId, base64_encode( $compressed ), self::TTL_SECONDS );
    }

    /**
     * Push the entry's expiry TTL_SECONDS into the future without
     * re-writing the payload.
     *
     * Without a persistent object cache a transient is two wp_options
     * rows, and expiry lives only in `_transient_timeout_<key>` — the
     * same row WordPress itself rewrites when set_transient() updates an
     * existing entry. Bumping it alone costs one tiny UPDATE per tick
     * instead of re-writing a multi-MB blob. With an object cache the
     * expiry is part of the cache item, so the payload is re-set as is.
     */
    public static function touch( int $runId ): void
    {
        if ( $runId <= 0 ) return;
        $key = self::KEY_PREFIX . $runId;

        if ( function_exists( 'wp_using_ext_object_cache' ) && \wp_using_ext_object_cache() ) {
            $raw = \get_transient( $key );
            if ( is_string( $raw ) && $raw !== '' ) {
                \set_transient( $key, $raw, self::TTL_SECONDS );
            }
            return;
        }

        $timeoutOption = '_transient_timeout_' . $key;
        if ( \get_option( $timeoutOption ) !== false ) {
            \update_option( $timeoutOption, time() + self::TTL_SECONDS );
        }
    }

    public static function clear( int $runId ): void
    {
        if ( $runId <= 0 ) return;
        \delete_transient( self::KEY_PREFIX . $runId );
    }
}
