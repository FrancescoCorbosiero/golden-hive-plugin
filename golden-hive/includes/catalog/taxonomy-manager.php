<?php
/**
 * Taxonomy Manager — CRUD per la gerarchia categorie prodotto (Sezione > Marca > Sottocategoria).
 * Opera sulla tassonomia product_cat di WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ritorna l'albero completo delle categorie con conteggio prodotti.
 *
 * @return array Struttura gerarchica con term_id, name, slug, parent, count, children.
 */
function rp_cm_get_taxonomy_tree(): array {

    $terms = get_terms( [
        'taxonomy'   => 'product_cat',
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
 * Crea una nuova categoria prodotto.
 *
 * @param string $name      Nome della categoria.
 * @param int    $parent_id ID della categoria padre (0 per root).
 * @param string $slug      Slug opzionale (auto-generato se vuoto).
 * @return int|WP_Error term_id della nuova categoria o errore.
 */
function rp_cm_create_category( string $name, int $parent_id = 0, string $slug = '' ): int|WP_Error {

    $name = trim( $name );
    if ( ! $name ) {
        return new WP_Error( 'empty_name', 'Il nome della categoria non puo essere vuoto.' );
    }

    // Verifica che il parent esista (se specificato)
    if ( $parent_id > 0 ) {
        $parent = get_term( $parent_id, 'product_cat' );
        if ( is_wp_error( $parent ) || ! $parent ) {
            return new WP_Error( 'invalid_parent', "Categoria padre #{$parent_id} non trovata." );
        }
    }

    $args = [ 'parent' => $parent_id ];
    if ( $slug ) $args['slug'] = sanitize_title( $slug );

    $result = wp_insert_term( $name, 'product_cat', $args );

    if ( is_wp_error( $result ) ) return $result;

    return $result['term_id'];
}

/**
 * Rinomina una categoria esistente.
 *
 * @param int    $term_id ID della categoria.
 * @param string $name    Nuovo nome.
 * @param string $slug    Nuovo slug (opzionale, rigenera se vuoto).
 * @return true|WP_Error
 */
function rp_cm_rename_category( int $term_id, string $name, string $slug = '' ): true|WP_Error {

    $name = trim( $name );
    if ( ! $name ) {
        return new WP_Error( 'empty_name', 'Il nome della categoria non puo essere vuoto.' );
    }

    $args = [ 'name' => $name ];
    if ( $slug ) {
        $args['slug'] = sanitize_title( $slug );
    } else {
        $args['slug'] = sanitize_title( $name );
    }

    $result = wp_update_term( $term_id, 'product_cat', $args );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Sposta una categoria sotto un nuovo parent.
 *
 * @param int $term_id       ID della categoria da spostare.
 * @param int $new_parent_id Nuovo parent (0 per root).
 * @return true|WP_Error
 */
function rp_cm_move_category( int $term_id, int $new_parent_id ): true|WP_Error {

    // Non puoi spostare una categoria dentro se stessa o un suo figlio
    if ( $term_id === $new_parent_id ) {
        return new WP_Error( 'self_parent', 'Una categoria non puo essere figlia di se stessa.' );
    }

    if ( $new_parent_id > 0 && rp_cm_is_descendant( $new_parent_id, $term_id ) ) {
        return new WP_Error( 'circular', 'Non puoi spostare una categoria dentro un suo discendente.' );
    }

    $result = wp_update_term( $term_id, 'product_cat', [ 'parent' => $new_parent_id ] );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Elimina una categoria. I prodotti vengono scollegati (NON eliminati).
 *
 * @param int  $term_id       ID della categoria.
 * @param bool $reassign_children Se true, i figli vengono spostati sotto il parent della categoria eliminata.
 * @return true|WP_Error
 */
function rp_cm_delete_category( int $term_id, bool $reassign_children = true ): true|WP_Error {

    $term = get_term( $term_id, 'product_cat' );
    if ( is_wp_error( $term ) || ! $term ) {
        return new WP_Error( 'not_found', "Categoria #{$term_id} non trovata." );
    }

    // Riassegna i figli al parent della categoria eliminata
    if ( $reassign_children ) {
        $children = get_terms( [
            'taxonomy'   => 'product_cat',
            'parent'     => $term_id,
            'hide_empty' => false,
        ] );
        if ( ! is_wp_error( $children ) ) {
            foreach ( $children as $child ) {
                wp_update_term( $child->term_id, 'product_cat', [ 'parent' => $term->parent ] );
            }
        }
    }

    $result = wp_delete_term( $term_id, 'product_cat' );

    if ( is_wp_error( $result ) ) return $result;
    if ( $result === false ) {
        return new WP_Error( 'delete_failed', "Impossibile eliminare la categoria #{$term_id}." );
    }

    return true;
}

/**
 * Ritorna i prodotti assegnati a una categoria specifica.
 *
 * @param int  $term_id   ID della categoria.
 * @param bool $direct    Se true, solo prodotti direttamente in questa categoria (non figli).
 * @return array Array di [ id, name, sku, type, status ].
 */
function rp_cm_get_category_products( int $term_id, bool $direct = true ): array {

    $args = [
        'limit'    => -1,
        'status'   => 'any',
        'type'     => [ 'simple', 'variable' ],
        'return'   => 'objects',
        'category' => [ get_term( $term_id, 'product_cat' )->slug ?? '' ],
        'orderby'  => 'title',
        'order'    => 'ASC',
    ];

    $query    = new WC_Product_Query( $args );
    $products = $query->get_products();

    if ( $direct ) {
        // Filtra solo quelli direttamente assegnati a questo term
        $products = array_filter( $products, function ( $p ) use ( $term_id ) {
            $ids = wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
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
 * Assegna un prodotto a una o piu categorie (aggiunge, non sostituisce).
 *
 * @param int   $product_id   ID del prodotto.
 * @param int[] $category_ids Array di term_id da aggiungere.
 * @return true|WP_Error
 */
function rp_cm_assign_product_categories( int $product_id, array $category_ids ): true|WP_Error {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    $result = wp_set_object_terms(
        $product_id,
        array_map( 'intval', $category_ids ),
        'product_cat',
        true  // append
    );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Rimuove un prodotto da una o piu categorie.
 *
 * @param int   $product_id   ID del prodotto.
 * @param int[] $category_ids Array di term_id da rimuovere.
 * @return true|WP_Error
 */
function rp_cm_remove_product_categories( int $product_id, array $category_ids ): true|WP_Error {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    $current = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
    if ( is_wp_error( $current ) ) return $current;

    $remaining = array_diff( $current, array_map( 'intval', $category_ids ) );

    $result = wp_set_object_terms( $product_id, array_values( $remaining ), 'product_cat' );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

/**
 * Imposta le categorie di un prodotto (sostituisce tutte).
 *
 * @param int   $product_id   ID del prodotto.
 * @param int[] $category_ids Array di term_id.
 * @return true|WP_Error
 */
function rp_cm_set_product_categories( int $product_id, array $category_ids ): true|WP_Error {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    $result = wp_set_object_terms(
        $product_id,
        array_map( 'intval', $category_ids ),
        'product_cat',
        false  // replace
    );

    if ( is_wp_error( $result ) ) return $result;

    return true;
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Controlla se un term e discendente di un altro.
 *
 * @param int $term_id  ID del term da verificare.
 * @param int $ancestor ID del possibile antenato.
 * @return bool
 */
function rp_cm_is_descendant( int $term_id, int $ancestor ): bool {

    $current = get_term( $term_id, 'product_cat' );

    while ( $current && ! is_wp_error( $current ) && $current->parent ) {
        if ( $current->parent === $ancestor ) return true;
        $current = get_term( $current->parent, 'product_cat' );
    }

    return false;
}
