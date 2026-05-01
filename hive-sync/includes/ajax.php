<?php
/**
 * Hive Sync AJAX endpoints.
 *
 * All handlers share the same guard:
 *   1. check_ajax_referer( 'hsync_nonce', 'nonce' )
 *   2. current_user_can( 'manage_woocommerce' )
 *   3. wp_send_json_success / wp_send_json_error
 *
 * Endpoints (prefix hsync_ajax_):
 *   sources_list           → registered Sources + their config schemas
 *   source_test_fetch      → run Source::fetch with provided config; preview
 *   mappings_list          → MappingRepository::all(?source_kind)
 *   mappings_save          → MappingRepository::save
 *   mappings_delete        → MappingRepository::delete
 *   run_now                → ImportRunner::run (ad-hoc, dry-run optional)
 *   runs_recent            → RunRepository::recent
 */

defined( 'ABSPATH' ) || exit;

function hsync_ajax_guard(): void {
    check_ajax_referer( 'hsync_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
}

function hsync_post_text( string $key, string $default = '' ): string {
    return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : $default;
}

function hsync_post_json( string $key, array $default = [] ): array {
    if ( ! isset( $_POST[ $key ] ) ) return $default;
    $raw = is_string( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
    if ( $raw === '' ) return $default;
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : $default;
}

function hsync_post_bool( string $key ): bool {
    if ( ! isset( $_POST[ $key ] ) ) return false;
    $v = strtolower( (string) wp_unslash( (string) $_POST[ $key ] ) );
    return in_array( $v, [ '1', 'true', 'on', 'yes' ], true );
}

// ─── Sources ───────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_sources_list', function () {
    hsync_ajax_guard();
    if ( ! \HiveSync\Core\Bootstrap::$sources ) {
        wp_send_json_success( [ 'sources' => [] ] );
    }
    $out = [];
    foreach ( \HiveSync\Core\Bootstrap::$sources->all() as $src ) {
        $cap = $src->capabilities();
        $out[] = [
            'id'           => $src->id(),
            'label'        => $src->label(),
            'capabilities' => [
                'can_fetch'        => $cap->canFetch,
                'can_diff'         => $cap->canDiff,
                'can_materialize'  => $cap->canMaterialize,
                'image_sideload'   => $cap->supportsImageSideload,
            ],
            'config_schema' => $src->configSchema(),
        ];
    }
    wp_send_json_success( [ 'sources' => $out ] );
} );

add_action( 'wp_ajax_hsync_ajax_source_test_fetch', function () {
    hsync_ajax_guard();
    $sourceId = hsync_post_text( 'source_id' );
    $config   = hsync_post_json( 'config' );
    $options  = hsync_post_json( 'options' );

    if ( ! \HiveSync\Core\Bootstrap::$sources ) {
        wp_send_json_error( [ 'message' => 'Bootstrap non inizializzato.' ] );
    }
    $src = \HiveSync\Core\Bootstrap::$sources->get( $sourceId );
    if ( ! $src ) {
        wp_send_json_error( [ 'message' => "Source '{$sourceId}' non registrata." ] );
    }

    $ctx   = new \HiveSync\Core\Source\Context( runId: 'preview' );
    $req   = new \HiveSync\Core\Source\FetchRequest( $config, $options );
    try {
        $fetch = $src->fetch( $req, $ctx );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }

    $preview = [];
    foreach ( array_slice( $fetch->items, 0, 10 ) as $item ) {
        $preview[] = [
            'sku'  => $item->sku,
            'data' => $item->data,
        ];
    }

    wp_send_json_success( [
        'count'    => count( $fetch->items ),
        'stats'    => $fetch->stats,
        'warnings' => $fetch->warnings,
        'preview'  => $preview,
    ] );
} );

// ─── Mappings ──────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_mappings_list', function () {
    hsync_ajax_guard();
    $kind = hsync_post_text( 'source_kind' );
    $repo = new \HiveSync\Core\Repo\MappingRepository();
    wp_send_json_success( [ 'mappings' => $repo->all( $kind !== '' ? $kind : null ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_mappings_save', function () {
    hsync_ajax_guard();
    $data = [
        'slug'        => hsync_post_text( 'slug' ),
        'name'        => hsync_post_text( 'name' ),
        'source_kind' => hsync_post_text( 'source_kind' ),
        'config'      => hsync_post_json( 'config' ),
    ];
    if ( $data['name'] === '' || $data['source_kind'] === '' ) {
        wp_send_json_error( [ 'message' => 'name e source_kind sono richiesti.' ] );
    }
    $repo = new \HiveSync\Core\Repo\MappingRepository();
    $slug = $repo->save( $data );
    wp_send_json_success( [ 'slug' => $slug ] );
} );

add_action( 'wp_ajax_hsync_ajax_mappings_delete', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );
    $repo = new \HiveSync\Core\Repo\MappingRepository();
    wp_send_json_success( [ 'deleted' => $repo->delete( $slug ) ] );
} );

// ─── Run ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_run_now', function () {
    hsync_ajax_guard();
    $sourceId = hsync_post_text( 'source_id' );
    $config   = hsync_post_json( 'config' );
    $options  = hsync_post_json( 'options' );
    $dryRun   = hsync_post_bool( 'dry_run' );

    if ( ! \HiveSync\Core\Bootstrap::$sources ) {
        wp_send_json_error( [ 'message' => 'Bootstrap non inizializzato.' ] );
    }
    $src = \HiveSync\Core\Bootstrap::$sources->get( $sourceId );
    if ( ! $src ) {
        wp_send_json_error( [ 'message' => "Source '{$sourceId}' non registrata." ] );
    }

    $deadline = time() + 25; // soft cap so AJAX doesn't blow Apache's php-cgi limit
    $runner   = new \HiveSync\Workflow\Run\ImportRunner( new \HiveSync\Core\Repo\RunRepository() );
    $envelope = $runner->run(
        source: $src,
        config: $config,
        options: $options,
        meta: [ 'trigger' => 'adhoc' ],
        dryRun: $dryRun,
        deadline: $deadline,
    );
    wp_send_json_success( $envelope );
} );

// ─── Runs ──────────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_runs_recent', function () {
    hsync_ajax_guard();
    $limit = max( 1, min( 200, (int) hsync_post_text( 'limit', '50' ) ) );
    $repo  = new \HiveSync\Core\Repo\RunRepository();
    wp_send_json_success( [ 'runs' => $repo->recent( $limit ) ] );
} );
