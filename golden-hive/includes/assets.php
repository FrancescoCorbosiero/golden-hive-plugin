<?php
/**
 * Admin asset loading — enqueues the CSS/JS that used to be inlined into
 * the page by admin-page.php.
 *
 * Why this exists: the admin page shipped ~726 KB on EVERY load (505 KB of
 * JS + 80 KB of CSS, printed inline), none of it cacheable. As enqueued
 * files the browser stores them once and revalidates by version, so a
 * repeat load costs a handful of 304s instead of re-parsing half a
 * megabyte of JavaScript.
 *
 * The files under assets/ are GENERATED from includes/views/*.php. Edit the
 * view file and re-extract; don't hand-edit assets/.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Screen id of the Hive Commerce admin page (add_menu_page slug
 * 'hive-commerce' → 'toplevel_page_hive-commerce').
 */
const GH_ADMIN_SCREEN = 'toplevel_page_hive-commerce';

/**
 * JS modules, in load order.
 *
 * ORDER IS LOAD-BEARING, not cosmetic: gh-operations, gh-media and gh-jobs
 * each wrap GH.switchTab around the previous definition, so the chain's
 * order decides which wrappers run. Each handle therefore declares the
 * PREVIOUS one as its dependency, which is what forces WordPress to print
 * them in exactly this sequence. Keep the list in sync with the order the
 * <script> block used before the extraction.
 */
function gh_admin_js_modules(): array {
    return [
        'termpicker', 'settings', 'operations', 'inline', 'smart',
        'navigation', 'media', 'mapper', 'jobs', 'email',
        'email-campaigns', 'email-transactional', 'kicksdb', 'history',
        'workflow',
    ];
}

/**
 * Version string for an asset: its mtime, so a deploy busts the cache
 * without anyone having to remember to bump GH_VERSION.
 */
function gh_asset_version( string $rel ): string {
    $path = GH_DIR . $rel;
    $mtime = file_exists( $path ) ? filemtime( $path ) : false;

    return $mtime ? (string) $mtime : GH_VERSION;
}

add_action( 'admin_enqueue_scripts', function ( string $hook_suffix ): void {
    if ( $hook_suffix !== GH_ADMIN_SCREEN ) {
        return;
    }

    $url = plugin_dir_url( GH_DIR . 'golden-hive.php' );

    // ── Fonts ────────────────────────────────────────────────────────
    wp_enqueue_style(
        'gh-fonts',
        'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap',
        [],
        null
    );

    // ── Styles ───────────────────────────────────────────────────────
    wp_enqueue_style(
        'gh-admin',
        $url . 'assets/css/gh-admin.css',
        [ 'gh-fonts' ],
        gh_asset_version( 'assets/css/gh-admin.css' )
    );
    wp_enqueue_style(
        'gh-kicksdb',
        $url . 'assets/css/gh-kicksdb.css',
        [ 'gh-admin' ],
        gh_asset_version( 'assets/css/gh-kicksdb.css' )
    );

    // ── Core ─────────────────────────────────────────────────────────
    // js.php + js2.php concatenated: they are a single IIFE
    // (`const GH = (function(){` … `})();`) that was split across two
    // includes, so they ship as one file and cannot be separated.
    wp_enqueue_script(
        'gh-core',
        $url . 'assets/js/gh-core.js',
        [],
        gh_asset_version( 'assets/js/gh-core.js' ),
        true // in footer, matching where the inline <script> used to sit.
             // Deliberately NOT deferred: the core registers a
             // DOMContentLoaded handler for hash routing, which would be
             // missed if the script ran after that event had fired.
    );

    // The only two values the JS layer ever needed from PHP.
    wp_localize_script( 'gh-core', 'GHBoot', [
        'ajax'  => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'gh_nonce' ),
    ] );

    // ── Modules, strictly ordered via a linear dependency chain ──────
    $previous = 'gh-core';
    foreach ( gh_admin_js_modules() as $module ) {
        $handle = 'gh-' . $module;
        $rel    = 'assets/js/' . $handle . '.js';

        wp_enqueue_script(
            $handle,
            $url . $rel,
            [ $previous ],
            gh_asset_version( $rel ),
            true
        );

        $previous = $handle;
    }
} );
