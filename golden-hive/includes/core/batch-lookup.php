<?php
/**
 * Batch lookup — primitive per collassare gli N+1 dei loop di import/diff.
 *
 * Tre helper componibili, tutti read-only:
 *  - gh_sku_to_id_map()        N SKU → 1-2 query (vs N wc_get_product_id_by_sku)
 *  - gh_batch_get_meta()       N post × M meta key → 1 query per chunk
 *  - gh_prime_product_caches() posts+meta+terms cache warm-up in 3 query,
 *                              dopo di che wc_get_product() non tocca il DB
 *
 * Un diff GS da 2.000 SKU con 10 varianti l'uno passava da ~44.000 query
 * (lookup SKU + hydration per ogni parent e ogni variante) a ~10.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Risolve un set di SKU in product/variation ID con query batched.
 *
 * Replica il predicato di wc_get_product_id_by_sku() (join sulla
 * wc_product_meta_lookup, post_type product|product_variation, trash
 * escluso) ma per l'intero set in una query per chunk da 500. Con SKU
 * duplicati vince l'ID più basso (deterministico). Il match è
 * case-insensitive come la collation della colonna sku; le chiavi della
 * mappa ritornata sono gli SKU nella forma richiesta dal chiamante.
 *
 * @param string[] $skus SKU da risolvere (vuoti/duplicati ignorati).
 * @return array<string,int> [ sku richiesto => post_id ] — solo i trovati.
 *
 * Esempio:
 *   $map = gh_sku_to_id_map( array_column( $items, 'sku' ) );
 *   $pid = $map['DD1873-102'] ?? 0;
 */
function gh_sku_to_id_map( array $skus ): array {
    global $wpdb;

    $skus = array_values( array_unique( array_filter(
        array_map( 'strval', $skus ),
        static fn( string $s ): bool => $s !== ''
    ) ) );
    if ( ! $skus ) return [];

    $table   = ! empty( $wpdb->wc_product_meta_lookup )
        ? $wpdb->wc_product_meta_lookup
        : $wpdb->prefix . 'wc_product_meta_lookup';
    $by_lc = [];

    foreach ( array_chunk( $skus, 500 ) as $chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT lookup.sku, lookup.product_id
             FROM {$table} AS lookup
             INNER JOIN {$wpdb->posts} AS posts ON posts.ID = lookup.product_id
             WHERE posts.post_type IN ('product','product_variation')
               AND posts.post_status != 'trash'
               AND lookup.sku IN ({$placeholders})
             ORDER BY lookup.product_id ASC",
            $chunk
        ) );
        foreach ( (array) $rows as $row ) {
            $lc = strtolower( (string) $row->sku );
            if ( ! isset( $by_lc[ $lc ] ) ) {
                $by_lc[ $lc ] = (int) $row->product_id;
            }
        }
    }

    $map = [];
    foreach ( $skus as $sku ) {
        $lc = strtolower( $sku );
        if ( isset( $by_lc[ $lc ] ) ) {
            $map[ $sku ] = $by_lc[ $lc ];
        }
    }
    return $map;
}

/**
 * Legge un set di meta key per N post in una query per chunk.
 *
 * A differenza di update_postmeta_cache non trascina TUTTI i meta in
 * memoria: solo le chiavi richieste — adatto a diff su cataloghi interi
 * dove servono 2-3 colonne per migliaia di varianti.
 *
 * @param int[]    $post_ids  Post ID (dedup automatico).
 * @param string[] $meta_keys Meta key da leggere.
 * @return array<int,array<string,string>> [ post_id => [ meta_key => valore ] ]
 *                                         (prima occorrenza per chiave; i
 *                                         post senza righe meta non compaiono).
 *
 * Esempio:
 *   $meta = gh_batch_get_meta( $var_ids, [ '_sale_price', '_stock' ] );
 *   $sale = $meta[ $vid ]['_sale_price'] ?? '';
 */
function gh_batch_get_meta( array $post_ids, array $meta_keys ): array {
    global $wpdb;

    $post_ids  = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
    $meta_keys = array_values( array_unique( array_filter( array_map( 'strval', $meta_keys ) ) ) );
    if ( ! $post_ids || ! $meta_keys ) return [];

    $key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
    $out = [];

    foreach ( array_chunk( $post_ids, 1000 ) as $chunk ) {
        $id_placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$id_placeholders})
               AND meta_key IN ({$key_placeholders})
             ORDER BY meta_id ASC",
            array_merge( $chunk, $meta_keys )
        ) );
        foreach ( (array) $rows as $row ) {
            $pid = (int) $row->post_id;
            $key = (string) $row->meta_key;
            if ( ! isset( $out[ $pid ][ $key ] ) ) {
                $out[ $pid ][ $key ] = (string) $row->meta_value;
            }
        }
    }
    return $out;
}

/**
 * Scalda le cache post + meta + term per un set di prodotti.
 *
 * Dopo questa chiamata wc_get_product() su ciascun ID costruisce
 * l'oggetto senza query aggiuntive: post row (post cache), tutti i meta
 * (meta cache), product_type e le altre tassonomie (object term cache).
 * 3 query per l'intero set invece di 2-3 per prodotto.
 *
 * @param int[] $post_ids
 * @return void
 *
 * Esempio:
 *   gh_prime_product_caches( array_values( $sku_map ) );
 *   foreach ( $sku_map as $pid ) { $p = wc_get_product( $pid ); ... }
 */
function gh_prime_product_caches( array $post_ids ): void {
    $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
    if ( ! $post_ids || ! function_exists( '_prime_post_caches' ) ) return;
    _prime_post_caches( $post_ids, true, true );
}
