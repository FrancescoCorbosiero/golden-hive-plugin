<?php
/**
 * AJAX handlers — collegano UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── EXPORT CATALOG ──────────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_export_catalog', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $filters = [];
    if ( ! empty( $_POST['filters'] ) ) {
        $raw     = stripslashes( $_POST['filters'] );
        $filters = json_decode( $raw, true ) ?: [];
    }

    $result = rp_cm_export_catalog( $filters );
    wp_send_json_success( $result );
} );

// ── EXPORT ROUNDTRIP ────────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_export_roundtrip', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
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
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
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
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
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

// ── GET SUMMARY (fast, no tree) ─────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_get_summary', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $start    = microtime( true );
    $products = rp_cm_get_all_products( [ 'status' => 'publish' ] );

    $total          = count( $products );
    $in_stock       = 0;
    $variant_count  = 0;
    $variants_in_stock = 0;
    $brands         = [];
    $categories     = [];

    foreach ( $products as $product ) {
        $id       = $product->get_id();
        $variants = rp_cm_get_product_variants( $id );

        if ( empty( $variants ) ) {
            if ( $product->get_stock_status() === 'instock' ) $in_stock++;
        } else {
            $has_stock = false;
            foreach ( $variants as $v ) {
                $variant_count++;
                if ( $v->get_stock_status() === 'instock' ) {
                    $variants_in_stock++;
                    $has_stock = true;
                }
            }
            if ( $has_stock ) $in_stock++;
        }

        $terms = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'all' ] );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( $term->parent === 0 ) continue;
                $parent = get_term( $term->parent, 'product_cat' );
                if ( ! is_wp_error( $parent ) && $parent->parent === 0 ) {
                    $brands[ $term->name ] = true;
                } else {
                    $categories[ $term->name ] = true;
                }
            }
        }
    }

    wp_send_json_success( [
        'total_products'          => $total,
        'total_in_stock'          => $in_stock,
        'total_variants'          => $variant_count,
        'total_variants_in_stock' => $variants_in_stock,
        'categories'              => count( $categories ),
        'brands'                  => count( $brands ),
        'generated_in_seconds'    => round( microtime( true ) - $start, 2 ),
    ] );
} );

// ── GET TREE PATHS (per filtri UI) ──────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_get_tree_paths', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_cm_get_available_paths() );
} );

// ── BULK IMPORT PREVIEW ─────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_bulk_preview', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
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
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
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

// ── TAXONOMY: GET TREE ──────────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_tree', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_cm_get_taxonomy_tree() );
} );

// ── TAXONOMY: CREATE CATEGORY ───────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_create', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $name      = sanitize_text_field( $_POST['name'] ?? '' );
    $parent_id = intval( $_POST['parent_id'] ?? 0 );
    $slug      = sanitize_text_field( $_POST['slug'] ?? '' );

    $result = rp_cm_create_category( $name, $parent_id, $slug );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'term_id' => $result ] );
} );

// ── TAXONOMY: RENAME CATEGORY ───────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_rename', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id = intval( $_POST['term_id'] ?? 0 );
    $name    = sanitize_text_field( $_POST['name'] ?? '' );
    $slug    = sanitize_text_field( $_POST['slug'] ?? '' );

    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $result = rp_cm_rename_category( $term_id, $name, $slug );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'term_id' => $term_id ] );
} );

// ── TAXONOMY: MOVE CATEGORY ─────────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_move', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id       = intval( $_POST['term_id'] ?? 0 );
    $new_parent_id = intval( $_POST['new_parent_id'] ?? 0 );

    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $result = rp_cm_move_category( $term_id, $new_parent_id );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'term_id' => $term_id ] );
} );

// ── TAXONOMY: DELETE CATEGORY ───────────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_delete', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id = intval( $_POST['term_id'] ?? 0 );
    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    $result = rp_cm_delete_category( $term_id );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'deleted' => $term_id ] );
} );

// ── TAXONOMY: GET PRODUCTS IN CATEGORY ──────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_products', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $term_id = intval( $_POST['term_id'] ?? 0 );
    if ( ! $term_id ) { wp_send_json_error( 'term_id mancante.' ); }

    wp_send_json_success( rp_cm_get_category_products( $term_id ) );
} );

// ── TAXONOMY: SET PRODUCT CATEGORIES ────────────────────────
add_action( 'wp_ajax_rp_cm_ajax_taxonomy_assign', function () {
    check_ajax_referer( 'rp_cm_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $product_id   = intval( $_POST['product_id'] ?? 0 );
    $raw          = stripslashes( $_POST['category_ids'] ?? '[]' );
    $category_ids = json_decode( $raw, true );

    if ( ! $product_id ) { wp_send_json_error( 'product_id mancante.' ); }
    if ( ! is_array( $category_ids ) ) { wp_send_json_error( 'category_ids non valido.' ); }

    $result = rp_cm_set_product_categories( $product_id, $category_ids );
    if ( is_wp_error( $result ) ) { wp_send_json_error( $result->get_error_message() ); }

    wp_send_json_success( [ 'product_id' => $product_id ] );
} );
