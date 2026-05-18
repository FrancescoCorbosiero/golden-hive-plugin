<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Batched SKU → product_id resolver. Replaces N individual calls to
 * wc_get_product_id_by_sku() with a single SQL query — the difference
 * between a 50ms diff and a 50-second diff at 10k items.
 *
 * Each wc_get_product_id_by_sku() call does its own meta query (~5ms
 * including WC sanity checks); a 10k feed against an existing store
 * blows past the 25s tick budget purely on lookups.
 *
 * We query wp_postmeta directly + filter to product + product_variation
 * post types so the result matches the WC helper's behavior. Variations
 * are skipped at the caller level (FeedItem.sku always refers to the
 * parent product for variable types).
 */
final class SkuLookup
{
    /**
     * @param string[] $skus Unique SKUs to resolve. Empty strings are dropped.
     * @return array<string, int> sku → post_id (only entries with a hit)
     */
    public static function mapSkusToIds( array $skus ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) return [];

        $clean = [];
        foreach ( $skus as $sku ) {
            if ( ! is_string( $sku ) || $sku === '' ) continue;
            $clean[ $sku ] = true;
        }
        if ( ! $clean ) return [];
        $clean = array_keys( $clean );

        $out = [];
        // Chunk to keep IN() lists bounded — MySQL/Maria copes with 65k+
        // params but the placeholders eat prepared-statement memory and
        // some shared-host configs cap at 1000. 500 is conservative and
        // still keeps the diff under a handful of round-trips for a 10k
        // feed.
        foreach ( array_chunk( $clean, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $sql = "SELECT pm.meta_value AS sku, pm.post_id AS pid
                    FROM {$wpdb->postmeta} pm
                    JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE pm.meta_key = '_sku'
                      AND p.post_type = 'product'
                      AND p.post_status NOT IN ('trash','auto-draft')
                      AND pm.meta_value IN ($placeholders)";
            $prepared = $wpdb->prepare( $sql, $chunk );
            $rows = $wpdb->get_results( $prepared, ARRAY_A );
            if ( ! is_array( $rows ) ) continue;
            foreach ( $rows as $row ) {
                $sku = (string) ( $row['sku'] ?? '' );
                $pid = (int) ( $row['pid'] ?? 0 );
                if ( $sku === '' || $pid <= 0 ) continue;
                // First match wins if a SKU is somehow duplicated; trash/
                // auto-draft already filtered above so collisions are rare.
                if ( ! isset( $out[ $sku ] ) ) $out[ $sku ] = $pid;
            }
        }
        return $out;
    }
}
