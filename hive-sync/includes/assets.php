<?php
/**
 * Enqueue Hive Sync admin CSS/JS only on the plugin's own page.
 * Localizes the AJAX URL + a fresh nonce + the registered sources
 * payload so the JS bootstraps without an extra round-trip.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_hive-sync' ) return;

    // Cache-bust assets on every file change. HSYNC_VERSION is
    // pinned to plugin releases, so without filemtime() a CSS or JS
    // edit ships with the same `?ver=1.0.0` query and browsers keep
    // serving the stale file (the user spent a session debugging
    // a "fixed but not fixed" CSS regression for exactly this).
    $cssPath = HSYNC_PATH . 'assets/css/admin.css';
    $jsPath  = HSYNC_PATH . 'assets/js/admin.js';
    $cssVer  = file_exists( $cssPath ) ? HSYNC_VERSION . '.' . filemtime( $cssPath ) : HSYNC_VERSION;
    $jsVer   = file_exists( $jsPath )  ? HSYNC_VERSION . '.' . filemtime( $jsPath )  : HSYNC_VERSION;

    // Dashicons is bundled with WordPress admin but isn't loaded
    // automatically on every screen — enqueue it explicitly so our
    // tab + cockpit icons render without falling back to text.
    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style(
        'hive-sync-admin',
        HSYNC_URL . 'assets/css/admin.css',
        [ 'dashicons' ],
        $cssVer,
    );
    wp_enqueue_script(
        'hive-sync-admin',
        HSYNC_URL . 'assets/js/admin.js',
        [],
        $jsVer,
        true,
    );

    wp_localize_script( 'hive-sync-admin', 'HSyncBoot', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'hsync_nonce' ),
        'version' => HSYNC_VERSION,
    ] );
} );
