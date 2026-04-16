<?php
/**
 * AJAX handlers — Media module (Media Library + Whitelist + Cleanup).
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 *
 * Endpoint attivi:
 *   gh_ajax_media_query                 — query paginaged con filtri
 *   gh_ajax_media_query_ids             — solo ID (select all in filter)
 *   gh_ajax_media_safe_cleanup_preview  — preview: N da eliminare, whitelist esclusi
 *   gh_ajax_media_bulk_whitelist        — whitelist bulk add (IDs + reason)
 *   gh_ajax_media_gallery_removal_preview — preview: quali prodotti verranno toccati
 *   gh_ajax_media_remove_from_galleries  — esegue l'unlink dalle gallerie
 *   rp_mm_ajax_bulk_delete              — delete chunked (safety: whitelist + is_used)
 *   rp_mm_ajax_usage                    — dettagli usage di un singolo media
 *   rp_mm_ajax_set_featured / set_gallery — ops singole usate inline
 *   rp_mm_ajax_{get,add,remove}_whitelist — whitelist CRUD
 *   rp_mm_ajax_get_log                  — deletion log
 *
 * Rimossi: rp_mm_ajax_scan (Safe Cleanup tab), rp_mm_ajax_browse (Browse tab),
 * rp_mm_ajax_mapping (Mapping tab), rp_mm_ajax_delete_one (non usato lato UI).
 * Le loro responsabilita sono assorbite dalla nuova Media Library.
 */

defined( 'ABSPATH' ) || exit;

// ═══ MEDIA LIBRARY ══════════════════════════════════════════════════════════

add_action( 'wp_ajax_gh_ajax_media_query', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 180 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $filters = [
        'filename'  => sanitize_text_field( $_POST['filename'] ?? '' ),
        'usage'     => sanitize_key( $_POST['usage'] ?? 'all' ),
        'whitelist' => sanitize_key( $_POST['whitelist'] ?? 'all' ),
    ];
    $pagination = [
        'page'     => intval( $_POST['page'] ?? 1 ),
        'per_page' => intval( $_POST['per_page'] ?? 100 ),
        'orderby'  => sanitize_key( $_POST['orderby'] ?? 'date' ),
        'order'    => strtoupper( sanitize_key( $_POST['order'] ?? 'DESC' ) ),
    ];

    try {
        wp_send_json_success( gh_media_query( $filters, $pagination ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Query fallita: ' . $e->getMessage() );
    }
} );

add_action( 'wp_ajax_gh_ajax_media_query_ids', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 180 );

    $filters = [
        'filename'  => sanitize_text_field( $_POST['filename'] ?? '' ),
        'usage'     => sanitize_key( $_POST['usage'] ?? 'all' ),
        'whitelist' => sanitize_key( $_POST['whitelist'] ?? 'all' ),
    ];

    try {
        $ids = gh_media_query_all_ids( $filters );
        wp_send_json_success( [ 'ids' => $ids, 'count' => count( $ids ) ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Query IDs fallita: ' . $e->getMessage() );
    }
} );

add_action( 'wp_ajax_gh_ajax_media_safe_cleanup_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 180 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    try {
        wp_send_json_success( gh_media_safe_cleanup_preview() );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Preview fallita: ' . $e->getMessage() );
    }
} );

add_action( 'wp_ajax_gh_ajax_media_bulk_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw    = stripslashes( $_POST['ids'] ?? '[]' );
    $ids    = json_decode( $raw, true );
    $reason = sanitize_text_field( $_POST['reason'] ?? '' );

    if ( ! is_array( $ids ) || empty( $ids ) ) {
        wp_send_json_error( 'Nessun ID fornito.' );
    }
    // Reason is optional

    $added = 0;
    foreach ( $ids as $id ) {
        $id = (int) $id;
        if ( $id > 0 ) {
            rp_mm_add_to_whitelist( $id, null, $reason );
            $added++;
        }
    }

    gh_media_invalidate_usage_index();

    wp_send_json_success( [ 'added' => $added ] );
} );

