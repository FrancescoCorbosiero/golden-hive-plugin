<?php
/**
 * Admin page — registrazione menu e render UI.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'RP Catalog Manager',
        'RP Catalog',
        'manage_woocommerce',
        'rp-catalog-manager',
        'rp_cm_render_page',
        'dashicons-category',
        61
    );
} );

function rp_cm_render_page(): void {
    $nonce = wp_create_nonce( 'rp_cm_nonce' );
    $ajax  = admin_url( 'admin-ajax.php' );
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
#rpcm { all: initial; }
#rpcm *, #rpcm *::before, #rpcm *::after {
    box-sizing: border-box; margin: 0; padding: 0;
    font-family: 'DM Sans', system-ui, sans-serif;
}
#rpcm {
    --bg:  #0c0d10; --s1: #111317; --s2: #16181d; --s3: #1c1f26;
    --b1:  #232630; --b2: #2e3240;
    --acc: #3d7fff; --grn: #22c78b; --red: #e85d5d; --amb: #e8a824; --pur: #9b72f5;
    --txt: #d8dce8; --dim: #5f6480; --mut: #2a2d3a;
    --mono: 'JetBrains Mono', 'Courier New', monospace;
    display: flex; flex-direction: column; height: 100vh;
    background: var(--bg); color: var(--txt); font-size: 13px;
    margin: -10px -20px -20px -20px; overflow: hidden;
}

/* Header */
#rpcm .header {
    background: var(--s1); border-bottom: 1px solid var(--b1);
    padding: 10px 20px; display: flex; align-items: center; gap: 16px;
    flex-shrink: 0;
}
#rpcm .header-logo {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: .2em; color: var(--acc); text-transform: uppercase; white-space: nowrap;
}
#rpcm .header-desc {
    font-size: 11px; color: var(--dim); font-family: var(--mono);
}

/* Layout */
#rpcm .main { flex: 1; display: flex; overflow: hidden; }
#rpcm .tabs-col {
    width: 160px; background: var(--s1); border-right: 1px solid var(--b1);
    display: flex; flex-direction: column; flex-shrink: 0;
}
#rpcm .tab-item {
    padding: 14px 16px; cursor: pointer; border-left: 2px solid transparent;
    border-bottom: 1px solid var(--b1); transition: all .15s;
    display: flex; align-items: center; gap: 10px;
}
#rpcm .tab-item:hover { background: var(--s2); }
#rpcm .tab-item.active { background: var(--s3); border-left-color: var(--acc); }
#rpcm .tab-icon { font-size: 14px; width: 18px; text-align: center; }
#rpcm .tab-label { font-size: 12px; font-weight: 500; color: var(--dim); }
#rpcm .tab-item.active .tab-label { color: var(--txt); }

#rpcm .content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
#rpcm .panel { display: none; flex-direction: column; flex: 1; overflow: hidden; }
#rpcm .panel.active { display: flex; }

/* Buttons */
#rpcm .btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border: 1px solid transparent; border-radius: 4px;
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: .06em; cursor: pointer; transition: all .15s; white-space: nowrap;
}
#rpcm .btn:disabled { opacity: .3; cursor: not-allowed; }
#rpcm .btn-primary { background: var(--acc); color: #fff; border-color: var(--acc); }
#rpcm .btn-primary:hover:not(:disabled) { filter: brightness(1.15); }
#rpcm .btn-ghost { background: transparent; color: var(--dim); border-color: var(--b2); }
#rpcm .btn-ghost:hover:not(:disabled) { color: var(--txt); background: var(--s3); }

/* Summary cards */
#rpcm .summary-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 12px; padding: 20px;
}
#rpcm .summary-card {
    background: var(--s2); border: 1px solid var(--b1); border-radius: 8px;
    padding: 16px; display: flex; flex-direction: column; gap: 6px;
}
#rpcm .sc-label {
    font-family: var(--mono); font-size: 9px; letter-spacing: .12em;
    text-transform: uppercase; color: var(--dim);
}
#rpcm .sc-value {
    font-family: var(--mono); font-size: 22px; font-weight: 600; color: var(--txt);
}
#rpcm .sc-value.green { color: var(--grn); }
#rpcm .sc-value.blue  { color: var(--acc); }
#rpcm .sc-value.amber { color: var(--amb); }
#rpcm .sc-value.purple { color: var(--pur); }

