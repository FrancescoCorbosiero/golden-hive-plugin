<?php
/**
 * Plugin Name:  Hive Sync
 * Plugin URI:   https://github.com/FrancescoCorbosiero/golden-hive-plugin
 * Description:  Stock sync — Woo product import/export with reusable mappings, rules, and scheduled jobs. Standalone; integrates with Golden Hive when present.
 * Version:      0.1.0
 * Author:       Golden Hive
 * License:      Private
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'HSYNC_VERSION', '0.1.0' );
define( 'HSYNC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'HSYNC_URL',     plugin_dir_url( __FILE__ ) );
define( 'HSYNC_FILE',    __FILE__ );

$hsync_autoload = HSYNC_DIR . 'vendor/autoload.php';
if ( file_exists( $hsync_autoload ) ) {
    require_once $hsync_autoload;
} elseif ( is_admin() ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-warning"><p><strong>Hive Sync:</strong> '
            . '<code>vendor/autoload.php</code> mancante. Esegui <code>composer install</code> '
            . 'nella directory del plugin.'
            . '</p></div>';
    } );
}
unset( $hsync_autoload );

require_once HSYNC_DIR . 'includes/migrate.php';
require_once HSYNC_DIR . 'includes/host-adapter.php';
require_once HSYNC_DIR . 'includes/admin-page.php';
require_once HSYNC_DIR . 'includes/assets.php';
require_once HSYNC_DIR . 'includes/ajax.php';

// Concrete sources / operations / checks self-register on the
// 'hive_sync/core_booted' action. Required BEFORE Bootstrap::boot()
// so the listener is in place when boot() fires the hook.
require_once HSYNC_DIR . 'includes/registrations.php';

register_activation_hook( __FILE__, 'hsync_activate' );

function hsync_activate(): void {
    hsync_migrate_schema();
    update_option( 'hsync_db_version', HSYNC_VERSION, false );
}

add_action( 'plugins_loaded', function () {
    $installed = get_option( 'hsync_db_version' );
    if ( $installed !== HSYNC_VERSION ) {
        hsync_migrate_schema();
        update_option( 'hsync_db_version', HSYNC_VERSION, false );
    }

    if ( class_exists( '\\HiveSync\\Core\\Bootstrap' ) ) {
        \HiveSync\Core\Bootstrap::boot();
    }
}, 20 );
