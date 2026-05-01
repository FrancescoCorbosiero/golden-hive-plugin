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
