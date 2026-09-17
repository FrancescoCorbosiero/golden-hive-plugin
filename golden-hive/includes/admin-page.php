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
    // Feed e sincronizzazione sono di competenza di HIVE SYNC, non di Hive
    // Commerce: i tab FEED (GS / SF / CSV / KicksDB) restano NASCOSTI.
    // È solo UI-hiding, non una rimozione: tutto il PHP resta caricato —
    // AJAX handler, bridge hive-sync (rp_rc_gs_* / gh_sf_*), REST gh/v1,
    // job kinds, cron — perché altri plugin/tool chiamano questo codice.
    // I pannelli restano nel DOM e i deep-link (#/gsfeed, #/csvfeed, …)
    // continuano a funzionare come scorciatoia d'emergenza.
    // Ri-mostrare la sezione: define( 'GH_SHOW_FEED_UI', true ) in
    // wp-config.php, oppure add_filter( 'gh_feed_ui_visible', '__return_true' ).
    //
    // NON sono feed, e restano quindi SEMPRE visibili sotto CATALOGO, gli
    // strumenti JSON locali: Import JSON (file .json → prodotti) e Roundtrip
    // (export snapshot → edit → re-import). Lavorano su un file che carichi
    // tu, senza sorgente remota né scheduling: sono tool di catalogo di Hive
    // Commerce a tutti gli effetti.
    $show_feed_ui = ( defined( 'GH_SHOW_FEED_UI' ) && GH_SHOW_FEED_UI )
        || ( defined( 'GH_SHOW_IMPORT_UI' ) && GH_SHOW_IMPORT_UI );

    // gh_import_ui_visible è il nome storico di quando il flag copriva feed E
    // tool JSON insieme: deprecato ma ancora onorato, così chi l'ha già messo
    // in wp-config.php non perde l'escape hatch. Corre per primo perché
    // gh_feed_ui_visible (nome corrente) dica sempre l'ultima parola.
    $show_feed_ui = (bool) apply_filters( 'gh_import_ui_visible', $show_feed_ui );
    $show_feed_ui = (bool) apply_filters( 'gh_feed_ui_visible',   $show_feed_ui );
    // Styles and scripts are enqueued in includes/assets.php.
    ?>

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
        <nav class="tabs-col" aria-label="Sezioni Hive Commerce">
            <h2 class="tab-section">OPERAZIONI</h2>
            <button type="button" class="tab-item active" aria-current="page" onclick="GH.switchTab('filter',this)"><span class="tab-icon" aria-hidden="true">&#9881;</span><span class="tab-label">Filtra & Agisci</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('inline-editor',this)"><span class="tab-icon" aria-hidden="true">&#9783;</span><span class="tab-label">Inline Editor</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('sorting',this)"><span class="tab-icon" aria-hidden="true">&#8693;</span><span class="tab-label">Ordinamento</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('taxonomy',this);GH.loadTaxonomy()"><span class="tab-icon" aria-hidden="true">&#9698;</span><span class="tab-label">Tassonomie</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('tax-query',this)"><span class="tab-icon" aria-hidden="true">&#9906;</span><span class="tab-label">Tax Query</span></button>
            <button type="button" class="tab-item" data-gh-tab="navigation" onclick="GH.switchTab('navigation',this);GH.navLoadMenus()"><span class="tab-icon" aria-hidden="true">&#9881;</span><span class="tab-label">Navigazione</span></button>
            <h2 class="tab-section">MEDIA</h2>
            <button type="button" class="tab-item" onclick="GH.switchTab('media-library',this)"><span class="tab-icon" aria-hidden="true">&#9636;</span><span class="tab-label">Media Library</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('whitelist',this);GH.loadWhitelist()"><span class="tab-icon" aria-hidden="true">&#9737;</span><span class="tab-label">Whitelist</span></button>
            <h2 class="tab-section">MAPPER</h2>
            <button type="button" class="tab-item" data-mp-tab="rules" onclick="GH.switchTab('mapper-rules',this)"><span class="tab-icon" aria-hidden="true">&#9881;</span><span class="tab-label">Regole</span></button>
            <button type="button" class="tab-item" data-mp-tab="editor" onclick="GH.switchTab('mapper-editor',this)"><span class="tab-icon" aria-hidden="true">&#9783;</span><span class="tab-label">Editor</span></button>
