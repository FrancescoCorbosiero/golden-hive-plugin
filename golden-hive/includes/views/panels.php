<!-- ═══ TAXONOMY ═══ -->
<div class="panel" id="panel-taxonomy" style="position:relative">
    <div class="toolbar">
        <span class="filter-label">Sorgente</span>
        <select class="filter-select" id="tax-source" onchange="GH.loadTaxonomy()">
            <option value="product_cat">Categorie (product_cat)</option>
            <option value="product_brand">Brand (product_brand)</option>
        </select>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="btn-tax-load" onclick="GH.loadTaxonomy()"><span class="spin" id="tax-spin" style="display:none"></span> Ricarica albero</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.taxCreateRoot()">+ Root</button>
        <button class="btn btn-ghost" onclick="GH.smartSyncAll()" title="Esegui tutte le smart rules abilitate">&#9889; Sync All</button>
    </div>
    <div style="flex:1;display:flex;overflow:hidden">
        <div class="tax-wrap" id="tax-tree-area"><div class="empty-state"><div class="empty-icon">&#9698;</div><div class="empty-text">Carica l'albero tassonomia</div></div></div>
        <div class="tax-detail" id="tax-detail" style="display:none">
            <div class="tax-detail-head"><span class="tax-detail-title" id="tax-detail-title"></span><span class="tax-detail-id" id="tax-detail-id"></span></div>
            <div class="tax-products" id="tax-products-list"></div>
            <!-- Smart Rule section: appears below product list for the selected term -->
            <div class="smart-rule-section" id="smart-rule-section">
                <div class="smart-rule-head">
                    <span class="smart-rule-label">&#9889; Smart Rule</span>
                    <span class="smart-rule-status" id="sr-status"></span>
                </div>
                <div id="sr-content">
                    <!-- Populated by js-smart.php -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MEDIA LIBRARY ═══ -->
<!--
    Browser unificato della media library con product awareness.
    Sostituisce le vecchie tab Mapping + Safe Cleanup (rimosse).

    Funzionalita chiave:
    - Paginazione su migliaia di media
    - Filtri: nome file, usage (mapped/unmapped), whitelist
    - Bulk ops: whitelist, remove-from-galleries, delete
    - Shortcut "Safe Cleanup": preview in-panel con lista whitelist esclusi + stats
    - Row actions: whitelist toggle, link al prodotto, badge ruolo per ogni usage

    Tutti i safety check (whitelist, rp_mm_is_used, log) vivono server-side in
    rp_mm_delete_attachment() - questo panel li rispetta per costruzione.
