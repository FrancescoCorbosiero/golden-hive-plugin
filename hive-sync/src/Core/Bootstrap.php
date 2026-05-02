<?php
declare(strict_types=1);

namespace HiveSync\Core;

use HiveSync\Core\Check\CheckRegistry;
use HiveSync\Core\Check\ImportCheckRegistry;
use HiveSync\Core\Operation\OperationRegistry;
use HiveSync\Core\Source\SourceRegistry;

/**
 * Single boot point for the namespaced Hive Sync core. Concrete sources,
 * operations, and checks self-register by hooking the
 * 'hive_sync/core_booted' action — same decoupling pattern as Golden
 * Hive's 'gh_core_booted'.
 *
 * Phase 2 ships the empty registries + dedicated table-backed repositories.
 * Concrete implementations land in phase 3.
 */
final class Bootstrap
{
    public static ?SourceRegistry $sources = null;
    public static ?OperationRegistry $operations = null;
    public static ?CheckRegistry $checks = null;
    public static ?ImportCheckRegistry $importChecks = null;

    private static bool $booted = false;

    public static function boot(): void
    {
        if ( self::$booted ) return;
        self::$booted = true;

        self::$sources      = new SourceRegistry();
        self::$operations   = new OperationRegistry();
        self::$checks       = new CheckRegistry();
        self::$importChecks = new ImportCheckRegistry();

        do_action( 'hive_sync/core_booted' );
    }
}
