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

    $woo_products = rp_rc_gs_transform_all( $products );
    $diff         = rp_rc_gs_diff( $woo_products );

    wp_send_json_success( $diff );
} );

// ── GOLDEN SNEAKERS: Apply import ───────────────────────────
add_action( 'wp_ajax_rp_rc_ajax_gs_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
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

// ── STOCKFIRMATI: Fetch + normalize ────────────────────────
add_action( 'wp_ajax_gh_ajax_sf_fetch', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $url = esc_url_raw( $_POST['url'] ?? '' );
    if ( ! $url ) {
        wp_send_json_error( 'URL mancante.' );
    }

    // Fetch CSV
    $response = rp_rc_request( [
        'url'     => $url,
        'method'  => 'GET',
        'timeout' => 120,
    ] );

    if ( ! empty( $response['error'] ) ) {
        wp_send_json_error( $response['error'] );
    }
    if ( $response['status'] !== 200 ) {
        wp_send_json_error( "HTTP {$response['status']}: risposta non valida." );
    }

    // Parse CSV (pipe-delimited, auto-detected)
    $rows = rp_rc_parse_csv( $response['body'] );
    if ( is_wp_error( $rows ) ) {
        wp_send_json_error( $rows->get_error_message() );
    }

    // Normalize: group PRODUCT + MODEL
    $products = gh_sf_normalize( $rows );

    wp_send_json_success( [
        'csv_rows'      => count( $rows ),
        'product_count' => count( $products ),
        'products'      => $products,
    ] );
} );

// ── STOCKFIRMATI: Upload CSV file + normalize ──────────────
add_action( 'wp_ajax_gh_ajax_sf_upload', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    if ( empty( $_FILES['csv_file'] ) ) {
        wp_send_json_error( 'Nessun file caricato.' );
    }

    $file = $_FILES['csv_file'];
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'Errore upload: codice ' . $file['error'] );
    }

    $body = file_get_contents( $file['tmp_name'] );
    if ( ! $body ) {
        wp_send_json_error( 'File vuoto.' );
    }

    $rows = rp_rc_parse_csv( $body );
    if ( is_wp_error( $rows ) ) {
        wp_send_json_error( $rows->get_error_message() );
    }

    $products = gh_sf_normalize( $rows );

    wp_send_json_success( [
        'csv_rows'      => count( $rows ),
        'product_count' => count( $products ),
        'products'      => $products,
    ] );
} );

// ── STOCKFIRMATI: Preview (diff) ───────────────────────────
add_action( 'wp_ajax_gh_ajax_sf_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $woo_products = gh_sf_transform_all( $products );
    $diff         = gh_sf_diff( $woo_products );

    wp_send_json_success( $diff );
} );

// ── STOCKFIRMATI: Apply import ─────────────────────────────
add_action( 'wp_ajax_gh_ajax_sf_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true ) ?: [];

    $raw_opts = stripslashes( $_POST['options'] ?? '{}' );
    $options  = json_decode( $raw_opts, true ) ?: [];

    $woo_products = gh_sf_transform_all( $products );
    $diff         = gh_sf_diff( $woo_products );
    $result       = gh_sf_apply( $diff, $options );

    wp_send_json_success( $result );
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

    // Move to uploads/golden-hive/csv/
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
    if ( is_wp_error( $rows ) ) {
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
