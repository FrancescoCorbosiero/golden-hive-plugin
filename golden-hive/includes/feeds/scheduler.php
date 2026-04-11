<?php
/**
 * Feed Scheduler — unified cron-based scheduling for all feed types.
 *
 * Manages scheduled import tasks stored in wp_options.
 * Each task points to a feed source (config file, CSV feed, etc.)
 * and runs on a WP Cron schedule.
 *
 * Supported feed types:
 * - "config"   → config-engine feed (JSON config + source URL/file)
 * - "csv_feed" → generic CSV feed pipeline (feed-csv.php)
 */

defined( 'ABSPATH' ) || exit;

/** wp_options key for scheduled tasks. */
define( 'GH_SCHED_OPTION_KEY', 'gh_scheduled_imports' );

/** WP Cron hook name. */
define( 'GH_SCHED_CRON_HOOK', 'gh_sched_cron_run' );

/** wp_options key for run log (last N runs). */
define( 'GH_SCHED_LOG_KEY', 'gh_scheduled_log' );

/** Max log entries kept. */
const GH_SCHED_LOG_MAX = 100;

// ── Task CRUD ─────────────────────────────────────────────

/**
 * Gets all scheduled tasks.
 *
 * @return array[]
 */
function gh_sched_get_tasks(): array {
    $tasks = get_option( GH_SCHED_OPTION_KEY, [] );
    return is_array( $tasks ) ? $tasks : [];
}

/**
 * Gets a single task by ID.
 */
function gh_sched_get_task( string $task_id ): ?array {
    foreach ( gh_sched_get_tasks() as $t ) {
        if ( ( $t['id'] ?? '' ) === $task_id ) return $t;
    }
    return null;
}

/**
 * Saves (creates or updates) a scheduled task.
 *
 * @param array $task Task data.
 * @return array The saved task.
 */
function gh_sched_save_task( array $task ): array {
    $tasks = gh_sched_get_tasks();
    $now   = wp_date( 'c' );

    if ( empty( $task['id'] ) ) {
        $task['id']         = 'st_' . bin2hex( random_bytes( 6 ) );
        $task['created_at'] = $now;
        $task['updated_at'] = $now;
        $task['last_run']   = null;
        $task['last_result'] = null;
        $task['run_count']  = 0;
        $tasks[]            = $task;
    } else {
        foreach ( $tasks as $i => $existing ) {
            if ( ( $existing['id'] ?? '' ) === $task['id'] ) {
                $task['created_at']  = $existing['created_at'] ?? $now;
                $task['updated_at']  = $now;
                $task['last_run']    = $task['last_run'] ?? $existing['last_run'] ?? null;
                $task['last_result'] = $task['last_result'] ?? $existing['last_result'] ?? null;
                $task['run_count']   = $existing['run_count'] ?? 0;
                $tasks[ $i ] = $task;
                break;
            }
        }
    }

    update_option( GH_SCHED_OPTION_KEY, $tasks, false );
    gh_sched_sync_cron( $task );

    return $task;
}

/**
 * Deletes a scheduled task.
 */
function gh_sched_delete_task( string $task_id ): bool {
    $tasks   = gh_sched_get_tasks();
    $initial = count( $tasks );
    $tasks   = array_values( array_filter( $tasks, fn( $t ) => ( $t['id'] ?? '' ) !== $task_id ) );

    if ( count( $tasks ) === $initial ) return false;

    update_option( GH_SCHED_OPTION_KEY, $tasks, false );
    gh_sched_unschedule( $task_id );

    return true;
}

/**
 * Toggles a task active/paused.
 */
function gh_sched_toggle_task( string $task_id ): ?array {
    $task = gh_sched_get_task( $task_id );
    if ( ! $task ) return null;

    $task['status'] = ( $task['status'] ?? 'active' ) === 'active' ? 'paused' : 'active';
    return gh_sched_save_task( $task );
}

// ── Cron Management ───────────────────────────────────────

/**
 * Syncs WP Cron for a task.
 */
function gh_sched_sync_cron( array $task ): void {
    $task_id  = $task['id'] ?? '';
    $schedule = $task['schedule'] ?? 'manual';
    $status   = $task['status'] ?? 'active';

    gh_sched_unschedule( $task_id );

    if ( $schedule !== 'manual' && $status === 'active' ) {
        wp_schedule_event( time() + 60, $schedule, GH_SCHED_CRON_HOOK, [ $task_id ] );
    }
}

/**
 * Removes cron event for a task.
 */
function gh_sched_unschedule( string $task_id ): void {
    $ts = wp_next_scheduled( GH_SCHED_CRON_HOOK, [ $task_id ] );
    if ( $ts ) {
        wp_unschedule_event( $ts, GH_SCHED_CRON_HOOK, [ $task_id ] );
    }
}

/**
 * Gets the next scheduled run timestamp for a task.
 */
function gh_sched_next_run( string $task_id ): ?int {
    $ts = wp_next_scheduled( GH_SCHED_CRON_HOOK, [ $task_id ] );
    return $ts ?: null;
}

// ── Task Runner ───────────────────────────────────────────

/**
 * Runs a scheduled task.
 *
 * @param string $task_id Task ID.
 * @return array|WP_Error Result.
 */
