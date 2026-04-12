<?php
/**
 * Jobs Runner — orchestrates WP-Cron ticks, locks, chunking and rescheduling.
 *
 * ## Lifecycle
 *
 *   wp_schedule_single_event( next_run, 'gh_jobs_tick', [ job_id ] )
 *      └─ gh_jobs_tick_handler( job_id )
 *            ├─ acquire lock (transient)
 *            ├─ call kind handler with context (deadline = now + tick_budget)
 *            ├─ dispatch on result status:
 *            │     done       → release lock, log, schedule next cron run
 *            │     continue   → store cursor, schedule +1s continuation
 *            │     error      → release lock, log, schedule next cron run
 *            └─ stale lock recovery: older than max_runtime → crashed, release
 *
 *   gh_jobs_continue_handler( run_id )
 *      └─ reads active run state from lock, re-enters handler with cursor,
 *         same dispatch as above. Lock is held across continuations.
 *
 * ## Locking
 *
 * Locks are site transients keyed `gh_job_lock_{job_id}` with TTL = max_runtime.
 * Value is a JSON blob containing run_id, started_at, cursor, tick counter.
 * Only the holder of the lock may progress a run. A crashed holder (no
 * continuation fires) is reclaimed automatically when the transient expires.
 *
 * ## Chunking contract
 *
 * Handlers must check gh_jobs_should_yield() periodically. When it returns
 * true they must return status=continue with a cursor they can resume from.
 * Cursor shape is handler-defined (e.g. integer row offset, array of seen IDs).
 *
 * ## Concurrency guarantees
 *
 * - At most ONE tick can execute handler code for a given job_id at a time.
 * - A job still "running" when the next cron-scheduled tick fires will
 *   SKIP that tick and log a 'skipped' entry — the in-flight run continues
 *   until done. The skipped cron occurrence does not queue up.
 * - On the cron-less VPS setup (crontab → wp-cron.php), overlapping ticks
 *   are blocked by the transient lock regardless of how the request arrives.
 */

defined( 'ABSPATH' ) || exit;

/** Hook names */
const GH_JOBS_TICK_HOOK     = 'gh_jobs_tick';
const GH_JOBS_CONTINUE_HOOK = 'gh_jobs_continue';

/** Transient key prefix for per-job locks. */
const GH_JOBS_LOCK_PREFIX = 'gh_job_lock_';

/** Static cache of the current tick deadline — read by gh_jobs_should_yield(). */
$GLOBALS['gh_jobs_current_deadline'] = 0;

// ── Lock helpers ───────────────────────────────────────────

/**
 * Attempts to acquire the per-job lock.
 *
 * Returns the existing lock value if already held (caller decides what to do),
 * or the newly created lock value on success. Uses add_option atomicity via
 * set_site_transient's underlying storage.
 *
 * @param array $job
 * @param array $lock_data Fresh lock payload.
 * @return array{acquired:bool, lock:array}
 */
function gh_jobs_acquire_lock( array $job, array $lock_data ): array {
    $key      = GH_JOBS_LOCK_PREFIX . $job['id'];
    $existing = get_site_transient( $key );

    if ( is_array( $existing ) ) {
        // Stale recovery: if older than max_runtime, reclaim.
        $age = time() - (int) ( $existing['started_at_ts'] ?? 0 );
        if ( $age > (int) ( $job['max_runtime'] ?? GH_JOBS_DEFAULT_MAX_RUNTIME ) ) {
            delete_site_transient( $key );
        } else {
            return [ 'acquired' => false, 'lock' => $existing ];
        }
    }

    set_site_transient( $key, $lock_data, (int) ( $job['max_runtime'] ?? GH_JOBS_DEFAULT_MAX_RUNTIME ) );
    return [ 'acquired' => true, 'lock' => $lock_data ];
}

/**
 * Updates the lock payload in-place (e.g. to store a new cursor after a tick).
 */
function gh_jobs_update_lock( string $job_id, array $lock_data, int $ttl ): void {
    set_site_transient( GH_JOBS_LOCK_PREFIX . $job_id, $lock_data, $ttl );
}

/**
 * Reads the current lock payload for a job, or null.
 */
function gh_jobs_read_lock( string $job_id ): ?array {
    $v = get_site_transient( GH_JOBS_LOCK_PREFIX . $job_id );
    return is_array( $v ) ? $v : null;
}

/**
 * Releases a job lock unconditionally.
 */
function gh_jobs_release_lock( string $job_id ): void {
    delete_site_transient( GH_JOBS_LOCK_PREFIX . $job_id );
}

// ── Scheduling helpers ─────────────────────────────────────

/**
 * Schedules the next WP-Cron tick for a job based on its cron expression.
 *
 * Called after create/update/enable and after every completed or errored run.
 * Does NOT schedule if the job is disabled.
 */
