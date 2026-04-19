<?php
/**
 * AJAX handlers — bulk actions + sorter.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// ── GET BULK ACTION DEFINITIONS ─────────────────────────────────
add_action( 'wp_ajax_gh_ajax_bulk_meta', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( [
        'actions'    => gh_get_bulk_action_definitions(),
        'sort_rules' => gh_get_sort_rules(),
    ] );
} );

// ── EXECUTE BULK ACTION ─────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_bulk_execute', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $action = sanitize_key( $_POST['bulk_action'] ?? '' );
    if ( empty( $action ) ) {
        wp_send_json_error( 'Azione bulk mancante.' );
    }

    // Product IDs: possono arrivare come JSON array o da filtro
    $ids_raw = stripslashes( $_POST['product_ids'] ?? '[]' );
    $product_ids = json_decode( $ids_raw, true );

    if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
        wp_send_json_error( 'Nessun prodotto selezionato.' );
    }

    // Parametri azione
    $params_raw = stripslashes( $_POST['params'] ?? '{}' );
    $params     = json_decode( $params_raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON parametri non valido.' );
    }

    // Sanitizza parametri in base al tipo
    $params = gh_sanitize_bulk_params( $action, $params );

    $result = gh_execute_bulk_action( $action, $product_ids, $params );

    wp_send_json_success( $result );
} );

// ── DISPATCH BULK ACTION AS BACKGROUND JOB ──────────────────────
// Creates a one-shot `bulk_action` job (enabled:false → no cron
// rescheduling) and fires it immediately via wp-cron loopback. The
// job's chunked handler honors tick_budget + continuation ticks, so
// 1000+ product operations no longer block the admin request.
add_action( 'wp_ajax_gh_ajax_bulk_dispatch_job', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $action = sanitize_key( $_POST['bulk_action'] ?? '' );
    if ( $action === '' ) {
        wp_send_json_error( 'Azione bulk mancante.' );
    }
    if ( ! isset( gh_get_bulk_action_definitions()[ $action ] ) ) {
        wp_send_json_error( 'Azione sconosciuta.' );
    }

    $ids_raw = stripslashes( $_POST['product_ids'] ?? '[]' );
    $ids     = json_decode( $ids_raw, true );
    if ( ! is_array( $ids ) || empty( $ids ) ) {
        wp_send_json_error( 'Nessun prodotto selezionato.' );
    }
    $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    if ( empty( $ids ) ) {
        wp_send_json_error( 'Lista prodotti vuota dopo sanitize.' );
    }

    $params_raw = stripslashes( $_POST['params'] ?? '{}' );
    $params     = json_decode( $params_raw, true );
    if ( ! is_array( $params ) ) $params = [];
    $params = gh_sanitize_bulk_params( $action, $params );

    $chunk_size = max( 10, min( 500, (int) ( $_POST['chunk_size'] ?? 100 ) ) );

    $label = sprintf( 'Bulk %s · %d prodotti', $action, count( $ids ) );

    $saved = gh_jobs_save( [
        'kind'        => 'bulk_action',
        'label'       => $label,
        // Valid expression required by validator; enabled:false means it
        // is never actually scheduled by cron. We fire a single run below.
        'cron'        => '0 0 1 1 *',
        'enabled'     => false,
        'max_runtime' => 3600,
        'tick_budget' => 25,
        'params'      => [
            'action'        => $action,
            'product_ids'   => wp_json_encode( $ids ),
            'action_params' => wp_json_encode( $params ),
            'chunk_size'    => (string) $chunk_size,
        ],
    ] );

    if ( is_wp_error( $saved ) ) {
        wp_send_json_error( $saved->get_error_message() );
    }

    $job_id = $saved['id'];

    // Kick off immediately. wp_schedule_single_event + spawn_cron asks
    // WordPress to loopback wp-cron.php right away; the tick hook lands
    // on gh_jobs_run_tick() which handles locking, chunking and
    // continuation by itself.
    wp_schedule_single_event( time(), GH_JOBS_TICK_HOOK, [ $job_id ] );
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron( time() );
    }

    wp_send_json_success( [
        'job_id' => $job_id,
        'label'  => $label,
        'total'  => count( $ids ),
    ] );
} );

// ── SORT PREVIEW ────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sort_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule = sanitize_key( $_POST['rule'] ?? '' );
    if ( empty( $rule ) ) {
        wp_send_json_error( 'Regola di ordinamento mancante.' );
    }

    $ids_raw     = stripslashes( $_POST['product_ids'] ?? '[]' );
    $product_ids = json_decode( $ids_raw, true );

    if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
        wp_send_json_error( 'Nessun prodotto da ordinare.' );
    }

    $start = intval( $_POST['start_order'] ?? 10 );
    $step  = intval( $_POST['step'] ?? 10 );

    $result = gh_sort_preview( $product_ids, $rule, $start, $step );

    wp_send_json_success( $result );
} );

// ── SORT APPLY ──────────────────────────────────────────────────
add_action( 'wp_ajax_gh_ajax_sort_apply', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $rule = sanitize_key( $_POST['rule'] ?? '' );
    if ( empty( $rule ) ) {
        wp_send_json_error( 'Regola di ordinamento mancante.' );
    }

    $ids_raw     = stripslashes( $_POST['product_ids'] ?? '[]' );
    $product_ids = json_decode( $ids_raw, true );

    if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
        wp_send_json_error( 'Nessun prodotto da ordinare.' );
    }

    $start = intval( $_POST['start_order'] ?? 10 );
    $step  = intval( $_POST['step'] ?? 10 );

    $result = gh_sort_products( $product_ids, $rule, $start, $step );

    wp_send_json_success( $result );
} );

// ── HELPERS ─────────────────────────────────────────────────────

/**
 * Sanitizza i parametri in base all'azione.
 */