-->
<div class="panel" id="panel-media-library" style="position:relative">
    <!-- Filters toolbar -->
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <input class="filter-select" id="ml-search" placeholder="Nome file contiene (SKU, title, ...)" style="min-width:220px;flex:1;max-width:320px"
               onkeydown="if(event.key==='Enter')GH.mlQuery(true)" />
        <select class="filter-select" id="ml-usage" onchange="GH.mlQuery(true)">
            <option value="all">Usage: tutti</option>
            <option value="mapped">Mappati</option>
            <option value="unmapped">Non mappati</option>
        </select>
        <select class="filter-select" id="ml-whitelist" onchange="GH.mlQuery(true)">
            <option value="all">Whitelist: tutti</option>
            <option value="yes">Whitelisted</option>
            <option value="no">Non whitelisted</option>
        </select>
        <select class="filter-select" id="ml-orderby" onchange="GH.mlQuery(true)">
            <option value="date">Data ↓</option>
            <option value="id">ID ↓</option>
            <option value="filename">Nome A→Z</option>
        </select>
        <button class="btn btn-primary" onclick="GH.mlQuery(true)"><span class="spin" id="ml-spin" style="display:none"></span> Cerca</button>
        <div class="filter-sep"></div>
        <button class="btn btn-warn" onclick="GH.mlSafeCleanup()" title="Preview + delete di tutti i media non mappati (esclude whitelist)">Safe Cleanup</button>
    </div>

    <!-- Stats bar + pagination info -->
    <div class="stats-bar" id="ml-stats-bar" style="display:none">
        <div class="stat"><span id="ml-total">0</span> risultati</div>
        <div class="stat">Pagina <span id="ml-page">1</span> / <span id="ml-total-pages">1</span></div>
        <div class="stat" id="ml-sel-stat" style="display:none"><span class="green" id="ml-sel-n">0</span> selezionati</div>
    </div>

    <!-- Bulk action bar (hidden until selection) -->
    <div id="ml-bulk-bar" style="display:none;padding:10px 16px;background:var(--s1);border-bottom:1px solid var(--b1);flex-shrink:0">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span class="filter-label" style="color:var(--grn);font-weight:600">Azioni bulk:</span>
            <button class="btn btn-ghost" onclick="GH.mlBulkWhitelist()">+ Whitelist</button>
            <button class="btn btn-ghost" onclick="GH.mlBulkRemoveFromGalleries()">&#x2702; Rimuovi da gallerie</button>
            <button class="btn btn-danger" onclick="GH.mlBulkDelete()">&times; Elimina dalla library</button>
            <div class="filter-sep"></div>
            <button class="btn btn-ghost" onclick="GH.mlSelectAllInFilter()" title="Seleziona tutti i media corrispondenti ai filtri (anche quelli non in pagina)">Seleziona tutti i risultati</button>
            <button class="btn btn-ghost" onclick="GH.mlClearSelection()">Deseleziona tutto</button>
        </div>
    </div>

    <!-- Results area (table) -->
    <div id="ml-results" style="flex:1;overflow-y:auto">
        <div class="empty-state"><div class="empty-icon">&#9636;</div><div class="empty-text">Usa i filtri per caricare i media</div></div>
    </div>

    <!-- Pagination controls -->
    <div id="ml-pagination" style="display:none;padding:10px 16px;background:var(--s1);border-top:1px solid var(--b1);flex-shrink:0;display:flex;gap:8px;align-items:center;justify-content:center"></div>

    <!-- Safe Cleanup preview (hidden until invoked) -->
    <div id="ml-safe-preview" style="display:none;position:absolute;inset:0;background:var(--bg);z-index:20;overflow-y:auto;padding:20px"></div>

    <div class="gen-overlay" id="ml-overlay"><div class="gen-spinner"></div><div class="gen-text" id="ml-overlay-text">Caricamento...</div></div>
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

<!-- ═══ SF FEED (StockFirmati) ═══ -->
<div class="panel" id="panel-sffeed" style="position:relative">
    <div class="config-form">
        <div class="cfg-row">
            <span class="cfg-label">Sorgente</span>
            <select class="cfg-select" id="sf-source-type" onchange="GH.sfToggleSource()">
                <option value="url">URL remoto</option>
                <option value="file">File caricato</option>
            </select>
        </div>
        <div class="cfg-row" id="sf-source-url-row">
            <span class="cfg-label">URL CSV</span>
            <input class="cfg-input" id="sf-url" placeholder="https://www.stockfirmati.com/export/..." />
            <button class="btn btn-ghost" onclick="GH.sfSaveSettings()" style="font-size:10px">Salva</button>
            <button class="btn btn-primary" id="btn-sf-fetch" onclick="GH.sfFetch()"><span class="spin" id="sf-fetch-spin" style="display:none"></span> Fetch</button>
        </div>
        <div class="cfg-row" id="sf-source-file-row" style="display:none">
            <span class="cfg-label">File</span>
            <div class="drop-area" id="sf-drop" style="flex:1;min-height:40px;padding:8px" onclick="document.getElementById('sf-file-input').click()">
                <input type="file" id="sf-file-input" accept=".csv,.tsv,.txt" style="display:none" />
                <div class="drop-area-text" style="font-size:11px">Clicca o trascina il CSV StockFirmati</div>
                <div class="drop-area-file" id="sf-file-name" style="font-size:11px"></div>
            </div>
        </div>
        <div class="cfg-row">
            <span class="cfg-label">Ricarico</span>
            <input class="cfg-input" id="sf-markup" type="number" step="0.1" min="1" value="3.5" style="max-width:80px" />
            <span style="font-family:var(--mono);font-size:10px;color:var(--dim)">&times; costo ingrosso = prezzo vendita</span>
            <div class="filter-sep"></div>
            <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px"><input type="checkbox" id="sf-opt-images" /> Sideload immagini</label>
        </div>
    </div>
    <div class="stats-bar" id="sf-stats" style="display:none">
        <div class="stat">Righe CSV: <span class="blue" id="sf-csv-rows">0</span></div>
        <div class="stat">Prodotti: <span class="blue" id="sf-total">0</span></div>
        <div class="stat">Nuovi: <span class="blue" id="sf-new">0</span></div>
        <div class="stat">Aggiornare: <span class="amber" id="sf-update">0</span></div>
        <div class="stat">Invariati: <span class="green" id="sf-unchanged">0</span></div>
    </div>
    <!-- Shared product list toolbar: search + quick filters -->
    <div class="gs-sel-bar" id="sf-sel-bar" style="display:none">
        <input class="cfg-input" id="sf-search" placeholder="Cerca nome, SKU, brand..." style="max-width:250px;font-size:11px" oninput="GH.sfFilterList()" />
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.sfSelectAll()">Tutti</button>
        <button class="btn btn-ghost" onclick="GH.sfSelectNone()">Nessuno</button>
        <button class="btn btn-ghost" onclick="GH.sfSelectByType('new')">Solo nuovi</button>
        <button class="btn btn-ghost" onclick="GH.sfSelectByType('update')">Solo aggiorn.</button>
        <span class="gs-sel-count" id="sf-sel-count"></span>
    </div>
    <div class="preview-wrap" id="sf-preview"><div class="empty-state"><div class="empty-icon">&#9783;</div><div class="empty-text">Configura l'endpoint StockFirmati (URL o file CSV)</div></div></div>
    <div class="confirm-bar" id="sf-confirm" style="display:none">
        <div class="summary-text" id="sf-confirm-text"></div>
        <button class="btn btn-ghost" onclick="GH.sfCancel()">Annulla</button>
        <button class="btn btn-warn" id="btn-sf-apply" onclick="GH.sfApply()"><span class="spin" id="sf-apply-spin" style="display:none"></span> Importa</button>
    </div>
    <div class="gen-overlay" id="sf-overlay"><div class="gen-spinner"></div><div class="gen-text" id="sf-overlay-text">Fetch...</div></div>
