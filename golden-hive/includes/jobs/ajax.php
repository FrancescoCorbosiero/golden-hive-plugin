<?php
/**
 * Jobs AJAX bridge — thin glue between the UI and the jobs core.
 *
 * All handlers follow the Golden Hive convention:
 *   check_ajax_referer( 'gh_nonce', 'nonce' );
 *   current_user_can( 'manage_woocommerce' );
 *   wp_send_json_{success,error}(...);
 *
 * Actions:
 *   gh_ajax_jobs_list          → list jobs + registered kinds
 *   gh_ajax_jobs_get           → single job
 *   gh_ajax_jobs_save          → create/update (accepts raw JSON via `job_json`)
 *   gh_ajax_jobs_delete        → delete
 *   gh_ajax_jobs_toggle        → enable/disable
 *   gh_ajax_jobs_run_now       → manual run (bypasses schedule, respects lock)
 *   gh_ajax_jobs_log           → run log
 *   gh_ajax_jobs_log_clear     → clear log
 *   gh_ajax_jobs_cron_preview  → parse + describe a cron expression + next 5 runs
 *   gh_ajax_jobs_cron_simple   → build cron expression from "every N unit"
 */

defined( 'ABSPATH' ) || exit;

// ── Read ────────────────────────────────────────────────────

add_action( 'wp_ajax_gh_ajax_jobs_list', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $kinds = [];
    foreach ( gh_jobs_get_kinds() as $slug => $def ) {
        $kinds[ $slug ] = [
            'slug'        => $slug,
            'label'       => $def['label'] ?? $slug,
            'description' => $def['description'] ?? '',
            'params'      => $def['params'] ?? [],
        ];
    }

    // Hydrate each job with its current lock state (if any) so the UI can
    // surface "suspended mid-run" with a Resume affordance. Cheap: one
    // get_site_transient per job, all in-memory once we hit object cache.
    $jobs = gh_jobs_get_all();
    foreach ( $jobs as &$j ) {
        $lock = gh_jobs_read_lock( (string) $j['id'] );
        if ( is_array( $lock ) ) {
            $j['lock'] = [
                'run_id'       => (string) ( $lock['run_id'] ?? '' ),
                'started_at'   => (string) ( $lock['started_at'] ?? '' ),
                'started_at_ts'=> (int)    ( $lock['started_at_ts'] ?? 0 ),
                'ticks'        => (int)    ( $lock['ticks'] ?? 0 ),
                'has_cursor'   => isset( $lock['cursor'] ) && $lock['cursor'] !== null,
                'age_s'        => max( 0, time() - (int) ( $lock['started_at_ts'] ?? time() ) ),
            ];
        }
    }
    unset( $j );

    wp_send_json_success( [
        'jobs'  => $jobs,
        'kinds' => $kinds,
    ] );
} );

add_action( 'wp_ajax_gh_ajax_jobs_get', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $job_id = sanitize_text_field( (string) ( $_POST['job_id'] ?? '' ) );
    $job    = gh_jobs_get( $job_id );

    if ( ! $job ) {
        wp_send_json_error( 'Job non trovato.' );
    }
    wp_send_json_success( $job );
} );

// ── Write ───────────────────────────────────────────────────

add_action( 'wp_ajax_gh_ajax_jobs_save', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    // Accept either structured fields or a raw JSON blob (Code tab).
    $raw = (string) ( $_POST['job_json'] ?? '' );
    if ( $raw !== '' ) {
        $decoded = json_decode( wp_unslash( $raw ), true );
        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( 'JSON non valido.' );
        }
        $job = $decoded;
    } else {
        $job = [
            'id'          => sanitize_text_field( (string) ( $_POST['id']          ?? '' ) ),
            'label'       => sanitize_text_field( (string) ( $_POST['label']       ?? '' ) ),
            'kind'        => sanitize_text_field( (string) ( $_POST['kind']        ?? '' ) ),
            'cron'        => sanitize_text_field( (string) ( $_POST['cron']        ?? '' ) ),
            'enabled'     => ! empty( $_POST['enabled'] ),
            'max_runtime' => (int) ( $_POST['max_runtime'] ?? GH_JOBS_DEFAULT_MAX_RUNTIME ),
            'tick_budget' => (int) ( $_POST['tick_budget'] ?? GH_JOBS_DEFAULT_TICK_BUDGET ),
            'params'      => [],
        ];
        $params_raw = (string) ( $_POST['params_json'] ?? '' );
        if ( $params_raw !== '' ) {
            $dp = json_decode( wp_unslash( $params_raw ), true );
            $job['params'] = is_array( $dp ) ? $dp : [];
        }
    }

    $saved = gh_jobs_save( $job );
    if ( is_wp_error( $saved ) ) {
        wp_send_json_error( $saved->get_error_message() );
    }

    wp_send_json_success( $saved );
} );

