<?php
/**
 * AJAX handlers — bridge tra UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── SCAN: Safe cleanup (mapping + diff) ─────────────────────
// Flusso a due fasi visibile all'utente:
// 1. Mapping: costruiamo rp_mm_build_usage_map() e ritorniamo il breakdown
//    per sorgente (featured, variations, gallery, posts, inline).
// 2. Diff: rp_mm_get_orphan_attachments() con lo stesso usage_map. Gli
//    orfani che arrivano al client sono il complemento esatto dell'insieme
//    "mapped/used", quindi "100% sicuri".
//
// Performance: le ottimizzazioni vivono in scanner.php (query $wpdb dirette,
// niente file I/O su media in uso, URL map precomputata per il content scan).
// Qui ci limitiamo a bump limits + error trap per convertire i fatal PHP in
// un JSON pulito che l'UI puo mostrare come toast invece di un 500 muto.
add_action( 'wp_ajax_rp_mm_ajax_scan', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    // Store grandi: alza i limiti solo per questa request.
    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'admin' );
    }

    // Cap sul numero di orfani con metadati completi restituiti all'UI.
    // Gli ID sono sempre tutti ritornati cosi bulkDelete + "delete all"
    // funzionano anche sopra il cap.
    $display_cap = max( 100, min( 5000, intval( $_POST['display_cap'] ?? 2000 ) ) );

    try {
        $usage_map    = rp_mm_build_usage_map();
        $all_ids      = rp_mm_get_all_attachment_ids( 'image' );
        $orphan_pack  = rp_mm_get_orphan_attachments( $usage_map, $display_cap );
        $orphans      = $orphan_pack['orphans'];
        $orphan_ids   = $orphan_pack['orphan_ids'];
        $size_info    = rp_mm_estimate_orphan_size( $orphans );

        $breakdown = [
            'featured_products'   => count( $usage_map['featured_products'] ),
            'featured_variations' => count( $usage_map['featured_variations'] ),
            'gallery_products'    => count( $usage_map['gallery_products'] ),
            'featured_posts'      => count( $usage_map['featured_posts'] ),
            'inline_content'      => count( $usage_map['inline_content'] ),
        ];

        wp_send_json_success( [
            'breakdown'      => $breakdown,
            'total_media'    => count( $all_ids ),
            'used_count'     => count( $usage_map['all_used'] ),
            'orphan_count'   => count( $orphan_ids ),
            'orphans'        => $orphans,       // capped a display_cap
            'orphan_ids'     => $orphan_ids,    // tutti gli ID, non cappati
            'display_cap'    => $display_cap,
            'capped'         => count( $orphan_ids ) > count( $orphans ),
            'estimated_size' => $size_info,     // basata sul subset
        ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Scan fallita: ' . $e->getMessage() );
    }
} );

// ── MAPPING: Product-media map ──────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_mapping', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $filters = [];
    if ( ! empty( $_POST['status'] ) ) $filters['status'] = sanitize_text_field( $_POST['status'] );

    wp_send_json_success( rp_mm_get_product_media_map( $filters ) );
} );

// ── MAPPING: Get attachment usage ───────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_usage', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['attachment_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'attachment_id mancante.' ); }

    wp_send_json_success( rp_mm_get_attachment_usage( $id ) );
} );

// ── MAPPING: Set featured image ─────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_set_featured', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $product_id    = intval( $_POST['product_id'] ?? 0 );
    $attachment_id = intval( $_POST['attachment_id'] ?? 0 );

    if ( ! $product_id || ! $attachment_id ) { wp_send_json_error( 'Parametri mancanti.' ); }

    $result = rp_mm_set_product_featured_image( $product_id, $attachment_id );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'product_id' => $product_id ] );
} );

// ── MAPPING: Set gallery ────────────────────────────────────
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

    wp_send_json_success( [ 'product_id' => $product_id ] );
} );

// ── WHITELIST: Get ──────────────────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_get_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_mm_get_whitelist() );
} );

// ── WHITELIST: Add ──────────────────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_add_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id     = intval( $_POST['attachment_id'] ?? 0 ) ?: null;
    $url    = sanitize_url( $_POST['url'] ?? '' ) ?: null;
    $reason = sanitize_text_field( $_POST['reason'] ?? '' );

    if ( ! $id && ! $url ) { wp_send_json_error( 'Serve almeno attachment_id o url.' ); }

    rp_mm_add_to_whitelist( $id, $url, $reason );
    wp_send_json_success( rp_mm_get_whitelist() );
} );

// ── WHITELIST: Remove ───────────────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_remove_whitelist', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['attachment_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'attachment_id mancante.' ); }

    rp_mm_remove_from_whitelist( $id );
    wp_send_json_success( rp_mm_get_whitelist() );
} );

// ── DELETE: Single ──────────────────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_delete_one', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['attachment_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'attachment_id mancante.' ); }

    $result = rp_mm_delete_attachment( $id );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'deleted' => $id ] );
} );

// ── DELETE: Bulk ────────────────────────────────────────────
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
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Bulk delete fallito: ' . $e->getMessage() );
    }
} );

// ── LOG: Deletion log ───────────────────────────────────────
add_action( 'wp_ajax_rp_mm_ajax_get_log', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $limit = intval( $_POST['limit'] ?? 100 );
    wp_send_json_success( rp_mm_get_deletion_log( $limit ) );
} );