/* Toolbar */
#rpcm .toolbar {
    background: var(--s2); border-bottom: 1px solid var(--b1);
    padding: 10px 20px; display: flex; align-items: center; gap: 12px;
    flex-shrink: 0; flex-wrap: wrap;
}
#rpcm .filter-group { display: flex; align-items: center; gap: 6px; }
#rpcm .filter-label {
    font-family: var(--mono); font-size: 9px; letter-spacing: .1em;
    color: var(--dim); text-transform: uppercase; white-space: nowrap;
}
#rpcm .filter-select, #rpcm .filter-toggle {
    background: var(--s3); border: 1px solid var(--b1); border-radius: 4px;
    padding: 5px 8px; font-family: var(--mono); font-size: 11px;
    color: var(--txt); outline: none; cursor: pointer;
}
#rpcm .filter-select:focus { border-color: var(--acc); }
#rpcm .filter-sep { width: 1px; height: 20px; background: var(--b1); flex-shrink: 0; }
#rpcm .filter-toggle { display: flex; align-items: center; gap: 4px; }
#rpcm .filter-toggle input { accent-color: var(--acc); cursor: pointer; }

/* JSON viewer */
#rpcm .json-area {
    flex: 1; overflow-y: auto; padding: 16px 20px;
    font-family: var(--mono); font-size: 11px; line-height: 1.7;
    white-space: pre-wrap; word-break: break-all;
}
#rpcm .jk { color: #a78bfa; }
#rpcm .js { color: var(--grn); }
#rpcm .jn { color: var(--amb); }
#rpcm .jb { color: var(--acc); }
#rpcm .jx { color: var(--red); }

/* JSON toolbar */
#rpcm .json-toolbar {
    background: var(--s1); border-top: 1px solid var(--b1);
    padding: 8px 20px; display: flex; align-items: center; gap: 10px;
    flex-shrink: 0;
}
#rpcm .file-size {
    font-family: var(--mono); font-size: 10px; color: var(--dim); margin-left: auto;
}

/* Empty state */
#rpcm .empty-state {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 12px; color: var(--dim);
}
#rpcm .empty-icon { font-size: 32px; }
#rpcm .empty-text { font-family: var(--mono); font-size: 12px; letter-spacing: .08em; text-align: center; }

/* Toast */
#rpcm .toast-wrap {
    position: fixed; bottom: 20px; right: 20px;
    display: flex; flex-direction: column; gap: 6px; z-index: 9999; pointer-events: none;
}
#rpcm .toast {
    font-family: var(--mono); font-size: 11px; padding: 9px 14px;
    border-radius: 5px; border: 1px solid; pointer-events: none;
    max-width: 360px; animation: rpcm-tin .18s ease;
}
@keyframes rpcm-tin { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
#rpcm .toast.ok  { background: rgba(34,199,139,.15); border-color: rgba(34,199,139,.4); color: var(--grn); }
#rpcm .toast.err { background: rgba(232,93,93,.15);  border-color: rgba(232,93,93,.4);  color: var(--red); }
#rpcm .toast.inf { background: rgba(61,127,255,.15); border-color: rgba(61,127,255,.4); color: var(--acc); }

/* Spinner */
#rpcm .spin {
    display: inline-block; width: 9px; height: 9px;
    border: 1.5px solid var(--b2); border-top-color: var(--acc);
    border-radius: 50%; animation: rpcm-sp .5s linear infinite;
}
@keyframes rpcm-sp { to { transform: rotate(360deg); } }

/* Generating overlay */
#rpcm .gen-overlay {
    display: none; position: absolute; inset: 0;
    background: rgba(12,13,16,.85); z-index: 50;
    align-items: center; justify-content: center; flex-direction: column; gap: 12px;
}
#rpcm .gen-overlay.visible { display: flex; }
#rpcm .gen-text { font-family: var(--mono); font-size: 12px; color: var(--dim); }
#rpcm .gen-spinner {
    width: 24px; height: 24px;
    border: 2px solid var(--b2); border-top-color: var(--acc);
    border-radius: 50%; animation: rpcm-sp .6s linear infinite;
}

/* Scrollbar */
#rpcm *::-webkit-scrollbar { width: 4px; height: 4px; }
#rpcm *::-webkit-scrollbar-thumb { background: var(--b2); border-radius: 2px; }
#rpcm * { scrollbar-width: thin; scrollbar-color: var(--b2) transparent; }

/* Section divider */
#rpcm .section-title {
    font-family: var(--mono); font-size: 9px; letter-spacing: .15em;
    text-transform: uppercase; color: var(--dim); padding: 12px 20px 6px;
    border-bottom: 1px solid var(--b1); flex-shrink: 0;
}

