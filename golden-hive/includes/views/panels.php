<!-- ═══ OVERVIEW ═══ -->
<div class="panel active" id="panel-overview">
    <div style="padding:20px">
        <button class="btn btn-primary" id="btn-summary" onclick="GH.loadSummary()"><span class="spin" id="sum-spin" style="display:none"></span> Genera Overview</button>
    </div>
    <div id="summary-container">
        <div class="empty-state"><div class="empty-icon">&#9673;</div><div class="empty-text">Premi "Genera Overview" per una panoramica rapida</div></div>
    </div>
</div>

<!-- ═══ CATALOG ═══ -->
<div class="panel" id="panel-catalog" style="position:relative">
    <div class="toolbar">
        <span class="filter-label">Stato</span>
        <select class="filter-select" id="cat-filter-status"><option value="publish">Pubblicati</option><option value="draft">Bozze</option><option value="any">Tutti</option></select>
        <span class="filter-label">Brand</span>
        <select class="filter-select" id="cat-filter-brand"><option value="">Tutti</option></select>
        <label class="filter-toggle"><input type="checkbox" id="cat-filter-stock" /><span class="filter-label" style="letter-spacing:0">Solo in stock</span></label>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="btn-catalog" onclick="GH.generateCatalog()"><span class="spin" id="cat-spin" style="display:none"></span> Genera Catalog</button>
    </div>
    <div class="json-area" id="catalog-viewer"><div class="empty-state"><div class="empty-icon">&#9776;</div><div class="empty-text">Genera il catalogo aggregato</div></div></div>
    <div class="json-toolbar" id="catalog-toolbar" style="display:none">
        <button class="btn btn-ghost" onclick="GH.copyJSON('catalog')">&#9112; Copia</button>
        <button class="btn btn-ghost" onclick="GH.downloadJSON('catalog')">&#8681; Download</button>
        <span class="file-size" id="catalog-size"></span>
    </div>
    <div class="gen-overlay" id="cat-overlay"><div class="gen-spinner"></div><div class="gen-text">Generazione catalogo...</div></div>
</div>

<!-- ═══ TAXONOMY ═══ -->
<div class="panel" id="panel-taxonomy" style="position:relative">
    <div class="toolbar">
        <button class="btn btn-primary" id="btn-tax-load" onclick="GH.loadTaxonomy()"><span class="spin" id="tax-spin" style="display:none"></span> Carica albero</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.taxCreateRoot()">+ Sezione</button>
    </div>
    <div style="flex:1;display:flex;overflow:hidden">
        <div class="tax-wrap" id="tax-tree-area"><div class="empty-state"><div class="empty-icon">&#9698;</div><div class="empty-text">Carica l'albero tassonomia</div></div></div>
        <div class="tax-detail" id="tax-detail" style="display:none">
            <div class="tax-detail-head"><span class="tax-detail-title" id="tax-detail-title"></span><span class="tax-detail-id" id="tax-detail-id"></span></div>
            <div class="tax-products" id="tax-products-list"></div>
        </div>
    </div>
</div>

<!-- ═══ MEDIA MAPPING ═══ -->
<div class="panel" id="panel-mapping" style="position:relative">
    <div class="toolbar">
        <button class="btn btn-primary" id="btn-map" onclick="GH.loadMapping()"><span class="spin" id="map-spin" style="display:none"></span> Carica mapping</button>
    </div>
    <div class="map-wrap" id="map-area"><div class="empty-state"><div class="empty-icon">&#9636;</div><div class="empty-text">Carica il mapping prodotto-immagini</div></div></div>
</div>

<!-- ═══ MEDIA BROWSE ═══ -->
<div class="panel" id="panel-browse">
    <div class="toolbar">
        <input class="search-input" id="browse-search" placeholder="Cerca media..." oninput="GH.debounceBrowse()" />
        <button class="btn btn-ghost" onclick="GH.browseMedia()">Cerca</button>
    </div>
    <div class="media-grid" id="browse-grid"><div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">&#9871;</div><div class="empty-text">Cerca nella media library</div></div></div>
</div>

<!-- ═══ ORPHANS ═══ -->
<div class="panel" id="panel-orphans" style="position:relative">
    <div class="toolbar">
        <button class="btn btn-primary" id="btn-scan" onclick="GH.scanOrphans()"><span class="spin" id="scan-spin" style="display:none"></span> Avvia scansione</button>
        <div class="filter-sep"></div>
        <button class="btn btn-danger" id="btn-bulk-del" onclick="GH.bulkDeleteOrphans()" style="display:none">Elimina selezionati</button>
        <span class="stat" id="sel-stat" style="display:none"><span id="sel-n">0</span> selezionati</span>
    </div>
    <div class="stats-bar" id="orphan-stats" style="display:none">
        <div class="stat">Orfani: <span class="red" id="st-orphans">0</span></div>
        <div class="stat">In uso: <span class="green" id="st-used">0</span></div>
        <div class="stat">Spazio recuperabile: <span id="st-size">0</span></div>
    </div>
    <div class="media-grid" id="orphan-grid"><div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">&#9888;</div><div class="empty-text">Scansiona per trovare immagini orfane</div></div></div>
    <div class="gen-overlay" id="scan-overlay"><div class="gen-spinner"></div><div class="gen-text">Scansione in corso...</div></div>
