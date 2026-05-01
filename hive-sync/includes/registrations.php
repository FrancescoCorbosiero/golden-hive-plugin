<?php
/**
 * Concrete sources, operations, and checks self-register here on the
 * 'hive_sync/core_booted' action — same decoupling pattern as Golden
 * Hive's 'gh_core_booted' hook.
 *
 * As more concrete classes are added (phase 3+), register them here.
 * Single hook = single place to inspect what's wired.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'hive_sync/core_booted', function () {
    if ( ! class_exists( '\\HiveSync\\Core\\Bootstrap' ) ) return;

    // Sources
    \HiveSync\Core\Bootstrap::$sources->register( new \HiveSync\Sources\GoldenSneakersSource() );
    \HiveSync\Core\Bootstrap::$sources->register( new \HiveSync\Sources\CsvSource() );

    // Operations
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Status\SetStatus() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Pricing\AdjustPrice() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Stock\SetStockStatus() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Stock\SetStockQuantity() );

    // Checks
    \HiveSync\Core\Bootstrap::$checks->register( new \HiveSync\Checks\Media\HasImages() );
    \HiveSync\Core\Bootstrap::$checks->register( new \HiveSync\Checks\Taxonomy\HasCategory() );
} );
