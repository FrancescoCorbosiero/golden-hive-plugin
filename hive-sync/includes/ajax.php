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

/**
 * Arms a shutdown handler that converts PHP fatals into a JSON envelope
 * so the JS client always sees `{success:false, data:{message,...}}`
 * instead of HTML. Without this, a fatal during an AJAX tick leaks
 * WordPress's HTML error page and crashes the JS JSON parser
 * ("Unexpected token '<'") — the user reported this on tick 134 of a
 * 15k-product import.
 *
 * Idempotent — safe to call from each handler that needs the guard.
 */
function hsync_arm_fatal_guard(): void {
    static $armed = false;
    if ( $armed ) return;
    $armed = true;

    register_shutdown_function( static function (): void {
        $err = error_get_last();
        if ( ! $err ) return;
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if ( ! ( $err['type'] & $fatalTypes ) ) return;

        // If the buffer already holds HTML (PHP error page), drop it so
        // the client gets ONLY our JSON envelope.
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }

        if ( ! headers_sent() ) {
            status_header( 500 );
            header( 'Content-Type: application/json; charset=utf-8' );
        }

        $payload = [
            'success' => false,
            'data'    => [
                'message' => sprintf(
                    'PHP fatal: %s @ %s:%d',
                    (string) $err['message'],
                    basename( (string) $err['file'] ),
                    (int) $err['line']
                ),
                'fatal'   => true,
            ],
        ];
        echo wp_json_encode( $payload );
    } );
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
    $importRules = [];
    $chk = [];
    $importChecks = [];
    if ( \HiveSync\Core\Bootstrap::$operations ) {
        foreach ( \HiveSync\Core\Bootstrap::$operations->all() as $op ) {
            $isImportRule = $op instanceof \HiveSync\Core\Operation\ImportRule;
            $row = [
                'id'             => $op->id(),
                'label'          => $op->label(),
                'params_schema'  => $op->paramsSchema(),
                'applies_to'     => $op->appliesTo(),
                'is_import_rule' => $isImportRule,
            ];
            // Operations split into two registries from the UI's POV: a
            // class implementing ImportRule appears in BOTH lists so the
            // composer can stack it as either a post-op or import-rule.
            $ops[] = $row;
            if ( $isImportRule ) $importRules[] = $row;
        }
    }
    if ( \HiveSync\Core\Bootstrap::$checks ) {
        foreach ( \HiveSync\Core\Bootstrap::$checks->all() as $c ) {
            $chk[] = [
                'id'              => $c->id(),
                'label'           => $c->label(),
                'params_schema'   => $c->paramsSchema(),
                'default_severity'=> $c->defaultSeverity()->value,
            ];
        }
    }
    if ( \HiveSync\Core\Bootstrap::$importChecks ) {
        foreach ( \HiveSync\Core\Bootstrap::$importChecks->all() as $c ) {
            $importChecks[] = [
                'id'              => $c->id(),
                'label'           => $c->label(),
                'params_schema'   => $c->paramsSchema(),
                'default_severity'=> $c->defaultSeverity()->value,
            ];
        }
    }
    wp_send_json_success( [
        'operations'    => $ops,
        'import_rules'  => $importRules,
        'checks'        => $chk,
        'import_checks' => $importChecks,
    ] );
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

/**
 * List taxonomy terms for the Rule-editor filter pickers (category +
 * brand). Returns slim rows ({id, name, count, parent}) so the UI can
 * render a multi-select without a per-term roundtrip.
 *
 * Always serves both `product_cat` and `product_brand` in one call —
 * cheap (a couple of `get_terms` queries, hide_empty=false so
 * just-imported drafts are visible). The Rule's filter executes via
 * `gh_filter_product_ids` which already supports both taxonomies, so
 * this endpoint is purely a UI convenience.
 *
 * Stays standalone-safe: if `product_brand` isn't registered (Woo
 * Brands plugin not active), the brands array is empty rather than
 * an error — UI hides the picker accordingly.
 */
