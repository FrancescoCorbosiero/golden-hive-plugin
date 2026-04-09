<?php
/**
 * AJAX handlers — UI Mapper.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── GET MAPPER META (target fields + transform types) ──────────
add_action( 'wp_ajax_gh_ajax_mapper_meta', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( [
        'target_fields'   => gh_mapper_get_target_fields(),
        'transform_types' => gh_mapper_get_transform_types(),
    ] );
} );

// ── EXTRACT SOURCE PATHS FROM JSON SAMPLE ──────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_extract', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['json_sample'] ?? '' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    $paths = gh_mapper_extract_paths( $data );

    wp_send_json_success( [
        'paths' => $paths,
        'count' => count( $paths ),
    ] );
} );

// ── LIST ALL RULES ─────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_list_rules', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rules = gh_mapper_get_rules();

    // Return summary (without full source_sample to keep response light)
    $summary = array_map( fn( $r ) => [
        'id'            => $r['id'],
        'name'          => $r['name'] ?? 'Senza nome',
        'description'   => $r['description'] ?? '',
        'mapping_count' => count( $r['mappings'] ?? [] ),
        'items_path'    => $r['items_path'] ?? '',
        'created_at'    => $r['created_at'] ?? '',
        'updated_at'    => $r['updated_at'] ?? '',
    ], $rules );

    wp_send_json_success( $summary );
} );

// ── GET SINGLE RULE ────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_get_rule', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
    if ( empty( $rule_id ) ) {
        wp_send_json_error( 'ID regola mancante.' );
    }

    $rule = gh_mapper_get_rule( $rule_id );
    if ( ! $rule ) {
        wp_send_json_error( 'Regola non trovata.' );
    }

    wp_send_json_success( $rule );
} );

// ── SAVE RULE ──────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_save_rule', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['rule'] ?? '' );
    $rule = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $rule ) ) {
        wp_send_json_error( 'JSON regola non valido.' );
    }

    // Sanitize rule fields
    $clean = [
        'id'            => sanitize_text_field( $rule['id'] ?? '' ),
        'name'          => sanitize_text_field( $rule['name'] ?? 'Senza nome' ),
        'description'   => sanitize_text_field( $rule['description'] ?? '' ),
        'items_path'    => sanitize_text_field( $rule['items_path'] ?? '' ),
        'source_sample' => $rule['source_sample'] ?? null,
        'mappings'      => gh_mapper_sanitize_mappings( $rule['mappings'] ?? [] ),
    ];

    $saved = gh_mapper_save_rule( $clean );

    wp_send_json_success( $saved );
} );

// ── DELETE RULE ────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_delete_rule', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
    if ( empty( $rule_id ) ) {
        wp_send_json_error( 'ID regola mancante.' );
    }

    if ( ! gh_mapper_delete_rule( $rule_id ) ) {
        wp_send_json_error( 'Regola non trovata.' );
    }

    wp_send_json_success( 'Eliminata.' );
} );

// ── DUPLICATE RULE ─────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_duplicate_rule', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
    if ( empty( $rule_id ) ) {
        wp_send_json_error( 'ID regola mancante.' );
    }

    $copy = gh_mapper_duplicate_rule( $rule_id );
    if ( ! $copy ) {
        wp_send_json_error( 'Regola originale non trovata.' );
    }

    wp_send_json_success( $copy );
} );

// ── PREVIEW MAPPING ────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw_source   = stripslashes( $_POST['source_data'] ?? '' );
    $raw_mappings = stripslashes( $_POST['mappings'] ?? '' );
    $items_path   = sanitize_text_field( $_POST['items_path'] ?? '' );

    $source   = json_decode( $raw_source, true );
    $mappings = json_decode( $raw_mappings, true );

    if ( ! is_array( $source ) ) {
        wp_send_json_error( 'Dati sorgente non validi.' );
    }
    if ( ! is_array( $mappings ) ) {
        wp_send_json_error( 'Mappings non validi.' );
    }

    $results = gh_mapper_apply_rule_bulk( $source, $mappings, $items_path );

    // Limit preview to 20 items
    $total = count( $results );
    $preview = array_slice( $results, 0, 20 );

    wp_send_json_success( [
        'results' => $preview,
        'total'   => $total,
        'limited' => $total > 20,
    ] );
} );

// ── APPLY MAPPING TO WOOCOMMERCE ───────────────────────────────
add_action( 'wp_ajax_gh_ajax_mapper_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw_source = stripslashes( $_POST['source_data'] ?? '' );
    $rule_id    = sanitize_text_field( $_POST['rule_id'] ?? '' );
    $items_path = sanitize_text_field( $_POST['items_path'] ?? '' );
    $mode       = sanitize_key( $_POST['mode'] ?? 'create' );  // create | update_by_sku | create_or_update

    if ( empty( $rule_id ) ) {
        wp_send_json_error( 'ID regola mancante.' );
    }

    $rule = gh_mapper_get_rule( $rule_id );
    if ( ! $rule ) {
        wp_send_json_error( 'Regola non trovata.' );
    }

    $source = json_decode( $raw_source, true );
    if ( ! is_array( $source ) ) {
        wp_send_json_error( 'Dati sorgente non validi.' );
    }

    $mappings = $rule['mappings'] ?? [];
    $path     = $items_path ?: ( $rule['items_path'] ?? '' );
    $products = gh_mapper_apply_rule_bulk( $source, $mappings, $path );

    if ( empty( $products ) ) {
        wp_send_json_error( 'Nessun prodotto da processare.' );
    }

    $results = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => [] ];

    foreach ( $products as $p_data ) {
        $detail = [ 'name' => $p_data['name'] ?? '', 'sku' => $p_data['sku'] ?? '' ];

        try {
            if ( $mode === 'update_by_sku' || $mode === 'create_or_update' ) {
                // Try to find existing product by SKU
                $existing_id = $p_data['sku'] ? wc_get_product_id_by_sku( $p_data['sku'] ) : 0;

                if ( $existing_id ) {
                    $update_result = rp_update_product( $existing_id, $p_data );
                    if ( is_wp_error( $update_result ) ) {
                        $detail['status'] = 'error';
                        $detail['error']  = $update_result->get_error_message();
                        $results['errors']++;
                    } else {
                        $detail['status'] = 'updated';
                        $detail['id']     = $existing_id;
                        $results['updated']++;
                    }
                } elseif ( $mode === 'create_or_update' ) {
                    $type = $p_data['type'] ?? 'simple';
                    $new_id = $type === 'variable'
                        ? gh_create_variable_product( $p_data )
                        : gh_create_simple_product( $p_data );
                    $detail['status'] = 'created';
                    $detail['id']     = $new_id;
                    $results['created']++;
                } else {
                    $detail['status'] = 'skipped';
                    $detail['reason'] = 'SKU non trovato';
                    $results['skipped']++;
                }
            } else {
                // Create mode
                $type = $p_data['type'] ?? 'simple';
                $new_id = $type === 'variable'
                    ? gh_create_variable_product( $p_data )
                    : gh_create_simple_product( $p_data );
                $detail['status'] = 'created';
                $detail['id']     = $new_id;
                $results['created']++;
            }
        } catch ( \Throwable $e ) {
            $detail['status'] = 'error';
            $detail['error']  = $e->getMessage();
            $results['errors']++;
        }

        $results['details'][] = $detail;
    }

    wp_send_json_success( $results );
} );

// ── HELPERS ────────────────────────────────────────────────────

/**
 * Sanitizes an array of mapping definitions.
 *
 * @param array $mappings Raw mappings from client.
 * @return array Sanitized mappings.
 */
function gh_mapper_sanitize_mappings( array $mappings ): array {
    $clean = [];
    foreach ( $mappings as $m ) {
        if ( ! is_array( $m ) ) continue;
        $clean[] = [
            'source'     => sanitize_text_field( $m['source'] ?? '' ),
            'target'     => sanitize_key( $m['target'] ?? '' ),
            'transforms' => gh_mapper_sanitize_transforms( $m['transforms'] ?? [] ),
        ];
    }
    return $clean;
}

/**
 * Sanitizes transform chains.
 *
 * @param array $transforms Raw transforms.
 * @return array Sanitized transforms.
 */
function gh_mapper_sanitize_transforms( array $transforms ): array {
    $valid_types = array_keys( gh_mapper_get_transform_types() );
    $clean = [];
    foreach ( $transforms as $t ) {
        if ( ! is_array( $t ) ) continue;
        $type = sanitize_key( $t['type'] ?? '' );
        if ( ! in_array( $type, $valid_types, true ) ) continue;
        $clean[] = [
            'type'  => $type,
            'value' => $t['value'] ?? '',
        ];
    }
    return $clean;
}
