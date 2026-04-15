<?php
/**
 * Taxonomy Manager — CRUD per le tassonomie gerarchiche prodotto.
 *
 * Supporta qualsiasi tassonomia gerarchica di WooCommerce: di default opera su
 * `product_cat` (categorie prodotto), ma puo essere invocato con `product_brand`
 * (Woo Brands) o altre tassonomie custom semplicemente passando $taxonomy.
 *
 * Le funzioni mantengono il prefix storico `rp_cm_` per compatibilita con il
 * codice esistente (feed, importer, exporter). La firma e stata estesa con un
 * parametro opzionale $taxonomy che defaulta a 'product_cat', cosi il codice
 * legacy continua a funzionare senza modifiche.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whitelist delle tassonomie gestibili da questo modulo.
 *
 * Una tassonomia e abilitata solo se e effettivamente registrata e di tipo
 * gerarchica. Cosi evitiamo scritture su tassonomie arbitrarie via AJAX.
 *
 * @return string[]
 */
function rp_cm_supported_taxonomies(): array {

    $candidates = [ 'product_cat', 'product_brand' ];
    $out = [];
    foreach ( $candidates as $tax ) {
        if ( taxonomy_exists( $tax ) && is_taxonomy_hierarchical( $tax ) ) {
            $out[] = $tax;
        }
    }
    return $out;
}

/**
 * Normalizza e valida una tassonomia richiesta dall'esterno. Fallback a
 * `product_cat` se non supportata (comportamento compatibile con il vecchio
 * codice che non passava mai questo parametro).
 */
function rp_cm_normalize_taxonomy( string $taxonomy ): string {

    $taxonomy = $taxonomy ?: 'product_cat';
    return in_array( $taxonomy, rp_cm_supported_taxonomies(), true ) ? $taxonomy : 'product_cat';
}

/**
 * Ritorna l'albero completo di una tassonomia prodotto con conteggio termini.
 *
 * @param string $taxonomy Tassonomia (default: product_cat).
 * @return array Struttura gerarchica con term_id, name, slug, parent, count, children.
 */
function rp_cm_get_taxonomy_tree( string $taxonomy = 'product_cat' ): array {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $terms = get_terms( [
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'orderby'    => 'name',
    ] );

    if ( is_wp_error( $terms ) ) return [];

    // Indicizza per ID
    $by_id = [];
    foreach ( $terms as $term ) {
        $by_id[ $term->term_id ] = [
            'id'       => $term->term_id,
            'name'     => $term->name,
            'slug'     => $term->slug,
            'parent'   => $term->parent,
            'count'    => $term->count,
            'children' => [],
        ];
    }

    // Costruisci la gerarchia
    $tree = [];
    foreach ( $by_id as $id => &$node ) {
        if ( $node['parent'] && isset( $by_id[ $node['parent'] ] ) ) {
            $by_id[ $node['parent'] ]['children'][] = &$node;
        } else {
            $tree[] = &$node;
        }
    }
    unset( $node );

    // Ordina children a ogni livello
    rp_cm_sort_tree( $tree );

    return $tree;
}

/**
 * Ordina ricorsivamente i nodi dell'albero per nome.
 *
 * @param array &$nodes
 */
function rp_cm_sort_tree( array &$nodes ): void {

    usort( $nodes, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
    foreach ( $nodes as &$node ) {
        if ( ! empty( $node['children'] ) ) {
            rp_cm_sort_tree( $node['children'] );
        }
    }
}

/**
 * Crea un nuovo termine in una tassonomia prodotto.
 *
 * @param string $name      Nome del termine.
 * @param int    $parent_id ID del termine padre (0 per root).
 * @param string $slug      Slug opzionale (auto-generato se vuoto).
 * @param string $taxonomy  Tassonomia (default: product_cat).
 * @return int|WP_Error term_id del nuovo termine o errore.
 */
function rp_cm_create_category( string $name, int $parent_id = 0, string $slug = '', string $taxonomy = 'product_cat' ): int|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $name = trim( $name );
    if ( ! $name ) {
        return new WP_Error( 'empty_name', 'Il nome non puo essere vuoto.' );
    }

    // Verifica che il parent esista (se specificato)
    if ( $parent_id > 0 ) {
        $parent = get_term( $parent_id, $taxonomy );
        if ( is_wp_error( $parent ) || ! $parent ) {
            return new WP_Error( 'invalid_parent', "Termine padre #{$parent_id} non trovato in {$taxonomy}." );
        }
    }

    $args = [ 'parent' => $parent_id ];
    if ( $slug ) $args['slug'] = sanitize_title( $slug );

    $result = wp_insert_term( $name, $taxonomy, $args );

    if ( is_wp_error( $result ) ) return $result;

    return $result['term_id'];
}