/* Import file drop area */
#rpcm .drop-area {
    border: 2px dashed var(--b2); border-radius: 8px; padding: 24px;
    text-align: center; cursor: pointer; transition: all .15s;
    margin: 16px 20px; flex-shrink: 0;
}
#rpcm .drop-area:hover, #rpcm .drop-area.dragover {
    border-color: var(--acc); background: rgba(61,127,255,.05);
}
#rpcm .drop-area-text { font-family: var(--mono); font-size: 11px; color: var(--dim); }
#rpcm .drop-area-file { font-family: var(--mono); font-size: 12px; color: var(--grn); margin-top: 6px; }

/* Import mode selector */
#rpcm .mode-row {
    display: flex; align-items: center; gap: 16px; padding: 8px 20px;
    flex-shrink: 0;
}
#rpcm .mode-row label {
    font-family: var(--mono); font-size: 11px; color: var(--txt);
    display: flex; align-items: center; gap: 4px; cursor: pointer;
}
#rpcm .mode-row input[type="radio"] { accent-color: var(--acc); }

/* Preview table */
#rpcm .preview-wrap { flex: 1; overflow-y: auto; padding: 0 20px 20px; }
#rpcm table.ptable { width: 100%; border-collapse: collapse; font-size: 11px; }
#rpcm .ptable thead th {
    background: var(--s2); border-bottom: 2px solid var(--b1); padding: 8px 10px;
    font-family: var(--mono); font-size: 9px; letter-spacing: .1em;
    text-transform: uppercase; color: var(--dim); text-align: left;
    font-weight: 600; position: sticky; top: 0; z-index: 10;
}
#rpcm .ptable tbody tr { border-bottom: 1px solid var(--b1); }
#rpcm .ptable tbody tr:hover { background: rgba(255,255,255,.02); }
#rpcm .ptable td { padding: 6px 10px; font-family: var(--mono); font-size: 11px; vertical-align: top; }
#rpcm .ptable .st-matched { color: var(--grn); }
#rpcm .ptable .st-skipped { color: var(--dim); }
#rpcm .ptable .st-create  { color: var(--acc); }
#rpcm .ptable .st-updated { color: var(--grn); }
#rpcm .ptable .st-error   { color: var(--red); }
#rpcm .ptable .changes-list { font-size: 10px; color: var(--amb); }
#rpcm .ptable .change-detail { font-size: 9px; color: var(--dim); margin-top: 2px; }
#rpcm .ptable .old-val { color: var(--red); text-decoration: line-through; }
#rpcm .ptable .new-val { color: var(--grn); }

/* Confirm bar */
#rpcm .confirm-bar {
    background: var(--s1); border-top: 1px solid var(--b1);
    padding: 10px 20px; display: flex; align-items: center; gap: 12px;
    flex-shrink: 0;
}
#rpcm .confirm-bar .summary-text {
    font-family: var(--mono); font-size: 11px; color: var(--txt); flex: 1;
}
#rpcm .confirm-bar .summary-text span { font-weight: 600; }
#rpcm .btn-warn {
    background: rgba(232,168,36,.15); color: var(--amb); border-color: rgba(232,168,36,.4);
}
#rpcm .btn-warn:hover:not(:disabled) { background: rgba(232,168,36,.25); }

