<?php
/**
 * Admin page — one unified UI for all Hive Commerce modules.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'Hive Commerce',
        'Hive Commerce',
        'manage_woocommerce',
        'hive-commerce',
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

<style>
#wpfooter{display:none !important}
#wpbody-content{padding-bottom:0 !important}
html.wp-toolbar,body.wp-admin.toplevel_page_hive-commerce{background:#0c0d10}
</style>
<?php include GH_DIR . 'includes/views/css.php'; ?>

<div id="gh">
    <div class="header">
        <div class="header-logo">Hive Commerce</div>
        <div class="header-desc">WooCommerce Management Suite</div>
        <?php
        $gh_build = function_exists( 'gh_get_build_tag' ) ? gh_get_build_tag() : null;
        if ( $gh_build ) :
            $tip = $gh_build['source'] === 'git'
                ? sprintf( '%s · %s · click per copiare', esc_attr( $gh_build['sha'] ), esc_attr( $gh_build['branch'] ) )
                : 'Build deployata (no .git mountato)';
            ?>
            <span id="gh-build-tag"
                  title="<?php echo $tip; ?>"
                  data-sha="<?php echo esc_attr( $gh_build['sha'] ); ?>"
                  onclick="GH && GH.copyToClipboard && GH.copyToClipboard(this.dataset.sha || this.textContent)"
                  style="margin-left:auto;padding:2px 8px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--dim,#8a8f9d);border:1px solid var(--b1,#232630);border-radius:3px;cursor:pointer;user-select:all;letter-spacing:.04em">
                <?php echo esc_html( $gh_build['label'] ); ?>
                <?php if ( $gh_build['source'] === 'git' && $gh_build['branch'] !== '' ) : ?>
                    <span style="opacity:.55;margin-left:4px"><?php echo esc_html( '· ' . ( strlen( $gh_build['branch'] ) > 24 ? substr( $gh_build['branch'], 0, 22 ) . '…' : $gh_build['branch'] ) ); ?></span>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-section">OPERAZIONI</div>
            <div class="tab-item active" onclick="GH.switchTab('filter',this)"><span class="tab-icon">&#9881;</span><span class="tab-label">Filtra & Agisci</span></div>
            <div class="tab-item" onclick="GH.switchTab('inline-editor',this)"><span class="tab-icon">&#9783;</span><span class="tab-label">Inline Editor</span></div>
            <div class="tab-item" onclick="GH.switchTab('sorting',this)"><span class="tab-icon">&#8693;</span><span class="tab-label">Ordinamento</span></div>
            <div class="tab-item" onclick="GH.switchTab('taxonomy',this);GH.loadTaxonomy()"><span class="tab-icon">&#9698;</span><span class="tab-label">Tassonomie</span></div>
            <div class="tab-item" onclick="GH.switchTab('tax-query',this)"><span class="tab-icon">&#9906;</span><span class="tab-label">Tax Query</span></div>
            <div class="tab-item" data-gh-tab="navigation" onclick="GH.switchTab('navigation',this);GH.navLoadMenus()"><span class="tab-icon">&#9881;</span><span class="tab-label">Navigazione</span></div>
            <div class="tab-section">MEDIA</div>
            <div class="tab-item" onclick="GH.switchTab('media-library',this)"><span class="tab-icon">&#9636;</span><span class="tab-label">Media Library</span></div>
            <div class="tab-item" onclick="GH.switchTab('whitelist',this);GH.loadWhitelist()"><span class="tab-icon">&#9737;</span><span class="tab-label">Whitelist</span></div>
            <div class="tab-section">MAPPER</div>
            <div class="tab-item" data-mp-tab="rules" onclick="GH.switchTab('mapper-rules',this)"><span class="tab-icon">&#9881;</span><span class="tab-label">Regole</span></div>
            <div class="tab-item" data-mp-tab="editor" onclick="GH.switchTab('mapper-editor',this)"><span class="tab-icon">&#9783;</span><span class="tab-label">Editor</span></div>
            <div class="tab-section">IMPORT</div>
            <div class="tab-item" onclick="GH.switchTab('gsfeed',this);GH.gsLoadSettings()"><span class="tab-icon">&#9733;</span><span class="tab-label">GS Feed</span></div>
            <div class="tab-item" onclick="GH.switchTab('sffeed',this);GH.sfLoadSettings()"><span class="tab-icon">&#9879;</span><span class="tab-label">SF Feed</span></div>
            <div class="tab-item" onclick="GH.switchTab('csvfeed',this);GH.csvLoadFeeds()"><span class="tab-icon">&#9783;</span><span class="tab-label">CSV Feed</span></div>
            <div class="tab-item" data-kdb-tab="lookup" onclick="GH.switchTab('kicksdb',this);GH.kdbInit()"><span class="tab-icon">&#9883;</span><span class="tab-label">KicksDB</span></div>
            <div class="tab-item" onclick="GH.switchTab('bulkimport',this)"><span class="tab-icon">&#8615;</span><span class="tab-label">Bulk JSON</span></div>
            <div class="tab-item" onclick="GH.switchTab('roundtrip',this)"><span class="tab-icon">&#8644;</span><span class="tab-label">Roundtrip</span></div>
            <div class="tab-item" onclick="GH.switchTab('history',this);GH.histInit()"><span class="tab-icon">&#9201;</span><span class="tab-label">Catalog History</span></div>
            <div class="tab-section">JOBS</div>
            <div class="tab-item" onclick="GH.switchTab('jobs',this)"><span class="tab-icon">&#9202;</span><span class="tab-label">Jobs</span></div>
            <div class="tab-item" onclick="GH.switchTab('workflow',this);GH.workflowInit()"><span class="tab-icon">&#9881;</span><span class="tab-label">Workflow <small style="opacity:.6">v2</small></span></div>
            <div class="tab-section">EMAIL</div>
            <div class="tab-item" onclick="GH.switchTab('email-brand',this);GH.emBrandLoad()"><span class="tab-icon">&#9733;</span><span class="tab-label">Brand</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-templates',this);GH.emTplLoad()"><span class="tab-icon">&#9881;</span><span class="tab-label">Templates</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-campaigns',this);GH.emCampaignsLoad()"><span class="tab-icon">&#9758;</span><span class="tab-label">Campagne</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-transactional',this);GH.emTrxLoad()"><span class="tab-icon">&#9993;</span><span class="tab-label">Transazionali</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-contacts',this);GH.emContactsInit()"><span class="tab-icon">&#9786;</span><span class="tab-label">Contatti</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-test',this);GH.emTestInit()"><span class="tab-icon">&#9993;</span><span class="tab-label">Test Email</span></div>
            <div class="tab-item" onclick="GH.switchTab('email-history',this);GH.emHistoryLoad()"><span class="tab-icon">&#9202;</span><span class="tab-label">Storico</span></div>
            <div class="tab-section">TOOLS</div>
            <div class="tab-item" onclick="GH.switchTab('httpclient',this)"><span class="tab-icon">&#8680;</span><span class="tab-label">HTTP Client</span></div>
            <div class="tab-item" onclick="GH.switchTab('nuclear',this)"><span class="tab-icon">&#9762;</span><span class="tab-label">Nuclear Cleanup</span></div>
        </div>

        <div class="content">
            <?php include GH_DIR . 'includes/views/panels.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-operations.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-navigation.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-mapper.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-jobs.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-email.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-kicksdb.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-history.php'; ?>
            <?php include GH_DIR . 'includes/views/panels-workflow.php'; ?>
        </div>
    </div>
    <div id="gh-toasts" class="toast-wrap"></div>
</div>

<script>
<?php include GH_DIR . 'includes/views/js.php'; ?>
<?php include GH_DIR . 'includes/views/js2.php'; ?>
<?php include GH_DIR . 'includes/views/js-termpicker.php'; ?>
<?php include GH_DIR . 'includes/views/js-settings.php'; ?>
<?php include GH_DIR . 'includes/views/js-operations.php'; ?>
<?php include GH_DIR . 'includes/views/js-inline.php'; ?>
<?php include GH_DIR . 'includes/views/js-smart.php'; ?>
<?php include GH_DIR . 'includes/views/js-navigation.php'; ?>
<?php include GH_DIR . 'includes/views/js-media.php'; ?>
<?php include GH_DIR . 'includes/views/js-mapper.php'; ?>
<?php include GH_DIR . 'includes/views/js-jobs.php'; ?>
<?php include GH_DIR . 'includes/views/js-email.php'; ?>
<?php include GH_DIR . 'includes/views/js-email-campaigns.php'; ?>
<?php include GH_DIR . 'includes/views/js-email-transactional.php'; ?>
<?php include GH_DIR . 'includes/views/js-kicksdb.php'; ?>
<?php include GH_DIR . 'includes/views/js-history.php'; ?>
<?php include GH_DIR . 'includes/views/js-workflow.php'; ?>
</script>
<?php
}
