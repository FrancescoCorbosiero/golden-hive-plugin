<?php
/**
 * Enqueue Hive Sync admin CSS/JS only on the plugin's own page.
 * Localizes the AJAX URL + a fresh nonce + the registered sources
 * payload so the JS bootstraps without an extra round-trip.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_hive-sync' ) return;

    wp_enqueue_style(
        'hive-sync-admin',
        HSYNC_URL . 'assets/css/admin.css',
        [],
        HSYNC_VERSION,
    );
    wp_enqueue_script(
        'hive-sync-admin',
        HSYNC_URL . 'assets/js/admin.js',
        [],
        HSYNC_VERSION,
        true,
    );

    wp_localize_script( 'hive-sync-admin', 'HSyncBoot', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'hsync_nonce' ),
        'version' => HSYNC_VERSION,
    ] );
} );
