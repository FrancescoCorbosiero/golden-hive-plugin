<?php
/**
 * Jobs Storage — CRUD over the jobs collection in wp_options.
 *
 * Job record shape:
 *   [
 *     'id'           => 'job_abc123',
 *     'label'        => 'Human friendly name',
 *     'kind'         => 'csv_feed',
 *     'params'       => [ ... kind-specific ... ],
 *     'cron'         => '* /15 * * * *',  // 5-field cron expression (without the space)
 *     'enabled'      => true,
 *     'max_runtime'  => 3600,       // seconds — hard lock TTL
 *     'tick_budget'  => 25,         // seconds — per-tick handler budget (for chunking)
 *     'created_at'   => ISO-8601,
 *     'updated_at'   => ISO-8601,
 *     'last_run_at'  => ISO-8601 | null,
 *     'last_status'  => 'done' | 'error' | 'continue' | 'crashed' | null,
 *     'last_summary' => array | null,
 *     'run_count'    => int,
 *     'next_run_at'  => unix timestamp | null,
 *   ]
 */

defined( 'ABSPATH' ) || exit;

/** wp_options key for the job collection. */
const GH_JOBS_OPTION_KEY = 'gh_jobs';

/** Default per-tick handler budget in seconds. */
const GH_JOBS_DEFAULT_TICK_BUDGET = 25;

/** Default hard lock TTL (max runtime) in seconds. */
const GH_JOBS_DEFAULT_MAX_RUNTIME = 3600;

/**
 * Returns all jobs.
 *
 * @return array[]
 */
function gh_jobs_get_all(): array {
    $jobs = get_option( GH_JOBS_OPTION_KEY, [] );
    return is_array( $jobs ) ? array_values( $jobs ) : [];
}

/**
 * Returns a single job by ID, or null.
 */
function gh_jobs_get( string $job_id ): ?array {
    foreach ( gh_jobs_get_all() as $j ) {
        if ( ( $j['id'] ?? '' ) === $job_id ) return $j;
    }
    return null;
}

/**
 * Persists the full jobs collection.
 *
 * @internal Use gh_jobs_save() / gh_jobs_delete() for single-record operations.
 */
function gh_jobs_put_all( array $jobs ): void {
    update_option( GH_JOBS_OPTION_KEY, array_values( $jobs ), false );
}

/**
 * Creates or updates a job record.
 *
 * Validates the cron expression and the kind. Recomputes next_run_at
 * and reschedules the WP-Cron tick through gh_jobs_schedule_next().
 *
 * @param array $job Job data. If 'id' is empty/unset a new record is created.
 * @return array|WP_Error The saved job, or an error.
 */
function gh_jobs_save( array $job ): array|WP_Error {
    // Validation
    $kind = trim( (string) ( $job['kind'] ?? '' ) );
    if ( $kind === '' || ! gh_jobs_get_kind( $kind ) ) {
        return new WP_Error( 'jobs_kind', "Kind non valido: {$kind}" );
    }

    $cron = trim( (string) ( $job['cron'] ?? '' ) );
    if ( $cron === '' ) {
        return new WP_Error( 'jobs_cron', 'Espressione cron mancante.' );
    }

    $parsed = gh_cron_parse( $cron );
    if ( is_wp_error( $parsed ) ) return $parsed;

    $now       = wp_date( 'c' );
    $now_ts    = time();
    $next_run  = gh_cron_next_run( $cron, $now_ts );
    if ( is_wp_error( $next_run ) ) return $next_run;

    $jobs     = gh_jobs_get_all();
    $is_new   = empty( $job['id'] );
    $existing = null;

    if ( ! $is_new ) {
        foreach ( $jobs as $j ) {
            if ( ( $j['id'] ?? '' ) === $job['id'] ) { $existing = $j; break; }
        }
    }

    $record = [
        'id'           => $is_new ? 'job_' . bin2hex( random_bytes( 6 ) ) : $job['id'],
        'label'        => (string) ( $job['label'] ?? ( $existing['label'] ?? 'Untitled job' ) ),
        'kind'         => $kind,
        'params'       => is_array( $job['params'] ?? null ) ? $job['params'] : ( $existing['params'] ?? [] ),
        'cron'         => $cron,
        'enabled'      => (bool) ( $job['enabled'] ?? ( $existing['enabled'] ?? true ) ),
        'max_runtime'  => max( 60, (int) ( $job['max_runtime'] ?? ( $existing['max_runtime'] ?? GH_JOBS_DEFAULT_MAX_RUNTIME ) ) ),
        'tick_budget'  => max( 5,  (int) ( $job['tick_budget'] ?? ( $existing['tick_budget'] ?? GH_JOBS_DEFAULT_TICK_BUDGET ) ) ),
        'created_at'   => $existing['created_at'] ?? $now,
        'updated_at'   => $now,
        'last_run_at'  => $existing['last_run_at']  ?? null,
        'last_status'  => $existing['last_status']  ?? null,
        'last_summary' => $existing['last_summary'] ?? null,
        'run_count'    => (int) ( $existing['run_count'] ?? 0 ),
        'next_run_at'  => $next_run,
    ];

    if ( $is_new ) {
        $jobs[] = $record;
    } else {
        $found = false;
        foreach ( $jobs as $i => $j ) {
            if ( ( $j['id'] ?? '' ) === $record['id'] ) {
                $jobs[ $i ] = $record;
                $found = true;
                break;
            }
        }
        if ( ! $found ) $jobs[] = $record;
    }

    gh_jobs_put_all( $jobs );
    gh_jobs_schedule_next( $record );

    return $record;
}

/**
 * Updates in-place fields on an existing job without going through the
 * full validation path. Used internally by the runner to record run state.
 */
function gh_jobs_update_fields( string $job_id, array $fields ): void {
    $jobs = gh_jobs_get_all();
    foreach ( $jobs as $i => $j ) {
        if ( ( $j['id'] ?? '' ) === $job_id ) {
            $jobs[ $i ] = array_merge( $j, $fields, [ 'updated_at' => wp_date( 'c' ) ] );
            break;
        }
    }
    gh_jobs_put_all( $jobs );
}

/**
 * Deletes a job and unschedules its cron tick.
 */
function gh_jobs_delete( string $job_id ): bool {
    $jobs    = gh_jobs_get_all();
    $initial = count( $jobs );
    $jobs    = array_values( array_filter( $jobs, fn( $j ) => ( $j['id'] ?? '' ) !== $job_id ) );

    if ( count( $jobs ) === $initial ) return false;

    gh_jobs_put_all( $jobs );
    gh_jobs_unschedule( $job_id );
    gh_jobs_release_lock( $job_id );

    return true;
}

/**
 * Toggles enabled flag.
 */
function gh_jobs_toggle( string $job_id ): ?array {
    $job = gh_jobs_get( $job_id );
    if ( ! $job ) return null;

    $job['enabled'] = ! ( $job['enabled'] ?? true );
    $saved = gh_jobs_save( $job );

    return is_wp_error( $saved ) ? null : $saved;
}