add_action( 'wp_ajax_hsync_ajax_taxonomy_terms', function () {
    hsync_ajax_guard();

    $slim = static function ( array $terms ): array {
        $out = [];
        foreach ( $terms as $t ) {
            $out[] = [
                'id'     => (int) $t->term_id,
                'name'   => (string) $t->name,
                'slug'   => (string) $t->slug,
                'count'  => (int) $t->count,
                'parent' => (int) $t->parent,
            ];
        }
        return $out;
    };

    $args = [
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
        'number'     => 0,
    ];

    $cats = taxonomy_exists( 'product_cat' )
        ? get_terms( array_merge( $args, [ 'taxonomy' => 'product_cat' ] ) )
        : [];
    $brands = taxonomy_exists( 'product_brand' )
        ? get_terms( array_merge( $args, [ 'taxonomy' => 'product_brand' ] ) )
        : [];

    wp_send_json_success( [
        'categories' => is_wp_error( $cats   ) ? [] : $slim( $cats   ),
        'brands'     => is_wp_error( $brands ) ? [] : $slim( $brands ),
    ] );
} );

// ─── Run ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_hsync_ajax_run_now', function () {
    hsync_ajax_guard();
    hsync_arm_fatal_guard();

    $sourceId   = hsync_post_text( 'source_id' );
    $configSlug = hsync_post_text( 'config_slug' );
    $config     = hsync_resolve_source_config( hsync_post_json( 'config' ), $configSlug );
    $options    = hsync_post_json( 'options' );
    $dryRun     = hsync_post_bool( 'dry_run' );
    $cursor     = hsync_post_json( 'cursor' );

    if ( ! \HiveSync\Core\Bootstrap::$sources ) {
        wp_send_json_error( [ 'message' => 'Bootstrap non inizializzato.' ] );
    }
    $src = \HiveSync\Core\Bootstrap::$sources->get( $sourceId );
    if ( ! $src ) {
        wp_send_json_error( [ 'message' => "Source '{$sourceId}' non registrata." ] );
    }

    $deadline = time() + 25; // soft cap so AJAX doesn't blow Apache's php-cgi limit
    $runner   = new \HiveSync\Workflow\Run\ImportRunner( new \HiveSync\Core\Repo\RunRepository() );

    try {
        $envelope = $runner->run(
            source: $src,
            config: $config,
            options: $options,
            meta: [ 'trigger' => 'adhoc' ],
            dryRun: $dryRun,
            deadline: $deadline,
            cursor: $cursor ?: null,
        );
        wp_send_json_success( $envelope );
    } catch ( \Throwable $e ) {
        // Preserve cursor so the client can retry/resume from where it
        // crashed instead of restarting the run from scratch.
        wp_send_json_error( [
            'message'  => 'Tick fallito: ' . $e->getMessage(),
            'cursor'   => $cursor ?: null,
            'recoverable' => true,
            'where'    => basename( $e->getFile() ) . ':' . $e->getLine(),
        ] );
    }
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
        new \HiveSync\Core\Repo\MappingRepository(),
    );
    wp_send_json_success( $runner->runJobNow( $id ) );
} );

add_action( 'wp_ajax_hsync_ajax_jobs_tick_now', function () {
    hsync_ajax_guard();
    wp_send_json_success( hsync_run_tick() );
} );

// ─── Defaults reinstall (mappings + pipelines) ────────────────────

