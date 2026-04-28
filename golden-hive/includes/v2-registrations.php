<?php
/**
 * v2 Source / Operation / Check self-registration.
 *
 * Concrete sources/operations/checks subscribe to the 'gh_core_booted'
 * action (fired once by GH\Core\Bootstrap::boot()) and add themselves
 * to the registries. Same decoupling pattern as the legacy
 * 'gh_jobs_register' hook.
 *
 * As more sources/operations/checks are ported in subsequent batches,
 * register them here. Single hook = single place to inspect what's wired.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'gh_core_booted', function () {
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) ) {
        return;
    }

    // Sources
    \GH\Core\Bootstrap::$sources->register( new \GH\Sources\WooStoreSource() );
    \GH\Core\Bootstrap::$sources->register( new \GH\Sources\GoldenSneakersSource() );

    // Operations
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Status\SetStatus() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Pricing\MarkupPercent() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Pricing\SetSalePercent() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Pricing\AdjustPrice() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Taxonomy\AssignBrand() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Taxonomy\AssignCategory() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Stock\SetStockStatus() );
    \GH\Core\Bootstrap::$operations->register( new \GH\Operations\Stock\SetStockQuantity() );

    // Checks — none registered yet (Batch 7+)
} );
