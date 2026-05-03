<?php
/**
 * Hive Sync — WP-Cron tick wiring.
 *
 * Registers a 'hive_sync_jobs_tick' event firing every 5 minutes (the
 * smallest standard WP-Cron interval). The handler delegates to
 * HiveSync\Workflow\Schedule\JobRunner::tick(); it never blocks the
 * request because each individual job carries its own deadline (25s).
 *
 * Activation re-schedules; deactivation clears. Idempotent: re-firing
 * activate is safe.
 */

defined( 'ABSPATH' ) || exit;

const HSYNC_CRON_HOOK = 'hive_sync_jobs_tick';

add_action( 'init', function () {
    if ( ! wp_next_scheduled( HSYNC_CRON_HOOK ) ) {
        wp_schedule_event( time() + 60, 'hsync_5min', HSYNC_CRON_HOOK );
    }
} );

add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['hsync_5min'] ) ) {
        $schedules['hsync_5min'] = [
            'interval' => 5 * 60,
            'display'  => 'Hive Sync — every 5 minutes',
        ];
    }
    return $schedules;
} );

add_action( HSYNC_CRON_HOOK, 'hsync_run_tick' );

function hsync_run_tick(): array {
    $runs = new \HiveSync\Core\Repo\RunRepository();
    $runner = new \HiveSync\Workflow\Schedule\JobRunner(
        new \HiveSync\Core\Repo\JobRepository(),
        $runs,
        new \HiveSync\Core\Repo\RuleRepository(),
        new \HiveSync\Core\Repo\SourceConfigRepository(),
        new \HiveSync\Core\Repo\MappingRepository(),
    );
    $result = $runner->tick( time() );

    // Periodic auto-prune of the runs table — only fires once a day
    // (gated by a transient so high-frequency tick scheduling doesn't
    // hammer the DB with DELETE queries). Drops finished runs older
    // than `hsync_runs_retention_days` (default 30) AND caps total
    // rows at `hsync_runs_keep_max` (default 5000) as a safety net.
    if ( ! get_transient( 'hsync_runs_pruned_today' ) ) {
        $days = (int) get_option( 'hsync_runs_retention_days', 30 );
        $cap  = (int) get_option( 'hsync_runs_keep_max', 5000 );
        if ( $days > 0 ) $runs->purgeOlderThan( $days );
        if ( $cap  > 0 ) $runs->trimToRecent( $cap );
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
    // deactivation's.
    delete_transient( 'hsync_jobs_tick_lock' );
    delete_transient( 'hsync_media_usage_index_v1' );
} );