add_action( 'wp_ajax_hsync_ajax_install_defaults', function () {
    hsync_ajax_guard();
    // `force=1` overwrites existing mappings/pipelines that share a slug
    // with a built-in default. Used by the "Reinstalla (sovrascrivi)"
    // button to ship code-level updates to installs that already ran
    // the activation seeder.
    $force  = hsync_post_bool( 'force' );
    $result = hsync_install_defaults( $force );
    wp_send_json_success( $result + [ 'force' => $force ] );
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

// ─── Media management ────────────────────────────────────────────
//
// Browser, whitelist CRUD, safe cleanup preview/apply, set featured /
// gallery on a product, deletion log. The reverse usage index is built
// lazily and invalidated on attachment + product save hooks (see
// HiveSync\Media\UsageIndex).

add_action( 'wp_ajax_hsync_ajax_media_query', function () {
    hsync_ajax_guard();
    $filters = [
        'filename'  => hsync_post_text( 'filename' ),
        'usage'     => hsync_post_text( 'usage', 'all' ),
        'whitelist' => hsync_post_text( 'whitelist', 'all' ),
    ];
    $page = isset( $_POST['page'] ) ? max( 1, (int) wp_unslash( $_POST['page'] ) ) : 1;
    $perPage = isset( $_POST['per_page'] ) ? max( 10, min( 500, (int) wp_unslash( $_POST['per_page'] ) ) ) : 60;
    wp_send_json_success(
        \HiveSync\Media\Browser::query( $filters, [ 'page' => $page, 'per_page' => $perPage ] ),
    );
} );

add_action( 'wp_ajax_hsync_ajax_media_index_rebuild', function () {
    hsync_ajax_guard();
    \HiveSync\Media\UsageIndex::invalidate();
    $index = \HiveSync\Media\UsageIndex::build( true );
    wp_send_json_success( [ 'attachments_indexed' => count( $index ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_cleanup_preview', function () {
    hsync_ajax_guard();
    wp_send_json_success( \HiveSync\Media\Browser::safeCleanupPreview() );
} );

add_action( 'wp_ajax_hsync_ajax_media_cleanup_apply', function () {
    hsync_ajax_guard();
    $ids = hsync_post_json( 'ids' );
    if ( empty( $ids ) ) wp_send_json_error( [ 'message' => 'Nessun ID fornito.' ] );
    $result = \HiveSync\Media\Cleaner::bulkDelete( array_map( 'intval', $ids ) );
    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_hsync_ajax_media_whitelist_list', function () {
    hsync_ajax_guard();
    wp_send_json_success( [ 'items' => \HiveSync\Media\Whitelist::all() ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_whitelist_add', function () {
    hsync_ajax_guard();
    $id     = isset( $_POST['attachment_id'] ) ? (int) wp_unslash( $_POST['attachment_id'] ) : 0;
    $url    = hsync_post_text( 'url' );
    $reason = hsync_post_text( 'reason' );
    $ok = \HiveSync\Media\Whitelist::add( $id ?: null, $url ?: null, $reason );
    if ( ! $ok ) wp_send_json_error( [ 'message' => 'Whitelist update failed (id/url mancanti?).' ] );
    wp_send_json_success( [ 'items' => \HiveSync\Media\Whitelist::all() ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_whitelist_remove', function () {
    hsync_ajax_guard();
    $id = isset( $_POST['attachment_id'] ) ? (int) wp_unslash( $_POST['attachment_id'] ) : 0;
    if ( $id <= 0 ) wp_send_json_error( [ 'message' => 'attachment_id richiesto.' ] );
    \HiveSync\Media\Whitelist::remove( $id );
    wp_send_json_success( [ 'items' => \HiveSync\Media\Whitelist::all() ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_set_featured', function () {
    hsync_ajax_guard();
    $pid = isset( $_POST['product_id'] )    ? (int) wp_unslash( $_POST['product_id'] )    : 0;
    $aid = isset( $_POST['attachment_id'] ) ? (int) wp_unslash( $_POST['attachment_id'] ) : 0;
    if ( $pid <= 0 || $aid <= 0 ) wp_send_json_error( [ 'message' => 'product_id + attachment_id richiesti.' ] );
    $r = \HiveSync\Media\Library::setProductFeaturedImage( $pid, $aid );
    if ( is_wp_error( $r ) ) wp_send_json_error( [ 'message' => $r->get_error_message() ] );
    wp_send_json_success( [ 'product_id' => $pid, 'attachment_id' => $aid ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_set_gallery', function () {
    hsync_ajax_guard();
    $pid = isset( $_POST['product_id'] ) ? (int) wp_unslash( $_POST['product_id'] ) : 0;
    $ids = hsync_post_json( 'attachment_ids' );
    if ( $pid <= 0 ) wp_send_json_error( [ 'message' => 'product_id richiesto.' ] );
    $r = \HiveSync\Media\Library::setProductGallery( $pid, array_map( 'intval', $ids ) );
    if ( is_wp_error( $r ) ) wp_send_json_error( [ 'message' => $r->get_error_message() ] );
    wp_send_json_success( [ 'product_id' => $pid, 'count' => count( $ids ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_remove_from_galleries', function () {
    hsync_ajax_guard();
    $ids = hsync_post_json( 'media_ids' );
    if ( empty( $ids ) ) wp_send_json_error( [ 'message' => 'media_ids richiesto.' ] );
    wp_send_json_success(
        \HiveSync\Media\Library::removeFromGalleries( array_map( 'intval', $ids ) ),
    );
} );

add_action( 'wp_ajax_hsync_ajax_media_deletion_log', function () {
    hsync_ajax_guard();
    $limit = isset( $_POST['limit'] ) ? max( 1, min( 500, (int) wp_unslash( $_POST['limit'] ) ) ) : 100;
    wp_send_json_success( [ 'log' => \HiveSync\Media\Cleaner::getLog( $limit ) ] );
} );

// ─── Mapping probe ───────────────────────────────────────────────
//
// Fetches a single representative row from a source (using either an
// inline config or a saved source-config slug) and returns the flat
// dot-path list + the raw sample. The visual mapping editor uses this
// to populate path autocomplete and to render a side-by-side preview
// of the mapping output.

add_action( 'wp_ajax_hsync_ajax_mapping_probe', function () {
    hsync_ajax_guard();
    $sourceId   = hsync_post_text( 'source_id' );
    $configSlug = hsync_post_text( 'config_slug' );
    $config     = hsync_resolve_source_config( hsync_post_json( 'config' ), $configSlug );

    if ( ! \HiveSync\Core\Bootstrap::$sources ) {
        wp_send_json_error( [ 'message' => 'Bootstrap non inizializzato.' ] );
    }
    $src = \HiveSync\Core\Bootstrap::$sources->get( $sourceId );
    if ( ! $src ) wp_send_json_error( [ 'message' => "Source '{$sourceId}' non registrata." ] );

    $ctx = new \HiveSync\Core\Source\Context( runId: 'probe' );
    try {
        $fetch = $src->fetch(
            new \HiveSync\Core\Source\FetchRequest( $config, [] ),
            $ctx,
        );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }

    $sample = $fetch->items[0] ?? null;
    if ( ! $sample ) {
        wp_send_json_success( [
            'paths_raw'  => [],
            'paths_data' => [],
            'sample_raw'  => null,
            'sample_data' => null,
            'count'    => 0,
            'warnings' => $fetch->warnings,
        ] );
    }

    // The mapping editor wants the user to map FROM the source's native
    // shape, so we expose the raw upstream payload as the primary path
    // list. Some sources (e.g. legacy GS bridge) only populate `data` —
    // we still return its paths separately so the user can see what's
    // available even when the bridge doesn't preserve raw.
    $pathsRaw  = hsync_flatten_paths( $sample->raw  );
    $pathsData = hsync_flatten_paths( $sample->data );
    sort( $pathsRaw );
    sort( $pathsData );

    wp_send_json_success( [
        'paths_raw'   => $pathsRaw,
        'paths_data'  => $pathsData,
        'sample_raw'  => $sample->raw  ?: null,
        'sample_data' => $sample->data ?: null,
        'sku'         => $sample->sku,
        'count'       => count( $fetch->items ),
        'warnings'    => $fetch->warnings,
    ] );
} );

/**
 * Walks an assoc array and returns a flat list of dot-paths to scalar
 * leaves. Lists-of-scalars become a single entry (e.g. 'tags'); lists
 * of objects expose 'list.0.field' so the user sees the shape.
 *
 * @param array<string, mixed> $row
 * @return string[]
 */
function hsync_flatten_paths( array $row, string $prefix = '' ): array {
    $out = [];
    foreach ( $row as $key => $value ) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if ( is_array( $value ) ) {
            // Detect "list of scalars" — surface as a single path so
            // the user can pipe-join via Mapping\Template.
            $isList = array_keys( $value ) === range( 0, count( $value ) - 1 );
            if ( $isList ) {
                $first = $value[0] ?? null;
                if ( is_scalar( $first ) || $first === null ) {
                    $out[] = $path;
                    continue;
                }
                // List of objects: expose first row paths under '.0.…'
                if ( is_array( $first ) ) {
                    $out = array_merge( $out, hsync_flatten_paths( $first, $path . '.0' ) );
                    continue;
                }
            }
            $out = array_merge( $out, hsync_flatten_paths( $value, $path ) );
        } else {
            $out[] = $path;
        }
    }
    return array_values( array_unique( $out ) );
}

// ─── Tools / Nuclear Cleanup ─────────────────────────────────────
//
// Stricter capability gate than the rest of the plugin:
// `manage_options` is a higher bar than `manage_woocommerce`, which
// matches the destructive nature of these endpoints. Every destructive
// call also requires an explicit `confirm` flag.

function hsync_ajax_admin_guard(): void {
    check_ajax_referer( 'hsync_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Richiede capability manage_options.' ], 403 );
    }
}

add_action( 'wp_ajax_hsync_ajax_nuclear_preview', function () {
    hsync_ajax_admin_guard();
    $targets = hsync_post_json( 'targets' );
    // Whitelist target keys against the canonical list — accepting a
    // free-form payload here would let a misconfigured client trigger
    // unsupported branches.
    $clean = [];
    foreach ( \HiveSync\Tools\NuclearCleanup::TARGETS as $t ) {
        if ( ! empty( $targets[ $t ] ) ) $clean[ $t ] = true;
    }
    wp_send_json_success( [ 'preview' => \HiveSync\Tools\NuclearCleanup::preview( $clean ) ] );
} );

add_action( 'wp_ajax_hsync_ajax_nuclear_execute', function () {
    hsync_ajax_admin_guard();
    if ( ! hsync_post_bool( 'confirm' ) ) {
        wp_send_json_error( [ 'message' => 'Confirm flag mancante.' ] );
    }
    $targets = hsync_post_json( 'targets' );
    $clean = [];
    foreach ( \HiveSync\Tools\NuclearCleanup::TARGETS as $t ) {
        if ( ! empty( $targets[ $t ] ) ) $clean[ $t ] = true;
    }
    if ( empty( $clean ) ) {
        wp_send_json_error( [ 'message' => 'Nessun target selezionato.' ] );
    }
    @set_time_limit( 300 );
    $started = microtime( true );
    $results = \HiveSync\Tools\NuclearCleanup::execute( $clean );
    wp_send_json_success( [
        'results'    => $results,
        'duration_s' => round( microtime( true ) - $started, 2 ),
    ] );
} );

add_action( 'wp_ajax_hsync_ajax_nuclear_count_by_source', function () {
    hsync_ajax_admin_guard();
    $source = hsync_post_text( 'source' );
    if ( $source === '' ) wp_send_json_error( [ 'message' => 'source richiesto.' ] );
    wp_send_json_success( [
        'source' => $source,
        'count'  => \HiveSync\Tools\NuclearCleanup::countBySource( $source ),
    ] );
} );

add_action( 'wp_ajax_hsync_ajax_nuclear_delete_by_source', function () {
    hsync_ajax_admin_guard();
    if ( ! hsync_post_bool( 'confirm' ) ) {
        wp_send_json_error( [ 'message' => 'Confirm flag mancante.' ] );
    }
    $source = hsync_post_text( 'source' );
    if ( $source === '' ) wp_send_json_error( [ 'message' => 'source richiesto.' ] );
    @set_time_limit( 300 );
    wp_send_json_success( \HiveSync\Tools\NuclearCleanup::deleteBySource( $source ) );
} );

// ─── Usage summary ───────────────────────────────────────────────
//
// Returns a map of which entities (source-configs, mappings,
// pipelines, rules) are referenced by active jobs. The UI decorates
// each list item with an "in uso" / "non usato" pill so the operator
// can see at a glance which entries are wired up and which are
// abandoned drafts.
//
// Single source of truth = the jobs table. A pipeline that's only
// referenced by a disabled job is still "in uso" (the operator may
// flip it on later); the badge surfaces the enabled-job count
// separately so abandoned-while-disabled is detectable.

add_action( 'wp_ajax_hsync_ajax_usage_summary', function () {
    hsync_ajax_guard();
    $jobs = ( new \HiveSync\Core\Repo\JobRepository() )->all();

    $usage = [
        'source_configs' => [],
        'mappings'       => [],
        'pipelines'      => [],
        'rules'          => [],
    ];
    $bump = function ( string $kind, string $key, int $jobId, bool $enabled ) use ( &$usage ): void {
        if ( $key === '' ) return;
        if ( ! isset( $usage[ $kind ][ $key ] ) ) {
            $usage[ $kind ][ $key ] = [ 'jobs' => [], 'enabled_jobs' => 0 ];
        }
        $usage[ $kind ][ $key ]['jobs'][] = $jobId;
        if ( $enabled ) $usage[ $kind ][ $key ]['enabled_jobs']++;
    };

    foreach ( $jobs as $j ) {
        $jid     = (int) ( $j['id'] ?? 0 );
        $type    = (string) ( $j['runnable_type'] ?? '' );
        $ref     = (string) ( $j['runnable_ref']  ?? '' );
        $cfg     = (array)  ( $j['config']        ?? [] );
        $options = (array)  ( $cfg['options']     ?? [] );
        $enabled = ! empty( $j['enabled'] );

        // Source-config: extract `<config_slug>` from "<source_id>/<config_slug>"
        if ( $type === 'source.import' && str_contains( $ref, '/' ) ) {
            [ , $configSlug ] = explode( '/', $ref, 2 );
            $bump( 'source_configs', (string) $configSlug, $jid, $enabled );
        }

        // Rule reference: rule.<slug>
        if ( $type === 'rule' || str_starts_with( $ref, 'rule.' ) || str_starts_with( $type, 'rule.' ) ) {
            $slug = str_starts_with( $ref, 'rule.' ) ? substr( $ref, 5 ) : $ref;
            $bump( 'rules', (string) $slug, $jid, $enabled );
        }

        // Mapping + pipeline slugs come through options.
        $bump( 'mappings',  (string) ( $options['mapping_slug']  ?? '' ), $jid, $enabled );
        $bump( 'pipelines', (string) ( $options['pipeline_slug'] ?? '' ), $jid, $enabled );
    }

    wp_send_json_success( [ 'usage' => $usage ] );
} );

// ─── Cockpit header status ─────────────────────────────────────
//
// Lightweight aggregated status used by the page header strip:
// counts of jobs (active/total), last run summary, Woo product count.
// Single roundtrip per page load — refreshes only when the operator
// switches tabs or hits "Tick now".

add_action( 'wp_ajax_hsync_ajax_cockpit_status', function () {
    hsync_ajax_guard();
    global $wpdb;

    $jobs       = ( new \HiveSync\Core\Repo\JobRepository() )->all();
    $jobsTotal  = count( $jobs );
    $jobsActive = 0;
    foreach ( $jobs as $j ) if ( ! empty( $j['enabled'] ) ) $jobsActive++;

    // Pull the most recent FINISHED run (skip rows still in progress
    // so the header doesn't flash a stale summary mid-import).
    $runs     = ( new \HiveSync\Core\Repo\RunRepository() )->recent( 5 );
    $lastRun  = null;
    foreach ( $runs as $r ) {
        if ( ! empty( $r['finished_at'] ) ) { $lastRun = $r; break; }
    }
    if ( ! $lastRun && ! empty( $runs ) ) $lastRun = $runs[0];

    $summary = is_array( $lastRun['report']['summary'] ?? null ) ? $lastRun['report']['summary'] : [];
    $lastInfo = $lastRun
        ? [
            'id'          => (int) ( $lastRun['id'] ?? 0 ),
            'status'      => (string) ( $lastRun['status'] ?? '' ),
            'finished_at' => (string) ( $lastRun['finished_at'] ?? '' ),
            'started_at'  => (string) ( $lastRun['started_at']  ?? '' ),
            'kind'        => (string) ( $lastRun['runnable_type'] ?? '' ),
            'ref'         => (string) ( $lastRun['runnable_ref']  ?? '' ),
            'created'     => (int) ( $summary['created']       ?? 0 ),
            'updated'     => (int) ( $summary['updated']       ?? 0 ),
            'patched'     => (int) ( $summary['stock_patched'] ?? 0 ),
            'failed'      => (int) ( $summary['failed']        ?? 0 ),
        ]
        : null;

    $productCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')",
    );

    wp_send_json_success( [
        'jobs_active'   => $jobsActive,
        'jobs_total'    => $jobsTotal,
        'last_run'      => $lastInfo,
        'product_count' => $productCount,
        'now_iso'       => gmdate( 'c' ),
    ] );
} );

// ─── Cleanup helpers (history logs, deletion log, lock release) ───
//
// Surface every plugin-owned growing log so the operator can wipe
// noise without dropping the plugin. All idempotent + reversible-
// confirmation gated client-side.

add_action( 'wp_ajax_hsync_ajax_runs_purge_all', function () {
    hsync_ajax_guard();
    $deleted = ( new \HiveSync\Core\Repo\RunRepository() )->purgeAll();
    wp_send_json_success( [ 'deleted' => $deleted ] );
} );

add_action( 'wp_ajax_hsync_ajax_runs_purge_older', function () {
    hsync_ajax_guard();
    $days = isset( $_POST['days'] ) ? max( 1, (int) wp_unslash( $_POST['days'] ) ) : 30;
    $deleted = ( new \HiveSync\Core\Repo\RunRepository() )->purgeOlderThan( $days );
    wp_send_json_success( [ 'deleted' => $deleted, 'days' => $days ] );
} );

add_action( 'wp_ajax_hsync_ajax_media_log_clear', function () {
    hsync_ajax_guard();
    delete_option( \HiveSync\Media\Cleaner::LOG_OPTION_KEY );
    wp_send_json_success( [ 'cleared' => true ] );
} );

add_action( 'wp_ajax_hsync_ajax_release_tick_lock', function () {
    hsync_ajax_guard();
    delete_transient( 'hsync_jobs_tick_lock' );
    wp_send_json_success( [ 'released' => true ] );
} );

// ─── System status (production WP-Cron readiness check) ───────────
//
// Surfaces whether the operator's environment is set up for the
// recommended "real cron hits wp-cron.php" pattern. We can't
// actively probe their crontab, but we CAN tell them whether
// DISABLE_WP_CRON is set + when our event last fired + whether the
// next scheduled fire is in the past (= cron is broken).

add_action( 'wp_ajax_hsync_ajax_system_status', function () {
    hsync_ajax_guard();

    $next       = wp_next_scheduled( 'hive_sync_jobs_tick' );
    $now        = time();
    $disableWp  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON === true;
    $altCron    = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON === true;
    $wpUrl      = site_url( 'wp-cron.php?doing_wp_cron' );
    $isOverdue  = $next && $next < ( $now - 600 ); // > 10 min overdue

    wp_send_json_success( [
        'next_tick_at'     => $next ? gmdate( 'c', $next ) : null,
        'next_tick_in_sec' => $next ? ( $next - $now ) : null,
        'overdue'          => $isOverdue,
        'disable_wp_cron'  => $disableWp,
        'alternate_wp_cron'=> $altCron,
        'wp_cron_url'      => $wpUrl,
        'recommended_crontab' => '* * * * * curl -s "' . $wpUrl . '" > /dev/null 2>&1',
    ] );
} );

// ─── Source-config duplicate (server-side, preserves secrets) ─────
//
// Cloning a source-config in JS would round-trip the redacted secret
// placeholder back to the DB. The duplicate has to happen here where
// we have plaintext access — read the row, generate a new slug,
// suffix the name with " (copia)", insert. Returns the new slug.

add_action( 'wp_ajax_hsync_ajax_source_configs_duplicate', function () {
    hsync_ajax_guard();
    $slug = hsync_post_text( 'slug' );
    if ( $slug === '' ) wp_send_json_error( [ 'message' => 'slug richiesto.' ] );

    $repo = new \HiveSync\Core\Repo\SourceConfigRepository();
    $row  = $repo->find( $slug );
    if ( ! $row ) wp_send_json_error( [ 'message' => 'config non trovata: ' . $slug ] );

    $copyName = ( (string) ( $row['name'] ?? '' ) ) !== ''
        ? $row['name'] . ' (copia)'
        : $slug . ' (copia)';

    $newSlug = $repo->save( [
        // Empty slug → repo generates a fresh one.
        'slug'        => '',
        'name'        => $copyName,
        'source_kind' => (string) ( $row['source_kind'] ?? '' ),
        'config'      => (array) ( $row['config'] ?? [] ),
    ] );

    wp_send_json_success( [ 'slug' => $newSlug, 'name' => $copyName ] );
} );

// ─── Project config-as-code ──────────────────────────────────────
//
// Three endpoints back the Configurazione tab and any LLM-driven
// "paste a JSON to reshape the install" flow:
//
//   project_export    → dump every persisted entity to a single JSON
//                        document (secrets redacted to ••••XXXX)
//   project_validate  → structural + referential checks; returns
//                        { ok, errors, diff } so the operator can
//                        preview the planned changes
//   project_apply     → execute the diff. Optional `prune` flag
//                        deletes entities the document doesn't list
//                        ("declarative" mode); default is additive.

function hsync_project_repos(): array {
    return [
        new \HiveSync\Core\Repo\SourceConfigRepository(),
        new \HiveSync\Core\Repo\MappingRepository(),
        new \HiveSync\Core\Pipeline\PipelineRepository(),
        new \HiveSync\Core\Repo\RuleRepository(),
        new \HiveSync\Core\Repo\JobRepository(),
    ];
}

add_action( 'wp_ajax_hsync_ajax_project_export', function () {
    hsync_ajax_guard();
    [ $sc, $m, $p, $r, $j ] = hsync_project_repos();
    $exporter = new \HiveSync\Workflow\Config\ProjectExporter( $sc, $m, $p, $r, $j );
    wp_send_json_success( [ 'project' => $exporter->export() ] );
} );

add_action( 'wp_ajax_hsync_ajax_project_validate', function () {
    hsync_ajax_guard();
    $project = hsync_post_json( 'project' );
    if ( ! $project ) wp_send_json_error( [ 'message' => 'project JSON mancante o non parsabile.' ] );

    [ $sc, $m, $p, $r, $j ] = hsync_project_repos();
    $applier = new \HiveSync\Workflow\Config\ProjectApplier( $sc, $m, $p, $r, $j );
    $validation = $applier->validate( $project );
    $diff       = $validation['ok'] ? $applier->diff( $project ) : [];
    wp_send_json_success( [
        'ok'     => $validation['ok'],
        'errors' => $validation['errors'],
        'diff'   => $diff,
    ] );
} );

add_action( 'wp_ajax_hsync_ajax_project_apply', function () {
    hsync_ajax_guard();
    $project = hsync_post_json( 'project' );
    if ( ! $project ) wp_send_json_error( [ 'message' => 'project JSON mancante o non parsabile.' ] );
    $prune = hsync_post_bool( 'prune' );

    [ $sc, $m, $p, $r, $j ] = hsync_project_repos();
    $applier = new \HiveSync\Workflow\Config\ProjectApplier( $sc, $m, $p, $r, $j );
    $result  = $applier->apply( $project, [ 'prune' => $prune ] );

    if ( ! $result['ok'] ) {
        wp_send_json_error( [
            'message' => 'Validazione fallita.',
            'errors'  => $result['errors'],
        ], 422 );
    }
    wp_send_json_success( $result );
} );

// ─── Markup rules tester ──────────────────────────────────────────
//
// Runs source.fetch with the operator's current config (including
// markup_rules) on a small slice of the feed and returns per-product
// diagnostics: which rule matched, what value was compared, what
// multiplier resolved. Removes guessing about "did my rule fire?".

add_action( 'wp_ajax_hsync_ajax_markup_rules_test', function () {
    hsync_ajax_guard();
    $sourceId   = hsync_post_text( 'source_id' );
    $configSlug = hsync_post_text( 'config_slug' );
    $config     = hsync_resolve_source_config( hsync_post_json( 'config' ), $configSlug );

    if ( ! \HiveSync\Core\Bootstrap::$sources ) {
        wp_send_json_error( [ 'message' => 'Bootstrap non inizializzato.' ] );
    }
    $src = \HiveSync\Core\Bootstrap::$sources->get( $sourceId );
    if ( ! $src ) {
        wp_send_json_error( [ 'message' => "Source '{$sourceId}' non registrata." ] );
    }

    $ctx = new \HiveSync\Core\Source\Context( runId: 'markup-test' );
    $req = new \HiveSync\Core\Source\FetchRequest( $config, [] );
    try {
        $fetch = $src->fetch( $req, $ctx );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }

    // Surface the markup-relevant fields per product. The SF flavor
    // populates _sf_applied_multiplier on each FeedItem, so we just
    // pluck the diagnostic-ready fields directly. For generic CSV
    // and JSON the same diagnostics are computed on demand.
    $rows = [];
    foreach ( array_slice( $fetch->items, 0, 20 ) as $item ) {
        $d = $item->data;
        $rows[] = [
            'sku'                    => (string) $item->sku,
            'name'                   => (string) ( $d['name'] ?? '' ),
            // SF-specific diagnostic fields surfaced by the transform.
            'applied_multiplier'     => isset( $d['_sf_applied_multiplier'] ) ? (float) $d['_sf_applied_multiplier'] : null,
            'markup_target'          => (string) ( $d['_sf_markup_target'] ?? '' ),
            // The actual field values the matcher saw.
            '_sf_brand'              => (string) ( $d['_sf_brand']        ?? '' ),
            '_sf_category'           => (string) ( $d['_sf_category']     ?? '' ),
            '_sf_subcategory'        => (string) ( $d['_sf_subcategory']  ?? '' ),
            '_sf_taxonomy_any'       => trim(
                ( (string) ( $d['_sf_subcategory'] ?? '' ) ) !== ''
                    ? (string) $d['_sf_subcategory']
                    : (string) ( $d['_sf_category'] ?? '' )
            ),
            '_sf_taxonomy'           => trim( ( (string) ( $d['_sf_category'] ?? '' ) ) . ' > ' . ( (string) ( $d['_sf_subcategory'] ?? '' ) ), ' >' ),
            'variations_count'       => isset( $d['variations'] ) && is_array( $d['variations'] ) ? count( $d['variations'] ) : 0,
            'first_variation_prices' => isset( $d['variations'][0] ) && is_array( $d['variations'][0] )
                ? [
                    'regular_price'  => (string) ( $d['variations'][0]['regular_price']  ?? '' ),
                    'sale_price'     => (string) ( $d['variations'][0]['sale_price']     ?? '' ),
                    'stock_quantity' => (int)    ( $d['variations'][0]['stock_quantity'] ?? 0 ),
                ]
                : null,
        ];
    }

    wp_send_json_success( [
        'count'    => count( $fetch->items ),
        'rules'    => is_array( $config['markup_rules'] ?? null ) ? $config['markup_rules'] : [],
        'rows'     => $rows,
        'warnings' => $fetch->warnings,
    ] );
} );
