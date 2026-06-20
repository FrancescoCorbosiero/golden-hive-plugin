<?php
/**
 * REST wrapper for the Roundtrip feature — programmatic export/preview/apply.
 *
 * Thin transport layer over the existing roundtrip functions
 * (rp_cm_export_roundtrip / rp_cm_validate_import_json / rp_cm_import_preview /
 * rp_cm_import_apply). Adds NO business logic: same JSON file in, same JSON
 * out as the admin-ajax UI. The only reason it exists is auth — these routes
 * accept WordPress Application Passwords (Basic auth over HTTPS), so an
 * external tool can drive the loop without a login cookie or a scraped nonce.
 *
 * Namespace: gh/v1
 *   GET  /wp-json/gh/v1/roundtrip/export    → rp_cm_export_roundtrip()
 *   POST /wp-json/gh/v1/roundtrip/preview   → rp_cm_import_preview()   (dry-run)
 *   POST /wp-json/gh/v1/roundtrip/apply     → rp_cm_import_apply()     (writes)
 *
 * Every route requires the SAME capability as the UI: manage_woocommerce.
 */

defined( 'ABSPATH' ) || exit;

const GH_ROUNDTRIP_REST_NS  = 'gh/v1';
const GH_ROUNDTRIP_LOG_KEY  = 'gh_roundtrip_apply_log';
const GH_ROUNDTRIP_LOG_MAX  = 50;

/**
 * Permission callback shared by all roundtrip routes.
 *
 * Mirrors the UI gate (current_user_can('manage_woocommerce')). With an
 * Application Password the current user is resolved from the Basic auth
 * header before this runs, so the same capability check applies.
 */
function gh_roundtrip_rest_permission(): bool|WP_Error {
    if ( current_user_can( 'manage_woocommerce' ) ) {
        return true;
    }
    return new WP_Error(
        'gh_forbidden',
        'Servono i permessi manage_woocommerce.',
        [ 'status' => rest_authorization_required_code() ]
    );
}

add_action( 'rest_api_init', function () {

    // ── EXPORT ──────────────────────────────────────────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/roundtrip/export', [
        'methods'             => WP_REST_Server::READABLE, // GET
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'filters'     => [
                'description' => 'Oggetto filtri JSON (o stringa JSON). Chiavi: status, category, brand, in_stock, per_page.',
                'required'    => false,
            ],
            'include_ids' => [
                'description' => 'Sottoinsieme di prodotti: array di ID o stringa CSV. Vince sugli altri filtri di set.',
                'required'    => false,
            ],
            'status'      => [ 'required' => false ],
            'category'    => [ 'required' => false ],
            'brand'       => [ 'required' => false ],
            'in_stock'    => [ 'required' => false ],
            'per_page'    => [ 'required' => false ],
        ],
        'callback'            => 'gh_roundtrip_rest_export',
    ] );

    // ── PREVIEW (dry-run) ───────────────────────────────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/roundtrip/preview', [
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'mode' => [
                'description' => "update_only | create_if_missing",
                'required'    => false,
                'default'     => 'update_only',
                'enum'        => [ 'update_only', 'create_if_missing' ],
            ],
        ],
        'callback'            => 'gh_roundtrip_rest_preview',
    ] );

    // ── APPLY (writes) ──────────────────────────────────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/roundtrip/apply', [
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'mode' => [
                'description' => "update_only | create_if_missing",
                'required'    => false,
                'default'     => 'update_only',
                'enum'        => [ 'update_only', 'create_if_missing' ],
            ],
        ],
        'callback'            => 'gh_roundtrip_rest_apply',
    ] );

    // ── EXPORT: ID LIST (per chunking client-side) ──────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/roundtrip/ids', [
        'methods'             => WP_REST_Server::READABLE, // GET
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'filters'  => [ 'required' => false ],
            'status'   => [ 'required' => false ],
            'category' => [ 'required' => false ],
            'brand'    => [ 'required' => false ],
            'in_stock' => [ 'required' => false ],
        ],
        'callback'            => 'gh_roundtrip_rest_ids',
    ] );

    // ── BULK CREATE/UPDATE: PREVIEW ─────────────────────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/bulk/preview', [
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'mode' => [
                'description' => 'create | create_or_update',
                'required'    => false,
                'default'     => 'create',
                'enum'        => [ 'create', 'create_or_update' ],
            ],
        ],
        'callback'            => 'gh_roundtrip_rest_bulk_preview',
    ] );

    // ── BULK CREATE/UPDATE: APPLY (writes) ──────────────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/bulk/apply', [
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'mode' => [
                'description' => 'create | create_or_update',
                'required'    => false,
                'default'     => 'create',
                'enum'        => [ 'create', 'create_or_update' ],
            ],
        ],
        'callback'            => 'gh_roundtrip_rest_bulk_apply',
    ] );

    // ── BULK: DISPATCH BACKGROUND JOB (CDN-proof) ───────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/bulk/dispatch', [
        'methods'             => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'mode' => [
                'description' => 'create | create_or_update',
                'required'    => false,
                'default'     => 'create',
                'enum'        => [ 'create', 'create_or_update' ],
            ],
        ],
        'callback'            => 'gh_roundtrip_rest_bulk_dispatch',
    ] );

    // ── BULK: JOB STATUS (poll) ─────────────────────────────
    register_rest_route( GH_ROUNDTRIP_REST_NS, '/bulk/job', [
        'methods'             => WP_REST_Server::READABLE, // GET
        'permission_callback' => 'gh_roundtrip_rest_permission',
        'args'                => [
            'id' => [ 'description' => 'Job ID restituito da /bulk/dispatch', 'required' => true ],
        ],
        'callback'            => 'gh_roundtrip_rest_bulk_job',
    ] );
} );

