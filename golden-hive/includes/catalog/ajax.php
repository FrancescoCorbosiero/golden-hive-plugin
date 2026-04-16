<?php
/**
 * AJAX handlers — collegano UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── EXPORT ROUNDTRIP ────────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_export_roundtrip', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $filters = [];
    if ( ! empty( $_POST['filters'] ) ) {
        $raw     = stripslashes( $_POST['filters'] );
        $filters = json_decode( $raw, true ) ?: [];
    }

    $result = rp_cm_export_roundtrip( $filters );
    wp_send_json_success( $result );
} );

// ── IMPORT PREVIEW (dry-run) ────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_import_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['json_payload'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    $valid = rp_cm_validate_import_json( $data );
    if ( is_wp_error( $valid ) ) {
        wp_send_json_error( $valid->get_error_message() );
    }

    $mode   = sanitize_text_field( $_POST['mode'] ?? 'update_only' );
    $result = rp_cm_import_preview( $data, $mode );
    wp_send_json_success( $result );
} );

// ── IMPORT APPLY ────────────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_import_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['json_payload'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    $valid = rp_cm_validate_import_json( $data );
    if ( is_wp_error( $valid ) ) {
        wp_send_json_error( $valid->get_error_message() );
    }

    $mode   = sanitize_text_field( $_POST['mode'] ?? 'update_only' );
    $result = rp_cm_import_apply( $data, $mode );
    wp_send_json_success( $result );
} );

// ── GET TREE PATHS (per filtri UI) ──────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_get_tree_paths', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_cm_get_available_paths() );
} );

// ── BULK IMPORT PREVIEW ─────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_bulk_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['json_payload'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    $data = rp_cm_validate_bulk_json( $data );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( $data->get_error_message() );
    }

    $mode   = sanitize_text_field( $_POST['mode'] ?? 'create' );
    $result = rp_cm_bulk_preview( $data, $mode );
    wp_send_json_success( $result );
} );

// ── BULK IMPORT APPLY ───────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_bulk_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['json_payload'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    $data = rp_cm_validate_bulk_json( $data );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( $data->get_error_message() );
    }

    $mode   = sanitize_text_field( $_POST['mode'] ?? 'create' );
    $result = rp_cm_bulk_apply( $data, $mode );
    wp_send_json_success( $result );
} );

/**
 * Helper interno: legge e valida il parametro `taxonomy` dagli AJAX.
 * Tutti gli handler di tassonomia supportano product_cat (default) e
 * product_brand quando la tassonomia e registrata.
 */
function gh_ajax_read_taxonomy(): string {
    $tax = sanitize_key( $_POST['taxonomy'] ?? 'product_cat' );
    return rp_cm_normalize_taxonomy( $tax );
}

// ── TAXONOMY: GET SUPPORTED TAXONOMIES ──────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_sources', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $labels = [
        'product_cat'   => 'Categorie',
        'product_brand' => 'Brand',
    ];
    $out = [];
    foreach ( rp_cm_supported_taxonomies() as $tax ) {
        $out[] = [ 'key' => $tax, 'label' => $labels[ $tax ] ?? $tax ];
    }
    wp_send_json_success( $out );
} );

// ── TAXONOMY: GET TREE ──────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_tree', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_cm_get_taxonomy_tree( gh_ajax_read_taxonomy() ) );
} );

// ── TAXONOMY: CREATE TERM ───────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_create', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $name      = sanitize_text_field( $_POST['name'] ?? '' );
    $parent_id = intval( $_POST['parent_id'] ?? 0 );
    $slug      = sanitize_text_field( $_POST['slug'] ?? '' );
    $taxonomy  = gh_ajax_read_taxonomy();

    $result = rp_cm_create_category( $name, $parent_id, $slug, $taxonomy );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'term_id' => $result ] );
} );

// ── TAXONOMY: RENAME TERM ───────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_rename', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id  = intval( $_POST['term_id'] ?? 0 );
    $name     = sanitize_text_field( $_POST['name'] ?? '' );
    $slug     = sanitize_text_field( $_POST['slug'] ?? '' );
    $taxonomy = gh_ajax_read_taxonomy();

    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $result = rp_cm_rename_category( $term_id, $name, $slug, $taxonomy );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'term_id' => $term_id ] );
} );

