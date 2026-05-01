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

/**
 * Resolve the effective config for a source invocation: either an
 * inline config blob OR a saved config slug. The saved one always
 * takes precedence (its values include real plaintext secrets).
 */
function hsync_resolve_source_config( array $inline, string $configSlug ): array {
    if ( $configSlug !== '' ) {
        $repo = new \HiveSync\Core\Repo\SourceConfigRepository();
        $row  = $repo->find( $configSlug );
        if ( $row ) return (array) $row['config'];
    }
    return $inline;
}

add_action( 'wp_ajax_hsync_ajax_source_test_fetch', function () {
    hsync_ajax_guard();
    $sourceId   = hsync_post_text( 'source_id' );
    $configSlug = hsync_post_text( 'config_slug' );
    $config     = hsync_resolve_source_config( hsync_post_json( 'config' ), $configSlug );
    $options    = hsync_post_json( 'options' );

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

// ─── Source configs ────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_source_configs_list', function () {
    hsync_ajax_guard();
    $kind = hsync_post_text( 'source_kind' );
    $repo = new \HiveSync\Core\Repo\SourceConfigRepository();
    wp_send_json_success( [ 'configs' => $repo->allRedacted( $kind !== '' ? $kind : null ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_source_configs_save', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    $data = [
        'slug'        => $slug,
        'name'        => hsync_post_text( 'name' ),
        'source_kind' => hsync_post_text( 'source_kind' ),
        'config'      => hsync_post_json( 'config' ),
    ];
    if ( $data['name'] === '' || $data['source_kind'] === '' ) {
        wp_send_json_error( [ 'message' => 'name e source_kind richiesti.' ] );
    }
    $repo = new \HiveSync\Core\Repo\SourceConfigRepository();
    $existing = $slug !== '' ? ( $repo->find( $slug )['config'] ?? [] ) : [];
    $stored = $repo->save( $data, $existing );
    wp_send_json_success( [ 'slug' => $stored ] );
} );

add_action( 'wp_ajax_hsync_ajax_source_configs_delete', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );
    $repo = new \HiveSync\Core\Repo\SourceConfigRepository();
    wp_send_json_success( [ 'deleted' => $repo->delete( $slug ) ] );
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

// ─── Registry (operations + checks for the pipeline composer) ─────

add_action( 'wp_ajax_hsync_ajax_registry_list', function () {
    hsync_ajax_guard();
    $ops = [];
    $chk = [];
    if ( \HiveSync\Core\Bootstrap::$operations ) {
        foreach ( \HiveSync\Core\Bootstrap::$operations->all() as $op ) {
            $ops[] = [
                'id'             => $op->id(),
                'label'          => $op->label(),
                'params_schema'  => $op->paramsSchema(),
                'applies_to'     => $op->appliesTo(),
                'is_import_rule' => $op instanceof \HiveSync\Core\Operation\ImportRule,
            ];
        }
    }
    if ( \HiveSync\Core\Bootstrap::$checks ) {
        foreach ( \HiveSync\Core\Bootstrap::$checks->all() as $c ) {
            $chk[] = [
                'id'              => $c->id(),
                'label'           => $c->label(),
                'params_schema'   => $c->paramsSchema(),
                'default_severity' => $c->defaultSeverity()->value,
            ];
        }
    }
    wp_send_json_success( [ 'operations' => $ops, 'checks' => $chk ] );
} );

// ─── Pipelines ─────────────────────────────────────────────────────

function hsync_serialize_pipeline( \HiveSync\Core\Pipeline\Pipeline $p ): array {
    $steps = [];
    foreach ( $p->steps as $s ) {
        $steps[] = [
            'kind'   => $s->kind->value,
            'ref_id' => $s->refId,
            'params' => $s->params,
            'note'   => $s->note,
        ];
    }
    return [
        'slug'  => $p->id,
        'name'  => $p->name,
        'steps' => $steps,
        'meta'  => $p->meta,
    ];
}

add_action( 'wp_ajax_hsync_ajax_pipelines_list', function () {
    hsync_ajax_guard();
    $repo = new \HiveSync\Core\Pipeline\PipelineRepository();
    $out  = array_map( 'hsync_serialize_pipeline', $repo->all() );
    wp_send_json_success( [ 'pipelines' => $out ] );
} );

add_action( 'wp_ajax_hsync_ajax_pipeline_get', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );
    $repo = new \HiveSync\Core\Pipeline\PipelineRepository();
    $p    = $repo->find( $slug );
    if ( ! $p ) wp_send_json_error( [ 'message' => 'Pipeline non trovata.' ] );
    wp_send_json_success( hsync_serialize_pipeline( $p ) );
} );

