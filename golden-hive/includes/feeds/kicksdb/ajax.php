<?php
/**
 * KicksDB — AJAX handlers (minimali, Phase 1).
 *
 * Esposti:
 * - gh_kicksdb_settings_get/save        — CRUD settings (api_key redatta in GET)
 * - gh_kicksdb_test_connection          — smoke test con l'API key corrente
 * - gh_kicksdb_lookup                   — enrichment preview per N SKU
 * - gh_kicksdb_search                   — discovery (ricerca query/brand)
 * - gh_kicksdb_apply                    — crea/aggiorna dopo preview
 * - gh_kicksdb_refresh_pricing          — batch pricing refresh per N SKU
 *
 * Le UI (Discover, Catalog viewer, Rules editor) arriveranno in Phase 5.
 * Questi endpoint sono gia usabili da script o da future UI.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'GH_KICKSDB_AJAX_LOADED' ) ) return;
define( 'GH_KICKSDB_AJAX_LOADED', 1 );

// ── Settings CRUD ───────────────────────────────────────────

add_action( 'wp_ajax_gh_kicksdb_settings_get', function () {
    gh_ajax_guard();
    wp_send_json_success( [ 'settings' => gh_kicksdb_get_settings_redacted() ] );
} );

add_action( 'wp_ajax_gh_kicksdb_settings_save', function () {
    gh_ajax_guard();

    $payload = gh_ajax_json( 'settings', [] );
    if ( empty( $payload ) ) {
        wp_send_json_error( 'Payload settings mancante.', 400 );
    }

    // API key: se il client manda placeholder redatto, NON sovrascrivere
    if ( isset( $payload['api_key'] ) && preg_match( '/^•+/', (string) $payload['api_key'] ) ) {
        unset( $payload['api_key'] );
    }

    $saved = gh_kicksdb_save_settings( $payload );

    // Ritorna redacted
    wp_send_json_success( [ 'settings' => gh_kicksdb_get_settings_redacted() ] );
} );

// ── Test connection ─────────────────────────────────────────

add_action( 'wp_ajax_gh_kicksdb_test_connection', function () {
    gh_ajax_guard();

    // Cheap smoke test: search con limit=1
    $resp = gh_kicksdb_search_products( [ 'query' => 'test', 'limit' => 1 ] );

    if ( ! empty( $resp['error'] ) ) {
        wp_send_json_error( [
            'ok'       => false,
            'error'    => $resp['error'],
            'status'   => $resp['status'] ?? 0,
            'attempts' => $resp['attempts'] ?? 0,
        ], 502 );
    }

    wp_send_json_success( [
        'ok'          => true,
        'status'      => $resp['status'] ?? 0,
        'duration_ms' => $resp['duration_ms'] ?? 0,
        'attempts'    => $resp['attempts'] ?? 1,
    ] );
} );

// ── Lookup (enrichment preview) ─────────────────────────────

add_action( 'wp_ajax_gh_kicksdb_lookup', function () {
    gh_ajax_guard();

    $skus_raw = gh_ajax_text( 'skus' );
    // Accetta CSV, newline-separated, o JSON array
    $skus = preg_split( '/[\s,;]+/', $skus_raw, -1, PREG_SPLIT_NO_EMPTY );
    if ( empty( $skus ) ) {
        $json = gh_ajax_json( 'skus', [] );
        if ( is_array( $json ) ) $skus = $json;
    }

    if ( empty( $skus ) ) {
        wp_send_json_error( 'Nessuno SKU fornito.', 400 );
    }

    // Limite di sicurezza: niente richieste > 200 SKU in un AJAX call.
    // Per piu grandi usare il Jobs runner (Phase 6).
    if ( count( $skus ) > 200 ) {
        wp_send_json_error( 'Troppi SKU (max 200 per richiesta, usa Jobs per lotti maggiori).', 413 );
    }

    $force = gh_ajax_bool( 'force' );

    $fetched = gh_kicksdb_fetch_skus( $skus, [ 'force' => $force ] );
    $diff    = gh_kicksdb_diff( $fetched['woo_products'] );

    wp_send_json_success( [
        'stats'  => $fetched['stats'],
        'errors' => $fetched['errors'],
        'diff'   => [
            'summary'   => $diff['summary'],
            'new'       => array_map( 'gh_kicksdb_ajax_preview_shape', $diff['new'] ),
            'update'    => array_map( 'gh_kicksdb_ajax_preview_shape', $diff['update'] ),
            'unchanged' => array_map( 'gh_kicksdb_ajax_preview_shape', $diff['unchanged'] ),
        ],
    ] );
} );

/**
 * Riduce un woo record alle chiavi che la UI vuole vedere in preview.
 * Teniamo i campi pesanti (variations) fuori per non gonfiare la response.
 */
