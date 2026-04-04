<?php
/**
 * AJAX handlers — bulk actions + sorter.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── GET BULK ACTION DEFINITIONS ─────────────────────────────────
add_action( 'wp_ajax_gh_ajax_bulk_meta', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( [
        'actions'    => gh_get_bulk_action_definitions(),
        'sort_rules' => gh_get_sort_rules(),
    ] );
} );

// ── EXECUTE BULK ACTION ─────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_bulk_execute', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $action = sanitize_key( $_POST['bulk_action'] ?? '' );
    if ( empty( $action ) ) {
        wp_send_json_error( 'Azione bulk mancante.' );
    }

    // Product IDs: possono arrivare come JSON array o da filtro
    $ids_raw = stripslashes( $_POST['product_ids'] ?? '[]' );
    $product_ids = json_decode( $ids_raw, true );

    if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
        wp_send_json_error( 'Nessun prodotto selezionato.' );
    }

    // Parametri azione
    $params_raw = stripslashes( $_POST['params'] ?? '{}' );
    $params     = json_decode( $params_raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON parametri non valido.' );
    }

    // Sanitizza parametri in base al tipo
    $params = gh_sanitize_bulk_params( $action, $params );

    $result = gh_execute_bulk_action( $action, $product_ids, $params );

    wp_send_json_success( $result );
} );

// ── SORT PREVIEW ────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sort_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule = sanitize_key( $_POST['rule'] ?? '' );
    if ( empty( $rule ) ) {
        wp_send_json_error( 'Regola di ordinamento mancante.' );
    }

    $ids_raw     = stripslashes( $_POST['product_ids'] ?? '[]' );
    $product_ids = json_decode( $ids_raw, true );

    if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
        wp_send_json_error( 'Nessun prodotto da ordinare.' );
    }

    $start = intval( $_POST['start_order'] ?? 10 );
    $step  = intval( $_POST['step'] ?? 10 );

    $result = gh_sort_preview( $product_ids, $rule, $start, $step );

    wp_send_json_success( $result );
} );

// ── SORT APPLY ──────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sort_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule = sanitize_key( $_POST['rule'] ?? '' );
    if ( empty( $rule ) ) {
        wp_send_json_error( 'Regola di ordinamento mancante.' );
    }

    $ids_raw     = stripslashes( $_POST['product_ids'] ?? '[]' );
    $product_ids = json_decode( $ids_raw, true );

    if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
        wp_send_json_error( 'Nessun prodotto da ordinare.' );
    }

    $start = intval( $_POST['start_order'] ?? 10 );
    $step  = intval( $_POST['step'] ?? 10 );

    $result = gh_sort_products( $product_ids, $rule, $start, $step );

    wp_send_json_success( $result );
} );

// ── LOAD CATEGORY PRODUCTS (ordered) ────────────────────────────
add_action( 'wp_ajax_gh_ajax_category_order', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $cat_id = intval( $_POST['category_id'] ?? 0 );
    if ( ! $cat_id ) { wp_send_json_error( 'ID categoria mancante.' ); }

    $products = gh_get_category_ordered_products( $cat_id );
    $term     = get_term( $cat_id, 'product_cat' );

    wp_send_json_success( [
        'category_id'   => $cat_id,
        'category_name' => $term ? $term->name : '#' . $cat_id,
        'products'      => $products,
        'count'         => count( $products ),
    ] );
} );

// ── REPOSITION PREVIEW ──────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_reposition_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $cat_id    = intval( $_POST['category_id'] ?? 0 );
    $operation = sanitize_key( $_POST['operation'] ?? '' );
    $target    = intval( $_POST['target'] ?? 0 );
    $move_raw  = stripslashes( $_POST['move_ids'] ?? '[]' );
    $move_ids  = json_decode( $move_raw, true );

    if ( ! $cat_id || ! $operation || ! is_array( $move_ids ) || empty( $move_ids ) ) {
        wp_send_json_error( 'Parametri mancanti.' );
    }

    $result = gh_reposition_preview( $cat_id, $move_ids, $operation, $target );
    wp_send_json_success( $result );
} );

// ── REPOSITION APPLY ────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_reposition_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $cat_id    = intval( $_POST['category_id'] ?? 0 );
    $operation = sanitize_key( $_POST['operation'] ?? '' );
    $target    = intval( $_POST['target'] ?? 0 );
    $move_raw  = stripslashes( $_POST['move_ids'] ?? '[]' );
    $move_ids  = json_decode( $move_raw, true );

    if ( ! $cat_id || ! $operation || ! is_array( $move_ids ) || empty( $move_ids ) ) {
        wp_send_json_error( 'Parametri mancanti.' );
    }

    $result = gh_reposition_products( $cat_id, $move_ids, $operation, $target );
    wp_send_json_success( $result );
} );

// ── HELPERS ─────────────────────────────────────────────────────

/**
 * Sanitizza i parametri in base all'azione.
 */
function gh_sanitize_bulk_params( string $action, array $params ): array {

    return match ( $action ) {
        'assign_categories', 'remove_categories', 'set_categories' =>
            [ 'category_ids' => array_map( 'intval', $params['category_ids'] ?? [] ) ],

        'assign_tags', 'remove_tags' =>
            [ 'tag_ids' => array_map( 'intval', $params['tag_ids'] ?? [] ) ],

        'set_status' =>
            [ 'status' => sanitize_key( $params['status'] ?? 'publish' ) ],

        'set_sale_percent' =>
            [ 'percent' => floatval( $params['percent'] ?? 0 ) ],

        'remove_sale' => [],

        'adjust_price' => [
            'amount' => floatval( $params['amount'] ?? 0 ),
            'target' => sanitize_key( $params['target'] ?? 'regular_price' ),
        ],

        'set_stock_status' =>
            [ 'stock_status' => sanitize_key( $params['stock_status'] ?? 'instock' ) ],

        'set_stock_quantity' =>
            [ 'quantity' => intval( $params['quantity'] ?? 0 ) ],

        'set_seo_template' => [
            'meta_title_template'       => sanitize_text_field( $params['meta_title_template'] ?? '' ),
            'meta_description_template' => sanitize_text_field( $params['meta_description_template'] ?? '' ),
        ],

        'set_menu_order' =>
            [ 'menu_order' => intval( $params['menu_order'] ?? 0 ) ],

        default => $params,
    };
}
