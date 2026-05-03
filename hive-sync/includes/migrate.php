<?php
/**
 * Hive Sync — schema migrations.
 *
 * Idempotent dbDelta-based creation of the six tables that own this plugin's
 * state. Phase 1: structure only, no logic reads/writes through these yet.
 *
 *   hsync_mappings        — saved external→Woo field maps (GS, CSV, custom)
 *   hsync_pipelines       — source + map + ops + checks compositions
 *   hsync_rules           — scoped operation bundles (selection + ops + checks)
 *   hsync_jobs            — scheduled or ad-hoc Runnable references + cron
 *   hsync_runs            — execution records (per Runnable invocation)
 *   hsync_checks          — saved Check definitions (pre/post import)
 *   hsync_source_configs  — saved per-source config bundles (URL, token, …)
 */

defined( 'ABSPATH' ) || exit;

function hsync_table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'hsync_' . $name;
}

function hsync_migrate_schema(): void {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();

    $statements = [];

    $statements[] = "CREATE TABLE " . hsync_table( 'mappings' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(100) NOT NULL,
        name VARCHAR(190) NOT NULL,
        source_kind VARCHAR(50) NOT NULL,
        config LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY source_kind (source_kind)
    ) $charset;";

    $statements[] = "CREATE TABLE " . hsync_table( 'pipelines' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(100) NOT NULL,
        name VARCHAR(190) NOT NULL,
        definition LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug)
    ) $charset;";

    $statements[] = "CREATE TABLE " . hsync_table( 'rules' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(100) NOT NULL,
        name VARCHAR(190) NOT NULL,
        selection LONGTEXT NOT NULL,
        operations LONGTEXT NOT NULL,
        checks LONGTEXT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY enabled (enabled)
    ) $charset;";

    $statements[] = "CREATE TABLE " . hsync_table( 'jobs' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        runnable_type VARCHAR(50) NOT NULL,
        runnable_ref VARCHAR(190) NOT NULL,
        cron_expr VARCHAR(100) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        next_run_at DATETIME NULL,
        last_run_at DATETIME NULL,
        last_run_status VARCHAR(20) NULL,
        config LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY runnable (runnable_type, runnable_ref),
        KEY next_run_at (next_run_at),
        KEY enabled (enabled)
    ) $charset;";

    $statements[] = "CREATE TABLE " . hsync_table( 'runs' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id BIGINT UNSIGNED NULL,
        runnable_type VARCHAR(50) NOT NULL,
        runnable_ref VARCHAR(190) NOT NULL,
        status VARCHAR(20) NOT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        items_total INT UNSIGNED NULL,
        items_done INT UNSIGNED NULL,
        items_failed INT UNSIGNED NULL,
        report LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY job_id (job_id),
        KEY status (status),
        KEY started_at (started_at)
    ) $charset;";

    $statements[] = "CREATE TABLE " . hsync_table( 'checks' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(100) NOT NULL,
        name VARCHAR(190) NOT NULL,
        phase VARCHAR(20) NOT NULL,
        config LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY phase (phase)
    ) $charset;";

    $statements[] = "CREATE TABLE " . hsync_table( 'source_configs' ) . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(100) NOT NULL,
        name VARCHAR(190) NOT NULL,
        source_kind VARCHAR(50) NOT NULL,
        config LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY source_kind (source_kind)
    ) $charset;";

    foreach ( $statements as $sql ) {
        dbDelta( $sql );
    }
}

/**
 * One-time migration: rename source_kind = 'goldensneakers' →
 * 'json' (with config.flavor = 'goldensneakers') everywhere it
 * appears, so existing source-configs / mappings / job runnable_refs
 * keep working after the GoldenSneakersSource → JsonSource rename.
 *
 * Idempotent — runs once per install via the option flag below;
 * subsequent activations no-op.
 */
function hsync_migrate_gs_to_json(): void {
    if ( get_option( 'hsync_migrated_gs_to_json' ) === 'done' ) return;
    global $wpdb;
    if ( ! isset( $wpdb ) ) return;

    // 1. Source configs: kind 'goldensneakers' → 'json' + flavor flag
    //    inside the JSON config blob.
    $cfgTable = hsync_table( 'source_configs' );
    $rows = $wpdb->get_results(
        $wpdb->prepare( "SELECT id, config FROM `$cfgTable` WHERE source_kind = %s", 'goldensneakers' ),
        ARRAY_A,
    );
    foreach ( $rows ?: [] as $row ) {
        $cfg = json_decode( (string) $row['config'], true );
        if ( ! is_array( $cfg ) ) $cfg = [];
        $cfg['flavor'] = 'goldensneakers';
        $wpdb->update(
            $cfgTable,
            [
                'source_kind' => 'json',
                'config'      => wp_json_encode( $cfg ),
                'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ 'id' => (int) $row['id'] ],
        );
    }

    // 2. Mappings: same kind rename.
    $mapTable = hsync_table( 'mappings' );
    $wpdb->update(
        $mapTable,
        [ 'source_kind' => 'json', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
        [ 'source_kind' => 'goldensneakers' ],
    );

    // 3. Jobs: runnable_ref 'goldensneakers/<slug>' → 'json/<slug>'.
    $jobTable = hsync_table( 'jobs' );
    $jobRows = $wpdb->get_results(
        "SELECT id, runnable_ref FROM `$jobTable` WHERE runnable_type = 'source.import' AND runnable_ref LIKE 'goldensneakers/%'",
        ARRAY_A,
    );
    foreach ( $jobRows ?: [] as $row ) {
        $newRef = 'json/' . substr( (string) $row['runnable_ref'], strlen( 'goldensneakers/' ) );
        $wpdb->update(
            $jobTable,
            [ 'runnable_ref' => $newRef, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => (int) $row['id'] ],
        );
    }

    update_option( 'hsync_migrated_gs_to_json', 'done', false );
}
