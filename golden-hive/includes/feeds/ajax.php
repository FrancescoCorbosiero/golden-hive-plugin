<?php
/**
 * AJAX handlers — bridge tra UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── GENERIC: Execute HTTP request ───────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_execute', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
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
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_rc_get_saved_endpoints() );
} );

add_action( 'wp_ajax_rp_rc_ajax_save_endpoint', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw    = stripslashes( $_POST['config'] ?? '{}' );
    $config = json_decode( $raw, true ) ?: [];
    $id     = rp_rc_save_endpoint( $config );

    wp_send_json_success( [ 'id' => $id ] );
} );

add_action( 'wp_ajax_rp_rc_ajax_delete_endpoint', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    rp_rc_delete_endpoint( $id );
    wp_send_json_success( [ 'deleted' => $id ] );
} );

// ── GOLDEN SNEAKERS: Fetch feed ─────────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_fetch', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
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
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $price_mode = sanitize_key( $_POST['price_mode'] ?? 'direct' );
    $sale_mult  = (float) ( $_POST['sale_mult'] ?? 1.3 );

    $woo_products = rp_rc_gs_transform_all( $products, $price_mode, $sale_mult );
    $diff         = rp_rc_gs_diff( $woo_products );

    wp_send_json_success( $diff );
} );

// ── GOLDEN SNEAKERS: Apply import ───────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $raw_opts = stripslashes( $_POST['options'] ?? '{}' );
    $options  = json_decode( $raw_opts, true ) ?: [];

    $price_mode = sanitize_key( $options['price_mode'] ?? 'direct' );
    $sale_mult  = (float) ( $options['sale_mult'] ?? 1.3 );

    $woo_products = rp_rc_gs_transform_all( $products, $price_mode, $sale_mult );

    // Propaga import status (draft/publish)
    $import_status = sanitize_key( $options['status'] ?? 'publish' );
    if ( $import_status && $import_status !== 'publish' ) {
        foreach ( $woo_products as &$wp ) {
            $wp['status'] = $import_status;
            if ( ! empty( $wp['variations'] ) ) {
                foreach ( $wp['variations'] as &$v ) { $v['status'] = $import_status; }
                unset( $v );
            }
        }
        unset( $wp );
    }

    $diff = rp_rc_gs_diff( $woo_products );

    add_filter( 'woocommerce_product_object_updated_props', '__return_empty_array', 999 );
    $result = rp_rc_gs_apply( $diff, $options );
    remove_filter( 'woocommerce_product_object_updated_props', '__return_empty_array', 999 );
    wc_delete_product_transients();

    wp_send_json_success( $result );
} );

// ── FEED SETTINGS: Save/load per-feed UI settings ──────────
add_action( 'wp_ajax_gh_ajax_feed_save_settings', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feed_key = sanitize_key( $_POST['feed_key'] ?? '' );
    $raw      = stripslashes( $_POST['settings'] ?? '{}' );
    $settings = json_decode( $raw, true ) ?: [];

    if ( ! $feed_key ) { wp_send_json_error( 'Feed key mancante.' ); }

    update_option( 'gh_feed_settings_' . $feed_key, $settings, false );
    wp_send_json_success( 'Salvato.' );
} );

add_action( 'wp_ajax_gh_ajax_feed_load_settings', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feed_key = sanitize_key( $_POST['feed_key'] ?? '' );
    if ( ! $feed_key ) { wp_send_json_error( 'Feed key mancante.' ); }

    $settings = get_option( 'gh_feed_settings_' . $feed_key, [] );
    wp_send_json_success( $settings );
} );

// ── CONFIG ENGINE: List available configs ───────────────────
add_action( 'wp_ajax_gh_ajax_fc_list_configs', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( gh_fc_list_configs() );
} );

// ── CONFIG ENGINE: Fetch URL + normalize via config ────────
add_action( 'wp_ajax_gh_ajax_fc_fetch', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    $url       = esc_url_raw( $_POST['url'] ?? '' );

    if ( ! $config_id ) { wp_send_json_error( 'Config ID mancante.' ); }
    if ( ! $url )       { wp_send_json_error( 'URL mancante.' ); }

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) { wp_send_json_error( 'Config non trovato: ' . $config_id ); }

    $response = rp_rc_request( [ 'url' => $url, 'method' => 'GET', 'timeout' => 120 ] );
    if ( ! empty( $response['error'] ) ) { wp_send_json_error( $response['error'] ); }
    if ( $response['status'] !== 200 ) { wp_send_json_error( "HTTP {$response['status']}" ); }

    $rows = rp_rc_parse_csv( $response['body'] );
    if ( is_wp_error( $rows ) ) { wp_send_json_error( $rows->get_error_message() ); }

    $products = gh_fc_normalize( $rows, $config );

    // Persist fetched data so the user doesn't have to re-fetch
    set_transient( 'gh_fc_last_fetch_' . $config_id, [
        'products'      => $products,
        'csv_rows'      => count( $rows ),
        'fetched_at'    => current_time( 'mysql' ),
    ], 24 * HOUR_IN_SECONDS );

    wp_send_json_success( [
        'config'        => $config['name'] ?? $config_id,
        'csv_rows'      => count( $rows ),
        'product_count' => count( $products ),
        'products'      => $products,
    ] );
} );

// ── CONFIG ENGINE: Upload file + normalize ─────────────────
add_action( 'wp_ajax_gh_ajax_fc_upload', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    if ( ! $config_id ) { wp_send_json_error( 'Config ID mancante.' ); }
    if ( empty( $_FILES['csv_file'] ) ) { wp_send_json_error( 'Nessun file.' ); }

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) { wp_send_json_error( 'Config non trovato: ' . $config_id ); }

    $body = file_get_contents( $_FILES['csv_file']['tmp_name'] );
    if ( ! $body ) { wp_send_json_error( 'File vuoto.' ); }

    $rows = rp_rc_parse_csv( $body );
    if ( is_wp_error( $rows ) ) { wp_send_json_error( $rows->get_error_message() ); }

    $products = gh_fc_normalize( $rows, $config );

    set_transient( 'gh_fc_last_fetch_' . $config_id, [
        'products'      => $products,
        'csv_rows'      => count( $rows ),
        'fetched_at'    => current_time( 'mysql' ),
    ], 24 * HOUR_IN_SECONDS );

    wp_send_json_success( [
        'config'        => $config['name'] ?? $config_id,
        'csv_rows'      => count( $rows ),
        'product_count' => count( $products ),
        'products'      => $products,
    ] );
} );

// ── CONFIG ENGINE: Load cached fetch ──────────────────────
add_action( 'wp_ajax_gh_ajax_fc_load_cached', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    if ( ! $config_id ) { wp_send_json_error( 'Config ID mancante.' ); }

    $cached = get_transient( 'gh_fc_last_fetch_' . $config_id );
    if ( ! $cached ) { wp_send_json_success( null ); }

    wp_send_json_success( $cached );
} );

// ── CONFIG ENGINE: Preview (transform + diff) ──────────────
add_action( 'wp_ajax_gh_ajax_fc_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    $raw       = stripslashes( $_POST['products'] ?? '[]' );
    $products  = json_decode( $raw, true ) ?: [];
    $markup    = (float) ( $_POST['markup'] ?? 0 );

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) { wp_send_json_error( 'Config non trovato.' ); }

    if ( $markup > 0 ) {
        $config = gh_fc_override_markup( $config, $markup );
    }

    $woo_products = gh_fc_transform_all( $products, $config );
    $diff         = gh_csv_diff( $woo_products );

    wp_send_json_success( $diff );
} );

// ── CONFIG ENGINE: Apply import ────────────────────────────
// Supporta chunking lato client: il JS invia N prodotti per request,
// il handler processa quelli e ritorna i risultati parziali.
// Il JS accumula i risultati e mostra il progresso.
add_action( 'wp_ajax_gh_ajax_fc_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    $raw       = stripslashes( $_POST['products'] ?? '[]' );
    $products  = json_decode( $raw, true ) ?: [];
    $raw_opts  = stripslashes( $_POST['options'] ?? '{}' );
    $options   = json_decode( $raw_opts, true ) ?: [];
    $markup    = (float) ( $_POST['markup'] ?? 0 );

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) { wp_send_json_error( 'Config non trovato.' ); }

    if ( $markup > 0 ) {
        $config = gh_fc_override_markup( $config, $markup );
    }

    $woo_products = gh_fc_transform_all( $products, $config );

    // Propaga lo status dalla UI (default: publish, opzione: draft)
    $import_status = sanitize_key( $options['status'] ?? 'publish' );
    if ( $import_status && $import_status !== 'publish' ) {
        foreach ( $woo_products as &$wp ) {
            $wp['status'] = $import_status;
            // Anche le varianti se presenti
            if ( ! empty( $wp['variations'] ) ) {
                foreach ( $wp['variations'] as &$v ) {
                    $v['status'] = $import_status;
                }
                unset( $v );
            }
        }
        unset( $wp );
    }

    $diff = gh_csv_diff( $woo_products );

    $create   = $options['create_new'] ?? true;
    $update   = $options['update_existing'] ?? true;
    $sideload = $options['sideload_images'] ?? false;
    $results  = [];

    $tax_map = gh_fc_prepare_taxonomies( $woo_products );

    // Suppress expensive WC hooks during batch — flush once at the end
    add_filter( 'woocommerce_product_object_updated_props', '__return_empty_array', 999 );

    if ( $create && ! empty( $diff['new'] ) ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['new'],
            fn( $p ) => gh_fc_create_product( $p, $sideload, $tax_map )
        ) );
    }
    if ( $update && ! empty( $diff['update'] ) ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['update'],
            fn( $p ) => gh_csv_update_product( $p )
        ) );
    }

    remove_filter( 'woocommerce_product_object_updated_props', '__return_empty_array', 999 );
    wc_delete_product_transients();

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    wp_send_json_success( [
        'summary' => compact( 'created', 'updated', 'errors' ),
        'details' => $results,
    ] );
} );

// ── CONFIG ENGINE: Quick patch (price/stock only) ─────────
add_action( 'wp_ajax_gh_ajax_fc_quick_patch', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    $raw       = stripslashes( $_POST['products'] ?? '[]' );
    $products  = json_decode( $raw, true ) ?: [];
    $markup    = (float) ( $_POST['markup'] ?? 0 );

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) { wp_send_json_error( 'Config non trovato.' ); }

    if ( $markup > 0 ) {
        $config = gh_fc_override_markup( $config, $markup );
    }

    $woo_products = gh_fc_transform_all( $products, $config );

    try {
        $result = gh_fc_quick_patch( $woo_products );
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Quick patch fallito: ' . $e->getMessage() );
    }
} );

// ── GS FEED: Quick patch (price/stock only) ───────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_quick_patch', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $price_mode = sanitize_key( $_POST['price_mode'] ?? 'direct' );
    $sale_mult  = (float) ( $_POST['sale_mult'] ?? 1.3 );

    $woo_products = rp_rc_gs_transform_all( $products, $price_mode, $sale_mult );

    try {
        $result = gh_fc_quick_patch( $woo_products );
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Quick patch fallito: ' . $e->getMessage() );
    }
} );

// ── CSV FEEDS: List all ────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_list_feeds', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feeds = gh_csv_get_feeds();

    // Return summary (strip large fields)
    $summary = array_map( fn( $f ) => [
        'id'              => $f['id'],
        'name'            => $f['name'] ?? 'Senza nome',
        'source_type'     => $f['source_type'] ?? 'url',
        'source_url'      => $f['source_url'] ?? '',
        'source_path'     => $f['source_path'] ?? '',
        'mapping_rule_id' => $f['mapping_rule_id'] ?? '',
        'schedule'        => $f['schedule'] ?? 'manual',
        'status'          => $f['status'] ?? 'active',
        'last_run'        => $f['last_run'] ?? null,
        'last_result'     => $f['last_result'] ?? null,
        'created_at'      => $f['created_at'] ?? '',
    ], $feeds );

    wp_send_json_success( $summary );
} );

// ── CSV FEEDS: Get single feed ─────────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_get_feed', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
    if ( empty( $feed_id ) ) {
        wp_send_json_error( 'ID feed mancante.' );
    }

    $feed = gh_csv_get_feed( $feed_id );
    if ( ! $feed ) {
        wp_send_json_error( 'Feed non trovato.' );
    }

    wp_send_json_success( $feed );
} );

// ── CSV FEEDS: Save feed config ────────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_save_feed', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['feed'] ?? '' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
        wp_send_json_error( 'JSON feed non valido.' );
    }

    $clean = [
        'id'              => sanitize_text_field( $data['id'] ?? '' ),
        'name'            => sanitize_text_field( $data['name'] ?? 'Senza nome' ),
        'source_type'     => sanitize_key( $data['source_type'] ?? 'url' ),
        'source_url'      => esc_url_raw( $data['source_url'] ?? '' ),
        'source_path'     => sanitize_text_field( $data['source_path'] ?? '' ),
        'source_headers'  => gh_csv_sanitize_headers( $data['source_headers'] ?? [] ),
        'mapping_mode'    => sanitize_key( $data['mapping_mode'] ?? 'auto' ),
        'preset_id'       => sanitize_text_field( $data['preset_id'] ?? '' ),
        'mapping_rule_id' => sanitize_text_field( $data['mapping_rule_id'] ?? '' ),
        'schedule'        => sanitize_key( $data['schedule'] ?? 'manual' ),
        'status'          => sanitize_key( $data['status'] ?? 'active' ),
        'options'         => [
            'create_new'      => ! empty( $data['options']['create_new'] ?? true ),
            'update_existing' => ! empty( $data['options']['update_existing'] ?? true ),
        ],
    ];

    $saved = gh_csv_save_feed( $clean );

    wp_send_json_success( $saved );
} );

// ── CSV FEEDS: Delete feed ─────────────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_delete_feed', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
    if ( empty( $feed_id ) ) {
        wp_send_json_error( 'ID feed mancante.' );
    }

    if ( ! gh_csv_delete_feed( $feed_id ) ) {
        wp_send_json_error( 'Feed non trovato.' );
    }

    wp_send_json_success( 'Eliminato.' );
} );

// ── CSV FEEDS: Preview (dry run) ───────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
    if ( empty( $feed_id ) ) {
        wp_send_json_error( 'ID feed mancante.' );
    }

    $result = gh_csv_run_feed( $feed_id, [ 'dry_run' => true ] );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( $result );
} );

// ── CSV FEEDS: Run import ──────────────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_run', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $feed_id = sanitize_text_field( $_POST['feed_id'] ?? '' );
    if ( empty( $feed_id ) ) {
        wp_send_json_error( 'ID feed mancante.' );
    }

    $raw_opts = stripslashes( $_POST['options'] ?? '{}' );
    $options  = json_decode( $raw_opts, true ) ?: [];

    $result = gh_csv_run_feed( $feed_id, $options );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( $result );
} );

// ── CSV FEEDS: Upload CSV file ─────────────────────────────
add_action( 'wp_ajax_gh_ajax_csv_upload', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    if ( empty( $_FILES['csv_file'] ) ) {
        wp_send_json_error( 'Nessun file caricato.' );
    }

    $file = $_FILES['csv_file'];
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'Errore upload: codice ' . $file['error'] );
    }

    // Validate extension
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'csv', 'tsv', 'txt' ], true ) ) {
        wp_send_json_error( 'Solo file .csv, .tsv o .txt sono accettati.' );
    }

    // Move to uploads/golden-hive/csv/ (for feeds that reference the file)
    $upload_dir = wp_upload_dir();
    $csv_dir    = trailingslashit( $upload_dir['basedir'] ) . 'golden-hive/csv';
    wp_mkdir_p( $csv_dir );

    $filename   = sanitize_file_name( $file['name'] );
    $dest       = trailingslashit( $csv_dir ) . $filename;

    if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
        wp_send_json_error( 'Impossibile salvare il file.' );
    }

    // Parse to return a preview of columns
    $rows = rp_rc_parse_csv( file_get_contents( $dest ) );

    // Clean up: delete the file unless it will be used by a CSV feed with source_type=file
    // The file path is returned so it CAN be saved in a feed config if needed,
    // but we schedule cleanup of orphaned uploads older than 24h
    gh_csv_schedule_cleanup();

    if ( is_wp_error( $rows ) ) {
        @unlink( $dest );
        wp_send_json_error( $rows->get_error_message() );
    }

    $columns = ! empty( $rows ) ? array_keys( $rows[0] ) : [];

    wp_send_json_success( [
        'path'      => 'golden-hive/csv/' . $filename,
        'filename'  => $filename,
        'rows'      => count( $rows ),
        'columns'   => $columns,
        'sample'    => array_slice( $rows, 0, 3 ),
    ] );
} );

// ── CSV FEEDS: Parse inline CSV content ────────────────────
add_action( 'wp_ajax_gh_ajax_csv_parse_url', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $url     = esc_url_raw( $_POST['url'] ?? '' );
    $raw_hdr = stripslashes( $_POST['headers'] ?? '{}' );
    $headers = json_decode( $raw_hdr, true ) ?: [];

    if ( ! $url ) {
        wp_send_json_error( 'URL mancante.' );
    }

    $rows = gh_csv_fetch_url( $url, $headers );
    if ( is_wp_error( $rows ) ) {
        wp_send_json_error( $rows->get_error_message() );
    }

    $columns = ! empty( $rows ) ? array_keys( $rows[0] ) : [];

    wp_send_json_success( [
        'rows'    => count( $rows ),
        'columns' => $columns,
        'sample'  => array_slice( $rows, 0, 3 ),
    ] );
} );

// ── CSV PRESETS: List available presets ─────────────────────
add_action( 'wp_ajax_gh_ajax_csv_list_presets', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $presets = gh_csv_get_presets();

    // Return summary (without column_aliases to keep it light)
    $summary = [];
    foreach ( $presets as $p ) {
        $summary[] = [
            'id'          => $p['id'],
            'name'        => $p['name'],
            'description' => $p['description'],
            'fields'      => count( $p['mappings'] ),
        ];
    }

    wp_send_json_success( $summary );
} );

// ── CSV AUTO-MAP: Preview auto-mapping for given columns ───
add_action( 'wp_ajax_gh_ajax_csv_auto_map', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw     = stripslashes( $_POST['columns'] ?? '[]' );
    $columns = json_decode( $raw, true ) ?: [];

    if ( empty( $columns ) ) {
        wp_send_json_error( 'Nessuna colonna fornita.' );
    }

    $result = gh_csv_auto_map( $columns );

    // Enrich with target field labels
    $target_fields = gh_mapper_get_target_fields();
    foreach ( $result['mappings'] as &$m ) {
        $t = $m['target'] ?? '';
        $m['target_label'] = $target_fields[ $t ]['label'] ?? $t;
    }
    unset( $m );

    wp_send_json_success( $result );
} );

// ── CSV PRESET RESOLVE: Preview preset mapping for columns ─
add_action( 'wp_ajax_gh_ajax_csv_resolve_preset', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $preset_id = sanitize_text_field( $_POST['preset_id'] ?? '' );
    $raw       = stripslashes( $_POST['columns'] ?? '[]' );
    $columns   = json_decode( $raw, true ) ?: [];

    if ( empty( $preset_id ) ) {
        wp_send_json_error( 'Preset ID mancante.' );
    }

    $preset = gh_csv_get_preset( $preset_id );
    if ( ! $preset ) {
        wp_send_json_error( 'Preset non trovato.' );
    }

    $resolved = gh_csv_resolve_preset( $preset, $columns );

    // Enrich with target field labels
    $target_fields = gh_mapper_get_target_fields();
    foreach ( $resolved as &$m ) {
        $t = $m['target'] ?? '';
        $m['target_label'] = $target_fields[ $t ]['label'] ?? $t;
    }
    unset( $m );

    wp_send_json_success( [
        'preset_name' => $preset['name'],
        'mappings'    => $resolved,
        'total_in_preset' => count( $preset['mappings'] ),
        'resolved'        => count( $resolved ),
    ] );
} );

// ── SCHEDULER: List tasks ──────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_list', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $tasks = gh_sched_get_tasks();

    // Enrich with next_run
    foreach ( $tasks as &$t ) {
        $next = gh_sched_next_run( $t['id'] );
        $t['next_run'] = $next ? gmdate( 'c', $next ) : null;
    }
    unset( $t );

    wp_send_json_success( $tasks );
} );

// ── SCHEDULER: Save task ───────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_save', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['task'] ?? '' );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) { wp_send_json_error( 'JSON non valido.' ); }

    $clean = [
        'id'            => sanitize_text_field( $data['id'] ?? '' ),
        'name'          => sanitize_text_field( $data['name'] ?? 'Import task' ),
        'feed_type'     => sanitize_key( $data['feed_type'] ?? 'config' ),
        'config_id'     => sanitize_text_field( $data['config_id'] ?? '' ),
        'csv_feed_id'   => sanitize_text_field( $data['csv_feed_id'] ?? '' ),
        'source_type'   => sanitize_key( $data['source_type'] ?? 'url' ),
        'source_url'    => esc_url_raw( $data['source_url'] ?? '' ),
        'source_path'   => sanitize_text_field( $data['source_path'] ?? '' ),
        'schedule'      => sanitize_key( $data['schedule'] ?? 'manual' ),
        'status'        => sanitize_key( $data['status'] ?? 'active' ),
        'options'       => [
            'create_new'      => ! empty( $data['options']['create_new'] ?? true ),
            'update_existing' => ! empty( $data['options']['update_existing'] ?? true ),
            'sideload_images' => ! empty( $data['options']['sideload_images'] ?? false ),
        ],
    ];

    wp_send_json_success( gh_sched_save_task( $clean ) );
} );

// ── SCHEDULER: Delete task ─────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_delete', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_text_field( $_POST['task_id'] ?? '' );
    if ( ! gh_sched_delete_task( $id ) ) { wp_send_json_error( 'Non trovato.' ); }
    wp_send_json_success( 'Eliminato.' );
} );

// ── SCHEDULER: Toggle active/paused ────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_toggle', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id   = sanitize_text_field( $_POST['task_id'] ?? '' );
    $task = gh_sched_toggle_task( $id );
    if ( ! $task ) { wp_send_json_error( 'Non trovato.' ); }
    wp_send_json_success( $task );
} );

// ── SCHEDULER: Run now ─────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_run', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id     = sanitize_text_field( $_POST['task_id'] ?? '' );
    $result = gh_sched_run_task( $id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( $result );
} );

// ── SCHEDULER: Get log ─────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_log', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $limit = (int) ( $_POST['limit'] ?? 50 );
    wp_send_json_success( gh_sched_get_log( $limit ) );
} );

// ── SCHEDULER: Clear log ───────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sched_clear_log', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    gh_sched_clear_log();
    wp_send_json_success( 'Log svuotato.' );
} );

/**
 * Sanitizes HTTP headers array.
 */
