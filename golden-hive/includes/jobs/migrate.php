<?php
/**
 * Jobs Migration — one-shot migration from legacy feed scheduler.
 *
 * The old scheduler stored tasks in wp_options `gh_scheduled_imports` and
 * used named WP-Cron intervals (hourly, twicedaily, daily) via the hook
 * `gh_sched_cron_run`. This migration:
 *
 *  1. Reads legacy tasks and converts each into a gh_jobs record.
 *  2. Translates named intervals into equivalent cron expressions.
 *  3. Unschedules the old hook occurrences.
 *  4. Marks migration done via an option flag so it runs at most once.
 *
 * The legacy wp_options entries are NOT deleted — kept as a safety net.
 * Clean them up manually after verifying the new system works.
 */

defined( 'ABSPATH' ) || exit;

const GH_JOBS_MIGRATE_FLAG = 'gh_jobs_migrated_from_sched';

/**
 * Runs the migration if it hasn't been run yet.
 *
 * Hooked on admin_init so it executes once per install without blocking
 * front-end requests.
 */
function gh_jobs_maybe_migrate_legacy_scheduler(): void {
    if ( get_option( GH_JOBS_MIGRATE_FLAG ) ) return;

    $legacy = get_option( 'gh_scheduled_imports', [] );
    if ( ! is_array( $legacy ) || empty( $legacy ) ) {
        update_option( GH_JOBS_MIGRATE_FLAG, 1, false );
        return;
    }

    $interval_map = [
        'hourly'     => '0 * * * *',
        'twicedaily' => '0 */12 * * *',
        'daily'      => '0 0 * * *',
        'weekly'     => '0 0 * * 0',
    ];

    $migrated = 0;

    foreach ( $legacy as $task ) {
        $kind = match ( $task['feed_type'] ?? '' ) {
            'config'   => 'config_feed',
            'csv_feed' => 'csv_feed',
            default    => null,
        };
        if ( $kind === null ) continue;

        $schedule = $task['schedule'] ?? 'hourly';
        $cron     = $interval_map[ $schedule ] ?? '0 * * * *';

        $params = [];
        if ( $kind === 'csv_feed' ) {
            $params = [
                'feed_id'         => (string) ( $task['csv_feed_id'] ?? '' ),
                'create_new'      => (bool) ( $task['options']['create_new']      ?? true ),
                'update_existing' => (bool) ( $task['options']['update_existing'] ?? true ),
                'sideload_images' => (bool) ( $task['options']['sideload_images'] ?? false ),
            ];
        } elseif ( $kind === 'config_feed' ) {
            $params = [
                'config_id'       => (string) ( $task['config_id']   ?? '' ),
                'source_type'     => (string) ( $task['source_type'] ?? 'url' ),
                'source_url'      => (string) ( $task['source_url']  ?? '' ),
                'source_path'     => (string) ( $task['source_path'] ?? '' ),
                'create_new'      => (bool) ( $task['options']['create_new']      ?? true ),
                'update_existing' => (bool) ( $task['options']['update_existing'] ?? true ),
                'sideload_images' => (bool) ( $task['options']['sideload_images'] ?? false ),
            ];
        }

        $saved = gh_jobs_save( [
            'label'   => (string) ( $task['name'] ?? 'Legacy task' ),
            'kind'    => $kind,
            'cron'    => $cron,
            'enabled' => ( $task['status'] ?? 'active' ) === 'active',
            'params'  => $params,
        ] );

        if ( ! is_wp_error( $saved ) ) {
            $migrated++;
        }
    }

    // Unschedule the legacy cron hook occurrences.
    if ( defined( 'GH_SCHED_CRON_HOOK' ) ) {
        $hook = GH_SCHED_CRON_HOOK;
        while ( $ts = wp_next_scheduled( $hook ) ) {
            wp_unschedule_event( $ts, $hook );
        }
        // Also clear per-task single events left over.
        foreach ( $legacy as $task ) {
            $id = $task['id'] ?? '';
            if ( $id ) {
                while ( $ts = wp_next_scheduled( $hook, [ $id ] ) ) {
                    wp_unschedule_event( $ts, $hook, [ $id ] );
                }
            }
        }
    }

    update_option( GH_JOBS_MIGRATE_FLAG, [
        'migrated_at' => wp_date( 'c' ),
        'count'       => $migrated,
    ], false );
}

add_action( 'admin_init', 'gh_jobs_maybe_migrate_legacy_scheduler' );
