<?php
/**
 * v2 Workflow UI — AJAX bridge.
 *
 * Read-only enumeration endpoints that surface the in-process registries
 * (sources / operations / checks) as JSON for the new Workflow tab.
 *
 * Mutating endpoints (create pipeline, run pipeline as job, save pipeline
 * recipe) land in Batch 5b/c/d. Until then this file stays small and
 * defensive.
 *
 * Contract: nonce 'gh_nonce' + capability 'manage_woocommerce' via
 * gh_ajax_guard(). All actions registered without the _nopriv variant —
 * authenticated users only. Same security envelope as every other
 * AJAX in golden-hive.
 */

defined( 'ABSPATH' ) || exit;

/**
 * GET → list of registered Sources. Drives the Workflow tab's source picker
 * and the configSchema-driven config form.
 */
add_action( 'wp_ajax_gh_v2_sources_list', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }
    wp_send_json_success( [ 'sources' => \GH\Core\Bootstrap::sourcesAsArray() ] );
} );

/**
 * GET → list of registered Operations (with paramsSchema for the pipeline
 * builder's per-step editor in Batch 5c).
 */
add_action( 'wp_ajax_gh_v2_operations_list', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }
    wp_send_json_success( [ 'operations' => \GH\Core\Bootstrap::operationsAsArray() ] );
} );

/**
 * GET → list of registered Checks. Empty until the first Check ships
 * (Batch 6).
 */
add_action( 'wp_ajax_gh_v2_checks_list', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    if ( ! class_exists( '\\GH\\Core\\Bootstrap' ) || ! \GH\Core\Bootstrap::isBooted() ) {
        wp_send_json_error( [ 'message' => 'v2 core not booted' ], 500 );
    }
    wp_send_json_success( [ 'checks' => \GH\Core\Bootstrap::checksAsArray() ] );
} );