function gh_sanitize_bulk_params( string $action, array $params ): array {

    return match ( $action ) {
        'assign_categories', 'remove_categories', 'set_categories' =>
            [ 'category_ids' => array_map( 'intval', $params['category_ids'] ?? [] ) ],

        'assign_tags', 'remove_tags' =>
            [ 'tag_ids' => array_map( 'intval', $params['tag_ids'] ?? [] ) ],

        'set_status' =>
            [ 'status' => sanitize_key( $params['status'] ?? 'publish' ) ],

        'set_sale_percent' =>
            [ 'percent' => floatval( $params['percent'] ?? 0 ) ],

        'remove_sale' => [],

        'adjust_price' => [
            'amount' => floatval( $params['amount'] ?? 0 ),
            'target' => sanitize_key( $params['target'] ?? 'regular_price' ),
        ],

        'markup_percent', 'discount_percent' => [
            'percent'  => max( 0, floatval( $params['percent'] ?? 0 ) ),
            'target'   => in_array( $params['target'] ?? '', [ 'regular_price', 'sale_price' ], true )
                ? $params['target']
                : 'regular_price',
            'rounding' => in_array(
                $params['rounding'] ?? '',
                [ 'none', '2dec', '99', '00', 'nearest_1', 'nearest_5', 'nearest_10' ],
                true
            ) ? $params['rounding'] : '2dec',
        ],

        'set_stock_status' =>
            [ 'stock_status' => sanitize_key( $params['stock_status'] ?? 'instock' ) ],

        'set_stock_quantity' =>
            [ 'quantity' => intval( $params['quantity'] ?? 0 ) ],

        'set_seo_template' => [
            'meta_title_template'       => sanitize_text_field( $params['meta_title_template'] ?? '' ),
            'meta_description_template' => sanitize_text_field( $params['meta_description_template'] ?? '' ),
        ],

        'set_menu_order' =>
            [ 'menu_order' => intval( $params['menu_order'] ?? 0 ) ],

        default => $params,
    };
}
