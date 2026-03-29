<?php
/**
 * Admin page — registrazione menu e render UI.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'RP Product Manager',
        'RP Products',
        'manage_woocommerce',
        'rp-product-manager',
        'rp_render_pm',
        'dashicons-sneakers',
        58
    );
} );

function rp_render_pm(): void {
    $nonce = wp_create_nonce( 'rp_crud_nonce' );
    $ajax  = admin_url( 'admin-ajax.php' );
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
#rpm { all: initial; }
#rpm *, #rpm *::before, #rpm *::after {
    box-sizing: border-box; margin: 0; padding: 0;
    font-family: 'DM Sans', system-ui, sans-serif;
}
#rpm {
    --bg:  #0c0d10; --s1: #111317; --s2: #16181d; --s3: #1c1f26;
    --b1:  #232630; --b2: #2e3240;
    --acc: #3d7fff; --grn: #22c78b; --red: #e85d5d; --amb: #e8a824; --pur: #9b72f5;
    --txt: #d8dce8; --dim: #5f6480; --mut: #2a2d3a;
    --mono: 'JetBrains Mono', 'Courier New', monospace;
    display: flex; flex-direction: column; height: 100vh;
    background: var(--bg); color: var(--txt); font-size: 13px;
    margin: -10px -20px -20px -20px; overflow: hidden;
}

/* Search bar */
#rpm .search-bar {
    background: var(--s1); border-bottom: 1px solid var(--b1);
    padding: 10px 20px; display: flex; align-items: center; gap: 12px;
    flex-shrink: 0; position: relative; z-index: 100;
}
#rpm .search-logo {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: .2em; color: var(--acc); text-transform: uppercase; white-space: nowrap;
}
#rpm .search-wrap { flex: 1; position: relative; max-width: 560px; }
#rpm .search-icon {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: var(--dim); font-size: 14px; pointer-events: none;
}
#rpm .search-input {
    width: 100%; background: var(--s3); border: 1px solid var(--b1); border-radius: 6px;
    padding: 8px 12px 8px 34px; font-family: var(--mono); font-size: 13px; color: var(--txt);
    outline: none; transition: border-color .15s, background .15s;
}
#rpm .search-input:focus { border-color: var(--acc); background: var(--s2); }
#rpm .search-input::placeholder { color: var(--dim); }
#rpm .search-dropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--s2); border: 1px solid var(--b2); border-radius: 6px;
    overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,.5); display: none; z-index: 200;
}
#rpm .search-dropdown.open { display: block; }
#rpm .search-result {
    display: flex; align-items: center; gap: 10px; padding: 10px 14px;
    cursor: pointer; border-bottom: 1px solid var(--b1); transition: background .1s;
}
#rpm .search-result:last-child { border-bottom: none; }
#rpm .search-result:hover, #rpm .search-result.focused { background: var(--s3); }
#rpm .sr-id { font-family: var(--mono); font-size: 10px; color: var(--dim); min-width: 36px; }
#rpm .sr-name { flex: 1; font-size: 13px; font-weight: 500; color: var(--txt); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#rpm .sr-sku { font-family: var(--mono); font-size: 10px; color: var(--dim); }
#rpm .sr-type { font-family: var(--mono); font-size: 9px; font-weight: 600; letter-spacing: .08em; padding: 2px 6px; border-radius: 3px; }
#rpm .type-variable { background: rgba(155,114,245,.15); color: var(--pur); }
#rpm .type-simple   { background: rgba(61,127,255,.15);  color: var(--acc); }
#rpm .search-empty { padding: 12px 14px; font-size: 12px; color: var(--dim); font-family: var(--mono); }
#rpm .search-hint { font-family: var(--mono); font-size: 10px; color: var(--dim); white-space: nowrap; }

/* Product card */
#rpm .product-card {
    background: var(--s2); border-bottom: 1px solid var(--b1);
    padding: 10px 20px; display: none; align-items: center; gap: 16px; flex-shrink: 0;
}
#rpm .product-card.visible { display: flex; }
#rpm .pc-id { font-family: var(--mono); font-size: 11px; color: var(--dim); background: var(--mut); padding: 3px 8px; border-radius: 4px; }
#rpm .pc-name { font-size: 14px; font-weight: 600; color: var(--txt); flex: 1; }
#rpm .pc-sku  { font-family: var(--mono); font-size: 11px; color: var(--dim); }
#rpm .pc-price { font-family: var(--mono); font-size: 13px; font-weight: 600; color: var(--grn); }
#rpm .pc-status { font-family: var(--mono); font-size: 9px; font-weight: 600; letter-spacing: .12em; padding: 3px 8px; border-radius: 3px; text-transform: uppercase; }
#rpm .status-publish { background: rgba(34,199,139,.12); color: var(--grn); }
#rpm .status-draft   { background: rgba(95,100,128,.15); color: var(--dim); }
#rpm .status-private { background: rgba(232,168,36,.12); color: var(--amb); }
#rpm .pc-clear { background: transparent; border: 1px solid var(--b1); color: var(--dim); border-radius: 4px; padding: 4px 10px; font-family: var(--mono); font-size: 10px; cursor: pointer; transition: all .15s; }
#rpm .pc-clear:hover { border-color: var(--red); color: var(--red); }