add_action( 'wp_ajax_hsync_ajax_pipeline_save', function () {
    hsync_ajax_guard();
    $slug  = hsync_post_text( 'slug' );
    $name  = hsync_post_text( 'name' );
    $steps = hsync_post_json( 'steps' );
    if ( $name === '' ) wp_send_json_error( [ 'message' => 'name richiesto.' ] );

    $stepObjs = [];
    foreach ( $steps as $s ) {
        $kind = \HiveSync\Core\Pipeline\PipelineStepKind::tryFrom( (string) ( $s['kind'] ?? '' ) );
        if ( ! $kind ) continue;
        $stepObjs[] = new \HiveSync\Core\Pipeline\PipelineStep(
            kind: $kind,
            refId: (string) ( $s['ref_id'] ?? '' ),
            params: (array) ( $s['params'] ?? [] ),
            note: isset( $s['note'] ) && $s['note'] !== '' ? (string) $s['note'] : null,
        );
    }

    $repo = new \HiveSync\Core\Pipeline\PipelineRepository();
    $stored = $repo->save( new \HiveSync\Core\Pipeline\Pipeline(
        id: $slug, name: $name, steps: $stepObjs,
    ) );
    wp_send_json_success( [ 'slug' => $stored ] );
} );

add_action( 'wp_ajax_hsync_ajax_pipeline_delete', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );
    $repo = new \HiveSync\Core\Pipeline\PipelineRepository();
    wp_send_json_success( [ 'deleted' => $repo->delete( $slug ) ] );
} );

// ─── Rules ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_rules_list', function () {
    hsync_ajax_guard();
    $repo = new \HiveSync\Core\Repo\RuleRepository();
    wp_send_json_success( [ 'rules' => $repo->all() ] );
} );

add_action( 'wp_ajax_hsync_ajax_rule_get', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );
    $repo = new \HiveSync\Core\Repo\RuleRepository();
    $r    = $repo->find( $slug );
    if ( ! $r ) wp_send_json_error( [ 'message' => 'Rule non trovata.' ] );
    wp_send_json_success( $r );
} );

