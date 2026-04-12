<?php
/**
 * Jobs Run Log — ring buffer of recent run entries.
 *
 * Entry shape:
 *   [
 *     'run_id'      => 'run_abc123',
 *     'job_id'      => 'job_xyz',
 *     'job_label'   => 'Human label',
 *     'kind'        => 'csv_feed',
 *     'status'      => 'done' | 'error' | 'continue' | 'crashed',
 *     'started_at'  => ISO-8601,
 *     'ended_at'    => ISO-8601,
 *     'duration_ms' => int,
 *     'summary'     => array | null,   // on done
 *     'progress'    => array | null,   // on continue
 *     'error'       => string | null,  // on error/crashed
 *     'trigger'     => 'cron' | 'manual' | 'continuation',
 *     'ticks'       => int,            // how many continuation ticks this run took
 *   ]
 */

defined( 'ABSPATH' ) || exit;

/** wp_options key for the run log. */
const GH_JOBS_LOG_KEY = 'gh_jobs_log';

/** Maximum entries retained in the log. */
const GH_JOBS_LOG_MAX = 250;

/**
 * Appends a run entry to the log (newest first, trimmed to GH_JOBS_LOG_MAX).
 */
function gh_jobs_log_append( array $entry ): void {
    $log = get_option( GH_JOBS_LOG_KEY, [] );
    $log = is_array( $log ) ? $log : [];

    array_unshift( $log, $entry );
    $log = array_slice( $log, 0, GH_JOBS_LOG_MAX );

    update_option( GH_JOBS_LOG_KEY, $log, false );
}

/**
 * Reads the log (optionally filtered by job_id).
 */
function gh_jobs_log_get( int $limit = 100, string $job_id = '' ): array {
    $log = get_option( GH_JOBS_LOG_KEY, [] );
    $log = is_array( $log ) ? $log : [];

    if ( $job_id !== '' ) {
        $log = array_values( array_filter( $log, fn( $e ) => ( $e['job_id'] ?? '' ) === $job_id ) );
    }

    return array_slice( $log, 0, max( 1, $limit ) );
}

/**
 * Clears the log.
 */
function gh_jobs_log_clear(): void {
    update_option( GH_JOBS_LOG_KEY, [], false );
}
