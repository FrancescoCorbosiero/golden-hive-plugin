<?php
/**
 * AJAX bridge per Navigation Manager + Taxonomy Query.
 *
 * Tutti gli handler seguono il pattern standard:
 *   check_ajax_referer('gh_nonce','nonce')
 *   current_user_can('manage_woocommerce')
 *   -> chiama la funzione del layer PHP
 *   -> wp_send_json_success/error
 */

defined( 'ABSPATH' ) || exit;

// ═══ TAXONOMY QUERY ═══════════════════════════════════════════════════════

// Risolve un elenco di term_ids nei product_ids associati.
// Usato per hand-off Tax Query → Filtra & Agisci (bulk actions).
add_action( 'wp_ajax_gh_ajax_products_for_terms', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $taxonomy = sanitize_key( (string) ( $_POST['taxonomy'] ?? 'product_cat' ) );
    $term_ids = function_exists( 'gh_ajax_int_array' )
        ? gh_ajax_int_array( 'term_ids' )
        : array_values( array_filter( array_map( 'intval', (array) json_decode( stripslashes( (string) ( $_POST['term_ids'] ?? '[]' ) ), true ) ) ) );

    if ( empty( $term_ids ) ) wp_send_json_error( 'Nessun termine selezionato.' );

    $ids = rp_cm_get_products_for_terms( $term_ids, $taxonomy );
    wp_send_json_success( [
        'product_ids' => $ids,
        'count'       => count( $ids ),
        'taxonomy'    => $taxonomy,
    ] );
} );

add_action( 'wp_ajax_gh_ajax_taxonomy_query', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $taxonomy = sanitize_key( $_POST['taxonomy'] ?? 'product_cat' );

    $args = [
        'taxonomy'     => $taxonomy,
        'search'       => sanitize_text_field( $_POST['search'] ?? '' ),
        'orderby'      => sanitize_key( $_POST['orderby'] ?? 'name' ),
        'order'        => sanitize_key( $_POST['order'] ?? 'asc' ),
        'limit'        => isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50,
        'offset'       => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
        'has_products' => ! empty( $_POST['has_products'] ),
    ];

    if ( isset( $_POST['parent'] ) && $_POST['parent'] !== '' ) {
        $args['parent'] = (int) $_POST['parent'];
    }
    if ( ! empty( $_POST['ancestor'] ) ) {
        $args['ancestor'] = (int) $_POST['ancestor'];
    }
    if ( isset( $_POST['depth_min'] ) && $_POST['depth_min'] !== '' ) {
        $args['depth_min'] = (int) $_POST['depth_min'];
    }
    if ( isset( $_POST['depth_max'] ) && $_POST['depth_max'] !== '' ) {
        $args['depth_max'] = (int) $_POST['depth_max'];
    }
    if ( isset( $_POST['min_count'] ) && $_POST['min_count'] !== '' ) {
        $args['min_count'] = (int) $_POST['min_count'];
    }
    if ( isset( $_POST['max_count'] ) && $_POST['max_count'] !== '' ) {
        $args['max_count'] = (int) $_POST['max_count'];
    }

    // Cross-taxonomy filters (accept CSV or JSON array of term IDs).
    $parse_ids = static function ( $raw ): array {
        if ( is_array( $raw ) ) return array_map( 'intval', $raw );
        $raw = (string) $raw;
        if ( $raw === '' ) return [];
        $decoded = json_decode( stripslashes( $raw ), true );
        if ( is_array( $decoded ) ) return array_map( 'intval', $decoded );
        return array_filter( array_map( 'intval', preg_split( '/[\s,;]+/', $raw ) ) );
    };
    if ( ! empty( $_POST['in_product_cat'] ) ) {
        $args['in_product_cat'] = $parse_ids( $_POST['in_product_cat'] );
    }
    if ( ! empty( $_POST['in_product_brand'] ) ) {
        $args['in_product_brand'] = $parse_ids( $_POST['in_product_brand'] );
    }

    $result = rp_cm_query_taxonomies( $args );
    wp_send_json_success( $result );
} );

// ═══ NAVIGATION: LIST MENUS ═══════════════════════════════════════════════

add_action( 'wp_ajax_gh_ajax_nav_menus', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( gh_nav_get_menus() );
} );

// ═══ NAVIGATION: LIST ITEMS OF A MENU ═════════════════════════════════════

add_action( 'wp_ajax_gh_ajax_nav_items', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $menu_id = (int) ( $_POST['menu_id'] ?? 0 );
    if ( ! $menu_id ) { wp_send_json_error( 'menu_id mancante.' ); }

    wp_send_json_success( gh_nav_get_menu_items( $menu_id ) );
} );

// ═══ NAVIGATION: POPULATE (one-shot) ══════════════════════════════════════
// Accetta esplicitamente una lista di term_id gia calcolata dalla UI
// (dopo preview via gh_ajax_taxonomy_query). La logica di scelta dei termini
// resta lato UI cosi l'utente vede ESATTAMENTE cosa verra scritto.

add_action( 'wp_ajax_gh_ajax_nav_populate', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $menu_id        = (int) ( $_POST['menu_id'] ?? 0 );
    $parent_item_id = (int) ( $_POST['parent_item_id'] ?? 0 );
    $taxonomy       = sanitize_key( $_POST['taxonomy'] ?? 'product_cat' );
    $replace        = isset( $_POST['replace_managed'] ) ? (bool) (int) $_POST['replace_managed'] : true;

    $raw      = stripslashes( $_POST['term_ids'] ?? '[]' );
    $term_ids = json_decode( $raw, true );

    if ( ! $menu_id )                 { wp_send_json_error( 'menu_id mancante.' ); }
    if ( ! is_array( $term_ids ) )    { wp_send_json_error( 'term_ids non valido.' ); }
    if ( ! count( $term_ids ) )       { wp_send_json_error( 'Nessun termine selezionato.' ); }

    $result = gh_nav_populate_from_terms( $menu_id, $parent_item_id, $term_ids, $taxonomy, [
        'replace_managed' => $replace,
    ] );
    wp_send_json_success( $result );
} );

// ═══ NAVIGATION: CLEAR MANAGED CHILDREN ═══════════════════════════════════

add_action( 'wp_ajax_gh_ajax_nav_clear_managed', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $menu_id        = (int) ( $_POST['menu_id'] ?? 0 );
    $parent_item_id = (int) ( $_POST['parent_item_id'] ?? 0 );
    if ( ! $menu_id ) { wp_send_json_error( 'menu_id mancante.' ); }

    $removed = gh_nav_clear_managed_children( $menu_id, $parent_item_id );
    wp_send_json_success( [ 'removed' => $removed ] );
} );

// ═══ NAVIGATION: DELETE ITEM ══════════════════════════════════════════════

add_action( 'wp_ajax_gh_ajax_nav_delete_item', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $item_id = (int) ( $_POST['item_id'] ?? 0 );
    if ( ! $item_id ) { wp_send_json_error( 'item_id mancante.' ); }

    $ok = gh_nav_delete_item( $item_id );
    if ( ! $ok ) { wp_send_json_error( 'Eliminazione fallita.' ); }
    wp_send_json_success( [ 'deleted' => $item_id ] );
} );
