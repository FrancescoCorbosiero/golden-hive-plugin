<?php
/**
 * Hive Sync admin shell — phase 1 menu placeholder.
 *
 * Tabs (Sources, Mappings, Pipelines, Rules, Jobs, Exports) land in phase 3.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'Hive Sync',
        'Hive Sync',
        'manage_woocommerce',
        'hive-sync',
        'hsync_render_admin_page',
        'dashicons-update',
        56
    );
} );

function hsync_render_admin_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
    ?>
    <div class="wrap">
        <h1>Hive Sync <span style="font-size:12px;opacity:.6;">v<?php echo esc_html( HSYNC_VERSION ); ?></span></h1>
        <p>Stock sync — Woo product import/export.</p>
        <div class="notice notice-info inline">
            <p><strong>Phase 1.</strong> Plugin shell installato. UI in arrivo nelle fasi successive (sources → mappings → run).</p>
        </div>
    </div>
    <?php
}