</div>

<!-- ═══ CSV FEED ═══ -->
<div class="panel" id="panel-csvfeed" style="position:relative">
    <!-- Feed list / config view -->
    <div id="csv-list-view">
        <div class="toolbar">
            <button class="btn btn-primary" id="btn-csv-new" onclick="GH.csvNewFeed()">+ Nuovo Feed CSV</button>
            <div class="filter-sep"></div>
            <button class="btn btn-ghost" id="btn-csv-refresh" onclick="GH.csvLoadFeeds()">Aggiorna lista</button>
        </div>
        <div class="preview-wrap" id="csv-feed-list">
            <div class="empty-state"><div class="empty-icon">&#9783;</div><div class="empty-text">Nessun feed CSV configurato</div></div>
        </div>
    </div>

    <!-- Feed editor (hidden by default) -->
    <div id="csv-edit-view" style="display:none">
        <div class="toolbar">
            <button class="btn btn-ghost" onclick="GH.csvBackToList()">&larr; Torna alla lista</button>
            <div class="filter-sep"></div>
            <span class="filter-label" id="csv-edit-title" style="font-weight:500"></span>
        </div>
        <div class="config-form" style="padding:16px">
            <div class="cfg-row"><span class="cfg-label">Nome</span><input class="cfg-input" id="csv-name" placeholder="Es: Supplier X Products" /></div>
            <div class="cfg-row">
                <span class="cfg-label">Sorgente</span>
                <select class="cfg-select" id="csv-source-type" onchange="GH.csvToggleSource()">
                    <option value="url">URL remoto</option>
                    <option value="file">File caricato</option>
                </select>
            </div>
            <div class="cfg-row" id="csv-source-url-row">
                <span class="cfg-label">URL</span>
                <input class="cfg-input" id="csv-source-url" placeholder="https://example.com/products.csv" />
                <button class="btn btn-ghost" onclick="GH.csvTestUrl()"><span class="spin" id="csv-test-spin" style="display:none"></span> Test</button>
            </div>
            <div class="cfg-row" id="csv-source-file-row" style="display:none">
                <span class="cfg-label">File</span>
                <div class="drop-area" id="csv-drop" style="flex:1;min-height:40px;padding:8px" onclick="document.getElementById('csv-file-input').click()">
                    <input type="file" id="csv-file-input" accept=".csv,.tsv,.txt" style="display:none" />
                    <div class="drop-area-text" style="font-size:11px">Clicca o trascina un file .csv</div>
                    <div class="drop-area-file" id="csv-file-name" style="font-size:11px"></div>
                </div>
            </div>
            <div class="cfg-row">
                <span class="cfg-label">Mapping</span>
                <select class="cfg-select" id="csv-mapping-mode" onchange="GH.csvToggleMapping()">
                    <option value="auto">Auto-detect (da colonne CSV)</option>
                    <option value="preset">Preset (configurazione pronta)</option>
                    <option value="rule">Regola mapper (manuale)</option>
                </select>
            </div>
            <div class="cfg-row" id="csv-preset-row" style="display:none">
                <span class="cfg-label">Preset</span>
                <select class="cfg-select" id="csv-preset" style="flex:1" onchange="GH.csvOnPresetChange()">
                    <option value="">-- Seleziona preset --</option>
                </select>
                <span id="csv-preset-desc" style="font-family:var(--mono);font-size:10px;color:var(--dim);max-width:300px"></span>
            </div>
            <div class="cfg-row" id="csv-rule-row" style="display:none">
                <span class="cfg-label">Regola</span>
                <select class="cfg-select" id="csv-mapping-rule" style="flex:1">
                    <option value="">-- Seleziona regola --</option>
                </select>
            </div>
            <!-- Mapping preview (auto-filled after Test/Upload) -->
            <div id="csv-mapping-preview" style="display:none;padding:8px 0">
                <div style="font-family:var(--mono);font-size:11px;color:var(--acc);margin-bottom:6px">Mapping rilevato:</div>
                <div id="csv-mapping-preview-list" style="font-family:var(--mono);font-size:10px;color:var(--dim)"></div>
            </div>
            <div class="cfg-row">
                <span class="cfg-label">Frequenza</span>
                <select class="cfg-select" id="csv-schedule">
                    <option value="manual">Manuale</option>
                    <option value="hourly">Ogni ora</option>
                    <option value="twicedaily">2 volte/giorno</option>
                    <option value="daily">Giornaliero</option>
                </select>
            </div>
            <div class="cfg-row">
                <label style="font-family:var(--mono);font-size:11px;color:var(--dim);display:flex;align-items:center;gap:6px"><input type="checkbox" id="csv-opt-create" checked /> Crea nuovi prodotti</label>
                <label style="font-family:var(--mono);font-size:11px;color:var(--dim);display:flex;align-items:center;gap:6px"><input type="checkbox" id="csv-opt-update" checked /> Aggiorna esistenti (by SKU)</label>
            </div>
            <div class="cfg-row" style="gap:8px">
                <button class="btn btn-primary" id="btn-csv-save" onclick="GH.csvSaveFeed()"><span class="spin" id="csv-save-spin" style="display:none"></span> Salva Feed</button>
                <button class="btn btn-ghost" id="btn-csv-preview" onclick="GH.csvPreview()" style="display:none"><span class="spin" id="csv-preview-spin" style="display:none"></span> Preview</button>
                <button class="btn btn-warn" id="btn-csv-run" onclick="GH.csvRunFeed()" style="display:none"><span class="spin" id="csv-run-spin" style="display:none"></span> Importa ora</button>
                <button class="btn btn-ghost" id="btn-csv-delete" onclick="GH.csvDeleteFeed()" style="display:none;margin-left:auto;color:var(--red)">Elimina</button>
            </div>
        </div>

        <!-- Source preview (columns/sample) -->
        <div id="csv-source-preview" style="display:none;padding:0 16px">
            <div class="section-title" style="margin:0 0 8px">Colonne CSV rilevate</div>
            <div id="csv-columns-list" style="font-family:var(--mono);font-size:11px;color:var(--dim);margin-bottom:12px"></div>
            <div id="csv-sample-table" class="preview-wrap" style="max-height:200px;overflow:auto"></div>
        </div>

        <!-- Diff / results -->
        <div id="csv-results-area" style="padding:0 16px"></div>
    </div>

    <div class="gen-overlay" id="csv-overlay"><div class="gen-spinner"></div><div class="gen-text" id="csv-overlay-text">Elaborazione...</div></div>
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

