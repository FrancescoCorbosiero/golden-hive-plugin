<?php
declare(strict_types=1);

namespace HiveSync\Core;

/**
 * Single boot point for the namespaced Hive Sync core. Concrete sources,
 * operations, checks, and pipelines self-register by hooking the
 * 'hive_sync/core_booted' action — same decoupling pattern as Golden Hive's
 * 'gh_core_booted'.
 *
 * Phase 1 ships only the empty registries. Implementations land in phase 2.
 */
final class Bootstrap {

    public static ?Registry $sources = null;
    public static ?Registry $operations = null;
    public static ?Registry $checks = null;

    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) return;
        self::$booted = true;

        self::$sources    = new Registry( 'source' );
        self::$operations = new Registry( 'operation' );
        self::$checks     = new Registry( 'check' );

        do_action( 'hive_sync/core_booted' );
    }
}