function gh_jobs_schedule_next( array $job ): void {
    gh_jobs_unschedule( $job['id'] );

    if ( empty( $job['enabled'] ) ) return;

    $next = gh_cron_next_run( (string) $job['cron'], time() );
    if ( ! is_int( $next ) ) return;

    wp_schedule_single_event( $next, GH_JOBS_TICK_HOOK, [ $job['id'] ] );
    gh_jobs_update_fields( $job['id'], [ 'next_run_at' => $next ] );
}

/**
 * Cancels any pending tick(s) for a job.
 */
function gh_jobs_unschedule( string $job_id ): void {
    while ( $ts = wp_next_scheduled( GH_JOBS_TICK_HOOK, [ $job_id ] ) ) {
        wp_unschedule_event( $ts, GH_JOBS_TICK_HOOK, [ $job_id ] );
    }
    while ( $ts = wp_next_scheduled( GH_JOBS_CONTINUE_HOOK, [ $job_id ] ) ) {
        wp_unschedule_event( $ts, GH_JOBS_CONTINUE_HOOK, [ $job_id ] );
    }
}

/**
 * Schedules an immediate continuation tick (+1s) for an in-flight run.
 */
function gh_jobs_schedule_continuation( string $job_id ): void {
    wp_schedule_single_event( time() + 1, GH_JOBS_CONTINUE_HOOK, [ $job_id ] );
}

// ── Yield check ────────────────────────────────────────────

/**
 * True if the current tick should yield and return status=continue.
 *
 * Handlers call this between chunks. The deadline is set by the runner
 * before invoking the handler.
 */
function gh_jobs_should_yield(): bool {
    $deadline = (int) ( $GLOBALS['gh_jobs_current_deadline'] ?? 0 );
    return $deadline > 0 && time() >= $deadline;
}

// ── Core execution ─────────────────────────────────────────

/**
 * Runs a single tick for a job. Handles acquire → execute → dispatch.
 *
 * @param string $job_id
 * @param string $trigger 'cron' | 'manual' | 'continuation'
 * @return array|WP_Error
 */
function gh_jobs_run_tick( string $job_id, string $trigger = 'cron' ): array|WP_Error {
    $job = gh_jobs_get( $job_id );
    if ( ! $job ) return new WP_Error( 'jobs_not_found', "Job non trovato: {$job_id}" );

    $kind_def = gh_jobs_get_kind( (string) $job['kind'] );
    if ( ! $kind_def ) {
        $err = "Kind non registrato: {$job['kind']}";
        gh_jobs_record_run( $job, [
            'run_id'     => 'run_' . bin2hex( random_bytes( 6 ) ),
            'status'     => 'error',
            'error'      => $err,
            'trigger'    => $trigger,
            'started_at' => time(),
            'ended_at'   => time(),
            'ticks'      => 0,
        ] );
        gh_jobs_schedule_next( $job );
        return new WP_Error( 'jobs_kind_missing', $err );
    }

    $is_continuation = $trigger === 'continuation';

    // Try to acquire or resume the lock.
    if ( $is_continuation ) {
        $existing = gh_jobs_read_lock( $job_id );
        if ( ! $existing ) {
            // Lock evaporated (likely crashed + TTL expired). Nothing to resume.
            return new WP_Error( 'jobs_no_lock', 'Lock scaduto; continuazione abortita.' );
        }
        $lock = $existing;
    } else {
        $fresh = [
            'run_id'        => 'run_' . bin2hex( random_bytes( 6 ) ),
            'job_id'        => $job_id,
            'started_at_ts' => time(),
            'started_at'    => wp_date( 'c' ),
            'cursor'        => null,
            'ticks'         => 0,
            'trigger'       => $trigger,
        ];
        $result = gh_jobs_acquire_lock( $job, $fresh );

        if ( ! $result['acquired'] ) {
            // Another run is in flight — skip this tick, reschedule the next cron occurrence.
            gh_jobs_log_append( [
                'run_id'      => $fresh['run_id'],
                'job_id'      => $job_id,
                'job_label'   => (string) $job['label'],
                'kind'        => (string) $job['kind'],
                'status'      => 'skipped',
                'started_at'  => wp_date( 'c' ),
                'ended_at'    => wp_date( 'c' ),
                'duration_ms' => 0,
                'summary'     => null,
                'progress'    => null,
                'error'       => 'Tick saltato: un\'altra esecuzione è in corso.',
                'trigger'     => $trigger,
                'ticks'       => 0,
            ] );
            gh_jobs_schedule_next( $job );
            return [ 'status' => 'skipped' ];
        }

        $lock = $result['lock'];
    }

    // Build context and set the tick deadline for gh_jobs_should_yield().
    $tick_budget = (int) ( $job['tick_budget'] ?? GH_JOBS_DEFAULT_TICK_BUDGET );
    $started_ms  = microtime( true );
    $deadline    = time() + $tick_budget;

    $GLOBALS['gh_jobs_current_deadline'] = $deadline;

    $context = [
        'run_id'     => $lock['run_id'],
        'job_id'     => $job_id,
        'cursor'     => $lock['cursor'] ?? null,
        'started_at' => (int) $lock['started_at_ts'],
        'deadline'   => $deadline,
        'trigger'    => $trigger,
    ];

    $handler = $kind_def['handler'];
    $result  = null;
    $thrown  = null;

    try {
        $result = $handler( $job, $context );
    } catch ( \Throwable $e ) {
        $thrown = $e;
    }

    $GLOBALS['gh_jobs_current_deadline'] = 0;

    $duration_ms = (int) round( ( microtime( true ) - $started_ms ) * 1000 );
    $tick_count  = (int) ( $lock['ticks'] ?? 0 ) + 1;

    // Normalize result
    if ( $thrown ) {
        $result = [
            'status' => 'error',
            'error'  => 'Exception: ' . $thrown->getMessage(),
        ];
    } elseif ( ! is_array( $result ) || ! isset( $result['status'] ) ) {
        $result = [
            'status' => 'error',
            'error'  => 'Handler ha restituito un valore non valido.',
        ];
    }

    $status = (string) $result['status'];

    // Dispatch
    switch ( $status ) {
        case 'continue':
            // Persist cursor in lock, schedule continuation, keep lock held.
            $lock['cursor'] = $result['cursor'] ?? null;
            $lock['ticks']  = $tick_count;
            gh_jobs_update_lock( $job_id, $lock, (int) ( $job['max_runtime'] ?? GH_JOBS_DEFAULT_MAX_RUNTIME ) );
            gh_jobs_schedule_continuation( $job_id );

            gh_jobs_update_fields( $job_id, [
                'last_status' => 'continue',
                'last_run_at' => wp_date( 'c' ),
            ] );
            return $result;

        case 'done':
            gh_jobs_release_lock( $job_id );
            gh_jobs_record_run( $job, [
                'run_id'      => $lock['run_id'],
                'status'      => 'done',
                'summary'     => $result['summary'] ?? null,
                'trigger'     => $lock['trigger'] ?? $trigger,
                'started_at'  => (int) $lock['started_at_ts'],
                'ended_at'    => time(),
                'duration_ms' => $duration_ms,
                'ticks'       => $tick_count,
            ] );
            gh_jobs_schedule_next( gh_jobs_get( $job_id ) ?? $job );
            return $result;

        case 'error':
        default:
            gh_jobs_release_lock( $job_id );
            gh_jobs_record_run( $job, [
                'run_id'      => $lock['run_id'],
                'status'      => 'error',
                'error'       => (string) ( $result['error'] ?? 'Errore sconosciuto.' ),
                'trigger'     => $lock['trigger'] ?? $trigger,
                'started_at'  => (int) $lock['started_at_ts'],
                'ended_at'    => time(),
                'duration_ms' => $duration_ms,
                'ticks'       => $tick_count,
            ] );
            gh_jobs_schedule_next( gh_jobs_get( $job_id ) ?? $job );
            return $result;
    }
}