add_action( 'wp_ajax_hsync_ajax_rule_save', function () {
    hsync_ajax_guard();
    $data = [
        'slug'       => hsync_post_text( 'slug' ),
        'name'       => hsync_post_text( 'name' ),
        'selection'  => hsync_post_json( 'selection' ),
        'operations' => hsync_post_json( 'operations' ),
        'checks'     => hsync_post_json( 'checks' ),
        'enabled'    => hsync_post_bool( 'enabled' ),
    ];
    if ( $data['name'] === '' ) wp_send_json_error( [ 'message' => 'name richiesto.' ] );
    $repo = new \HiveSync\Core\Repo\RuleRepository();
    wp_send_json_success( [ 'slug' => $repo->save( $data ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_rule_delete', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );
    $repo = new \HiveSync\Core\Repo\RuleRepository();
    wp_send_json_success( [ 'deleted' => $repo->delete( $slug ) ] );
} );

// ─── Run ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_run_now', function () {
    hsync_ajax_guard();
    $sourceId   = hsync_post_text( 'source_id' );
    $configSlug = hsync_post_text( 'config_slug' );
    $config     = hsync_resolve_source_config( hsync_post_json( 'config' ), $configSlug );
    $options    = hsync_post_json( 'options' );
    $dryRun     = hsync_post_bool( 'dry_run' );

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

// ─── Jobs ──────────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_jobs_list', function () {
    hsync_ajax_guard();
    $repo = new \HiveSync\Core\Repo\JobRepository();
    wp_send_json_success( [ 'jobs' => $repo->all() ] );
} );

add_action( 'wp_ajax_hsync_ajax_job_save', function () {
    hsync_ajax_guard();
    $cron = hsync_post_text( 'cron_expr' );
    if ( $cron !== '' && \HiveSync\Workflow\Schedule\CronExpr::parse( $cron ) === null ) {
        wp_send_json_error( [ 'message' => 'Cron expression non valida.' ] );
    }
    $nextRunAt = null;
    if ( $cron !== '' ) {
        $next = \HiveSync\Workflow\Schedule\CronExpr::nextRun( $cron, time() );
        if ( $next !== null ) $nextRunAt = gmdate( 'Y-m-d H:i:s', $next );
    }

    $repo = new \HiveSync\Core\Repo\JobRepository();
    $id   = $repo->save( [
        'id'            => (int) hsync_post_text( 'id' ),
        'runnable_type' => hsync_post_text( 'runnable_type' ),
        'runnable_ref'  => hsync_post_text( 'runnable_ref' ),
        'cron_expr'     => $cron,
        'enabled'       => hsync_post_bool( 'enabled' ),
        'next_run_at'   => $nextRunAt,
        'config'        => hsync_post_json( 'config' ),
    ] );
    wp_send_json_success( [ 'id' => $id, 'next_run_at' => $nextRunAt ] );
} );

add_action( 'wp_ajax_hsync_ajax_job_delete', function () {
    hsync_ajax_guard();
    $id = (int) hsync_post_text( 'id' );
    if ( $id <= 0 ) wp_send_json_error( [ 'message' => 'id richiesto.' ] );
    $repo = new \HiveSync\Core\Repo\JobRepository();
    wp_send_json_success( [ 'deleted' => $repo->delete( $id ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_job_run_now', function () {
    hsync_ajax_guard();
    $id = (int) hsync_post_text( 'id' );
    if ( $id <= 0 ) wp_send_json_error( [ 'message' => 'id richiesto.' ] );
    $runner = new \HiveSync\Workflow\Schedule\JobRunner(
        new \HiveSync\Core\Repo\JobRepository(),
        new \HiveSync\Core\Repo\RunRepository(),
        new \HiveSync\Core\Repo\RuleRepository(),
        new \HiveSync\Core\Repo\SourceConfigRepository(),
    );
    wp_send_json_success( $runner->runJobNow( $id ) );
} );

add_action( 'wp_ajax_hsync_ajax_jobs_tick_now', function () {
    hsync_ajax_guard();
    wp_send_json_success( hsync_run_tick() );
} );

// ─── Action Scheduler health (helpful when DISABLE_WP_CRON) ───────

add_action( 'wp_ajax_hsync_ajax_as_health', function () {
    hsync_ajax_guard();
    if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
        wp_send_json_error( [ 'message' => 'Action Scheduler non disponibile (WooCommerce caricato?).' ] );
    }
    $past_due = as_get_scheduled_actions( [
        'status'    => \ActionScheduler_Store::STATUS_PENDING,
        'date'      => 'now',
        'date_compare' => '<',
        'per_page'  => 1,
        'group'     => '',
    ], 'ids' );
    $total_pending = as_get_scheduled_actions( [
        'status'   => \ActionScheduler_Store::STATUS_PENDING,
        'per_page' => 1,
    ], 'ids' );

    // For an accurate count we need a SELECT COUNT, not LIMIT 1. The
    // 'ids' return is paginated; use the store directly for totals.
    global $wpdb;
    $total_pending_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending'"
    );
    $past_due_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND scheduled_date_gmt < %s",
        gmdate( 'Y-m-d H:i:s' ),
    ) );
    $failed_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'failed'"
    );
    $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

    wp_send_json_success( [
        'pending'       => $total_pending_count,
        'past_due'      => $past_due_count,
        'failed'        => $failed_count,
        'cron_disabled' => $cron_disabled,
    ] );
} );