/* Layout */
#rpm .main { flex: 1; display: flex; overflow: hidden; }
#rpm .tabs-col { width: 160px; background: var(--s1); border-right: 1px solid var(--b1); display: flex; flex-direction: column; flex-shrink: 0; }
#rpm .tab-item { padding: 14px 16px; cursor: pointer; border-left: 2px solid transparent; border-bottom: 1px solid var(--b1); transition: all .15s; display: flex; align-items: center; gap: 10px; }
#rpm .tab-item:hover { background: var(--s2); }
#rpm .tab-item.active { background: var(--s3); border-left-color: var(--acc); }
#rpm .tab-icon { font-size: 14px; width: 18px; text-align: center; }
#rpm .tab-label { font-size: 12px; font-weight: 500; color: var(--dim); }
#rpm .tab-item.active .tab-label { color: var(--txt); }
#rpm .tab-count { margin-left: auto; font-family: var(--mono); font-size: 9px; background: var(--mut); color: var(--dim); padding: 1px 5px; border-radius: 3px; }
#rpm .tab-item.active .tab-count { background: rgba(61,127,255,.2); color: var(--acc); }

#rpm .content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
#rpm .panel { display: none; flex-direction: column; flex: 1; overflow: hidden; }
#rpm .panel.active { display: flex; }

/* Bulk bar */
#rpm .bulk-bar {
    background: var(--s2); border-bottom: 1px solid var(--b1);
    padding: 8px 16px; display: flex; align-items: center; gap: 10px;
    flex-shrink: 0; flex-wrap: wrap;
}
#rpm .bulk-group { display: flex; align-items: center; gap: 6px; }
#rpm .bulk-label { font-family: var(--mono); font-size: 9px; letter-spacing: .1em; color: var(--dim); text-transform: uppercase; white-space: nowrap; }
#rpm .bulk-input { width: 80px; background: var(--s3); border: 1px solid var(--b1); border-radius: 4px; padding: 5px 8px; font-family: var(--mono); font-size: 12px; color: var(--txt); outline: none; transition: border-color .15s; }
#rpm .bulk-input:focus { border-color: var(--acc); }
#rpm .bulk-input::placeholder { color: var(--dim); }
#rpm .bulk-sep { width: 1px; height: 20px; background: var(--b1); flex-shrink: 0; }
#rpm .bulk-select-info { font-family: var(--mono); font-size: 10px; color: var(--dim); white-space: nowrap; }
#rpm .bulk-select-info span { color: var(--acc); font-weight: 600; }

/* Buttons */
#rpm .btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border: 1px solid transparent; border-radius: 4px; font-family: var(--mono); font-size: 10px; font-weight: 600; letter-spacing: .06em; cursor: pointer; transition: all .15s; white-space: nowrap; }
#rpm .btn:disabled { opacity: .3; cursor: not-allowed; }
#rpm .btn-apply  { background: rgba(61,127,255,.12);  color: var(--acc); border-color: rgba(61,127,255,.3); }
#rpm .btn-apply:hover:not(:disabled)  { background: rgba(61,127,255,.22); }
#rpm .btn-save   { background: var(--grn); color: #0c0d10; border-color: var(--grn); font-weight: 700; margin-left: auto; }
#rpm .btn-save:hover:not(:disabled)   { filter: brightness(1.1); }
#rpm .btn-ghost  { background: transparent; color: var(--dim); border-color: var(--b2); }
#rpm .btn-ghost:hover:not(:disabled)  { color: var(--txt); background: var(--s3); }
#rpm .btn-danger { background: rgba(232,93,93,.1); color: var(--red); border-color: rgba(232,93,93,.3); }
#rpm .btn-danger:hover:not(:disabled) { background: rgba(232,93,93,.2); }
#rpm .dirty-count { display: none; font-family: var(--mono); font-size: 10px; background: rgba(232,168,36,.15); color: var(--amb); border: 1px solid rgba(232,168,36,.3); border-radius: 3px; padding: 2px 8px; white-space: nowrap; }
#rpm .dirty-count.visible { display: inline-flex; align-items: center; gap: 4px; }