/**
 * Records a run in the log + updates the job's last_* fields.
 *
 * @param array $job
 * @param array $data Partial run envelope — gh_jobs_run_tick fills the rest.
 */
function gh_jobs_record_run( array $job, array $data ): void {
    $started_ts = (int) ( $data['started_at'] ?? time() );
    $ended_ts   = (int) ( $data['ended_at']   ?? time() );

    $entry = [
        'run_id'      => (string) ( $data['run_id'] ?? ( 'run_' . bin2hex( random_bytes( 6 ) ) ) ),
        'job_id'      => (string) $job['id'],
        'job_label'   => (string) $job['label'],
        'kind'        => (string) $job['kind'],
        'status'      => (string) $data['status'],
        'started_at'  => wp_date( 'c', $started_ts ),
        'ended_at'    => wp_date( 'c', $ended_ts ),
        'duration_ms' => (int) ( $data['duration_ms'] ?? max( 0, ( $ended_ts - $started_ts ) * 1000 ) ),
        'summary'     => $data['summary'] ?? null,
        'progress'    => $data['progress'] ?? null,
        'error'       => $data['error'] ?? null,
        'trigger'     => (string) ( $data['trigger'] ?? 'cron' ),
        'ticks'       => (int) ( $data['ticks'] ?? 1 ),
    ];

    gh_jobs_log_append( $entry );

    gh_jobs_update_fields( $job['id'], [
        'last_run_at'  => $entry['ended_at'],
        'last_status'  => $entry['status'],
        'last_summary' => $entry['summary'],
        'run_count'    => (int) ( $job['run_count'] ?? 0 ) + 1,
    ] );
}

// ── Cron hook callbacks ────────────────────────────────────

add_action( GH_JOBS_TICK_HOOK, function ( string $job_id ) {
    gh_jobs_run_tick( $job_id, 'cron' );
}, 10, 1 );

add_action( GH_JOBS_CONTINUE_HOOK, function ( string $job_id ) {
    gh_jobs_run_tick( $job_id, 'continuation' );
}, 10, 1 );