</div>

<!-- ═══ GS FEED ═══ -->
<div class="panel" id="panel-gsfeed" style="position:relative">
    <div class="config-form">
        <div class="cfg-row"><span class="cfg-label">URL</span><input class="cfg-input" id="gs-url" placeholder="https://www.goldensneakers.net/api/assortment/?..." /></div>
        <div class="cfg-row"><span class="cfg-label">Token</span><input class="cfg-input" id="gs-token" type="password" placeholder="Bearer token" /></div>
        <div class="cfg-row"><span class="cfg-label">Cookie</span><input class="cfg-input" id="gs-cookie" placeholder="csrftoken=... (opzionale)" /></div>
        <div class="cfg-row">
            <span class="cfg-label">Formato</span>
            <select class="cfg-select" id="gs-format"><option value="hierarchical">Gerarchico</option><option value="flat">Flat</option></select>
            <button class="btn btn-primary" id="btn-gs-fetch" onclick="GH.gsFetch()"><span class="spin" id="gs-fetch-spin" style="display:none"></span> Fetch</button>
        </div>
    </div>
    <div class="stats-bar" id="gs-stats" style="display:none">
        <div class="stat">Feed: <span class="blue" id="gs-total">0</span></div>
        <div class="stat">Nuovi: <span class="blue" id="gs-new">0</span></div>
        <div class="stat">Aggiornare: <span class="amber" id="gs-update">0</span></div>
        <div class="stat">Invariati: <span class="green" id="gs-unchanged">0</span></div>
    </div>
    <div class="gs-sel-bar" id="gs-sel-bar" style="display:none">
        <button class="btn btn-ghost" onclick="GH.gsSelectAll()">Tutti</button>
        <button class="btn btn-ghost" onclick="GH.gsSelectNone()">Nessuno</button>
        <button class="btn btn-ghost" onclick="GH.gsSelectByType('new')">Solo nuovi</button>
        <button class="btn btn-ghost" onclick="GH.gsSelectByType('update')">Solo aggiorn.</button>
        <span class="gs-sel-count" id="gs-sel-count"></span>
    </div>
    <div class="preview-wrap" id="gs-preview"><div class="empty-state"><div class="empty-icon">&#9733;</div><div class="empty-text">Configura l'endpoint Golden Sneakers</div></div></div>
    <div class="confirm-bar" id="gs-confirm" style="display:none">
        <div class="summary-text" id="gs-confirm-text"></div>
        <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px"><input type="checkbox" id="gs-opt-images" checked /> Sideload img</label>
        <button class="btn btn-ghost" onclick="GH.gsCancel()">Annulla</button>
        <button class="btn btn-warn" id="btn-gs-apply" onclick="GH.gsApply()"><span class="spin" id="gs-apply-spin" style="display:none"></span> Importa</button>
    </div>
    <div class="gen-overlay" id="gs-overlay"><div class="gen-spinner"></div><div class="gen-text" id="gs-overlay-text">Fetch...</div></div>
</div>

<!-- ═══ BULK IMPORT ═══ -->
<div class="panel" id="panel-bulkimport" style="position:relative">
    <div class="section-title">Importa prodotti da JSON</div>
    <div class="drop-area" id="imp-drop" onclick="document.getElementById('imp-file-input').click()">
        <input type="file" id="imp-file-input" accept=".json" style="display:none" />
        <div class="drop-area-text">Clicca o trascina un file .json</div>
        <div class="drop-area-file" id="imp-file-name"></div>
    </div>
    <div class="mode-row" id="imp-mode-row" style="display:none">
        <label><input type="radio" name="bulk-mode" value="create" checked /> Crea sempre</label>
        <label><input type="radio" name="bulk-mode" value="create_or_update" /> Crea o aggiorna (SKU)</label>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="btn-imp-preview" onclick="GH.bulkPreview()"><span class="spin" id="imp-preview-spin" style="display:none"></span> Preview</button>
    </div>
    <div class="preview-wrap" id="imp-preview-area"></div>
    <div class="confirm-bar" id="imp-confirm-bar" style="display:none">
        <div class="summary-text" id="imp-confirm-text"></div>
        <button class="btn btn-ghost" onclick="GH.bulkCancel()">Annulla</button>
        <button class="btn btn-warn" id="btn-imp-apply" onclick="GH.bulkApply()"><span class="spin" id="imp-apply-spin" style="display:none"></span> Crea prodotti</button>
    </div>
    <div class="gen-overlay" id="imp-overlay"><div class="gen-spinner"></div><div class="gen-text" id="imp-overlay-text">Elaborazione...</div></div>