function gh_sched_run_task( string $task_id ): array|WP_Error {
    $task = gh_sched_get_task( $task_id );
    if ( ! $task ) {
        return new WP_Error( 'not_found', 'Task non trovato: ' . $task_id );
    }

    $feed_type = $task['feed_type'] ?? '';
    $start     = microtime( true );

    $result = match ( $feed_type ) {
        'config'   => gh_sched_run_config( $task ),
        'csv_feed' => gh_sched_run_csv_feed( $task ),
        default    => new WP_Error( 'unknown_type', 'Tipo feed sconosciuto: ' . $feed_type ),
    };

    $elapsed = round( ( microtime( true ) - $start ) * 1000 );

    // Update task state
    $run_result = is_wp_error( $result )
        ? [ 'status' => 'error', 'error' => $result->get_error_message(), 'duration_ms' => $elapsed ]
        : array_merge( [ 'status' => 'completed', 'duration_ms' => $elapsed ], $result['summary'] ?? [] );

    $run_result['ran_at'] = wp_date( 'c' );

    gh_sched_update_run( $task_id, $run_result );
    gh_sched_log_run( $task_id, $task['name'] ?? '', $run_result );

    return $result;
}

/**
 * Runs a config-engine feed task.
 */
function gh_sched_run_config( array $task ): array|WP_Error {
    $config_id   = $task['config_id'] ?? '';
    $source_type = $task['source_type'] ?? 'url';
    $source_url  = $task['source_url'] ?? '';
    $source_path = $task['source_path'] ?? '';

    if ( ! $config_id ) {
        return new WP_Error( 'no_config', 'Config ID mancante.' );
    }

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) {
        return new WP_Error( 'config_not_found', 'Config non trovato: ' . $config_id );
    }

    // Read source
    if ( $source_type === 'url' ) {
        if ( ! $source_url ) return new WP_Error( 'no_url', 'URL sorgente mancante.' );
        $response = rp_rc_request( [ 'url' => $source_url, 'method' => 'GET', 'timeout' => 120 ] );
        if ( ! empty( $response['error'] ) ) return new WP_Error( 'fetch_error', $response['error'] );
        if ( $response['status'] < 200 || $response['status'] >= 300 ) return new WP_Error( 'http_error', "HTTP {$response['status']}" );
        $rows = rp_rc_parse_csv( $response['body'] );
    } else {
        $rows = gh_csv_read_file( $source_path );
    }

    if ( is_wp_error( $rows ) ) return $rows;
    if ( empty( $rows ) ) return new WP_Error( 'empty', 'CSV vuoto.' );

    // Run config engine
    $products     = gh_fc_normalize( $rows, $config );
    $woo_products = gh_fc_transform_all( $products, $config );
    $diff         = gh_csv_diff( $woo_products );

    $create   = $task['options']['create_new'] ?? true;
    $update   = $task['options']['update_existing'] ?? true;
    $sideload = $task['options']['sideload_images'] ?? false;
    $results  = [];

    if ( $create ) {
        foreach ( $diff['new'] as $p ) {
            $results[] = gh_fc_create_product( $p, $sideload );
        }
    }
    if ( $update ) {
        foreach ( $diff['update'] as $p ) {
            $results[] = gh_csv_update_product( $p );
        }
    }

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    return [
        'summary'   => compact( 'created', 'updated', 'errors' ),
        'rows_read' => count( $rows ),
        'details'   => $results,
    ];
}

/**
 * Runs a CSV feed pipeline task.
 */
function gh_sched_run_csv_feed( array $task ): array|WP_Error {
    $feed_id = $task['csv_feed_id'] ?? '';
    if ( ! $feed_id ) {
        return new WP_Error( 'no_feed', 'CSV Feed ID mancante.' );
    }
    return gh_csv_run_feed( $feed_id );
}

/**
 * Updates task last_run after execution.
 */
function gh_sched_update_run( string $task_id, array $run_result ): void {
    $tasks = gh_sched_get_tasks();
    foreach ( $tasks as $i => $t ) {
        if ( ( $t['id'] ?? '' ) === $task_id ) {
            $tasks[ $i ]['last_run']    = $run_result['ran_at'] ?? wp_date( 'c' );
            $tasks[ $i ]['last_result'] = $run_result;
            $tasks[ $i ]['run_count']   = ( $tasks[ $i ]['run_count'] ?? 0 ) + 1;
            break;
        }
    }
    update_option( GH_SCHED_OPTION_KEY, $tasks, false );
}

// ── Run Log ───────────────────────────────────────────────

/**
 * Appends a run entry to the log.
 */
function gh_sched_log_run( string $task_id, string $task_name, array $result ): void {
    $log   = get_option( GH_SCHED_LOG_KEY, [] );
    $log   = is_array( $log ) ? $log : [];

    array_unshift( $log, [
        'task_id'   => $task_id,
        'task_name' => $task_name,
        'status'    => $result['status'] ?? 'unknown',
        'created'   => $result['created'] ?? 0,
        'updated'   => $result['updated'] ?? 0,
        'errors'    => $result['errors'] ?? ( isset( $result['error'] ) ? 1 : 0 ),
        'error_msg' => $result['error'] ?? '',
        'duration'  => $result['duration_ms'] ?? 0,
        'ran_at'    => $result['ran_at'] ?? wp_date( 'c' ),
    ] );

    // Keep last N entries
    $log = array_slice( $log, 0, GH_SCHED_LOG_MAX );

    update_option( GH_SCHED_LOG_KEY, $log, false );
}

/**
 * Gets the run log.
 */
function gh_sched_get_log( int $limit = 50 ): array {
    $log = get_option( GH_SCHED_LOG_KEY, [] );
    return array_slice( is_array( $log ) ? $log : [], 0, $limit );
}

/**
 * Clears the run log.
 */
function gh_sched_clear_log(): void {
    update_option( GH_SCHED_LOG_KEY, [], false );
}

// ── Cron Callback ─────────────────────────────────────────

add_action( GH_SCHED_CRON_HOOK, function ( string $task_id ) {
    gh_sched_run_task( $task_id );
}, 10, 1 );