// ── Callbacks ───────────────────────────────────────────────

/**
 * GET /roundtrip/export — returns the rp_cm_roundtrip envelope verbatim.
 */
function gh_roundtrip_rest_export( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    if ( ! function_exists( 'rp_cm_export_roundtrip' ) ) {
        return new WP_Error( 'gh_unavailable', 'rp_cm_export_roundtrip non disponibile.', [ 'status' => 500 ] );
    }

    gh_roundtrip_rest_raise_limits();

    $filters = gh_roundtrip_rest_collect_filters( $request );

    $data = rp_cm_export_roundtrip( $filters );

    return new WP_REST_Response( $data, 200 );
}

/**
 * GET /roundtrip/ids — just the product ID list for a filter set, so an
 * external client can chunk the export the same way the UI does (fetch ids,
 * then page through /roundtrip/export?include_ids=...).
 */
function gh_roundtrip_rest_ids( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    if ( ! function_exists( 'rp_cm_get_product_ids' ) ) {
        return new WP_Error( 'gh_unavailable', 'rp_cm_get_product_ids non disponibile.', [ 'status' => 500 ] );
    }

    gh_roundtrip_rest_raise_limits();

    $filters = gh_roundtrip_rest_collect_filters( $request );
    $ids     = rp_cm_get_product_ids( $filters );

    return new WP_REST_Response( [ 'ids' => $ids, 'total' => count( $ids ) ], 200 );
}

/**
 * POST /bulk/preview — body = { products: [...] }. Bulk creator (NOT the
 * roundtrip importer). No writes.
 */
function gh_roundtrip_rest_bulk_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    $data = gh_roundtrip_rest_read_bulk( $request );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    gh_roundtrip_rest_raise_limits();
    $mode = gh_roundtrip_rest_bulk_mode( $request );

    try {
        $result = rp_cm_bulk_preview( $data, $mode );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'gh_preview_failed', 'Anteprima fallita: ' . $e->getMessage(), [ 'status' => 500 ] );
    }

    return new WP_REST_Response( $result, 200 );
}

/**
 * POST /bulk/apply — body = { products: [...] }. Bulk creator. Writes.
 */
function gh_roundtrip_rest_bulk_apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    $data = gh_roundtrip_rest_read_bulk( $request );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    gh_roundtrip_rest_raise_limits();
    $mode = gh_roundtrip_rest_bulk_mode( $request );

    try {
        $result = rp_cm_bulk_apply( $data, $mode );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'gh_apply_failed', 'Import fallito: ' . $e->getMessage(), [ 'status' => 500 ] );
    }

    gh_roundtrip_log_apply( $result['summary'] ?? [], 'bulk:' . $mode );

    return new WP_REST_Response( $result, 200 );
}

