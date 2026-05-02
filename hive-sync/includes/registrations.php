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

    // Operations (post-import)
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Status\SetStatus() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Pricing\AdjustPrice() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Stock\SetStockStatus() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Stock\SetStockQuantity() );

    // Operations (import-rules — mutate the FeedItem draft during import)
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Media\DownloadMedia() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Taxonomy\AutoCategorize() );
    \HiveSync\Core\Bootstrap::$operations->register( new \HiveSync\Operations\Taxonomy\ResolveTaxonomy() );

    // Checks (post-import — productId-scoped)
    \HiveSync\Core\Bootstrap::$checks->register( new \HiveSync\Checks\Media\HasImages() );
    \HiveSync\Core\Bootstrap::$checks->register( new \HiveSync\Checks\Taxonomy\HasCategory() );

    // Import checks (pre-import — FeedItem-scoped)
    \HiveSync\Core\Bootstrap::$importChecks->register( new \HiveSync\Checks\Import\HasRequiredFields() );
    \HiveSync\Core\Bootstrap::$importChecks->register( new \HiveSync\Checks\Import\HasMediaUrl() );

    // Media usage index — register cache invalidation hooks once on boot.
    if ( class_exists( '\\HiveSync\\Media\\UsageIndex' ) ) {
        \HiveSync\Media\UsageIndex::registerInvalidationHooks();
    }
} );