/* Table */
#rpm .table-wrap { flex: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--b2) transparent; }
#rpm .table-wrap::-webkit-scrollbar { width: 4px; }
#rpm .table-wrap::-webkit-scrollbar-thumb { background: var(--b2); border-radius: 2px; }
#rpm table.vtable { width: 100%; border-collapse: collapse; }
#rpm table.vtable thead th { background: var(--s2); border-bottom: 2px solid var(--b1); padding: 8px 10px; font-family: var(--mono); font-size: 9px; letter-spacing: .12em; text-transform: uppercase; color: var(--dim); text-align: left; font-weight: 600; position: sticky; top: 0; z-index: 10; }
#rpm table.vtable tbody tr { border-bottom: 1px solid var(--b1); transition: background .1s; }
#rpm table.vtable tbody tr:hover { background: rgba(255,255,255,.02); }
#rpm table.vtable tbody tr.dirty { background: rgba(232,168,36,.04); border-left: 2px solid var(--amb); }
#rpm table.vtable tbody tr.selected { background: rgba(61,127,255,.05); }
#rpm table.vtable td { padding: 6px 10px; vertical-align: middle; }
#rpm .vtable-size { font-family: var(--mono); font-size: 14px; font-weight: 600; color: var(--txt); }
#rpm .cell-input { width: 100%; background: transparent; border: 1px solid transparent; border-radius: 4px; padding: 4px 7px; font-family: var(--mono); font-size: 12px; color: var(--txt); outline: none; transition: all .15s; min-width: 0; }
#rpm .cell-input:hover { border-color: var(--b2); background: var(--s3); }
#rpm .cell-input:focus { border-color: var(--acc); background: var(--s3); }
#rpm .cell-input.dirty { border-color: rgba(232,168,36,.4); background: rgba(232,168,36,.06); }
#rpm .cell-input::placeholder { color: var(--dim); }
#rpm .cell-input.sale { color: var(--grn); }
#rpm .stock-wrap { display: flex; align-items: center; gap: 4px; }
#rpm .stock-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
#rpm .stock-dot.in   { background: var(--grn); }
#rpm .stock-dot.out  { background: var(--red); }
#rpm .stock-dot.back { background: var(--amb); }
#rpm .status-toggle { display: flex; gap: 4px; }
#rpm .st-btn { font-family: var(--mono); font-size: 9px; font-weight: 600; padding: 3px 7px; border-radius: 3px; border: 1px solid var(--b1); cursor: pointer; transition: all .15s; background: transparent; color: var(--dim); }
#rpm .st-btn.active-pub  { background: rgba(34,199,139,.12); color: var(--grn); border-color: rgba(34,199,139,.3); }
#rpm .st-btn.active-priv { background: rgba(95,100,128,.15); color: var(--dim); border-color: var(--b2); }
#rpm .row-check, #rpm .th-check-input { accent-color: var(--acc); cursor: pointer; width: 14px; height: 14px; }

/* Empty state */
#rpm .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--dim); }
#rpm .empty-icon { font-size: 32px; }
#rpm .empty-text { font-family: var(--mono); font-size: 12px; letter-spacing: .08em; }

/* Details panel */
#rpm .details-panel { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; overflow-y: auto; align-content: start; }
#rpm .det-field { display: flex; flex-direction: column; gap: 5px; }
#rpm .det-field.full { grid-column: 1 / -1; }
#rpm .det-label { font-family: var(--mono); font-size: 9px; letter-spacing: .1em; text-transform: uppercase; color: var(--dim); }
#rpm .det-input, #rpm .det-select, #rpm .det-textarea { background: var(--s2); border: 1px solid var(--b1); border-radius: 5px; padding: 7px 10px; font-family: var(--mono); font-size: 12px; color: var(--txt); outline: none; transition: border-color .15s; }
#rpm .det-input:focus, #rpm .det-select:focus, #rpm .det-textarea:focus { border-color: var(--acc); }
#rpm .det-input::placeholder, #rpm .det-textarea::placeholder { color: var(--dim); }
#rpm .det-textarea { resize: vertical; min-height: 80px; line-height: 1.5; }
#rpm .det-actions { grid-column: 1 / -1; display: flex; gap: 8px; padding-top: 4px; border-top: 1px solid var(--b1); margin-top: 4px; }

/* JSON panel */
#rpm .json-panel-inner { display: grid; grid-template-columns: 1fr 1fr; flex: 1; overflow: hidden; }
#rpm .json-col { display: flex; flex-direction: column; border-right: 1px solid var(--b1); overflow: hidden; }
#rpm .json-col:last-child { border-right: none; }
#rpm .json-col-head { background: var(--s2); border-bottom: 1px solid var(--b1); padding: 8px 14px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
#rpm .json-col-title { font-family: var(--mono); font-size: 9px; letter-spacing: .15em; text-transform: uppercase; color: var(--dim); }
#rpm .json-editor { flex: 1; background: var(--bg); border: none; padding: 14px; font-family: var(--mono); font-size: 11.5px; line-height: 1.7; color: var(--txt); outline: none; resize: none; overflow-y: auto; }
#rpm .json-viewer-body { flex: 1; overflow-y: auto; padding: 14px; font-family: var(--mono); font-size: 11px; line-height: 1.7; white-space: pre-wrap; word-break: break-all; }
#rpm .jk { color: #a78bfa; } #rpm .js { color: var(--grn); } #rpm .jn { color: var(--amb); } #rpm .jb { color: var(--acc); } #rpm .jx { color: var(--red); }
#rpm .json-empty { color: var(--dim); font-style: italic; }

