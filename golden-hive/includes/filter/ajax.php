<?php
/**
 * AJAX handlers — filter engine.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── GET FILTER META (conditions, categories, tags, attributes) ──
add_action( 'wp_ajax_gh_ajax_filter_meta', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( gh_get_filter_meta() );
} );

// ── FILTER PRODUCTS ─────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_filter_products', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw        = stripslashes( $_POST['conditions'] ?? '[]' );
    $conditions = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON condizioni non valido: ' . json_last_error_msg() );
    }

    $options = [
        'per_page' => intval( $_POST['per_page'] ?? 50 ),
        'page'     => intval( $_POST['page'] ?? 1 ),
        'orderby'  => sanitize_key( $_POST['orderby'] ?? 'title' ),
        'order'    => strtoupper( sanitize_key( $_POST['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC',
    ];

    $result = gh_filter_products( $conditions, $options );

    wp_send_json_success( $result );
} );

// ── FILTER PRODUCT IDS ONLY (for bulk actions on full set) ──────
add_action( 'wp_ajax_gh_ajax_filter_ids', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw        = stripslashes( $_POST['conditions'] ?? '[]' );
    $conditions = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON condizioni non valido.' );
    }

    $ids = gh_filter_product_ids( $conditions );

    wp_send_json_success( [
        'product_ids' => $ids,
        'count'       => count( $ids ),
    ] );
} );