add_action( 'wp_ajax_hsync_ajax_as_run_queue', function () {
    hsync_ajax_guard();
    if ( ! has_action( 'action_scheduler_run_queue' ) ) {
        wp_send_json_error( [ 'message' => 'Action Scheduler non disponibile.' ] );
    }
    $start = microtime( true );
    do_action( 'action_scheduler_run_queue', 'Hive Sync Manual' );
    wp_send_json_success( [ 'ok' => true, 'duration_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_as_purge_past_due', function () {
    hsync_ajax_guard();
    global $wpdb;
    // Hard delete pending actions that were due more than 7 days ago.
    // Anything past-due that long is almost certainly stale (the
    // operator has noticed and is manually cleaning up).
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * 86400 );
    $deleted = (int) $wpdb->query( $wpdb->prepare(
        "DELETE a, l
         FROM {$wpdb->prefix}actionscheduler_actions a
         LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON l.action_id = a.action_id
         WHERE a.status = 'pending' AND a.scheduled_date_gmt < %s",
        $cutoff,
    ) );
    wp_send_json_success( [ 'deleted' => max( 0, $deleted ) ] );
} );

// ─── Exports ───────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_export_inventory', function () {
    hsync_ajax_guard();
    $format = hsync_post_text( 'format', 'csv' );
    $exporter = new \HiveSync\Workflow\Export\Exporter();
    $rows = $exporter->inventoryRows();
    if ( $format === 'json' ) {
        wp_send_json_success( [
            'filename' => 'hive-sync-inventory-' . gmdate( 'Y-m-d-His' ) . '.json',
            'mime'     => 'application/json',
            'body'     => wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
            'count'    => count( $rows ),
        ] );
    }
    wp_send_json_success( [
        'filename' => 'hive-sync-inventory-' . gmdate( 'Y-m-d-His' ) . '.csv',
        'mime'     => 'text/csv',
        'body'     => \HiveSync\Workflow\Export\Exporter::rowsToCsv( $rows ),
        'count'    => count( $rows ),
    ] );
} );

add_action( 'wp_ajax_hsync_ajax_export_catalog', function () {
    hsync_ajax_guard();
    $exporter = new \HiveSync\Workflow\Export\Exporter();
    $tree = $exporter->catalogTree();
    wp_send_json_success( [
        'filename' => 'hive-sync-catalog-' . gmdate( 'Y-m-d-His' ) . '.json',
        'mime'     => 'application/json',
        'body'     => wp_json_encode( $tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
        'count'    => array_sum( array_map( 'count', $tree ) ),
    ] );
} );

// ─── Legacy migration ──────────────────────────────────────────────

function hsync_legacy_importer(): \HiveSync\Workflow\Migration\LegacyImporter {
    return new \HiveSync\Workflow\Migration\LegacyImporter(
        new \HiveSync\Core\Pipeline\PipelineRepository(),
        new \HiveSync\Core\Repo\MappingRepository(),
        new \HiveSync\Core\Repo\JobRepository(),
    );
}

add_action( 'wp_ajax_hsync_ajax_legacy_audit', function () {
    hsync_ajax_guard();
    wp_send_json_success( hsync_legacy_importer()->audit() );
} );

add_action( 'wp_ajax_hsync_ajax_legacy_import', function () {
    hsync_ajax_guard();
    wp_send_json_success( hsync_legacy_importer()->run() );
} );
