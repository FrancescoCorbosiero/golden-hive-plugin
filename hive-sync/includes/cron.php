<?php
/**
 * Hive Sync — WP-Cron heartbeat.
 *
 * One recurring event, 'hive_sync_jobs_tick', every MINUTE. Each firing
 * calls JobRunner::tick(), which:
 *
 *   - returns immediately when nothing is due (the common case — two
 *     cheap reads, no writes);
 *   - otherwise takes the runner lease and DRAINS: keeps giving due jobs
 *     25s slices back to back until nothing is due or the drain budget
 *     (hsync_cron_drain_budget(), default 240s) is spent. The next
 *     heartbeat picks up the rest.
 *
 * It used to be one 25s slice per job every 5 minutes — ~8% duty cycle.
 * A GS sync with 3 minutes of real work took an hour, and because a job
 * doesn't start a new run while one is going, an every-30-minutes
 * schedule showed runs every 60-90 minutes. The minute heartbeat also means a job starts
 * within a minute of its slot instead of up to five.
 *
 * Activation (re)schedules; deactivation clears. Idempotent.
 */

defined( 'ABSPATH' ) || exit;

const HSYNC_CRON_HOOK     = 'hive_sync_jobs_tick';
const HSYNC_CRON_SCHEDULE = 'hsync_1min';

add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules[ HSYNC_CRON_SCHEDULE ] ) ) {
        $schedules[ HSYNC_CRON_SCHEDULE ] = [
            'interval' => MINUTE_IN_SECONDS,
            'display'  => 'Hive Sync — every minute',
        ];
    }
    return $schedules;
} );

add_action( 'init', function () {
    // Installs upgraded from the 5-minute heartbeat carry an event on the
    // old 'hsync_5min' schedule: replace it, don't stack a second one.
    $current = wp_get_schedule( HSYNC_CRON_HOOK );
    if ( $current !== false && $current !== HSYNC_CRON_SCHEDULE ) {
        wp_clear_scheduled_hook( HSYNC_CRON_HOOK );
        $current = false;
    }
    if ( $current === false ) {
        wp_schedule_event( time() + 60, HSYNC_CRON_SCHEDULE, HSYNC_CRON_HOOK );
    }
} );

// WP-Cron passes the event's args (none here); a wrapper keeps them away
// from hsync_run_tick()'s typed $budget parameter.
add_action( HSYNC_CRON_HOOK, static function (): void {
    hsync_run_tick();
} );

/**
 * Seconds one cron request may spend draining jobs.
 *
 * Default 240s — overridable with the HSYNC_CRON_DRAIN_BUDGET constant or
 * the 'hive_sync/cron/drain_budget' filter. Always capped at 80% of the
 * PHP time limit in force (hsync_raise_limits() lifts it to 300s where the
 * host allows set_time_limit), so a host that pins max_execution_time
 * gets a shorter drain rather than a fatal mid-slice.
 */
function hsync_cron_drain_budget(): float {
    $budget = defined( 'HSYNC_CRON_DRAIN_BUDGET' ) ? (float) HSYNC_CRON_DRAIN_BUDGET : 240.0;
    $budget = (float) apply_filters( 'hive_sync/cron/drain_budget', $budget );
    $limit  = (int) ini_get( 'max_execution_time' );
    if ( $limit > 0 ) {
        $budget = min( $budget, $limit * 0.8 );
    }
    return max( (float) \HiveSync\Workflow\Schedule\JobRunner::MIN_SLICE_SECONDS, $budget );
}

function hsync_job_runner(): \HiveSync\Workflow\Schedule\JobRunner {
    return new \HiveSync\Workflow\Schedule\JobRunner(
        new \HiveSync\Core\Repo\JobRepository(),
        new \HiveSync\Core\Repo\RunRepository(),
        new \HiveSync\Core\Repo\RuleRepository(),
        new \HiveSync\Core\Repo\SourceConfigRepository(),
        new \HiveSync\Core\Repo\MappingRepository(),
    );
}

/**
 * One heartbeat. $budget = null → the cron drain budget; the AJAX
 * "Esegui ora" passes a short one so the request returns promptly.
 */
function hsync_run_tick( ?float $budget = null ): array {
    // Cron tick gets the same headroom the AJAX path has. Without
    // this, a scheduled import paying tick 1's one-shot fetch+
    // transform+diff cost against a multi-thousand-row feed hits
    // the default PHP max_execution_time (30s) or memory_limit
    // (~256MB on most hosts) and dies with an uncatchable fatal —
    // the cron's wp_hsync_runs row stays at status='running' and
    // the cursor is never persisted, so the next tick starts
    // from index 0 and dies the same way. The AJAX path wires this
    // via hsync_ajax_run_now; mirror it here so the scheduled path
    // matches. Must run BEFORE the budget is read: the budget is
    // capped by the time limit in force.
    if ( function_exists( 'hsync_raise_limits' ) ) {
        hsync_raise_limits();
    }

    $result = hsync_job_runner()->tick( time(), $budget ?? hsync_cron_drain_budget() );

    // Daily upkeep, gated by a transient so the minute heartbeat doesn't
    // hammer the DB with DELETEs:
    //  - drop finished runs older than `hsync_runs_retention_days`
    //    (default 30) and cap the table at `hsync_runs_keep_max`
    //    (default 5000);
    //  - close runs idle for a day that nothing will advance again
    //    (an Importa run whose tab was closed) — the runs of jobs,
    //    paused ones included, are excluded.
    if ( ! get_transient( 'hsync_runs_pruned_today' ) ) {
        $runs = new \HiveSync\Core\Repo\RunRepository();
        $days = (int) get_option( 'hsync_runs_retention_days', 30 );
        $cap  = (int) get_option( 'hsync_runs_keep_max', 5000 );
        if ( $days > 0 ) $runs->purgeOlderThan( $days );
        if ( $cap  > 0 ) $runs->trimToRecent( $cap );

        $active = [];
        foreach ( ( new \HiveSync\Core\Repo\JobRepository() )->all() as $job ) {
            $id = \HiveSync\Workflow\Schedule\RunState::runId( $job['run_state'] ?? null );
            if ( $id > 0 ) $active[] = $id;
        }
        $runs->markAbandoned( 24, $active );

        set_transient( 'hsync_runs_pruned_today', 1, DAY_IN_SECONDS );
    }

    return $result;
}

register_deactivation_hook( HSYNC_FILE, function () {
    // Clear ALL schedules for our hook (not just the next one — there
    // can be multiple instances if a previous deactivation failed mid-
    // way or the install was migrated from a different cron interval).
    wp_clear_scheduled_hook( HSYNC_CRON_HOOK );
    // Drop transient state so a re-activation starts clean. Data
    // tables and saved configs survive — that's deletion's job, not
    // deactivation's. A run in flight keeps its run_state and resumes
    // after re-activation.
    \HiveSync\Workflow\Schedule\RunnerLease::forSite()->forceRelease();
    delete_transient( 'hsync_jobs_tick_lock' ); // pre-1.2 lock
    delete_transient( 'hsync_media_usage_index_v1' );
} );