<!-- ═══ SCHEDULER ═══ -->
<div class="panel" id="panel-scheduler" style="position:relative">
    <div class="toolbar">
        <button class="btn btn-primary" id="btn-sched-new" onclick="GH.schedNewTask()">+ Nuovo task</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.schedLoad()">Aggiorna</button>
    </div>
    <div class="preview-wrap" id="sched-task-list">
        <div class="empty-state"><div class="empty-icon">&#9202;</div><div class="empty-text">Nessun task schedulato.<br>Crea un task per importare automaticamente da feed esterni.</div></div>
    </div>

    <!-- Task editor modal (inline) -->
    <div id="sched-editor" style="display:none;padding:16px;border-top:1px solid var(--brd)">
        <div style="font-family:var(--mono);font-size:12px;font-weight:500;margin-bottom:12px" id="sched-editor-title">Nuovo task</div>
        <div class="config-form">
            <div class="cfg-row"><span class="cfg-label">Nome</span><input class="cfg-input" id="sched-name" placeholder="Es: Import StockFirmati giornaliero" /></div>
            <div class="cfg-row">
                <span class="cfg-label">Tipo feed</span>
                <select class="cfg-select" id="sched-feed-type" onchange="GH.schedToggleFeedType()">
                    <option value="config">Config file (JSON)</option>
                    <option value="csv_feed">CSV Feed (pipeline generica)</option>
                </select>
            </div>
            <div class="cfg-row" id="sched-config-row">
                <span class="cfg-label">Config</span>
                <select class="cfg-select" id="sched-config-id" style="flex:1"><option value="">-- Seleziona --</option></select>
            </div>
            <div class="cfg-row" id="sched-csv-row" style="display:none">
                <span class="cfg-label">CSV Feed</span>
                <select class="cfg-select" id="sched-csv-feed-id" style="flex:1"><option value="">-- Seleziona --</option></select>
            </div>
            <div class="cfg-row" id="sched-source-row">
                <span class="cfg-label">URL sorgente</span>
                <input class="cfg-input" id="sched-source-url" placeholder="https://..." />
            </div>
            <div class="cfg-row">
                <span class="cfg-label">Frequenza</span>
                <select class="cfg-select" id="sched-schedule">
                    <option value="manual">Manuale</option>
                    <option value="hourly">Ogni ora</option>
                    <option value="twicedaily">2 volte/giorno</option>
                    <option value="daily" selected>Giornaliero</option>
                </select>
            </div>
            <div class="cfg-row">
                <label style="font-family:var(--mono);font-size:11px;color:var(--dim);display:flex;align-items:center;gap:6px"><input type="checkbox" id="sched-opt-create" checked /> Crea nuovi</label>
                <label style="font-family:var(--mono);font-size:11px;color:var(--dim);display:flex;align-items:center;gap:6px"><input type="checkbox" id="sched-opt-update" checked /> Aggiorna esistenti</label>
                <label style="font-family:var(--mono);font-size:11px;color:var(--dim);display:flex;align-items:center;gap:6px"><input type="checkbox" id="sched-opt-images" /> Sideload immagini</label>
            </div>
            <div class="cfg-row" style="gap:8px">
                <button class="btn btn-primary" onclick="GH.schedSaveTask()"><span class="spin" id="sched-save-spin" style="display:none"></span> Salva</button>
                <button class="btn btn-ghost" onclick="GH.schedCancelEdit()">Annulla</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SCHEDULER LOG ═══ -->