/**
 * POST /bulk/dispatch — body = { products: [...] }. Fires a background job and
 * returns immediately. Immune to the ~100s proxy cap; the import runs in
 * WP-Cron ticks server-side. Poll /bulk/job?id=... for progress.
 */
function gh_roundtrip_rest_bulk_dispatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    $data = gh_roundtrip_rest_read_bulk( $request );
    if ( is_wp_error( $data ) ) {
        return $data;
    }
    if ( ! function_exists( 'gh_cm_dispatch_bulk_import' ) ) {
        return new WP_Error( 'gh_unavailable', 'Dispatch non disponibile (job runner mancante).', [ 'status' => 500 ] );
    }

    $mode = gh_roundtrip_rest_bulk_mode( $request );
    $res  = gh_cm_dispatch_bulk_import( $data, $mode );
    if ( is_wp_error( $res ) ) {
        return new WP_Error( $res->get_error_code(), $res->get_error_message(), [ 'status' => 500 ] );
    }

    return new WP_REST_Response( $res, 202 );
}

/**
 * GET /bulk/job?id=... — status of a background bulk import.
 */
function gh_roundtrip_rest_bulk_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    if ( ! function_exists( 'gh_jobs_get' ) ) {
        return new WP_Error( 'gh_unavailable', 'Job runner non disponibile.', [ 'status' => 500 ] );
    }
    $job_id = (string) $request->get_param( 'id' );
    $job    = $job_id !== '' ? gh_jobs_get( $job_id ) : null;
    if ( ! $job ) {
        return new WP_Error( 'gh_not_found', 'Job non trovato.', [ 'status' => 404 ] );
    }

    $status = (string) ( $job['last_status'] ?? 'continue' );
    return new WP_REST_Response( [
        'job_id'  => $job_id,
        'status'  => $status,
        'done'    => in_array( $status, [ 'done', 'error' ], true ),
        'summary' => $job['last_summary'] ?? null,
    ], 200 );
}

/**
 * POST /roundtrip/preview — body = rp_cm_roundtrip envelope. No writes.
 */
function gh_roundtrip_rest_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    $check = gh_roundtrip_rest_read_envelope( $request );
    if ( is_wp_error( $check ) ) {
        return $check;
    }

    gh_roundtrip_rest_raise_limits();

    $mode = gh_roundtrip_rest_mode( $request );

    try {
        $result = rp_cm_import_preview( $check, $mode );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'gh_preview_failed', 'Anteprima fallita: ' . $e->getMessage(), [ 'status' => 500 ] );
    }

    return new WP_REST_Response( $result, 200 );
}

/**
 * POST /roundtrip/apply — body = rp_cm_roundtrip envelope. Writes to WooCommerce.
 */
function gh_roundtrip_rest_apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {

    $check = gh_roundtrip_rest_read_envelope( $request );
    if ( is_wp_error( $check ) ) {
        return $check;
    }

    gh_roundtrip_rest_raise_limits();

    $mode = gh_roundtrip_rest_mode( $request );

    try {
        $result = rp_cm_import_apply( $check, $mode );
    } catch ( \Throwable $e ) {
        return new WP_Error( 'gh_apply_failed', 'Import fallito: ' . $e->getMessage(), [ 'status' => 500 ] );
    }

    // Audit: unattended automation should leave a trail. Additive only —
    // a capped option, never read by the existing UI flows.
    gh_roundtrip_log_apply( $result['summary'] ?? [], $mode );

    return new WP_REST_Response( $result, 200 );
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Builds the $filters array for export from the request. Accepts a `filters`
 * JSON object/string plus individual overrides, and normalizes `include_ids`
 * from array or CSV.
 */
function gh_roundtrip_rest_collect_filters( WP_REST_Request $request ): array {

    $filters = [];

    $raw = $request->get_param( 'filters' );
    if ( is_array( $raw ) ) {
        $filters = $raw;
    } elseif ( is_string( $raw ) && $raw !== '' ) {
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            $filters = $decoded;
        }
    }

    // Individual params override the filters object.
    foreach ( [ 'status', 'category', 'brand', 'per_page' ] as $key ) {
        $val = $request->get_param( $key );
        if ( $val !== null && $val !== '' ) {
            $filters[ $key ] = $val;
        }
    }

    $in_stock = $request->get_param( 'in_stock' );
    if ( $in_stock !== null && $in_stock !== '' ) {
        $filters['in_stock'] = rest_sanitize_boolean( $in_stock );
    }

    $ids = gh_roundtrip_rest_int_list( $request->get_param( 'include_ids' ) );
    if ( ! empty( $ids ) ) {
        $filters['include_ids'] = $ids;
    }

    return $filters;
}

