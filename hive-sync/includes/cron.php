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
    $runner = new \HiveSync\Workflow\Schedule\JobRunner(
        new \HiveSync\Core\Repo\JobRepository(),
        new \HiveSync\Core\Repo\RunRepository(),
        new \HiveSync\Core\Repo\RuleRepository(),
        new \HiveSync\Core\Repo\SourceConfigRepository(),
    );
    return $runner->tick( time() );
}

register_deactivation_hook( HSYNC_FILE, function () {
    $next = wp_next_scheduled( HSYNC_CRON_HOOK );
    if ( $next ) wp_unschedule_event( $next, HSYNC_CRON_HOOK );
} );