<div class="panel" id="panel-sched-log">
    <div class="toolbar">
        <button class="btn btn-ghost" onclick="GH.schedLoadLog()">Aggiorna</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.schedClearLog()" style="color:var(--red)">Svuota log</button>
    </div>
    <div class="preview-wrap" id="sched-log-area">
        <div class="empty-state"><div class="empty-icon">&#9776;</div><div class="empty-text">Nessun run registrato</div></div>
    </div>
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
<!--
    La whitelist protegge gli attachment dall'eliminazione nel Safe Cleanup.
    Entries possono essere aggiunte da qui (ID o URL + motivo) oppure via
    click su una card orfana in Safe Cleanup (pattern originale).
-->
<div class="panel" id="panel-whitelist">
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <input class="cfg-input" id="wl-add-id" type="number" placeholder="Attachment ID" style="max-width:140px;font-size:11px" />
        <span class="filter-label" style="color:var(--dim)">oppure</span>
        <input class="cfg-input" id="wl-add-url" placeholder="URL attachment" style="flex:1;min-width:220px;font-size:11px" />
        <input class="cfg-input" id="wl-add-reason" placeholder="Motivo (obbligatorio)" style="flex:1;min-width:180px;font-size:11px" />
        <button class="btn btn-primary" id="btn-wl-add" onclick="GH.whitelistAdd()"><span class="spin" id="wl-add-spin" style="display:none"></span> + Proteggi</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.loadWhitelist()">Aggiorna</button>
    </div>
    <div class="wl-wrap" id="wl-area"><div class="empty-state"><div class="empty-icon">&#9737;</div><div class="empty-text">La whitelist protegge le immagini dall'eliminazione</div></div></div>
</div>