/* Toast */
#rpm .toast-wrap { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column; gap: 6px; z-index: 9999; pointer-events: none; }
#rpm .toast { font-family: var(--mono); font-size: 11px; padding: 9px 14px; border-radius: 5px; border: 1px solid; animation: tin .18s ease; pointer-events: none; max-width: 360px; }
@keyframes tin { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
#rpm .toast.ok  { background: rgba(34,199,139,.15); border-color: rgba(34,199,139,.4); color: var(--grn); }
#rpm .toast.err { background: rgba(232,93,93,.15);  border-color: rgba(232,93,93,.4);  color: var(--red); }
#rpm .toast.inf { background: rgba(61,127,255,.15); border-color: rgba(61,127,255,.4); color: var(--acc); }
#rpm .spin { display: inline-block; width: 9px; height: 9px; border: 1.5px solid var(--b2); border-top-color: var(--acc); border-radius: 50%; animation: sp .5s linear infinite; }
@keyframes sp { to { transform: rotate(360deg); } }
#rpm *::-webkit-scrollbar { width: 4px; height: 4px; }
#rpm *::-webkit-scrollbar-thumb { background: var(--b2); border-radius: 2px; }
#rpm .loading-row td { text-align: center; padding: 32px; color: var(--dim); font-family: var(--mono); font-size: 11px; }
</style>

<div id="rpm">
    <div class="search-bar">
        <div class="search-logo">RP · Products</div>
        <div class="search-wrap">
            <span class="search-icon">⌕</span>
            <input class="search-input" id="rpm-search" placeholder="Cerca per ID, SKU o nome prodotto..." autocomplete="off" spellcheck="false" />
            <div class="search-dropdown" id="rpm-dropdown"></div>
        </div>
        <span class="search-hint">↵ carica · ESC chiudi</span>
    </div>

    <div class="product-card" id="rpm-card">
        <span class="pc-id" id="card-id"></span>
        <span class="pc-name" id="card-name"></span>
        <span class="pc-sku" id="card-sku"></span>
        <span class="pc-price" id="card-price"></span>
        <span class="pc-status" id="card-status"></span>
        <button class="pc-clear" onclick="RPM.clearProduct()">✕ clear</button>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-item active" onclick="RPM.switchTab('variations', this)">
                <span class="tab-icon">⧉</span>
                <span class="tab-label">Varianti</span>
                <span class="tab-count" id="tc-variations">—</span>
            </div>
            <div class="tab-item" onclick="RPM.switchTab('product', this)">
                <span class="tab-icon">◈</span>
                <span class="tab-label">Prodotto</span>
            </div>
            <div class="tab-item" onclick="RPM.switchTab('json', this)">
                <span class="tab-icon">{}</span>
                <span class="tab-label">JSON</span>
            </div>
        </div>

        <div class="content">
            <!-- VARIANTI -->
            <div class="panel active" id="panel-variations">
                <div class="bulk-bar">
                    <span class="bulk-select-info"><span id="sel-count">0</span> selezionate</span>
                    <div class="bulk-sep"></div>
                    <div class="bulk-group">
                        <span class="bulk-label">Prezzo reg. €</span>
                        <input class="bulk-input" id="bulk-reg" type="number" step="0.01" placeholder="—" />
                    </div>
                    <div class="bulk-group">
                        <span class="bulk-label">Saldo €</span>
                        <input class="bulk-input" id="bulk-sale" type="number" step="0.01" placeholder="—" />
                        <button class="btn btn-ghost" onclick="RPM.bulkClearSale()">✕ saldo</button>
                    </div>
                    <div class="bulk-group">
                        <span class="bulk-label">Stock</span>
                        <input class="bulk-input" id="bulk-stock" type="number" placeholder="—" style="width:60px" />
                    </div>
                    <button class="btn btn-apply" onclick="RPM.applyBulk()">Applica alle selezionate</button>
                    <div class="bulk-sep"></div>
                    <span class="dirty-count" id="dirty-count"><span id="dirty-n">0</span> modifiche non salvate</span>
                    <button class="btn btn-save" id="btn-save" onclick="RPM.saveVariations()" disabled>
                        <span id="save-spin" style="display:none" class="spin"></span>
                        Salva modifiche
                    </button>
                </div>
                <div class="table-wrap">
                    <div class="empty-state" id="variations-empty">
                        <div class="empty-icon">⧉</div>
                        <div class="empty-text">Cerca e carica un prodotto variabile per gestire le varianti</div>
                    </div>
                    <table class="vtable" id="vtable" style="display:none">
                        <thead>
                            <tr>
                                <th style="width:36px"><input type="checkbox" class="th-check-input" id="check-all" onchange="RPM.toggleSelectAll(this.checked)" /></th>
                                <th style="width:70px">Taglia</th>
                                <th style="width:160px">SKU Variante</th>
                                <th style="width:120px">Prezzo Reg. (€)</th>
                                <th style="width:120px">Saldo (€)</th>
                                <th style="width:90px">Stock</th>
                                <th style="width:90px">Visibile</th>
                            </tr>
                        </thead>
                        <tbody id="vtable-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- PRODOTTO -->
            <div class="panel" id="panel-product">
                <div class="details-panel" id="details-panel">
                    <div class="empty-state" style="grid-column:1/-1">
                        <div class="empty-icon">◈</div>
                        <div class="empty-text">Carica un prodotto dalla barra di ricerca</div>
                    </div>
                </div>
            </div>

            <!-- JSON -->
            <div class="panel" id="panel-json">
                <div class="json-panel-inner">
                    <div class="json-col">
                        <div class="json-col-head">
                            <span class="json-col-title">Editor · Payload</span>
                            <div style="display:flex;gap:6px">
                                <button class="btn btn-ghost" style="font-size:9px" onclick="RPM.formatJSON()">FORMAT</button>
                                <button class="btn btn-ghost" style="font-size:9px" onclick="RPM.clearJSONEditor()">CLEAR</button>
                            </div>
                        </div>
                        <textarea class="json-editor" id="json-editor" spellcheck="false" placeholder="// Payload per Create / Update&#10;// Scrivi o incolla JSON qui..."></textarea>
                        <div style="background:var(--s1);border-top:1px solid var(--b1);padding:8px 14px;display:flex;gap:6px;flex-shrink:0;align-items:center">
                            <button class="btn btn-apply" onclick="RPM.jsonCreate()">POST Create</button>
                            <button class="btn btn-ghost" onclick="RPM.jsonUpdate()">PUT Update ID:</button>
                            <input id="json-update-id" type="number" placeholder="ID" style="width:70px;background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:4px 8px;font-family:var(--mono);font-size:11px;color:var(--txt);outline:none" />
                            <button class="btn btn-danger" style="margin-left:auto" onclick="RPM.jsonDelete()">DELETE ID:</button>
                            <input id="json-delete-id" type="number" placeholder="ID" style="width:70px;background:var(--s3);border:1px solid var(--b1);border-radius:4px;padding:4px 8px;font-family:var(--mono);font-size:11px;color:var(--txt);outline:none" />
                        </div>
                    </div>
                    <div class="json-col">
                        <div class="json-col-head">
                            <span class="json-col-title">Response · Viewer</span>
                            <button class="btn btn-ghost" style="font-size:9px" onclick="RPM.copyResponse()">⎘ COPY</button>
                        </div>
                        <div class="json-viewer-body" id="json-viewer">
                            <span class="json-empty">// La risposta apparirà qui...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="rpm-toasts" class="toast-wrap"></div>
</div>

<script>
const RPM = (function(){
    const AJAX  = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';
    let state = { product: null, variations: [], dirty: {}, selected: new Set() };

    async function ajax(action, body = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        Object.entries(body).forEach(([k,v]) => fd.append(k, v));
        const r = await fetch(AJAX, { method:'POST', body:fd });
        return r.json();
    }

    function toast(msg, type='ok', ms=3000) {
        const wrap = document.getElementById('rpm-toasts');
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(() => t.remove(), ms);
    }

    function hl(json) {
        return String(json).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, m => {
                let c='jn';
                if(/^"/.test(m)) c=/:$/.test(m)?'jk':'js';
                else if(/true|false/.test(m)) c='jb';
                else if(/null/.test(m)) c='jx';
                return `<span class="${c}">${m}</span>`;
            });
    }

    function setViewer(data) { document.getElementById('json-viewer').innerHTML = hl(JSON.stringify(data, null, 2)); }

    /* Search */
    let searchTimer;
    function initSearch() {
        const inp  = document.getElementById('rpm-search');
        const drop = document.getElementById('rpm-dropdown');
        inp.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const q = inp.value.trim();
            if (q.length < 1) { closeDrop(); return; }
            searchTimer = setTimeout(() => doSearch(q), 280);
        });
        inp.addEventListener('keydown', (e) => {
            const items   = drop.querySelectorAll('.search-result');
            const focused = drop.querySelector('.focused');
            if (e.key === 'Escape') { closeDrop(); inp.blur(); return; }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                const next = focused ? (focused.nextElementSibling || items[0]) : items[0];
                if (focused) focused.classList.remove('focused');
                if (next) next.classList.add('focused');
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prev = focused ? (focused.previousElementSibling || items[items.length-1]) : items[items.length-1];
                if (focused) focused.classList.remove('focused');
                if (prev) prev.classList.add('focused');
                return;
            }
            if (e.key === 'Enter') { e.preventDefault(); const t = focused || items[0]; if (t) t.click(); }
        });
        document.addEventListener('click', (e) => { if (!e.target.closest('.search-wrap')) closeDrop(); });
    }

    async function doSearch(q) {
        const drop = document.getElementById('rpm-dropdown');
        drop.innerHTML = '<div class="search-empty"><span class="spin"></span> Ricerca...</div>';
        drop.classList.add('open');
        const res = await ajax('rp_ajax_search', { query: q });
        if (!res.success) { drop.innerHTML = `<div class="search-empty">Errore</div>`; return; }
        if (!res.data.length) { drop.innerHTML = `<div class="search-empty">Nessun risultato</div>`; return; }
        drop.innerHTML = res.data.map(p => `
            <div class="search-result" onclick="RPM.loadProduct(${p.id})">
                <span class="sr-id">#${p.id}</span>
                <span class="sr-name">${p.name}</span>
                <span class="sr-sku">${p.sku||'—'}</span>
                <span class="sr-type type-${p.type}">${p.type}</span>
            </div>`).join('');
    }

    function closeDrop() { const d = document.getElementById('rpm-dropdown'); d.classList.remove('open'); d.innerHTML=''; }

    /* Load product */
    async function loadProduct(id) {
        closeDrop();
        document.getElementById('rpm-search').value = '';
        const res = await ajax('rp_ajax_read', { product_id: id });
        if (!res.success) { toast('Prodotto non trovato: ' + res.data, 'err'); return; }
        state.product = res.data; state.dirty = {}; state.selected = new Set();
        renderCard(res.data);
        renderProductForm(res.data);
        setViewer(res.data);
        document.getElementById('json-editor').value = JSON.stringify(res.data, null, 2);
        if (res.data.type === 'variable') {
            await loadVariations(id);
        } else {
            showEmptyVariations('Tipo "' + res.data.type + '" — nessuna variante');
            document.getElementById('tc-variations').textContent = '—';
        }
        toast('Caricato: ' + res.data.name, 'ok');
    }

    async function loadVariations(pid) {
        showLoadingVariations();
        const res = await ajax('rp_ajax_get_variations', { product_id: pid });
        if (!res.success) { showEmptyVariations('Errore: ' + res.data); return; }
        state.variations = res.data; state.dirty = {}; state.selected = new Set();
        renderVariationsTable(res.data);
        document.getElementById('tc-variations').textContent = res.data.length;
        updateDirtyUI();
    }

    function renderCard(p) {
        document.getElementById('card-id').textContent   = '#' + p.id;
        document.getElementById('card-name').textContent = p.name;
        document.getElementById('card-sku').textContent  = p.sku ? 'SKU: ' + p.sku : '';
        document.getElementById('card-price').textContent= p.price ? '€' + p.price : '';
        const s = document.getElementById('card-status');
        s.textContent = p.status; s.className = 'pc-status status-' + p.status;
        document.getElementById('rpm-card').classList.add('visible');
    }

    function clearProduct() {
        state = { product: null, variations: [], dirty: {}, selected: new Set() };
        document.getElementById('rpm-card').classList.remove('visible');
        showEmptyVariations('Cerca e carica un prodotto variabile per gestire le varianti');
        document.getElementById('tc-variations').textContent = '—';
        document.getElementById('vtable').style.display = 'none';
        document.getElementById('sel-count').textContent = '0';
        renderProductForm(null);
        document.getElementById('json-editor').value = '';
        document.getElementById('json-viewer').innerHTML = '<span class="json-empty">// La risposta apparirà qui...</span>';
        updateDirtyUI();
    }

    function showEmptyVariations(msg) {
        document.getElementById('variations-empty').innerHTML = `<div class="empty-icon">⧉</div><div class="empty-text">${msg}</div>`;
        document.getElementById('variations-empty').style.display = 'flex';
        document.getElementById('vtable').style.display = 'none';
    }
    function showLoadingVariations() {
        document.getElementById('variations-empty').style.display = 'none';
        document.getElementById('vtable').style.display = 'table';
        document.getElementById('vtable-body').innerHTML = '<tr class="loading-row"><td colspan="7"><span class="spin"></span> Carico varianti...</td></tr>';
    }

    function renderVariationsTable(vars) {
        document.getElementById('variations-empty').style.display = 'none';
        document.getElementById('vtable').style.display = 'table';
        document.getElementById('vtable-body').innerHTML = vars.map(v => buildRow(v)).join('');
    }

    function buildRow(v) {
        const vid   = v.variation_id;
        const dirty = state.dirty[vid] || {};
        const reg   = dirty.regular_price  ?? v.regular_price  ?? '';
        const sale  = dirty.sale_price     ?? v.sale_price     ?? '';
        const stock = dirty.stock_quantity ?? (v.stock_quantity !== null ? v.stock_quantity : '');
        const status= dirty.status         ?? v.status         ?? 'publish';
        const dot   = v.stock_status==='instock' ? 'in' : v.stock_status==='onbackorder' ? 'back' : 'out';
        const isDirty = Object.keys(dirty).length > 0;
        const isSel   = state.selected.has(vid);
        return `<tr id="row-${vid}" class="${isDirty?'dirty':''} ${isSel?'selected':''}">
            <td><input type="checkbox" class="row-check" ${isSel?'checked':''} onchange="RPM.toggleRow(${vid},this.checked)" /></td>
            <td><span class="vtable-size">${v.size}</span></td>
            <td><input class="cell-input" type="text" value="${v.sku||''}" placeholder="—" oninput="RPM.markDirty(${vid},'sku',this.value)" /></td>
            <td><input class="cell-input ${dirty.regular_price!==undefined?'dirty':''}" type="number" step="0.01" value="${reg}" placeholder="0.00" oninput="RPM.markDirty(${vid},'regular_price',this.value)" /></td>
            <td><input class="cell-input sale ${dirty.sale_price!==undefined?'dirty':''}" type="number" step="0.01" value="${sale}" placeholder="—" oninput="RPM.markDirty(${vid},'sale_price',this.value)" /></td>
            <td><div class="stock-wrap"><div class="stock-dot ${dot}" id="dot-${vid}"></div><input class="cell-input ${dirty.stock_quantity!==undefined?'dirty':''}" type="number" value="${stock}" placeholder="—" style="width:52px" oninput="RPM.markDirty(${vid},'stock_quantity',this.value)" /></div></td>
            <td><div class="status-toggle">
                <button class="st-btn ${status==='publish'?'active-pub':''}" onclick="RPM.setRowStatus(${vid},'publish')">ON</button>
                <button class="st-btn ${status!=='publish'?'active-priv':''}" onclick="RPM.setRowStatus(${vid},'private')">OFF</button>
            </div></td>
        </tr>`;
    }

    function markDirty(vid, field, value) {
        if (!state.dirty[vid]) state.dirty[vid] = {};
        state.dirty[vid][field] = value;
        document.getElementById('row-'+vid)?.classList.add('dirty');
        updateDirtyUI();
    }
    function setRowStatus(vid, status) {
        markDirty(vid, 'status', status);
        const row = document.getElementById('row-'+vid);
        if (!row) return;
        const btns = row.querySelectorAll('.st-btn');
        btns[0].className = 'st-btn ' + (status==='publish'?'active-pub':'');
        btns[1].className = 'st-btn ' + (status!=='publish'?'active-priv':'');
    }
    function updateDirtyUI() {
        const count = Object.keys(state.dirty).length;
        const dc = document.getElementById('dirty-count');
        const btn = document.getElementById('btn-save');
        document.getElementById('dirty-n').textContent = count;
        document.getElementById('sel-count').textContent = state.selected.size;
        if (count > 0) { dc.classList.add('visible'); btn.disabled = false; btn.innerHTML = `<span id="save-spin" style="display:none" class="spin"></span>Salva ${count} modific${count===1?'a':'he'}`; }
        else           { dc.classList.remove('visible'); btn.disabled = true; btn.innerHTML = `<span id="save-spin" style="display:none" class="spin"></span>Salva modifiche`; }
    }

    function toggleRow(vid, checked) {
        if (checked) state.selected.add(vid); else state.selected.delete(vid);
        document.getElementById('row-'+vid)?.classList.toggle('selected', checked);
        document.getElementById('sel-count').textContent = state.selected.size;
        document.getElementById('check-all').indeterminate = state.selected.size>0 && state.selected.size<state.variations.length;
        document.getElementById('check-all').checked = state.selected.size===state.variations.length;
    }
    function toggleSelectAll(checked) {
        state.selected = checked ? new Set(state.variations.map(v=>v.variation_id)) : new Set();
        state.variations.forEach(v => {
            document.getElementById('row-'+v.variation_id)?.classList.toggle('selected', checked);
            const cb = document.getElementById('row-'+v.variation_id)?.querySelector('.row-check');
            if (cb) cb.checked = checked;
        });
        document.getElementById('sel-count').textContent = state.selected.size;
    }

    function applyBulk() {
        const reg=document.getElementById('bulk-reg').value.trim();
        const sale=document.getElementById('bulk-sale').value.trim();
        const stock=document.getElementById('bulk-stock').value.trim();
        if (!reg && !sale && !stock) { toast('Compila almeno un campo', 'err'); return; }
        const targets = state.selected.size > 0 ? [...state.selected] : state.variations.map(v=>v.variation_id);
        targets.forEach(vid => {
            const row = document.getElementById('row-'+vid);
            if (!row) return;
            const inputs = row.querySelectorAll('.cell-input');
            if (reg)   { markDirty(vid,'regular_price',reg);   inputs[1].value=reg;   inputs[1].classList.add('dirty'); }
            if (sale)  { markDirty(vid,'sale_price',sale);     inputs[2].value=sale;  inputs[2].classList.add('dirty'); }
            if (stock) { markDirty(vid,'stock_quantity',stock); inputs[3].value=stock; inputs[3].classList.add('dirty'); }
            row.classList.add('dirty');
        });
        toast(`Bulk applicato a ${targets.length} varianti`, 'inf');
        updateDirtyUI();
    }
    function bulkClearSale() {
        const targets = state.selected.size > 0 ? [...state.selected] : state.variations.map(v=>v.variation_id);
        targets.forEach(vid => {
            markDirty(vid,'sale_price','');
            const row = document.getElementById('row-'+vid);
            const inp = row?.querySelectorAll('.cell-input')[2];
            if (inp) { inp.value=''; inp.classList.add('dirty'); }
        });
        toast(`Saldo rimosso da ${targets.length} varianti`, 'inf');
    }

    async function saveVariations() {
        const updates = Object.entries(state.dirty).map(([vid,fields]) => ({ variation_id:parseInt(vid), ...fields }));
        if (!updates.length) return;
        document.getElementById('save-spin').style.display='inline-block';
        document.getElementById('btn-save').disabled=true;
        const res = await ajax('rp_ajax_save_variations', { updates:JSON.stringify(updates), product_id: state.product?.id||0 });
        document.getElementById('save-spin').style.display='none';
        if (!res.success) { toast('Errore: '+res.data,'err'); document.getElementById('btn-save').disabled=false; return; }
        const errors = Object.values(res.data.results).filter(v=>v!=='ok');
        toast(errors.length ? `${errors.length} errori` : `Salvate ${updates.length} modific${updates.length===1?'a':'he'}`, errors.length?'err':'ok');
        state.dirty = {};
        if (res.data.variations?.length) { state.variations=res.data.variations; renderVariationsTable(res.data.variations); }
        updateDirtyUI();
    }

    /* Product form */
    function renderProductForm(p) {
        const panel = document.getElementById('details-panel');
        if (!p) { panel.innerHTML=`<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">◈</div><div class="empty-text">Carica un prodotto dalla barra di ricerca</div></div>`; return; }
        const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        const strip = s => s.replace(/<[^>]+>/g,'');
        const opt = (val,target) => val===target?'selected':'';
        panel.innerHTML = `
            <div class="det-field"><label class="det-label">Nome</label><input class="det-input" id="det-name" value="${esc(p.name||'')}" /></div>
            <div class="det-field"><label class="det-label">SKU</label><input class="det-input" id="det-sku" value="${esc(p.sku||'')}" /></div>
            <div class="det-field"><label class="det-label">Prezzo Regolare (€)</label><input class="det-input" id="det-reg" type="number" step="0.01" value="${p.regular_price||''}" /></div>
            <div class="det-field"><label class="det-label">Prezzo Scontato (€)</label><input class="det-input" id="det-sale" type="number" step="0.01" value="${p.sale_price||''}" /></div>
            <div class="det-field"><label class="det-label">Status</label><select class="det-select" id="det-status"><option value="publish" ${opt(p.status,'publish')}>Pubblicato</option><option value="draft" ${opt(p.status,'draft')}>Bozza</option><option value="private" ${opt(p.status,'private')}>Privato</option></select></div>
            <div class="det-field"><label class="det-label">Stock Qty</label><input class="det-input" id="det-stock" type="number" value="${p.stock_quantity??''}" /></div>
            <div class="det-field full"><label class="det-label">Descrizione Breve</label><textarea class="det-textarea" id="det-short">${esc(strip(p.short_description||''))}</textarea></div>
            <div class="det-field full"><label class="det-label">Meta Title (Rank Math)</label><input class="det-input" id="det-metatitle" value="${esc(p.meta_title||'')}" /></div>
            <div class="det-field full"><label class="det-label">Meta Description (Rank Math)</label><input class="det-input" id="det-metadesc" value="${esc(p.meta_description||'')}" /></div>
            <div class="det-field"><label class="det-label">Focus Keyword</label><input class="det-input" id="det-kw" value="${esc(p.focus_keyword||'')}" /></div>
            <div class="det-field" style="align-self:end"><label class="det-label">Permalink</label><a href="${p.permalink||'#'}" target="_blank" style="font-family:var(--mono);font-size:10px;color:var(--acc);word-break:break-all">${p.permalink||'—'}</a></div>
            <div class="det-actions">
                <button class="btn btn-apply" onclick="RPM.saveProductDetails()"><span id="det-spin" style="display:none" class="spin"></span>Salva Prodotto</button>
                <span style="font-family:var(--mono);font-size:10px;color:var(--dim);align-self:center">ID ${p.id} · ${p.type} · ${p.date_modified||'—'}</span>
            </div>`;
    }

    async function saveProductDetails() {
        const id = state.product?.id;
        if (!id) { toast('Nessun prodotto caricato','err'); return; }
        const spin = document.getElementById('det-spin');
        if (spin) spin.style.display='inline-block';
        const g = id => document.getElementById(id)?.value?.trim();
        const payload = {};
        if (g('det-name'))      payload.name              = g('det-name');
        if (g('det-sku'))       payload.sku               = g('det-sku');
        if (g('det-reg'))       payload.regular_price     = g('det-reg');
                                payload.sale_price        = g('det-sale') || '';
        if (g('det-status'))    payload.status            = g('det-status');
        if (g('det-stock'))     payload.stock_quantity    = parseInt(g('det-stock'));
        if (g('det-short'))     payload.short_description = g('det-short');
        if (g('det-metatitle')) payload.meta_title        = g('det-metatitle');
        if (g('det-metadesc'))  payload.meta_description  = g('det-metadesc');
        if (g('det-kw'))        payload.focus_keyword     = g('det-kw');
        const res = await ajax('rp_ajax_update', { product_id:id, json_payload:JSON.stringify(payload) });
        if (spin) spin.style.display='none';
        if (res.success) { state.product=res.data.product; renderCard(res.data.product); setViewer(res.data.product); toast('Prodotto aggiornato','ok'); }
        else toast('Errore: '+res.data,'err');
    }

    /* JSON panel */
    function formatJSON() { const ed=document.getElementById('json-editor'); try{ed.value=JSON.stringify(JSON.parse(ed.value),null,2);}catch(e){toast('JSON non valido: '+e.message,'err');} }
    function clearJSONEditor() { document.getElementById('json-editor').value=''; }
    function copyResponse() { navigator.clipboard.writeText(document.getElementById('json-viewer').innerText).then(()=>toast('JSON copiato','ok')); }

    async function jsonCreate() {
        let p; try{p=JSON.parse(document.getElementById('json-editor').value);}catch(e){toast('JSON non valido','err');return;}
        const res=await ajax('rp_ajax_create',{json_payload:JSON.stringify(p)});
        setViewer(res.success?res.data:res);
        toast(res.success?'Prodotto creato! ID: '+res.data.id:'Errore: '+res.data, res.success?'ok':'err');
    }
    async function jsonUpdate() {
        const id=document.getElementById('json-update-id').value.trim();
        if(!id){toast('Inserisci un ID','err');return;}
        let p; try{p=JSON.parse(document.getElementById('json-editor').value);}catch(e){toast('JSON non valido','err');return;}
        const res=await ajax('rp_ajax_update',{product_id:id,json_payload:JSON.stringify(p)});
        setViewer(res.success?res.data:res);
        toast(res.success?'Prodotto #'+id+' aggiornato':'Errore: '+res.data, res.success?'ok':'err');
    }
    async function jsonDelete() {
        const id=document.getElementById('json-delete-id').value.trim();
        if(!id){toast('Inserisci un ID','err');return;}
        if(!confirm(`Eliminare definitivamente il prodotto #${id}?`))return;
        const res=await ajax('rp_ajax_delete',{product_id:id,force:'1'});
        setViewer(res.success?res.data:res);
        toast(res.success?'Prodotto #'+id+' eliminato':'Errore: '+res.data, res.success?'ok':'err');
    }

    /* Tabs */
    function switchTab(id, el) {
        document.querySelectorAll('#rpm .tab-item').forEach(t=>t.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('#rpm .panel').forEach(p=>p.classList.remove('active'));
        document.getElementById('panel-'+id).classList.add('active');
    }

    initSearch();

    return { loadProduct, clearProduct, switchTab, toggleRow, toggleSelectAll, markDirty, setRowStatus, applyBulk, bulkClearSale, saveVariations, saveProductDetails, formatJSON, clearJSONEditor, copyResponse, jsonCreate, jsonUpdate, jsonDelete };
})();
</script>
<?php
}