<?php if ( $show_feed_ui ) : ?>
            <h2 class="tab-section">FEED</h2>
            <button type="button" class="tab-item" onclick="GH.switchTab('gsfeed',this);GH.gsLoadSettings()"><span class="tab-icon" aria-hidden="true">&#9733;</span><span class="tab-label">GS Feed</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('sffeed',this);GH.sfLoadSettings()"><span class="tab-icon" aria-hidden="true">&#9879;</span><span class="tab-label">SF Feed</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('csvfeed',this);GH.csvLoadFeeds()"><span class="tab-icon" aria-hidden="true">&#9783;</span><span class="tab-label">CSV Feed</span></button>
            <button type="button" class="tab-item" data-kdb-tab="lookup" onclick="GH.switchTab('kicksdb',this);GH.kdbInit()"><span class="tab-icon" aria-hidden="true">&#9883;</span><span class="tab-label">KicksDB</span></button>
<?php endif; ?>
            <h2 class="tab-section">CATALOGO</h2>
            <button type="button" class="tab-item" onclick="GH.switchTab('bulkimport',this)"><span class="tab-icon" aria-hidden="true">&#8615;</span><span class="tab-label">Import JSON</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('roundtrip',this)"><span class="tab-icon" aria-hidden="true">&#8644;</span><span class="tab-label">Roundtrip</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('history',this);GH.histInit()"><span class="tab-icon" aria-hidden="true">&#9201;</span><span class="tab-label">Catalog History</span></button>
            <h2 class="tab-section">JOBS</h2>
            <button type="button" class="tab-item" onclick="GH.switchTab('jobs',this)"><span class="tab-icon" aria-hidden="true">&#9202;</span><span class="tab-label">Jobs</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('workflow',this);GH.workflowInit()"><span class="tab-icon" aria-hidden="true">&#9881;</span><span class="tab-label">Workflow <small style="opacity:.6">v2</small></span></button>
            <h2 class="tab-section">EMAIL</h2>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-brand',this);GH.emBrandLoad()"><span class="tab-icon" aria-hidden="true">&#9733;</span><span class="tab-label">Brand</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-templates',this);GH.emTplLoad()"><span class="tab-icon" aria-hidden="true">&#9881;</span><span class="tab-label">Templates</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-campaigns',this);GH.emCampaignsLoad()"><span class="tab-icon" aria-hidden="true">&#9758;</span><span class="tab-label">Campagne</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-transactional',this);GH.emTrxLoad()"><span class="tab-icon" aria-hidden="true">&#9993;</span><span class="tab-label">Transazionali</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-contacts',this);GH.emContactsInit()"><span class="tab-icon" aria-hidden="true">&#9786;</span><span class="tab-label">Contatti</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-test',this);GH.emTestInit()"><span class="tab-icon" aria-hidden="true">&#9993;</span><span class="tab-label">Test Email</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('email-history',this);GH.emHistoryLoad()"><span class="tab-icon" aria-hidden="true">&#9202;</span><span class="tab-label">Storico</span></button>
            <h2 class="tab-section">TOOLS</h2>
            <button type="button" class="tab-item" onclick="GH.switchTab('httpclient',this)"><span class="tab-icon" aria-hidden="true">&#8680;</span><span class="tab-label">HTTP Client</span></button>
            <button type="button" class="tab-item" onclick="GH.switchTab('nuclear',this)"><span class="tab-icon" aria-hidden="true">&#9762;</span><span class="tab-label">Nuclear Cleanup</span></button>
        </nav>

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
    <div id="gh-toasts" class="toast-wrap" role="status" aria-live="polite"></div>
</div>

<?php
}