// ── TAXONOMY: MOVE TERM ─────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_move', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id       = intval( $_POST['term_id'] ?? 0 );
    $new_parent_id = intval( $_POST['new_parent_id'] ?? 0 );
    $taxonomy      = gh_ajax_read_taxonomy();

    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $result = rp_cm_move_category( $term_id, $new_parent_id, $taxonomy );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'term_id' => $term_id ] );
} );

// ── TAXONOMY: DELETE TERM ───────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_delete', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id  = intval( $_POST['term_id'] ?? 0 );
    $taxonomy = gh_ajax_read_taxonomy();
    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $result = rp_cm_delete_category( $term_id, true, $taxonomy );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'deleted' => $term_id ] );
} );

// ── TAXONOMY: GET PRODUCTS IN TERM ──────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_products', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id  = intval( $_POST['term_id'] ?? 0 );
    $taxonomy = gh_ajax_read_taxonomy();
    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    wp_send_json_success( rp_cm_get_category_products( $term_id, true, $taxonomy ) );
} );

// ── TAXONOMY: SET PRODUCT TERMS ─────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_assign', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $raw        = stripslashes( $_POST['category_ids'] ?? '[]' );
    $term_ids   = json_decode( $raw, true );
    $taxonomy   = gh_ajax_read_taxonomy();

    if ( ! $product_id ) { wp_send_json_error( 'product_id mancante.' ); }
    if ( ! is_array( $term_ids ) ) { wp_send_json_error( 'term_ids non valido.' ); }

    $result = rp_cm_set_product_categories( $product_id, $term_ids, $taxonomy );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'product_id' => $product_id ] );
} );

// ═══ SMART TAXONOMY ═════════════════════════════════════════════════════════

// ── GET RULE FOR TERM ───────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_smart_rule_for_term', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id  = intval( $_POST['term_id'] ?? 0 );
    $taxonomy = sanitize_key( $_POST['taxonomy'] ?? 'product_cat' );

    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $rule = gh_smart_get_rule_for_term( $term_id, $taxonomy );
    wp_send_json_success( $rule ); // null se non esiste
} );

// ── SAVE RULE ───────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_smart_rule_save', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw = stripslashes( $_POST['rule'] ?? '{}' );
    $rule = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    if ( empty( $rule['term_id'] ) || empty( $rule['conditions'] ) ) {
        wp_send_json_error( 'term_id e conditions sono obbligatori.' );
    }

    $rule_id = gh_smart_save_rule( $rule );
    wp_send_json_success( [ 'rule_id' => $rule_id ] );
} );

// ── DELETE RULE ─────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_smart_rule_delete', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
    if ( ! $rule_id ) { wp_send_json_error( 'rule_id mancante.' ); }

    $ok = gh_smart_delete_rule( $rule_id );
    wp_send_json_success( [ 'deleted' => $ok ] );
} );

// ── SYNC (run) RULE ─────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_smart_rule_sync', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 180 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
    if ( ! $rule_id ) { wp_send_json_error( 'rule_id mancante.' ); }

    try {
        $result = gh_smart_sync_rule( $rule_id );
        if ( isset( $result['error'] ) ) { wp_send_json_error( $result['error'] ); }
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Sync fallita: ' . $e->getMessage() );
    }
} );

// ── PREVIEW (dry-run count) ─────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_smart_rule_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 120 );

    $raw = stripslashes( $_POST['conditions'] ?? '[]' );
    $conditions = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido.' );
    }

    try {
        $count = gh_smart_preview_count( $conditions );
        wp_send_json_success( [ 'count' => $count ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Preview fallita: ' . $e->getMessage() );
    }
} );

// ── SYNC ALL ────────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_smart_sync_all', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    try {
        $results = gh_smart_sync_all();
        wp_send_json_success( $results );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Sync All fallita: ' . $e->getMessage() );
    }
} );
