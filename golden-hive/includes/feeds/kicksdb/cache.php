<?php
/**
 * KicksDB — cache per-SKU per product enrichment.
 *
 * Transient-backed. TTL da settings (default 24h). Evita di rifetchare ~800
 * prodotti ogni volta che si bulk-import o si rebuild di una pagina di
 * catalog viewer. Il pricing NON viene cachato (il suo valore decade in minuti).
 *
 * @see gh_kicksdb_get_product_full() — chi usa questa cache.
 */

defined( 'ABSPATH' ) || exit;

defined( 'GH_KICKSDB_CACHE_PREFIX' ) || define( 'GH_KICKSDB_CACHE_PREFIX', 'gh_kdb_prd_' );

if ( function_exists( 'gh_kicksdb_cache_get' ) ) return;

/**
 * Transient key per SKU.
 */
function gh_kicksdb_cache_key( string $sku ): string {
    // Transient keys sono limitati a 172 char; md5 garantisce lunghezza fissa.
    return GH_KICKSDB_CACHE_PREFIX . md5( strtolower( $sku ) );
}

/**
 * Ritorna la response cacheata per SKU o null.
 *
 * @return array|null Response shape (vedi gh_kicksdb_request).
 */
function gh_kicksdb_cache_get( string $sku ): ?array {
    if ( $sku === '' ) return null;
    $v = get_transient( gh_kicksdb_cache_key( $sku ) );
    return is_array( $v ) ? $v : null;
}

/**
 * Salva response in cache con TTL dalle settings.
 */
function gh_kicksdb_cache_set( string $sku, array $response ): void {
    if ( $sku === '' ) return;
    $s   = gh_kicksdb_get_settings();
    $ttl = (int) ( $s['cache_ttl'] ?? ( 24 * HOUR_IN_SECONDS ) );
    set_transient( gh_kicksdb_cache_key( $sku ), $response, $ttl );
}

/**
 * Invalida la cache per 1 o piu SKU.
 *
 * @param string[] $skus
 * @return int Numero di entry rimosse.
 */
function gh_kicksdb_cache_purge( array $skus ): int {
    $removed = 0;
    foreach ( $skus as $sku ) {
        if ( $sku === '' ) continue;
        if ( delete_transient( gh_kicksdb_cache_key( (string) $sku ) ) ) $removed++;
    }
    return $removed;
}

/**
 * Wrapper che cachifica gh_kicksdb_get_product_full.
 * Cache miss o force=true → fetch + set.
 *
 * @return array Response.
 */
function gh_kicksdb_get_product_cached( string $sku, bool $force = false ): array {
    if ( ! $force ) {
        $hit = gh_kicksdb_cache_get( $sku );
        if ( $hit !== null ) {
            $hit['_cached'] = true;
            return $hit;
        }
    }

    $resp = gh_kicksdb_get_product_full( $sku );

    // Cachea solo le success response (200 con body valido). 404 NON cachato
    // per evitare "stuck missing" se KicksDB pubblica lo SKU in seguito.
    if ( ( $resp['status'] ?? 0 ) === 200 && ! empty( $resp['body']['data'] ) ) {
        gh_kicksdb_cache_set( $sku, $resp );
    }

    $resp['_cached'] = false;
    return $resp;
}