/* Responsive */
@media (max-width: 768px) {
    #rpcm .tabs-col { width: 48px; }
    #rpcm .tab-label { display: none; }
    #rpcm .tab-item { justify-content: center; padding: 14px 8px; }
    #rpcm .summary-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div id="rpcm">
    <div class="header">
        <div class="header-logo">RP &middot; Catalog</div>
        <div class="header-desc">Catalogo strutturato WooCommerce</div>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-item active" onclick="RPCM.switchTab('overview', this)">
                <span class="tab-icon">&#9673;</span>
                <span class="tab-label">Overview</span>
            </div>
            <div class="tab-item" onclick="RPCM.switchTab('catalog', this)">
                <span class="tab-icon">&#9776;</span>
                <span class="tab-label">Catalog</span>
            </div>
            <div class="tab-item" onclick="RPCM.switchTab('roundtrip', this)">
                <span class="tab-icon">&#8644;</span>
                <span class="tab-label">Roundtrip</span>
            </div>
        </div>

        <div class="content">
            <!-- OVERVIEW -->
            <div class="panel active" id="panel-overview">
                <div style="padding:20px">
                    <button class="btn btn-primary" id="btn-summary" onclick="RPCM.loadSummary()">
                        <span class="spin" id="sum-spin" style="display:none"></span>
                        Genera Overview
                    </button>
                </div>
                <div id="summary-container">
                    <div class="empty-state">
                        <div class="empty-icon">&#9673;</div>
                        <div class="empty-text">Premi "Genera Overview" per una panoramica rapida del catalogo</div>
                    </div>
                </div>
            </div>

            <!-- CATALOG -->
            <div class="panel" id="panel-catalog" style="position:relative">
                <div class="toolbar">
                    <div class="filter-group">
                        <span class="filter-label">Stato</span>
                        <select class="filter-select" id="cat-filter-status">
                            <option value="publish">Pubblicati</option>
                            <option value="draft">Bozze</option>
                            <option value="any">Tutti</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label">Brand</span>
                        <select class="filter-select" id="cat-filter-brand">
                            <option value="">Tutti</option>
                        </select>
                    </div>
                    <label class="filter-toggle">
                        <input type="checkbox" id="cat-filter-stock" />
                        <span class="filter-label" style="letter-spacing:0">Solo in stock</span>
                    </label>
                    <div class="filter-sep"></div>
                    <button class="btn btn-primary" id="btn-catalog" onclick="RPCM.generateCatalog()">
                        <span class="spin" id="cat-spin" style="display:none"></span>
                        Genera Catalog
                    </button>
                </div>
                <div class="json-area" id="catalog-viewer">
                    <div class="empty-state">
                        <div class="empty-icon">&#9776;</div>
                        <div class="empty-text">Imposta i filtri e premi "Genera Catalog" per generare il JSON aggregato</div>
                    </div>
                </div>
                <div class="json-toolbar" id="catalog-toolbar" style="display:none">
                    <button class="btn btn-ghost" onclick="RPCM.copyJSON('catalog')">&#9112; Copia JSON</button>
                    <button class="btn btn-ghost" onclick="RPCM.downloadJSON('catalog')">&#8681; Download .json</button>
                    <span class="file-size" id="catalog-size"></span>
                </div>
                <div class="gen-overlay" id="cat-overlay">
                    <div class="gen-spinner"></div>
                    <div class="gen-text">Generazione catalogo in corso...</div>
                </div>
            </div>

            <!-- ROUNDTRIP -->
            <div class="panel" id="panel-roundtrip" style="position:relative">
                <!-- Export section -->
                <div class="section-title">Export snapshot</div>
                <div class="toolbar">
                    <div class="filter-group">
                        <span class="filter-label">Stato</span>
                        <select class="filter-select" id="rt-filter-status">
                            <option value="any" selected>Tutti</option>
                            <option value="publish">Pubblicati</option>
                            <option value="draft">Bozze</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <span class="filter-label">Brand</span>
                        <select class="filter-select" id="rt-filter-brand">
                            <option value="">Tutti</option>
                        </select>
                    </div>
                    <label class="filter-toggle">
                        <input type="checkbox" id="rt-filter-stock" />
                        <span class="filter-label" style="letter-spacing:0">Solo in stock</span>
                    </label>
                    <div class="filter-sep"></div>
                    <button class="btn btn-primary" id="btn-rt-export" onclick="RPCM.generateRoundtrip()">
                        <span class="spin" id="rt-spin" style="display:none"></span>
                        Genera Export
                    </button>
                    <button class="btn btn-ghost" id="btn-rt-copy" onclick="RPCM.copyJSON('roundtrip')" style="display:none">&#9112; Copia</button>
                    <button class="btn btn-ghost" id="btn-rt-download" onclick="RPCM.downloadJSON('roundtrip')" style="display:none">&#8681; Download .json</button>
                    <span class="file-size" id="rt-size"></span>
                </div>

                <!-- Import section -->
                <div class="section-title">Import JSON</div>
                <div class="drop-area" id="rt-drop" onclick="document.getElementById('rt-file-input').click()">
                    <input type="file" id="rt-file-input" accept=".json" style="display:none" />
                    <div class="drop-area-text">Clicca o trascina un file .json esportato</div>
                    <div class="drop-area-file" id="rt-file-name"></div>
                </div>
                <div class="mode-row" id="rt-mode-row" style="display:none">
                    <label><input type="radio" name="import-mode" value="update_only" checked /> Solo aggiornamento</label>
                    <label><input type="radio" name="import-mode" value="create_if_missing" /> Crea se non esiste</label>
                    <div class="filter-sep"></div>
                    <button class="btn btn-primary" id="btn-rt-preview" onclick="RPCM.importPreview()">
                        <span class="spin" id="preview-spin" style="display:none"></span>
                        Preview modifiche
                    </button>
                </div>

                <!-- Preview results -->
                <div class="preview-wrap" id="rt-preview-area"></div>

                <!-- Confirm bar (shown after preview) -->
                <div class="confirm-bar" id="rt-confirm-bar" style="display:none">
                    <div class="summary-text" id="rt-confirm-text"></div>
                    <button class="btn btn-ghost" onclick="RPCM.importCancel()">Annulla</button>
                    <button class="btn btn-warn" id="btn-rt-apply" onclick="RPCM.importApply()">
                        <span class="spin" id="apply-spin" style="display:none"></span>
                        Applica modifiche
                    </button>
                </div>

                <div class="gen-overlay" id="rt-overlay">
                    <div class="gen-spinner"></div>
                    <div class="gen-text" id="rt-overlay-text">Generazione in corso...</div>
                </div>
            </div>
        </div>
    </div>

    <div id="rpcm-toasts" class="toast-wrap"></div>
</div>

<script>
const RPCM = (function() {
    const AJAX  = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';

    let state = {
        catalogData:   null,
        roundtripData: null,
        summaryData:   null,
        importJSON:    null,
        previewData:   null,
    };

    // ── AJAX helper ─────────────────────────────────────────
    async function ajax(action, body = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        Object.entries(body).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(AJAX, { method: 'POST', body: fd });
        return r.json();
    }

    // ── Toast ───────────────────────────────────────────────
    function toast(msg, type = 'ok', ms = 3000) {
        const wrap = document.getElementById('rpcm-toasts');
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(() => t.remove(), ms);
    }

    // ── JSON syntax highlight ───────────────────────────────
    function hl(json) {
        return String(json)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, m => {
                let c = 'jn';
                if (/^"/.test(m)) c = /:$/.test(m) ? 'jk' : 'js';
                else if (/true|false/.test(m)) c = 'jb';
                else if (/null/.test(m)) c = 'jx';
                return '<span class="' + c + '">' + m + '</span>';
            });
    }

    // ── File size formatter ─────────────────────────────────
    function fileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // ── Tab switching ───────────────────────────────────────
    function switchTab(name, el) {
        document.querySelectorAll('#rpcm .tab-item').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('#rpcm .panel').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('panel-' + name).classList.add('active');
    }

    // ── Collect filters ─────────────────────────────────────
    function getFilters(prefix) {
        const filters = {};
        const status = document.getElementById(prefix + '-filter-status').value;
        if (status) filters.status = status;
        const brand = document.getElementById(prefix + '-filter-brand').value;
        if (brand) filters.brand = brand;
        if (document.getElementById(prefix + '-filter-stock').checked) {
            filters.in_stock = true;
        }
        return filters;
    }

    // ── Load brand options into filter dropdowns ────────────
    async function loadFilterOptions() {
        const res = await ajax('rp_cm_ajax_get_tree_paths');
        if (!res.success) return;
        const brands = res.data.brands || [];
        ['cat-filter-brand', 'rt-filter-brand'].forEach(id => {
            const sel = document.getElementById(id);
            brands.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b;
                opt.textContent = b;
                sel.appendChild(opt);
            });
        });
    }

    // ── OVERVIEW ────────────────────────────────────────────
    async function loadSummary() {
        const btn  = document.getElementById('btn-summary');
        const spin = document.getElementById('sum-spin');
        btn.disabled = true;
        spin.style.display = '';
        try {
            const res = await ajax('rp_cm_ajax_get_summary');
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            state.summaryData = res.data;
            renderSummary(res.data);
            toast('Overview generata in ' + res.data.generated_in_seconds + 's', 'ok');
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            btn.disabled = false;
            spin.style.display = 'none';
        }
    }

    function renderSummary(d) {
        const c = document.getElementById('summary-container');
        c.innerHTML = '<div class="summary-grid">' +
            card('Prodotti totali', d.total_products, '') +
            card('In stock', d.total_in_stock, 'green') +
            card('Varianti totali', d.total_variants, 'blue') +
            card('Varianti in stock', d.total_variants_in_stock, 'green') +
            card('Brand', d.brands, 'purple') +
            card('Categorie', d.categories, 'amber') +
            card('Tempo generazione', d.generated_in_seconds + 's', '') +
        '</div>';
    }

    function card(label, value, color) {
        return '<div class="summary-card"><span class="sc-label">' + label + '</span>' +
               '<span class="sc-value' + (color ? ' ' + color : '') + '">' + value + '</span></div>';
    }

    // ── CATALOG EXPORT ──────────────────────────────────────
    async function generateCatalog() {
        const overlay = document.getElementById('cat-overlay');
        const btn     = document.getElementById('btn-catalog');
        const spin    = document.getElementById('cat-spin');
        overlay.classList.add('visible');
        btn.disabled = true;
        spin.style.display = '';
        try {
            const filters = getFilters('cat');
            const res = await ajax('rp_cm_ajax_export_catalog', {
                filters: JSON.stringify(filters)
            });
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            state.catalogData = res.data;
            renderJSONViewer('catalog', res.data);
            toast('Catalogo generato: ' + (res.data.summary?.total_products || 0) + ' prodotti in ' + (res.data.summary?.generated_in_seconds || '?') + 's', 'ok');
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            overlay.classList.remove('visible');
            btn.disabled = false;
            spin.style.display = 'none';
        }
    }

    // ── ROUNDTRIP EXPORT ────────────────────────────────────
    async function generateRoundtrip() {
        const overlay = document.getElementById('rt-overlay');
        const otext   = document.getElementById('rt-overlay-text');
        const btn     = document.getElementById('btn-rt-export');
        const spin    = document.getElementById('rt-spin');
        otext.textContent = 'Generazione export in corso...';
        overlay.classList.add('visible');
        btn.disabled = true;
        spin.style.display = '';
        try {
            const filters = getFilters('rt');
            const res = await ajax('rp_cm_ajax_export_roundtrip', {
                filters: JSON.stringify(filters)
            });
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            state.roundtripData = res.data;
            const json = JSON.stringify(res.data, null, 2);
            document.getElementById('btn-rt-copy').style.display = '';
            document.getElementById('btn-rt-download').style.display = '';
            document.getElementById('rt-size').textContent = fileSize(new Blob([json]).size) + ' \u00b7 ' + res.data.product_count + ' prodotti';
            toast('Export generato: ' + res.data.product_count + ' prodotti in ' + res.data.generated_in_seconds + 's', 'ok');
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            overlay.classList.remove('visible');
            btn.disabled = false;
            spin.style.display = 'none';
        }
    }

    // ── IMPORT: File handling ───────────────────────────────
    function initImport() {
        const drop  = document.getElementById('rt-drop');
        const input = document.getElementById('rt-file-input');

        input.addEventListener('change', () => {
            if (input.files.length) handleImportFile(input.files[0]);
        });

        drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => { drop.classList.remove('dragover'); });
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('dragover');
            if (e.dataTransfer.files.length) handleImportFile(e.dataTransfer.files[0]);
        });
    }

    function handleImportFile(file) {
        if (!file.name.endsWith('.json')) { toast('Solo file .json', 'err'); return; }
        const reader = new FileReader();
        reader.onload = () => {
            try {
                const data = JSON.parse(reader.result);
                if (data.format !== 'rp_cm_roundtrip') {
                    toast('Formato non valido: atteso rp_cm_roundtrip', 'err'); return;
                }
                if (data.version !== 1) {
                    toast('Versione non supportata: ' + data.version, 'err'); return;
                }
                state.importJSON = data;
                state.previewData = null;
                document.getElementById('rt-file-name').textContent = file.name + ' \u00b7 ' + (data.product_count || data.products?.length || 0) + ' prodotti';
                document.getElementById('rt-mode-row').style.display = 'flex';
                document.getElementById('rt-preview-area').innerHTML = '';
                document.getElementById('rt-confirm-bar').style.display = 'none';
                toast('File caricato: ' + (data.product_count || data.products?.length || 0) + ' prodotti', 'inf');

                // Warn if different site
                if (data.site_url && !window.location.href.includes(new URL(data.site_url).hostname)) {
                    toast('Attenzione: export generato su ' + data.site_url, 'err', 5000);
                }
            } catch (e) {
                toast('JSON non valido: ' + e.message, 'err');
            }
        };
        reader.readAsText(file);
    }

    // ── IMPORT: Preview ─────────────────────────────────────
    async function importPreview() {
        if (!state.importJSON) { toast('Nessun file caricato', 'err'); return; }
        const btn  = document.getElementById('btn-rt-preview');
        const spin = document.getElementById('preview-spin');
        btn.disabled = true;
        spin.style.display = '';
        try {
            const mode = document.querySelector('input[name="import-mode"]:checked').value;
            const res = await ajax('rp_cm_ajax_import_preview', {
                json_payload: JSON.stringify(state.importJSON),
                mode: mode
            });
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            state.previewData = res.data;
            renderPreview(res.data);
            toast('Preview: ' + res.data.summary.matched + ' matched, ' + res.data.summary.with_changes + ' con modifiche', 'inf');
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            btn.disabled = false;
            spin.style.display = 'none';
        }
    }

    function renderPreview(data) {
        const area = document.getElementById('rt-preview-area');
        const s    = data.summary;

        let html = '<table class="ptable"><thead><tr>' +
            '<th>Stato</th><th>ID</th><th>SKU</th><th>Nome</th><th>Modifiche prodotto</th><th>Varianti</th>' +
            '</tr></thead><tbody>';

        for (const d of data.details) {
            const stClass = d.status === 'matched' ? 'st-matched'
                          : d.status === 'would_create' ? 'st-create' : 'st-skipped';
            const stLabel = d.status === 'matched' ? (d.changes.length ? '\u2713 modifiche' : '\u2713 invariato')
                          : d.status === 'would_create' ? '+ nuovo' : '\u2013 skip';

            let changesHtml = '';
            if (d.changes.length) {
                changesHtml = '<div class="changes-list">' + d.changes.map(c => {
                    const oldStr = truncVal(c.old);
                    const newStr = truncVal(c.new);
                    return '<div>' + esc(c.field) + ': <span class="old-val">' + esc(oldStr) + '</span> \u2192 <span class="new-val">' + esc(newStr) + '</span></div>';
                }).join('') + '</div>';
            }
            if (d.reason) changesHtml = '<div class="changes-list" style="color:var(--dim)">' + esc(d.reason) + '</div>';

            // Count variation changes
            let varInfo = '';
            if (d.variation_results.length) {
                const vc = d.variation_results.filter(v => v.changes && v.changes.length).length;
                const vs = d.variation_results.filter(v => v.status === 'skipped').length;
                varInfo = vc + ' mod';
                if (vs) varInfo += ', ' + vs + ' skip';
            }

            html += '<tr><td class="' + stClass + '">' + stLabel + '</td>' +
                '<td>' + (d.id || '\u2013') + '</td>' +
                '<td>' + esc(d.sku || '\u2013') + '</td>' +
                '<td>' + esc(d.name || '\u2013') + '</td>' +
                '<td>' + changesHtml + '</td>' +
                '<td>' + varInfo + '</td></tr>';
        }

        html += '</tbody></table>';
        area.innerHTML = html;

        // Show confirm bar
        const bar = document.getElementById('rt-confirm-bar');
        const txt = document.getElementById('rt-confirm-text');
        const hasChanges = s.with_changes > 0 || s.would_create > 0 || s.variations_with_changes > 0;
        if (hasChanges) {
            let msg = '<span>' + s.with_changes + '</span> prodotti da aggiornare';
            if (s.variations_with_changes) msg += ', <span>' + s.variations_with_changes + '</span> varianti';
            if (s.would_create) msg += ', <span>' + s.would_create + '</span> da creare';
            if (s.skipped) msg += ' \u00b7 ' + s.skipped + ' saltati';
            txt.innerHTML = msg;
            bar.style.display = 'flex';
        } else {
            bar.style.display = 'none';
            toast('Nessuna modifica rilevata', 'inf');
        }
    }

    function truncVal(v) {
        if (v === null || v === undefined) return 'null';
        if (Array.isArray(v)) return '[' + v.join(', ') + ']';
        const s = String(v);
        return s.length > 60 ? s.slice(0, 57) + '...' : s;
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ── IMPORT: Apply ───────────────────────────────────────
    async function importApply() {
        if (!state.importJSON) { toast('Nessun file caricato', 'err'); return; }
        const overlay = document.getElementById('rt-overlay');
        const otext   = document.getElementById('rt-overlay-text');
        const btn     = document.getElementById('btn-rt-apply');
        const spin    = document.getElementById('apply-spin');
        otext.textContent = 'Applicazione modifiche in corso...';
        overlay.classList.add('visible');
        btn.disabled = true;
        spin.style.display = '';
        try {
            const mode = document.querySelector('input[name="import-mode"]:checked').value;
            const res = await ajax('rp_cm_ajax_import_apply', {
                json_payload: JSON.stringify(state.importJSON),
                mode: mode
            });
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            renderApplyResult(res.data);
            const s = res.data.summary;
            toast('Import completato: ' + s.updated + ' aggiornati, ' + s.variations_updated + ' varianti', 'ok', 5000);
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            overlay.classList.remove('visible');
            btn.disabled = false;
            spin.style.display = 'none';
        }
    }

    function renderApplyResult(data) {
        const area = document.getElementById('rt-preview-area');
        const s    = data.summary;

        let html = '<table class="ptable"><thead><tr>' +
            '<th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th><th>Dettagli</th><th>Varianti</th>' +
            '</tr></thead><tbody>';

        for (const d of data.details) {
            const stClass = d.status === 'updated' ? 'st-updated'
                          : d.status === 'created' ? 'st-create'
                          : d.status === 'error' ? 'st-error' : 'st-skipped';
            const stLabel = d.status === 'updated' ? '\u2713 aggiornato'
                          : d.status === 'created' ? '+ creato'
                          : d.status === 'error' ? '\u2717 errore' : '\u2013 saltato';

            let details = '';
            if (d.changes && d.changes.length) {
                details = '<div class="changes-list">' + d.changes.join(', ') + '</div>';
            }
            if (d.reason) details = '<div class="changes-list" style="color:var(--red)">' + esc(d.reason) + '</div>';

            let varInfo = '';
            if (d.variation_results && d.variation_results.length) {
                const vu = d.variation_results.filter(v => v.status === 'updated').length;
                const ve = d.variation_results.filter(v => v.status === 'error').length;
                varInfo = vu + ' ok';
                if (ve) varInfo += ', ' + ve + ' err';
            }

            html += '<tr><td class="' + stClass + '">' + stLabel + '</td>' +
                '<td>' + (d.id || '\u2013') + '</td>' +
                '<td>' + esc(d.sku || '\u2013') + '</td>' +
                '<td>' + esc(d.name || '\u2013') + '</td>' +
                '<td>' + details + '</td>' +
                '<td>' + varInfo + '</td></tr>';
        }

        html += '</tbody></table>';
        area.innerHTML = html;
        document.getElementById('rt-confirm-bar').style.display = 'none';
    }

    function importCancel() {
        document.getElementById('rt-confirm-bar').style.display = 'none';
        document.getElementById('rt-preview-area').innerHTML = '';
        state.previewData = null;
        toast('Import annullato', 'inf');
    }

    // ── JSON viewer renderer ────────────────────────────────
    function renderJSONViewer(mode, data) {
        const viewer  = document.getElementById(mode + '-viewer');
        const toolbar = document.getElementById(mode + '-toolbar');
        const sizeEl  = document.getElementById(mode + '-size');
        const json    = JSON.stringify(data, null, 2);
        viewer.innerHTML = hl(json);
        toolbar.style.display = 'flex';
        sizeEl.textContent = fileSize(new Blob([json]).size);
    }

    // ── Copy JSON ───────────────────────────────────────────
    function copyJSON(mode) {
        const data = mode === 'catalog' ? state.catalogData : state.roundtripData;
        if (!data) { toast('Nessun dato da copiare', 'err'); return; }
        const json = JSON.stringify(data, null, 2);
        navigator.clipboard.writeText(json).then(
            () => toast('JSON copiato negli appunti', 'ok'),
            () => toast('Errore copia', 'err')
        );
    }

    // ── Download JSON ───────────────────────────────────────
    function downloadJSON(mode) {
        const data = mode === 'catalog' ? state.catalogData : state.roundtripData;
        if (!data) { toast('Nessun dato da scaricare', 'err'); return; }
        const json = JSON.stringify(data, null, 2);
        const blob = new Blob([json], { type: 'application/json' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        const date = new Date().toISOString().slice(0, 10);
        a.href     = url;
        a.download = mode === 'catalog'
            ? 'rp-catalog-' + date + '.json'
            : 'rp-roundtrip-' + date + '.json';
        a.click();
        URL.revokeObjectURL(url);
        toast('Download avviato', 'inf');
    }

    // ── Init ────────────────────────────────────────────────
    loadFilterOptions();
    initImport();

    return {
        switchTab,
        loadSummary,
        generateCatalog,
        generateRoundtrip,
        copyJSON,
        downloadJSON,
        importPreview,
        importApply,
        importCancel,
    };
})();
</script>
<?php
}
