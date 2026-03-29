<?php
/**
 * AJAX handlers — collegano UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── READ ────────────────────────────────────────────────────
add_action( 'wp_ajax_rp_ajax_read', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['product_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'ID prodotto mancante.' ); }

    $data = rp_get_product( $id );
    if ( isset( $data['error'] ) ) { wp_send_json_error( $data['error'] ); }

    wp_send_json_success( $data );
} );

// ── CREATE ───────────────────────────────────────────────────
add_action( 'wp_ajax_rp_ajax_create', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['json_payload'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    $result = rp_create_product( $data );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'id' => $result, 'product' => rp_get_product( $result ) ] );
} );

// ── UPDATE ───────────────────────────────────────────────────
add_action( 'wp_ajax_rp_ajax_update', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id   = intval( $_POST['product_id'] ?? 0 );
    $raw  = stripslashes( $_POST['json_payload'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( ! $id ) { wp_send_json_error( 'ID prodotto mancante.' ); }
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    foreach ( [ 'id', 'permalink', 'date_created', 'date_modified' ] as $k ) {
        unset( $data[ $k ] );
    }

    $result = rp_update_product( $id, $data );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'id' => $id, 'product' => rp_get_product( $id ) ] );
} );

// ── DELETE ───────────────────────────────────────────────────
add_action( 'wp_ajax_rp_ajax_delete', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id    = intval( $_POST['product_id'] ?? 0 );
    $force = filter_var( $_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN );

    if ( ! $id ) { wp_send_json_error( 'ID prodotto mancante.' ); }

    $result = rp_delete_product( $id, $force );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [
        'message' => $force
            ? "Prodotto #{$id} eliminato definitivamente."
            : "Prodotto #{$id} spostato nel cestino.",
    ] );
} );

// ── SEARCH ───────────────────────────────────────────────────
add_action( 'wp_ajax_rp_ajax_search', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $query = sanitize_text_field( $_POST['query'] ?? '' );
    wp_send_json_success( rp_search_products( $query ) );
} );

// ── GET VARIATIONS ───────────────────────────────────────────
add_action( 'wp_ajax_rp_ajax_get_variations', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id   = intval( $_POST['product_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'ID mancante.' ); }

    $vars = rp_get_product_variations( $id );
    if ( isset( $vars['error'] ) ) { wp_send_json_error( $vars['error'] ); }

    wp_send_json_success( $vars );
} );

// ── SAVE VARIATIONS (bulk) ───────────────────────────────────
add_action( 'wp_ajax_rp_ajax_save_variations', function () {
    check_ajax_referer( 'rp_crud_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw     = stripslashes( $_POST['updates'] ?? '[]' );
    $updates = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }
    if ( empty( $updates ) ) {
        wp_send_json_error( 'Nessun aggiornamento ricevuto.' );
    }

    $results    = rp_bulk_update_variations( $updates );
    $product_id = intval( $_POST['product_id'] ?? 0 );

    wp_send_json_success( [
        'results'    => $results,
        'errors'     => array_filter( $results, fn( $v ) => $v !== 'ok' ),
        'variations' => $product_id ? rp_get_product_variations( $product_id ) : [],
    ] );
} );