/**
 * Rinomina un termine esistente.
 *
 * @param int    $term_id  ID del termine.
 * @param string $name     Nuovo nome.
 * @param string $slug     Nuovo slug (opzionale, rigenera se vuoto).
 * @param string $taxonomy Tassonomia (default: product_cat).
 * @return true|WP_Error
 */
function rp_cm_rename_category( int $term_id, string $name, string $slug = '', string $taxonomy = 'product_cat' ): true|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $name = trim( $name );
    if ( ! $name ) {
        return new WP_Error( 'empty_name', 'Il nome non puo essere vuoto.' );
    }

    $args = [ 'name' => $name ];
    if ( $slug ) {
        $args['slug'] = sanitize_title( $slug );
    } else {
        $args['slug'] = sanitize_title( $name );
    }

    $result = wp_update_term( $term_id, $taxonomy, $args );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Sposta un termine sotto un nuovo parent.
 *
 * @param int    $term_id       ID del termine da spostare.
 * @param int    $new_parent_id Nuovo parent (0 per root).
 * @param string $taxonomy      Tassonomia (default: product_cat).
 * @return true|WP_Error
 */
function rp_cm_move_category( int $term_id, int $new_parent_id, string $taxonomy = 'product_cat' ): true|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    if ( $term_id === $new_parent_id ) {
        return new WP_Error( 'self_parent', 'Un termine non puo essere figlio di se stesso.' );
    }

    if ( $new_parent_id > 0 && rp_cm_is_descendant( $new_parent_id, $term_id, $taxonomy ) ) {
        return new WP_Error( 'circular', 'Non puoi spostare un termine dentro un suo discendente.' );
    }

    $result = wp_update_term( $term_id, $taxonomy, [ 'parent' => $new_parent_id ] );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Elimina un termine. I prodotti vengono scollegati (NON eliminati).
 *
 * @param int    $term_id           ID del termine.
 * @param bool   $reassign_children Se true, i figli vengono spostati sotto il parent.
 * @param string $taxonomy          Tassonomia (default: product_cat).
 * @return true|WP_Error
 */
function rp_cm_delete_category( int $term_id, bool $reassign_children = true, string $taxonomy = 'product_cat' ): true|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $term = get_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) || ! $term ) {
        return new WP_Error( 'not_found', "Termine #{$term_id} non trovato in {$taxonomy}." );
    }

    // Riassegna i figli al parent del termine eliminato
    if ( $reassign_children ) {
        $children = get_terms( [
            'taxonomy'   => $taxonomy,
            'parent'     => $term_id,
            'hide_empty' => false,
        ] );
        if ( ! is_wp_error( $children ) ) {
            foreach ( $children as $child ) {
                wp_update_term( $child->term_id, $taxonomy, [ 'parent' => $term->parent ] );
            }
        }
    }

    $result = wp_delete_term( $term_id, $taxonomy );

    if ( is_wp_error( $result ) ) return $result;
    if ( $result === false ) {
        return new WP_Error( 'delete_failed', "Impossibile eliminare il termine #{$term_id}." );
    }

    return true;
}

/**
 * Ritorna i prodotti assegnati a un termine specifico.
 *
 * @param int    $term_id  ID del termine.
 * @param bool   $direct   Se true, solo prodotti direttamente in questo termine (non figli).
 * @param string $taxonomy Tassonomia (default: product_cat).
 * @return array Array di [ id, name, sku, type, status ].
 */
