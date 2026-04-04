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

// ── INLINE UPDATE PRODUCT (single field) ────────────────────────
add_action( 'wp_ajax_gh_ajax_inline_update', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id    = intval( $_POST['product_id'] ?? 0 );
    $field = sanitize_key( $_POST['field'] ?? '' );
    $value = $_POST['value'] ?? '';

    if ( ! $id || ! $field ) {
        wp_send_json_error( 'ID prodotto o campo mancante.' );
    }

    // Sanitize value based on field
    $data = match ( $field ) {
        'name'              => [ 'name' => sanitize_text_field( $value ) ],
        'sku'               => [ 'sku' => sanitize_text_field( $value ) ],
        'regular_price'     => [ 'regular_price' => sanitize_text_field( $value ) ],
        'sale_price'        => [ 'sale_price' => sanitize_text_field( $value ) ],
        'status'            => [ 'status' => sanitize_key( $value ) ],
        'stock_status'      => [ 'stock_status' => sanitize_key( $value ) ],
        'stock_quantity'    => [ 'stock_quantity' => intval( $value ), 'manage_stock' => true ],
        'weight'            => [ 'weight' => sanitize_text_field( $value ) ],
        'menu_order'        => 'menu_order',
        'meta_title'        => [ 'meta_title' => sanitize_text_field( $value ) ],
        'meta_description'  => [ 'meta_description' => sanitize_text_field( $value ) ],
        'focus_keyword'     => [ 'focus_keyword' => sanitize_text_field( $value ) ],
        default             => null,
    };

    if ( $data === null ) {
        wp_send_json_error( "Campo non modificabile: {$field}" );
    }

    // menu_order is special — not in rp_update_product
    if ( $data === 'menu_order' ) {
        wp_update_post( [ 'ID' => $id, 'menu_order' => intval( $value ) ] );
        wp_send_json_success( [ 'id' => $id, 'field' => $field, 'value' => intval( $value ) ] );
        return;
    }

    $result = rp_update_product( $id, $data );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    // Return updated product row for table refresh
    $product = wc_get_product( $id );
    wp_send_json_success( [
        'id'      => $id,
        'field'   => $field,
        'value'   => $value,
        'product' => $product ? gh_serialize_product_row( $product ) : null,
    ] );
} );

// ── GET PRODUCT DETAIL (for expand/variations) ──────────────────
add_action( 'wp_ajax_gh_ajax_product_detail', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['product_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'ID mancante.' ); }

    $product_data = rp_get_product( $id );
    if ( isset( $product_data['error'] ) ) {
        wp_send_json_error( $product_data['error'] );
    }

    $variations = [];
    $product = wc_get_product( $id );
    if ( $product && $product->is_type( 'variable' ) ) {
        $variations = rp_get_product_variations( $id );
    }

    wp_send_json_success( [
        'product'    => $product_data,
        'variations' => $variations,
    ] );
} );

// ── UPDATE VARIATION (inline) ───────────────────────────────────
add_action( 'wp_ajax_gh_ajax_inline_update_variation', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $var_id = intval( $_POST['variation_id'] ?? 0 );
    $field  = sanitize_key( $_POST['field'] ?? '' );
    $value  = $_POST['value'] ?? '';

    if ( ! $var_id || ! $field ) {
        wp_send_json_error( 'ID variante o campo mancante.' );
    }

    $data = match ( $field ) {
        'regular_price'  => [ 'regular_price' => sanitize_text_field( $value ) ],
        'sale_price'     => [ 'sale_price' => sanitize_text_field( $value ) ],
        'sku'            => [ 'sku' => sanitize_text_field( $value ) ],
        'status'         => [ 'status' => sanitize_key( $value ) ],
        'stock_quantity' => [ 'stock_quantity' => intval( $value ) ],
        'stock_status'   => [ 'stock_status' => sanitize_key( $value ) ],
        default          => null,
    };

    if ( $data === null ) {
        wp_send_json_error( "Campo variante non modificabile: {$field}" );
    }

    $result = rp_update_variation( $var_id, $data );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( [ 'variation_id' => $var_id, 'field' => $field, 'value' => $value ] );
} );
