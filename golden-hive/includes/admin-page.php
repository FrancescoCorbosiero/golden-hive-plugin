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
        <div class="header-desc">WooCommerce Management Suite</div>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-section">OPERAZIONI</div>
            <div class="tab-item active" onclick="GH.switchTab('filter',this)"><span class="tab-icon">&#9881;</span><span class="tab-label">Filtra & Agisci</span></div>
            <div class="tab-item" onclick="GH.switchTab('sorting',this)"><span class="tab-icon">&#8693;</span><span class="tab-label">Ordinamento</span></div>
            <div class="tab-item" onclick="GH.switchTab('taxonomy',this);GH.loadTaxonomy()"><span class="tab-icon">&#9698;</span><span class="tab-label">Tassonomie</span></div>
            <div class="tab-section">MEDIA</div>
            <div class="tab-item" onclick="GH.switchTab('mapping',this)"><span class="tab-icon">&#9636;</span><span class="tab-label">Mapping</span></div>
            <div class="tab-item" onclick="GH.switchTab('browse',this)"><span class="tab-icon">&#9871;</span><span class="tab-label">Browse</span></div>
            <div class="tab-item" onclick="GH.switchTab('orphans',this)"><span class="tab-icon">&#9888;</span><span class="tab-label">Orphans</span></div>
            <div class="tab-section">MAPPER</div>
            <div class="tab-item" data-mp-tab="rules" onclick="GH.switchTab('mapper-rules',this)"><span class="tab-icon">&#9881;</span><span class="tab-label">Regole</span></div>
            <div class="tab-item" data-mp-tab="editor" onclick="GH.switchTab('mapper-editor',this)"><span class="tab-icon">&#9783;</span><span class="tab-label">Editor</span></div>
            <div class="tab-section">IMPORT</div>
            <div class="tab-item" onclick="GH.switchTab('gsfeed',this)"><span class="tab-icon">&#9733;</span><span class="tab-label">GS Feed</span></div>
            <div class="tab-item" onclick="GH.switchTab('sffeed',this)"><span class="tab-icon">&#9879;</span><span class="tab-label">SF Feed</span></div>
            <div class="tab-item" onclick="GH.switchTab('csvfeed',this);GH.csvLoadFeeds()"><span class="tab-icon">&#9783;</span><span class="tab-label">CSV Feed</span></div>
            <div class="tab-item" onclick="GH.switchTab('bulkimport',this)"><span class="tab-icon">&#8615;</span><span class="tab-label">Bulk JSON</span></div>
            <div class="tab-item" onclick="GH.switchTab('roundtrip',this)"><span class="tab-icon">&#8644;</span><span class="tab-label">Roundtrip</span></div>
            <div class="tab-section">JOBS</div>
            <div class="tab-item" onclick="GH.switchTab('jobs',this)"><span class="tab-icon">&#9202;</span><span class="tab-label">Jobs</span></div>
            <div class="tab-section">EMAIL</div>
            <div class="tab-item" onclick="GH.switchTab('email-test',this)"><span class="tab-icon">&#9993;</span><span class="tab-label">Test Email</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-campaigns',this);GH.emCampaignsLoad()"><span class="tab-icon">&#9758;</span><span class="tab-label">Campagne</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-contacts',this);GH.emContactsInit()"><span class="tab-icon">&#9786;</span><span class="tab-label">Contatti</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-history',this);GH.emHistoryLoad()"><span class="tab-icon">&#9202;</span><span class="tab-label">Storico</span></div>
            <div class="tab-section">TOOLS</div>
            <div class="tab-item" onclick="GH.switchTab('httpclient',this)"><span class="tab-icon">&#8680;</span><span class="tab-label">HTTP Client</span></div>
            <div class="tab-item" onclick="GH.switchTab('whitelist',this)"><span class="tab-icon">&#9737;</span><span class="tab-label">Whitelist</span></div>
        </div>

        <div class="content">
            <?php include GH_DIR . 'includes/views/panels.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-operations.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-mapper.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-jobs.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-email.php'; ?>
        </div>
    </div>
    <div id="gh-toasts" class="toast-wrap"></div>
</div>

<script>
<?php include GH_DIR . 'includes/views/js.php'; ?>
<?php include GH_DIR . 'includes/views/js2.php'; ?>
<?php include GH_DIR . 'includes/views/js-operations.php'; ?>
<?php include GH_DIR . 'includes/views/js-mapper.php'; ?>
<?php include GH_DIR . 'includes/views/js-jobs.php'; ?>
<?php include GH_DIR . 'includes/views/js-email.php'; ?>
</script>
<?php
}
