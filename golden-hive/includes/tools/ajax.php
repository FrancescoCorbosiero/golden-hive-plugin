<?php
/**
 * AJAX handlers — tools (nuclear cleanup, etc.)
 *
 * Each cleanup category has its own endpoint so the JS can call them
 * sequentially with live progress. If one step fails, the others
 * still run. Media deletion is further chunked (500/batch) to avoid
 * timeouts on large libraries.
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

// ── NUCLEAR CLEANUP: Execute single category ──────────────
add_action( 'wp_ajax_gh_ajax_nuclear_step', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $confirm = sanitize_text_field( $_POST['confirm'] ?? '' );
    if ( $confirm !== 'NUCLEAR' ) {
        wp_send_json_error( 'Conferma mancante.' );
    }

    $step = sanitize_key( $_POST['step'] ?? '' );
    if ( ! $step ) { wp_send_json_error( 'Step mancante.' ); }

    try {
        $target = [ $step => true ];
        $result = gh_nuclear_execute( $target );
        wp_send_json_success( [
            'step'   => $step,
            'result' => $result[ $step ] ?? 0,
        ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Step ' . $step . ' fallito: ' . $e->getMessage() );
    }
} );

// ── NUCLEAR CLEANUP: Media chunk (500 at a time) ──────────
add_action( 'wp_ajax_gh_ajax_nuclear_media_chunk', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 120 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $confirm = sanitize_text_field( $_POST['confirm'] ?? '' );
    if ( $confirm !== 'NUCLEAR' ) {
        wp_send_json_error( 'Conferma mancante.' );
    }

    global $wpdb;

    $wl_ids = gh_nuclear_get_whitelisted_ids();

    $where_not_wl = '';
    if ( ! empty( $wl_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $wl_ids ), '%d' ) );
        $where_not_wl = $wpdb->prepare( " AND ID NOT IN ($placeholders)", ...$wl_ids );
    }

    $batch = 500;
    $ids   = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        . $where_not_wl . " LIMIT {$batch}"
    );

    if ( empty( $ids ) ) {
        wp_send_json_success( [ 'deleted' => 0, 'remaining' => 0, 'done' => true ] );
    }

    remove_all_actions( 'delete_attachment' );
    remove_all_actions( 'wp_delete_file' );

    $deleted = 0;
    foreach ( $ids as $att_id ) {
        wp_delete_attachment( (int) $att_id, true );
        $deleted++;
    }

    $remaining = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" . $where_not_wl
    );

    wp_send_json_success( [
        'deleted'   => $deleted,
        'remaining' => $remaining,
        'done'      => $remaining === 0,
    ] );
} );