add_action( 'wp_ajax_gh_ajax_media_gallery_removal_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw = stripslashes( $_POST['ids'] ?? '[]' );
    $ids = json_decode( $raw, true );

    if ( ! is_array( $ids ) || empty( $ids ) ) {
        wp_send_json_error( 'Nessun ID fornito.' );
    }

    try {
        wp_send_json_success( gh_media_gallery_removal_preview( $ids ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Preview fallita: ' . $e->getMessage() );
    }
} );

add_action( 'wp_ajax_gh_ajax_media_remove_from_galleries', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 240 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $raw = stripslashes( $_POST['ids'] ?? '[]' );
    $ids = json_decode( $raw, true );

    if ( ! is_array( $ids ) || empty( $ids ) ) {
        wp_send_json_error( 'Nessun ID fornito.' );
    }

    try {
        wp_send_json_success( gh_media_remove_from_galleries( $ids ) );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Operazione fallita: ' . $e->getMessage() );
    }
} );

// ═══ SINGLE MEDIA OPS (legacy, ancora usate da inline ops) ══════════════════

add_action( 'wp_ajax_rp_mm_ajax_usage', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['attachment_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'attachment_id mancante.' ); }

    wp_send_json_success( rp_mm_get_attachment_usage( $id ) );
} );

add_action( 'wp_ajax_rp_mm_ajax_set_featured', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $product_id    = intval( $_POST['product_id'] ?? 0 );
    $attachment_id = intval( $_POST['attachment_id'] ?? 0 );

    if ( ! $product_id || ! $attachment_id ) { wp_send_json_error( 'Parametri mancanti.' ); }

    $result = rp_mm_set_product_featured_image( $product_id, $attachment_id );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    gh_media_invalidate_usage_index();
    wp_send_json_success( [ 'product_id' => $product_id ] );
} );

add_action( 'wp_ajax_rp_mm_ajax_set_gallery', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $raw        = stripslashes( $_POST['attachment_ids'] ?? '[]' );
    $ids        = json_decode( $raw, true );

    if ( ! $product_id ) { wp_send_json_error( 'product_id mancante.' ); }
    if ( ! is_array( $ids ) ) { wp_send_json_error( 'attachment_ids non valido.' ); }

    $result = rp_mm_set_product_gallery( $product_id, $ids );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    gh_media_invalidate_usage_index();
    wp_send_json_success( [ 'product_id' => $product_id ] );
} );

// ═══ WHITELIST ══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_mm_ajax_get_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_mm_get_whitelist() );
} );

add_action( 'wp_ajax_rp_mm_ajax_add_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id     = intval( $_POST['attachment_id'] ?? 0 ) ?: null;
    $url    = sanitize_url( $_POST['url'] ?? '' ) ?: null;
    $reason = sanitize_text_field( $_POST['reason'] ?? '' );

    if ( ! $id && ! $url ) { wp_send_json_error( 'Serve almeno attachment_id o url.' ); }

    rp_mm_add_to_whitelist( $id, $url, $reason );
    gh_media_invalidate_usage_index();
    wp_send_json_success( rp_mm_get_whitelist() );
} );

add_action( 'wp_ajax_rp_mm_ajax_remove_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['attachment_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'attachment_id mancante.' ); }

    rp_mm_remove_from_whitelist( $id );
    gh_media_invalidate_usage_index();
    wp_send_json_success( rp_mm_get_whitelist() );
} );

// ═══ DELETE (CHUNKED) ══════════════════════════════════════════════════════

// Supporta delete in chunk: se il client passa `chunk_size`, la action
// elimina solo i primi N ID e ritorna 'remaining' cosi l'UI puo loopare
// senza far andare in timeout una singola request.
add_action( 'wp_ajax_rp_mm_ajax_bulk_delete', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'admin' );
    }

    $raw = stripslashes( $_POST['ids'] ?? '[]' );
    $ids = json_decode( $raw, true );

    if ( ! is_array( $ids ) || empty( $ids ) ) { wp_send_json_error( 'Nessun ID fornito.' ); }

    $chunk_size = intval( $_POST['chunk_size'] ?? 0 );
    $remaining  = [];

    if ( $chunk_size > 0 && count( $ids ) > $chunk_size ) {
        $remaining = array_slice( $ids, $chunk_size );
        $ids       = array_slice( $ids, 0, $chunk_size );
    }

    try {
        $result = rp_mm_bulk_delete( $ids );
        $result['remaining_ids']   = $remaining;
        $result['remaining_count'] = count( $remaining );
        gh_media_invalidate_usage_index();
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Bulk delete fallito: ' . $e->getMessage() );
    }
} );

// ═══ LOG ═══════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_mm_ajax_get_log', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $limit = intval( $_POST['limit'] ?? 100 );
    wp_send_json_success( rp_mm_get_deletion_log( $limit ) );
} );