/**
 * Reads + validates the roundtrip envelope from the request body.
 *
 * @return array|WP_Error The decoded envelope on success.
 */
function gh_roundtrip_rest_read_envelope( WP_REST_Request $request ): array|WP_Error {

    if ( ! function_exists( 'rp_cm_validate_import_json' ) ) {
        return new WP_Error( 'gh_unavailable', 'Importer non disponibile.', [ 'status' => 500 ] );
    }

    $data = $request->get_json_params();

    if ( ! is_array( $data ) || empty( $data ) ) {
        return new WP_Error(
            'gh_bad_body',
            'Body mancante o non-JSON. Invia il file roundtrip come application/json.',
            [ 'status' => 400 ]
        );
    }

    $valid = rp_cm_validate_import_json( $data );
    if ( is_wp_error( $valid ) ) {
        return new WP_Error( $valid->get_error_code(), $valid->get_error_message(), [ 'status' => 400 ] );
    }

    return $data;
}

/**
 * Normalizes the mode param to a supported value.
 */
function gh_roundtrip_rest_mode( WP_REST_Request $request ): string {
    $mode = (string) $request->get_param( 'mode' );
    return in_array( $mode, [ 'update_only', 'create_if_missing' ], true ) ? $mode : 'update_only';
}

/**
 * Reads + validates the bulk body ({ products: [...] } or a bare array) via the
 * bulk creator's own validator.
 *
 * @return array|WP_Error Normalized data ([ 'products' => [...] ]) on success.
 */
function gh_roundtrip_rest_read_bulk( WP_REST_Request $request ): array|WP_Error {

    if ( ! function_exists( 'rp_cm_validate_bulk_json' ) ) {
        return new WP_Error( 'gh_unavailable', 'Bulk creator non disponibile.', [ 'status' => 500 ] );
    }

    $data = $request->get_json_params();
    if ( ! is_array( $data ) || empty( $data ) ) {
        return new WP_Error(
            'gh_bad_body',
            'Body mancante o non-JSON. Invia { "products": [...] } come application/json.',
            [ 'status' => 400 ]
        );
    }

    $valid = rp_cm_validate_bulk_json( $data );
    if ( is_wp_error( $valid ) ) {
        return new WP_Error( $valid->get_error_code(), $valid->get_error_message(), [ 'status' => 400 ] );
    }

    return $valid; // normalized [ 'products' => [...] ]
}

/**
 * Normalizes the bulk mode param.
 */
function gh_roundtrip_rest_bulk_mode( WP_REST_Request $request ): string {
    $mode = (string) $request->get_param( 'mode' );
    return in_array( $mode, [ 'create', 'create_or_update' ], true ) ? $mode : 'create';
}

/**
 * Accepts an array of ints/strings or a CSV string → clean positive int[].
 */
function gh_roundtrip_rest_int_list( mixed $value ): array {
    if ( is_string( $value ) ) {
        $value = explode( ',', $value );
    }
    if ( ! is_array( $value ) ) {
        return [];
    }
    return array_values( array_filter( array_map( 'intval', $value ) ) );
}

/**
 * Big catalogs + thousands of variations blow the default time/memory budget.
 * Mirrors the admin-ajax handlers which already raise these limits.
 */
function gh_roundtrip_rest_raise_limits(): void {
    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'admin' );
    }
}

/**
 * Appends one apply run to the capped audit log (option, autoload=false).
 */
function gh_roundtrip_log_apply( array $summary, string $mode ): void {
    $log = get_option( GH_ROUNDTRIP_LOG_KEY, [] );
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    array_unshift( $log, [
        'time'    => wp_date( 'c' ),
        'user_id' => get_current_user_id(),
        'mode'    => $mode,
        'via'     => 'rest',
        'summary' => $summary,
    ] );
    $log = array_slice( $log, 0, GH_ROUNDTRIP_LOG_MAX );
    update_option( GH_ROUNDTRIP_LOG_KEY, $log, false );
}