function gh_kicksdb_ajax_preview_shape( array $p ): array {
    return [
        'sku'           => $p['sku'] ?? '',
        'name'          => $p['name'] ?? '',
        'type'          => $p['type'] ?? 'simple',
        'brand'         => $p['_kdb_brand'] ?? '',
        'model'         => $p['_kdb_model'] ?? '',
        'gender'        => $p['_kdb_gender'] ?? '',
        'colorway'      => $p['_kdb_colorway'] ?? '',
        'category'      => $p['_kdb_category'] ?? '',
        'image'         => $p['_kdb_image'] ?? '',
        'variant_count' => count( $p['variations'] ?? [] ),
        'existing_id'   => $p['_existing_id'] ?? null,
    ];
} // Nota: in fase 4 (Rules editor) quest'ultima sara estesa.

// ── Search (discovery) ──────────────────────────────────────

add_action( 'wp_ajax_gh_kicksdb_search', function () {
    gh_ajax_guard();

    $params = [
        'query' => gh_ajax_text( 'query' ),
        'brand' => gh_ajax_text( 'brand' ),
        'limit' => max( 1, min( 100, gh_ajax_int( 'limit', 50 ) ) ),
        'page'  => max( 1, gh_ajax_int( 'page', 1 ) ),
        'sort'  => gh_ajax_text( 'sort' ),
        'order' => gh_ajax_text( 'order' ),
    ];

    $resp = gh_kicksdb_search_products( $params );

    if ( ! empty( $resp['error'] ) ) {
        wp_send_json_error( [
            'error'  => $resp['error'],
            'status' => $resp['status'] ?? 0,
        ], 502 );
    }

    $rows = $resp['body']['data'] ?? [];
    $out  = [];
    foreach ( (array) $rows as $r ) {
        $sku   = (string) ( $r['sku'] ?? '' );
        $state = 'new';
        if ( $sku !== '' ) {
            $pid = wc_get_product_id_by_sku( $sku );
            if ( $pid ) {
                $state = get_post_meta( $pid, '_gh_kicksdb_tracked', true ) === '1' ? 'tracked' : 'in_catalog';
            }
        }
        $out[] = [
            'sku'        => $sku,
            'id'         => $r['id'] ?? '',
            'title'      => $r['title'] ?? '',
            'brand'      => $r['brand'] ?? '',
            'model'      => $r['model'] ?? '',
            'colorway'   => $r['colorway'] ?? '',
            'image'      => $r['image'] ?? '',
            'release'    => $r['release_date'] ?? '',
            'state'      => $state,
        ];
    }

    wp_send_json_success( [
        'items'       => $out,
        'count'       => count( $out ),
        'duration_ms' => $resp['duration_ms'] ?? 0,
    ] );
} );

// ── Apply (crea/aggiorna dopo lookup preview) ───────────────

add_action( 'wp_ajax_gh_kicksdb_apply', function () {
    gh_ajax_guard();

    $skus = gh_ajax_int_array( 'skus' ); // puo essere passato come array di sku-strings in json
    $skus_json = gh_ajax_json( 'skus', [] );
    if ( ! empty( $skus_json ) ) $skus = array_map( 'strval', $skus_json );
    if ( empty( $skus ) ) {
        $raw = gh_ajax_text( 'skus' );
        $skus = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
    }

    if ( empty( $skus ) ) {
        wp_send_json_error( 'Nessuno SKU fornito.', 400 );
    }
    if ( count( $skus ) > 200 ) {
        wp_send_json_error( 'Troppi SKU (max 200 per richiesta).', 413 );
    }

    $create_new      = gh_ajax_bool( 'create_new' );
    $update_existing = gh_ajax_bool( 'update_existing' );

    @set_time_limit( 120 );
    @wp_raise_memory_limit( 'admin' );

    $fetched = gh_kicksdb_fetch_skus( $skus );
    $diff    = gh_kicksdb_diff( $fetched['woo_products'] );
    $result  = gh_kicksdb_apply( $diff, [
        'create_new'      => $create_new !== false,
        'update_existing' => $update_existing !== false,
    ] );

    wp_send_json_success( [
        'fetch_stats' => $fetched['stats'],
        'errors'      => $fetched['errors'],
        'summary'     => $result['summary'],
        'details'     => $result['details'],
    ] );
} );

// ── Refresh pricing (batch endpoint) ────────────────────────

add_action( 'wp_ajax_gh_kicksdb_refresh_pricing', function () {
    gh_ajax_guard();

    $skus_json = gh_ajax_json( 'skus', [] );
    if ( ! empty( $skus_json ) ) {
        $skus = array_map( 'strval', $skus_json );
    } else {
        $raw  = gh_ajax_text( 'skus' );
        $skus = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
    }

    if ( empty( $skus ) ) {
        wp_send_json_error( 'Nessuno SKU fornito.', 400 );
    }

    // Pricing batch chunks di 50 per call, quindi fino a ~1000 SKU girano in
    // ~20 call — tollerabili in un AJAX. Oltre usa Jobs runner.
    if ( count( $skus ) > 1000 ) {
        wp_send_json_error( 'Troppi SKU (max 1000; usa Jobs per > 1000).', 413 );
    }

    @set_time_limit( 120 );

    $result = gh_kicksdb_refresh_pricing( $skus );
    wp_send_json_success( $result );
} );
