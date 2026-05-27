<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * Per-SKU cache for KicksDB API responses. The wp_hsync_kicksdb_cache
 * table sits on top of a (sku, market) unique key so different IT/US
 * markets coexist without collision. Lookups are indexed, ~1ms per
 * call — the difference between enriching 10k items in seconds (warm
 * cache) and burning through 10k API calls every re-sync.
 *
 * Invalidation: an explicit expires_at (default 24h after fetched_at)
 * is stored per row. get() returns null on expiry so the caller
 * refreshes from the API and writes back. No background sweeper is
 * needed — stale rows just sit there until purgeExpired() is called.
 *
 * Negative-cache: misses are stored too (payload = {"_miss": true})
 * with a short TTL, so an unknown SKU doesn't hammer the API on
 * every re-sync until the operator wires it up.
 */
final class KicksDbCacheRepository
{
    public function get( string $sku, string $market ): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) || $sku === '' ) return null;
        $table = \hsync_table( 'kicksdb_cache' );
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT payload, expires_at FROM `$table` WHERE sku = %s AND market = %s LIMIT 1",
                $sku, $market,
            ),
            ARRAY_A,
        );
        if ( ! $row ) return null;
        if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) < time() ) {
            return null;  // stale
        }
        $payload = json_decode( (string) $row['payload'], true );
        return is_array( $payload ) ? $payload : null;
    }

    public function put( string $sku, string $market, array $payload, int $ttlSeconds = 86400 ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) || $sku === '' ) return;
        $table = \hsync_table( 'kicksdb_cache' );
        $now   = time();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO `$table` (sku, market, payload, fetched_at, expires_at)
             VALUES (%s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                payload    = VALUES(payload),
                fetched_at = VALUES(fetched_at),
                expires_at = VALUES(expires_at)",
            $sku,
            $market,
            wp_json_encode( $payload ),
            gmdate( 'Y-m-d H:i:s', $now ),
            gmdate( 'Y-m-d H:i:s', $now + max( 60, $ttlSeconds ) ),
        ) );
    }

    /**
     * Batch lookup. Returns sku=>payload for found+fresh entries;
     * missing/stale SKUs are simply absent. The caller pairs the result
     * with API calls for the misses.
     *
     * @param string[] $skus
     * @return array<string, array>
     */
    public function getMany( array $skus, string $market ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $skus = array_values( array_unique( array_filter( array_map( 'strval', $skus ), fn( $s ) => $s !== '' ) ) );
        if ( ! $skus ) return [];
        $table = \hsync_table( 'kicksdb_cache' );
        $out   = [];
        foreach ( array_chunk( $skus, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $sql    = "SELECT sku, payload, expires_at FROM `$table`
                       WHERE market = %s AND sku IN ($placeholders)";
            $params = array_merge( [ $market ], $chunk );
            $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
            if ( ! is_array( $rows ) ) continue;
            $cutoff = time();
            foreach ( $rows as $row ) {
                $sku = (string) ( $row['sku'] ?? '' );
                if ( $sku === '' ) continue;
                if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) < $cutoff ) continue;
                $payload = json_decode( (string) $row['payload'], true );
                if ( is_array( $payload ) ) $out[ $sku ] = $payload;
            }
        }
        return $out;
    }

    public function delete( string $sku, string $market ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $wpdb->delete( \hsync_table( 'kicksdb_cache' ), [ 'sku' => $sku, 'market' => $market ] );
    }

    /**
     * Delete all rows whose expires_at is in the past. Returns the
     * number of rows removed.
     */
    public function purgeExpired(): int
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return 0;
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM `" . \hsync_table( 'kicksdb_cache' ) . "` WHERE expires_at < %s",
            gmdate( 'Y-m-d H:i:s' ),
        ) );
    }

    public function count(): int
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return 0;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . \hsync_table( 'kicksdb_cache' ) . "`" );
    }
}
