<?php
/**
 * Hive Sync — uninstall handler.
 *
 * Fired by WordPress when the operator deletes the plugin from the
 * Plugins screen (NOT on simple deactivate). Owns every byte the
 * plugin ever wrote so deletion leaves zero trace:
 *
 *   - 8 wp_hsync_* tables (DROP)
 *   - 4 wp_options rows (delete)
 *   - 2 transients (delete)
 *   - 1 cron event hook (clear all schedules)
 *
 * Safe to run multiple times — every operation is idempotent.
 *
 * Note: deletion is intentional and total. The operator who hits
 * "Elimina" on the WP plugins screen has chosen to walk away. If
 * they want to deactivate without losing data, that's the
 * Deactivate button (handled by register_deactivation_hook in
 * includes/cron.php — which only unschedules cron events).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Drop tables.
$tables = [
    'mappings',
    'pipelines',
    'rules',
    'jobs',
    'runs',
    'checks',
    'source_configs',
];
foreach ( $tables as $name ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}hsync_{$name}`" );
}

// 2. Delete plugin options.
$options = [
    'hsync_db_version',
    'hsync_migrated_gs_to_json',
    'hsync_media_whitelist',
    'hsync_media_deletion_log',
];
foreach ( $options as $opt ) {
    delete_option( $opt );
    delete_site_option( $opt );  // multisite safety
}

// 3. Delete transients (caches + cron lock).
$transients = [
    'hsync_media_usage_index_v1',
    'hsync_jobs_tick_lock',
];
foreach ( $transients as $key ) {
    delete_transient( $key );
    delete_site_transient( $key );
}

// 4. Clear the cron hook entirely (handles edge cases where
//    deactivation didn't fire — e.g. WP-CLI uninstall on a
//    deactivated plugin).
wp_clear_scheduled_hook( 'hsync_cron_tick' );