add_action( 'wp_ajax_gh_ajax_jobs_delete', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $job_id = sanitize_text_field( (string) ( $_POST['job_id'] ?? '' ) );
    if ( ! gh_jobs_delete( $job_id ) ) {
        wp_send_json_error( 'Job non trovato.' );
    }
    wp_send_json_success( [ 'deleted' => $job_id ] );
} );

add_action( 'wp_ajax_gh_ajax_jobs_toggle', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $job_id = sanitize_text_field( (string) ( $_POST['job_id'] ?? '' ) );
    $job    = gh_jobs_toggle( $job_id );
    if ( ! $job ) {
        wp_send_json_error( 'Job non trovato o toggle fallito.' );
    }
    wp_send_json_success( $job );
} );

/**
 * Manual "Run now" — drains ticks within a single HTTP request so it works
 * on local environments where wp-cron does not fire continuation events.
 *
 * Smart trigger selection: if a lock with a cursor exists, the previous run
 * yielded mid-import and a continuation event is/was queued; resume from
 * that cursor by passing trigger='continuation'. Otherwise start fresh
 * with trigger='manual'.
 *
 * The loop bound (default 50) prevents pathological pipelines from hanging
 * the request indefinitely; pass `max_iters` from the client to override.
 */
add_action( 'wp_ajax_gh_ajax_jobs_run_now', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $job_id    = sanitize_text_field( (string) ( $_POST['job_id'] ?? '' ) );
    $max_iters = max( 1, min( 200, (int) ( $_POST['max_iters'] ?? 50 ) ) );

    if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );

    // If the previous run yielded a cursor, resume from it. Otherwise the
    // existing 'manual' path acquires a fresh lock and starts over.
    $existing = gh_jobs_read_lock( $job_id );
    $resumed  = is_array( $existing ) && isset( $existing['cursor'] ) && $existing['cursor'] !== null;
    $trigger  = $resumed ? 'continuation' : 'manual';

    $iters = 0;
    $last  = null;
    do {
        $last = gh_jobs_run_tick( $job_id, $trigger );
        $iters++;
        if ( is_wp_error( $last ) || ! is_array( $last ) ) break;
        // Subsequent iterations always resume the same in-flight run.
        $trigger = 'continuation';
    } while ( ( $last['status'] ?? '' ) === 'continue' && $iters < $max_iters );

    if ( is_wp_error( $last ) ) {
        wp_send_json_error( $last->get_error_message() );
    }

    wp_send_json_success( [
        'final'      => $last,
        'iterations' => $iters,
        'resumed'    => $resumed,
        'capped'     => ( $last['status'] ?? '' ) === 'continue' && $iters >= $max_iters,
    ] );
} );

// ── Log ─────────────────────────────────────────────────────

add_action( 'wp_ajax_gh_ajax_jobs_log', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $limit  = (int) ( $_POST['limit']  ?? 100 );
    $job_id = sanitize_text_field( (string) ( $_POST['job_id'] ?? '' ) );

    wp_send_json_success( gh_jobs_log_get( $limit, $job_id ) );
} );

add_action( 'wp_ajax_gh_ajax_jobs_log_clear', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    gh_jobs_log_clear();
    wp_send_json_success( [ 'cleared' => true ] );
} );

// ── Cron helpers ────────────────────────────────────────────

add_action( 'wp_ajax_gh_ajax_jobs_cron_preview', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $expr   = sanitize_text_field( (string) ( $_POST['cron'] ?? '' ) );
    $parsed = gh_cron_parse( $expr );
    if ( is_wp_error( $parsed ) ) {
        wp_send_json_error( $parsed->get_error_message() );
    }

    $runs = [];
    $from = time();
    for ( $i = 0; $i < 5; $i++ ) {
        $next = gh_cron_next_run( $expr, $from );
        if ( ! is_int( $next ) ) break;
        $runs[] = [ 'ts' => $next, 'iso' => wp_date( 'Y-m-d H:i', $next ) ];
        $from   = $next;
    }

    wp_send_json_success( [
        'expression'  => $expr,
        'description' => gh_cron_describe( $expr ),
        'next_runs'   => $runs,
    ] );
} );

add_action( 'wp_ajax_gh_ajax_jobs_cron_simple', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $every = (int) ( $_POST['every'] ?? 1 );
    $unit  = sanitize_text_field( (string) ( $_POST['unit'] ?? 'hour' ) );

    $expr = gh_cron_from_simple( $every, $unit );
    if ( is_wp_error( $expr ) ) {
        wp_send_json_error( $expr->get_error_message() );
    }
    wp_send_json_success( [ 'cron' => $expr ] );
} );