</div>

<!-- ═══ ROUNDTRIP ═══ -->
<div class="panel" id="panel-roundtrip" style="position:relative">
    <div class="section-title">Export snapshot</div>
    <div class="toolbar">
        <span class="filter-label">Stato</span>
        <select class="filter-select" id="rt-filter-status"><option value="any" selected>Tutti</option><option value="publish">Pubblicati</option><option value="draft">Bozze</option></select>
        <span class="filter-label">Brand</span>
        <select class="filter-select" id="rt-filter-brand"><option value="">Tutti</option></select>
        <label class="filter-toggle"><input type="checkbox" id="rt-filter-stock" /><span class="filter-label" style="letter-spacing:0">Solo in stock</span></label>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="btn-rt-export" onclick="GH.generateRoundtrip()"><span class="spin" id="rt-spin" style="display:none"></span> Genera Export</button>
        <button class="btn btn-ghost" id="btn-rt-copy" onclick="GH.copyJSON('roundtrip')" style="display:none">&#9112; Copia</button>
        <button class="btn btn-ghost" id="btn-rt-download" onclick="GH.downloadJSON('roundtrip')" style="display:none">&#8681; Download</button>
        <span class="file-size" id="rt-size"></span>
    </div>
    <div class="section-title">Import JSON</div>
    <div class="drop-area" id="rt-drop" onclick="document.getElementById('rt-file-input').click()">
        <input type="file" id="rt-file-input" accept=".json" style="display:none" />
        <div class="drop-area-text">Clicca o trascina un file roundtrip .json</div>
        <div class="drop-area-file" id="rt-file-name"></div>
    </div>
    <div class="mode-row" id="rt-mode-row" style="display:none">
        <label><input type="radio" name="import-mode" value="update_only" checked /> Solo aggiornamento</label>
        <label><input type="radio" name="import-mode" value="create_if_missing" /> Crea se non esiste</label>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="btn-rt-preview" onclick="GH.importPreview()"><span class="spin" id="preview-spin" style="display:none"></span> Preview</button>
    </div>
    <div class="preview-wrap" id="rt-preview-area"></div>
    <div class="confirm-bar" id="rt-confirm-bar" style="display:none">
        <div class="summary-text" id="rt-confirm-text"></div>
        <button class="btn btn-ghost" onclick="GH.importCancel()">Annulla</button>
        <button class="btn btn-warn" id="btn-rt-apply" onclick="GH.importApply()"><span class="spin" id="apply-spin" style="display:none"></span> Applica</button>
    </div>
    <div class="gen-overlay" id="rt-overlay"><div class="gen-spinner"></div><div class="gen-text" id="rt-overlay-text">...</div></div>
</div>

<!-- ═══ HTTP CLIENT ═══ -->
<div class="panel" id="panel-httpclient">
    <div class="config-form">
        <div class="cfg-row"><span class="cfg-label">URL</span><input class="cfg-input" id="hc-url" placeholder="https://api.example.com/endpoint" /></div>
        <div class="cfg-row">
            <span class="cfg-label">Metodo</span>
            <select class="cfg-select" id="hc-method"><option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option></select>
            <span class="cfg-label">Headers</span>
            <input class="cfg-input" id="hc-headers" placeholder='{"Authorization":"Bearer ..."}' />
        </div>
        <div class="cfg-row">
            <span class="cfg-label">Body</span>
            <input class="cfg-input" id="hc-body" placeholder="POST body (JSON)" />
            <button class="btn btn-primary" onclick="GH.hcExecute()"><span class="spin" id="hc-spin" style="display:none"></span> Esegui</button>
        </div>
    </div>
    <div class="json-area" id="hc-response"><div class="empty-state"><div class="empty-icon">&#8680;</div><div class="empty-text">Esegui una richiesta HTTP</div></div></div>
</div>

<!-- ═══ WHITELIST ═══ -->
<div class="panel" id="panel-whitelist">
    <div class="toolbar">
        <button class="btn btn-primary" onclick="GH.loadWhitelist()">Aggiorna</button>
    </div>
    <div class="wl-wrap" id="wl-area"><div class="empty-state"><div class="empty-icon">&#9737;</div><div class="empty-text">La whitelist protegge le immagini dall'eliminazione</div></div></div>
</div>
