<?php
/**
 * AJAX handlers — tools (nuclear cleanup, etc.)
 */

defined( 'ABSPATH' ) || exit;

// ── NUCLEAR CLEANUP: Preview ──────────────────────────────
add_action( 'wp_ajax_gh_ajax_nuclear_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw     = stripslashes( $_POST['targets'] ?? '{}' );
    $targets = json_decode( $raw, true ) ?: [];

    try {
        $preview = gh_nuclear_preview( $targets );
        wp_send_json_success( $preview );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Preview fallito: ' . $e->getMessage() );
    }
} );

// ── NUCLEAR CLEANUP: Execute ──────────────────────────────
add_action( 'wp_ajax_gh_ajax_nuclear_execute', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 600 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $raw     = stripslashes( $_POST['targets'] ?? '{}' );
    $targets = json_decode( $raw, true ) ?: [];

    $confirm = sanitize_text_field( $_POST['confirm'] ?? '' );
    if ( $confirm !== 'NUCLEAR' ) {
        wp_send_json_error( 'Conferma mancante. Digita NUCLEAR per procedere.' );
    }

    try {
        $results = gh_nuclear_execute( $targets );
        wp_send_json_success( $results );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Cleanup fallito: ' . $e->getMessage() );
    }
} );
