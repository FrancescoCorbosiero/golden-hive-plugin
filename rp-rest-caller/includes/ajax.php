<?php
/**
 * AJAX handlers — bridge tra UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── GENERIC: Execute HTTP request ───────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_execute', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw    = stripslashes( $_POST['config'] ?? '{}' );
    $config = json_decode( $raw, true ) ?: [];

    $response = rp_rc_request( $config );
    if ( ! empty( $response['error'] ) ) {
        wp_send_json_error( $response['error'] );
    }

    // Parse body
    $format = rp_rc_detect_content_type( $response['headers']['content-type'] ?? '', $response['body'] );
    $parsed = rp_rc_parse_response( $response['body'], $format );

    wp_send_json_success( [
        'status'      => $response['status'],
        'headers'     => rp_rc_redact_sensitive_headers( $response['headers'] ),
        'body_raw'    => mb_substr( $response['body'], 0, 50000 ),
        'parsed'      => is_wp_error( $parsed ) ? null : $parsed,
        'format'      => $format,
        'duration_ms' => $response['duration_ms'],
    ] );
} );

// ── ENDPOINTS: CRUD ─────────────────────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_get_endpoints', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_rc_get_saved_endpoints() );
} );

add_action( 'wp_ajax_rp_rc_ajax_save_endpoint', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw    = stripslashes( $_POST['config'] ?? '{}' );
    $config = json_decode( $raw, true ) ?: [];
    $id     = rp_rc_save_endpoint( $config );

    wp_send_json_success( [ 'id' => $id ] );
} );

add_action( 'wp_ajax_rp_rc_ajax_delete_endpoint', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    rp_rc_delete_endpoint( $id );
    wp_send_json_success( [ 'deleted' => $id ] );
} );

// ── GOLDEN SNEAKERS: Fetch feed ─────────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_fetch', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw    = stripslashes( $_POST['config'] ?? '{}' );
    $config = json_decode( $raw, true ) ?: [];

    $products = rp_rc_gs_fetch( $config );
    if ( is_wp_error( $products ) ) {
        wp_send_json_error( $products->get_error_message() );
    }

    wp_send_json_success( [
        'product_count' => count( $products ),
        'products'      => $products,
    ] );
} );

// ── GOLDEN SNEAKERS: Preview (diff) ─────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_preview', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $woo_products = rp_rc_gs_transform_all( $products );
    $diff         = rp_rc_gs_diff( $woo_products );

    wp_send_json_success( $diff );
} );

// ── GOLDEN SNEAKERS: Apply import ───────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_apply', function () {
    check_ajax_referer( 'rp_rc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $raw_opts = stripslashes( $_POST['options'] ?? '{}' );
    $options  = json_decode( $raw_opts, true ) ?: [];

    $woo_products = rp_rc_gs_transform_all( $products );
    $diff         = rp_rc_gs_diff( $woo_products );
    $result       = rp_rc_gs_apply( $diff, $options );

    wp_send_json_success( $result );
} );