function rp_cm_get_category_products( int $term_id, bool $direct = true, string $taxonomy = 'product_cat' ): array {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $term = get_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) || ! $term ) return [];

    // WC_Product_Query supporta 'category' come shortcut per product_cat, ma
    // per tassonomie generiche usiamo tax_query esplicita.
    $args = [
        'limit'     => -1,
        'status'    => 'any',
        'type'      => [ 'simple', 'variable' ],
        'return'    => 'objects',
        'orderby'   => 'title',
        'order'     => 'ASC',
        'tax_query' => [
            [
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => [ $term_id ],
                'operator' => 'IN',
            ],
        ],
    ];

    $query    = new WC_Product_Query( $args );
    $products = $query->get_products();

    if ( $direct ) {
        // Filtra solo quelli direttamente assegnati a questo term (WC include i figli).
        $products = array_filter( $products, function ( $p ) use ( $term_id, $taxonomy ) {
            $ids = wp_get_post_terms( $p->get_id(), $taxonomy, [ 'fields' => 'ids' ] );
            return in_array( $term_id, $ids, true );
        } );
    }

    $result = [];
    foreach ( $products as $p ) {
        $result[] = [
            'id'     => $p->get_id(),
            'name'   => $p->get_name(),
            'sku'    => $p->get_sku(),
            'type'   => $p->get_type(),
            'status' => $p->get_status(),
        ];
    }

    return array_values( $result );
}

/**
 * Assegna un prodotto a uno o piu termini (aggiunge, non sostituisce).
 *
 * @param int    $product_id ID del prodotto.
 * @param int[]  $term_ids   Array di term_id da aggiungere.
 * @param string $taxonomy   Tassonomia (default: product_cat).
 * @return true|WP_Error
 */
function rp_cm_assign_product_categories( int $product_id, array $term_ids, string $taxonomy = 'product_cat' ): true|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    $result = wp_set_object_terms(
        $product_id,
        array_map( 'intval', $term_ids ),
        $taxonomy,
        true  // append
    );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Rimuove un prodotto da uno o piu termini.
 *
 * @param int    $product_id ID del prodotto.
 * @param int[]  $term_ids   Array di term_id da rimuovere.
 * @param string $taxonomy   Tassonomia (default: product_cat).
 * @return true|WP_Error
 */
function rp_cm_remove_product_categories( int $product_id, array $term_ids, string $taxonomy = 'product_cat' ): true|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    $current = wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
    if ( is_wp_error( $current ) ) return $current;

    $remaining = array_diff( $current, array_map( 'intval', $term_ids ) );

    $result = wp_set_object_terms( $product_id, array_values( $remaining ), $taxonomy );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Imposta i termini di un prodotto su una tassonomia (sostituisce tutto).
 *
 * @param int    $product_id ID del prodotto.
 * @param int[]  $term_ids   Array di term_id.
 * @param string $taxonomy   Tassonomia (default: product_cat).
 * @return true|WP_Error
 */
function rp_cm_set_product_categories( int $product_id, array $term_ids, string $taxonomy = 'product_cat' ): true|WP_Error {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    $result = wp_set_object_terms(
        $product_id,
        array_map( 'intval', $term_ids ),
        $taxonomy,
        false  // replace
    );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Controlla se un term e discendente di un altro all'interno della stessa tassonomia.
 *
 * @param int    $term_id  ID del term da verificare.
 * @param int    $ancestor ID del possibile antenato.
 * @param string $taxonomy Tassonomia (default: product_cat).
 * @return bool
 */
function rp_cm_is_descendant( int $term_id, int $ancestor, string $taxonomy = 'product_cat' ): bool {

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $current = get_term( $term_id, $taxonomy );

    while ( $current && ! is_wp_error( $current ) && $current->parent ) {
        if ( $current->parent === $ancestor ) return true;
        $current = get_term( $current->parent, $taxonomy );
    }

    return false;
}
