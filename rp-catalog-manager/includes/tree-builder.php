<?php
/**
 * Tree Builder — organizza prodotti nella gerarchia Sezione > Marca > Sottocategoria.
 * Nessun side effect, nessuna scrittura.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Costruisce l'albero gerarchico da un array flat di catalog entries.
 *
 * @param array $products_data Array di catalog entries (output di aggregate o full).
 *                             Ogni entry deve avere 'id'.
 * @return array Albero: [ Sezione => [ Marca => [ Sottocategoria => [ ...entries ] ] ] ]
 */
function rp_cm_build_tree( array $products_data ): array {

    $tree = [];

    foreach ( $products_data as $entry ) {
        $path = rp_cm_get_product_tree_path( (int) $entry['id'] );

        $sezione        = $path[0];
        $marca          = $path[1];
        $sottocategoria = $path[2];

        if ( ! isset( $tree[ $sezione ] ) ) {
            $tree[ $sezione ] = [];
        }
        if ( ! isset( $tree[ $sezione ][ $marca ] ) ) {
            $tree[ $sezione ][ $marca ] = [];
        }
        if ( ! isset( $tree[ $sezione ][ $marca ][ $sottocategoria ] ) ) {
            $tree[ $sezione ][ $marca ][ $sottocategoria ] = [];
        }

        $tree[ $sezione ][ $marca ][ $sottocategoria ][] = $entry;
    }

    // Ordina alfabeticamente a ogni livello
    ksort( $tree );
    foreach ( $tree as &$brands ) {
        ksort( $brands );
        foreach ( $brands as &$subcats ) {
            ksort( $subcats );
        }
    }

    return $tree;
}

/**
 * Risolve il path a 3 livelli (Sezione, Marca, Sottocategoria) per un prodotto.
 *
 * Risale la gerarchia delle categorie WooCommerce dalla categoria piu profonda.
 * Fallback a 'Uncategorized' / 'General' se la profondita non raggiunge 3 livelli.
 *
 * @param int $product_id
 * @return array [ sezione, marca, sottocategoria ]
 */
function rp_cm_get_product_tree_path( int $product_id ): array {

    $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'orderby' => 'parent' ] );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return [ 'Uncategorized', 'Uncategorized', 'General' ];
    }

    // Trova la categoria piu profonda (massimo depth nella gerarchia)
    $deepest      = null;
    $deepest_depth = -1;

    foreach ( $terms as $term ) {
        $depth = rp_cm_term_depth( $term );
        if ( $depth > $deepest_depth ) {
            $deepest       = $term;
            $deepest_depth = $depth;
        }
    }

    // Ricostruisci il path risalendo via parent
    $path    = [];
    $current = $deepest;

    while ( $current && count( $path ) < 3 ) {
        array_unshift( $path, $current->name );
        $current = $current->parent ? get_term( $current->parent, 'product_cat' ) : null;
        if ( is_wp_error( $current ) ) $current = null;
    }

    // Normalizza a esattamente 3 livelli
    return match ( count( $path ) ) {
        1       => [ $path[0], 'Uncategorized', 'General' ],
        2       => [ $path[0], $path[1], 'General' ],
        default => array_slice( $path, 0, 3 ),
    };
}

/**
 * Ritorna i path disponibili (sezioni, marche, sottocategorie) per popolare i filtri UI.
 *
 * @return array [ 'sections' => [...], 'brands' => [...], 'subcategories' => [...] ]
 */
function rp_cm_get_available_paths(): array {

    $categories = rp_cm_get_product_categories();

    $sections      = [];
    $brands        = [];
    $subcategories = [];

    foreach ( $categories as $cat ) {
        if ( $cat['parent_id'] === 0 ) {
            $sections[] = $cat['name'];
        } else {
            // Controlla se il parent e root → questa e marca
            $parent = $categories[ $cat['parent_id'] ] ?? null;
            if ( $parent && $parent['parent_id'] === 0 ) {
                $brands[] = $cat['name'];
            } elseif ( $parent ) {
                $subcategories[] = $cat['name'];
            }
        }
    }

    sort( $sections );
    sort( $brands );
    sort( $subcategories );

    return [
        'sections'      => array_unique( $sections ),
        'brands'        => array_unique( $brands ),
        'subcategories' => array_unique( $subcategories ),
    ];
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Calcola la profondita di un termine nella tassonomia.
 *
 * @param WP_Term $term
 * @return int Depth (0 = root).
 */
function rp_cm_term_depth( WP_Term $term ): int {

    $depth   = 0;
    $current = $term;

    while ( $current->parent ) {
        $depth++;
        $current = get_term( $current->parent, $current->taxonomy );
        if ( is_wp_error( $current ) || ! $current ) break;
    }

    return $depth;
}