function gh_csv_sanitize_headers( array $headers ): array {
    $clean = [];
    foreach ( $headers as $key => $value ) {
        $clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $value );
    }
    return $clean;
}

// ═══ MEDIA PRE-IMPORT ═══════════════════════════════════════════════════════

// ── Download batch of image URLs ─────────────────────────────
add_action( 'wp_ajax_gh_ajax_preimport_download', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $raw  = stripslashes( $_POST['urls'] ?? '[]' );
    $urls = json_decode( $raw, true );

    if ( ! is_array( $urls ) || empty( $urls ) ) {
        wp_send_json_error( 'Nessun URL fornito.' );
    }

    try {
        $result = gh_preimport_download_batch( $urls );
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Download fallito: ' . $e->getMessage() );
    }
} );

// ── Get current map stats ────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_preimport_stats', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( gh_preimport_map_stats() );
} );

// ── Clear map (reset) ────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_preimport_clear', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    gh_preimport_clear_map();
    wp_send_json_success( 'Mappa resettata.' );
} );

// ── Validate map (prune stale entries) ───────────────────────
add_action( 'wp_ajax_gh_ajax_preimport_validate', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 120 );

    try {
        $result = gh_preimport_validate_map();
        wp_send_json_success( $result );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Validazione fallita: ' . $e->getMessage() );
    }
} );
