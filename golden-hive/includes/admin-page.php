<?php
/**
 * Admin page — one unified UI for all Golden Hive modules.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'Golden Hive',
        'Golden Hive',
        'manage_woocommerce',
        'golden-hive',
        'gh_render_page',
        'dashicons-screenoptions',
        57
    );
} );

function gh_render_page(): void {
    $nonce = wp_create_nonce( 'gh_nonce' );
    $ajax  = admin_url( 'admin-ajax.php' );
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<?php include GH_DIR . 'includes/views/css.php'; ?>

<div id="gh">
    <div class="header">
        <div class="header-logo">Golden Hive</div>
        <div class="header-desc">Suite gestione ResellPiacenza</div>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-section">CATALOGO</div>
            <div class="tab-item active" onclick="GH.switchTab('overview',this)"><span class="tab-icon">&#9673;</span><span class="tab-label">Overview</span></div>
            <div class="tab-item" onclick="GH.switchTab('catalog',this)"><span class="tab-icon">&#9776;</span><span class="tab-label">Catalog</span></div>
            <div class="tab-item" onclick="GH.switchTab('taxonomy',this)"><span class="tab-icon">&#9698;</span><span class="tab-label">Taxonomy</span></div>
            <div class="tab-section">MEDIA</div>
            <div class="tab-item" onclick="GH.switchTab('mapping',this)"><span class="tab-icon">&#9636;</span><span class="tab-label">Mapping</span></div>
            <div class="tab-item" onclick="GH.switchTab('browse',this)"><span class="tab-icon">&#9871;</span><span class="tab-label">Browse</span></div>
            <div class="tab-item" onclick="GH.switchTab('orphans',this)"><span class="tab-icon">&#9888;</span><span class="tab-label">Orphans</span></div>
            <div class="tab-section">IMPORT</div>
            <div class="tab-item" onclick="GH.switchTab('gsfeed',this)"><span class="tab-icon">&#9733;</span><span class="tab-label">GS Feed</span></div>
            <div class="tab-item" onclick="GH.switchTab('bulkimport',this)"><span class="tab-icon">&#8615;</span><span class="tab-label">Bulk JSON</span></div>
            <div class="tab-item" onclick="GH.switchTab('roundtrip',this)"><span class="tab-icon">&#8644;</span><span class="tab-label">Roundtrip</span></div>
            <div class="tab-section">TOOLS</div>
            <div class="tab-item" onclick="GH.switchTab('httpclient',this)"><span class="tab-icon">&#8680;</span><span class="tab-label">HTTP Client</span></div>
            <div class="tab-item" onclick="GH.switchTab('whitelist',this)"><span class="tab-icon">&#9737;</span><span class="tab-label">Whitelist</span></div>
            <div class="tab-item" onclick="GH.switchTab('autoreset',this)"><span class="tab-icon">&#8634;</span><span class="tab-label">Auto Reset</span></div>
        </div>

        <div class="content">
            <?php include GH_DIR . 'includes/views/panels.php'; ?>
        </div>
    </div>
    <div id="gh-toasts" class="toast-wrap"></div>
</div>

<script>
<?php include GH_DIR . 'includes/views/js.php'; ?>
<?php include GH_DIR . 'includes/views/js2.php'; ?>
</script>
<?php
}
