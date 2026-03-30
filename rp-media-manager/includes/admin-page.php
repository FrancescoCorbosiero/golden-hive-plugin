<?php
/**
 * Admin page — registrazione menu e render UI.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'RP Media Manager',
        'RP Media',
        'manage_woocommerce',
        'rp-media-manager',
        'rp_mm_render_page',
        'dashicons-format-gallery',
        59
    );
} );

function rp_mm_render_page(): void {
    $nonce = wp_create_nonce( 'rp_mm_nonce' );
    $ajax  = admin_url( 'admin-ajax.php' );
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
#rpmm { all: initial; }
#rpmm *, #rpmm *::before, #rpmm *::after {
    box-sizing: border-box; margin: 0; padding: 0;
    font-family: 'DM Sans', system-ui, sans-serif;
}
#rpmm {
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
#rpmm .header { background: var(--s1); border-bottom: 1px solid var(--b1); padding: 10px 20px; display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
#rpmm .header-logo { font-family: var(--mono); font-size: 10px; font-weight: 600; letter-spacing: .2em; color: var(--acc); text-transform: uppercase; }
#rpmm .header-desc { font-size: 11px; color: var(--dim); font-family: var(--mono); }

/* Layout */
#rpmm .main { flex: 1; display: flex; overflow: hidden; }
#rpmm .tabs-col { width: 160px; background: var(--s1); border-right: 1px solid var(--b1); display: flex; flex-direction: column; flex-shrink: 0; }
#rpmm .tab-item { padding: 14px 16px; cursor: pointer; border-left: 2px solid transparent; border-bottom: 1px solid var(--b1); transition: all .15s; display: flex; align-items: center; gap: 10px; }
#rpmm .tab-item:hover { background: var(--s2); }
#rpmm .tab-item.active { background: var(--s3); border-left-color: var(--acc); }
#rpmm .tab-icon { font-size: 14px; width: 18px; text-align: center; }
#rpmm .tab-label { font-size: 12px; font-weight: 500; color: var(--dim); }
#rpmm .tab-item.active .tab-label { color: var(--txt); }
#rpmm .content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
#rpmm .panel { display: none; flex-direction: column; flex: 1; overflow: hidden; }
#rpmm .panel.active { display: flex; }

/* Buttons */
#rpmm .btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; border: 1px solid transparent; border-radius: 4px; font-family: var(--mono); font-size: 10px; font-weight: 600; letter-spacing: .06em; cursor: pointer; transition: all .15s; white-space: nowrap; }
#rpmm .btn:disabled { opacity: .3; cursor: not-allowed; }
#rpmm .btn-primary { background: var(--acc); color: #fff; border-color: var(--acc); }
#rpmm .btn-primary:hover:not(:disabled) { filter: brightness(1.15); }
#rpmm .btn-ghost { background: transparent; color: var(--dim); border-color: var(--b2); }
#rpmm .btn-ghost:hover:not(:disabled) { color: var(--txt); background: var(--s3); }
#rpmm .btn-danger { background: rgba(232,93,93,.1); color: var(--red); border-color: rgba(232,93,93,.3); }
#rpmm .btn-danger:hover:not(:disabled) { background: rgba(232,93,93,.2); }

/* Toolbar */
#rpmm .toolbar { background: var(--s2); border-bottom: 1px solid var(--b1); padding: 10px 20px; display: flex; align-items: center; gap: 12px; flex-shrink: 0; flex-wrap: wrap; }
#rpmm .search-input { background: var(--s3); border: 1px solid var(--b1); border-radius: 6px; padding: 6px 12px; font-family: var(--mono); font-size: 12px; color: var(--txt); outline: none; width: 240px; }
#rpmm .search-input:focus { border-color: var(--acc); }
#rpmm .search-input::placeholder { color: var(--dim); }
#rpmm .filter-sep { width: 1px; height: 20px; background: var(--b1); flex-shrink: 0; }

/* Media grid */
#rpmm .media-grid { flex: 1; overflow-y: auto; padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; align-content: start; }
#rpmm .media-card { background: var(--s2); border: 1px solid var(--b1); border-radius: 6px; overflow: hidden; cursor: pointer; transition: all .15s; position: relative; }
#rpmm .media-card:hover { border-color: var(--b2); }
#rpmm .media-card.selected { border-color: var(--acc); box-shadow: 0 0 0 1px var(--acc); }
#rpmm .media-card.whitelisted { border-color: rgba(232,168,36,.3); }
#rpmm .media-thumb { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; background: var(--s3); }
#rpmm .media-info { padding: 6px 8px; }
#rpmm .media-filename { font-family: var(--mono); font-size: 9px; color: var(--txt); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#rpmm .media-size { font-family: var(--mono); font-size: 9px; color: var(--dim); }
#rpmm .media-badge { position: absolute; top: 6px; right: 6px; font-family: var(--mono); font-size: 8px; font-weight: 600; padding: 2px 5px; border-radius: 3px; }
#rpmm .badge-wl { background: rgba(232,168,36,.2); color: var(--amb); }
#rpmm .media-check { position: absolute; top: 6px; left: 6px; accent-color: var(--acc); width: 14px; height: 14px; cursor: pointer; }

/* Mapping table */
#rpmm .map-wrap { flex: 1; overflow-y: auto; }
#rpmm table.maptable { width: 100%; border-collapse: collapse; }
#rpmm .maptable thead th { background: var(--s2); border-bottom: 2px solid var(--b1); padding: 8px 12px; font-family: var(--mono); font-size: 9px; letter-spacing: .1em; text-transform: uppercase; color: var(--dim); text-align: left; font-weight: 600; position: sticky; top: 0; z-index: 10; }
#rpmm .maptable tbody tr { border-bottom: 1px solid var(--b1); }
#rpmm .maptable tbody tr:hover { background: rgba(255,255,255,.02); }
#rpmm .maptable td { padding: 8px 12px; vertical-align: middle; }
#rpmm .map-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; background: var(--s3); }
#rpmm .map-gallery { display: flex; gap: 4px; }
#rpmm .map-gallery img { width: 32px; height: 32px; object-fit: cover; border-radius: 3px; background: var(--s3); }
#rpmm .map-name { font-size: 12px; font-weight: 500; }
#rpmm .map-sku { font-family: var(--mono); font-size: 10px; color: var(--dim); }
#rpmm .map-none { font-family: var(--mono); font-size: 10px; color: var(--dim); font-style: italic; }

/* Whitelist table */
#rpmm .wl-wrap { flex: 1; overflow-y: auto; padding: 16px; }
#rpmm .wl-row { display: flex; align-items: center; gap: 12px; padding: 8px 12px; border-bottom: 1px solid var(--b1); }
#rpmm .wl-row:hover { background: var(--s3); }
#rpmm .wl-thumb { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; background: var(--s3); }
#rpmm .wl-info { flex: 1; }
#rpmm .wl-name { font-size: 12px; font-weight: 500; }
#rpmm .wl-reason { font-family: var(--mono); font-size: 10px; color: var(--dim); }
#rpmm .wl-id { font-family: var(--mono); font-size: 9px; color: var(--dim); }

/* Stats bar */
#rpmm .stats-bar { background: var(--s1); border-bottom: 1px solid var(--b1); padding: 8px 20px; display: flex; gap: 20px; flex-shrink: 0; }
#rpmm .stat { font-family: var(--mono); font-size: 10px; color: var(--dim); }
#rpmm .stat span { color: var(--txt); font-weight: 600; }
#rpmm .stat .green { color: var(--grn); }
#rpmm .stat .red { color: var(--red); }

/* Empty state */
#rpmm .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--dim); }
#rpmm .empty-icon { font-size: 32px; }
#rpmm .empty-text { font-family: var(--mono); font-size: 12px; letter-spacing: .08em; text-align: center; }

/* Toast */
#rpmm .toast-wrap { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column; gap: 6px; z-index: 9999; pointer-events: none; }
#rpmm .toast { font-family: var(--mono); font-size: 11px; padding: 9px 14px; border-radius: 5px; border: 1px solid; pointer-events: none; max-width: 360px; animation: rpmm-tin .18s ease; }
@keyframes rpmm-tin { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
#rpmm .toast.ok  { background: rgba(34,199,139,.15); border-color: rgba(34,199,139,.4); color: var(--grn); }
#rpmm .toast.err { background: rgba(232,93,93,.15);  border-color: rgba(232,93,93,.4);  color: var(--red); }
#rpmm .toast.inf { background: rgba(61,127,255,.15); border-color: rgba(61,127,255,.4); color: var(--acc); }
#rpmm .spin { display: inline-block; width: 9px; height: 9px; border: 1.5px solid var(--b2); border-top-color: var(--acc); border-radius: 50%; animation: rpmm-sp .5s linear infinite; }
@keyframes rpmm-sp { to { transform: rotate(360deg); } }
#rpmm *::-webkit-scrollbar { width: 4px; height: 4px; }
#rpmm *::-webkit-scrollbar-thumb { background: var(--b2); border-radius: 2px; }
#rpmm * { scrollbar-width: thin; scrollbar-color: var(--b2) transparent; }

/* Overlay */
#rpmm .gen-overlay { display: none; position: absolute; inset: 0; background: rgba(12,13,16,.85); z-index: 50; align-items: center; justify-content: center; flex-direction: column; gap: 12px; }
#rpmm .gen-overlay.visible { display: flex; }
#rpmm .gen-text { font-family: var(--mono); font-size: 12px; color: var(--dim); }
#rpmm .gen-spinner { width: 24px; height: 24px; border: 2px solid var(--b2); border-top-color: var(--acc); border-radius: 50%; animation: rpmm-sp .6s linear infinite; }

@media (max-width: 768px) {
    #rpmm .tabs-col { width: 48px; }
    #rpmm .tab-label { display: none; }
    #rpmm .tab-item { justify-content: center; padding: 14px 8px; }
    #rpmm .media-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
}
</style>

<div id="rpmm">
    <div class="header">
        <div class="header-logo">RP &middot; Media</div>
        <div class="header-desc">Gestione media WordPress</div>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-item active" onclick="RPMM.switchTab('mapping', this)">
                <span class="tab-icon">&#9636;</span>
                <span class="tab-label">Mapping</span>
            </div>
            <div class="tab-item" onclick="RPMM.switchTab('browse', this)">
                <span class="tab-icon">&#9871;</span>
                <span class="tab-label">Browse</span>
            </div>
            <div class="tab-item" onclick="RPMM.switchTab('orphans', this)">
                <span class="tab-icon">&#9888;</span>
                <span class="tab-label">Orphans</span>
            </div>
            <div class="tab-item" onclick="RPMM.switchTab('whitelist', this)">
                <span class="tab-icon">&#9737;</span>
                <span class="tab-label">Whitelist</span>
            </div>
        </div>

        <div class="content">
            <!-- MAPPING -->
            <div class="panel active" id="panel-mapping" style="position:relative">
                <div class="toolbar">
                    <button class="btn btn-primary" id="btn-map" onclick="RPMM.loadMapping()">
                        <span class="spin" id="map-spin" style="display:none"></span>
                        Carica mapping
                    </button>
                </div>
                <div class="map-wrap" id="map-area">
                    <div class="empty-state">
                        <div class="empty-icon">&#9636;</div>
                        <div class="empty-text">Premi "Carica mapping" per vedere il rapporto prodotto-immagini</div>
                    </div>
                </div>
            </div>

            <!-- BROWSE -->
            <div class="panel" id="panel-browse">
                <div class="toolbar">
                    <input class="search-input" id="browse-search" placeholder="Cerca media..." oninput="RPMM.debounceBrowse()" />
                    <button class="btn btn-ghost" onclick="RPMM.browseMedia()">Cerca</button>
                </div>
                <div class="media-grid" id="browse-grid">
                    <div class="empty-state" style="grid-column:1/-1">
                        <div class="empty-icon">&#9871;</div>
                        <div class="empty-text">Cerca nella media library</div>
                    </div>
                </div>
            </div>

            <!-- ORPHANS -->
            <div class="panel" id="panel-orphans" style="position:relative">
                <div class="toolbar">
                    <button class="btn btn-primary" id="btn-scan" onclick="RPMM.scanOrphans()">
                        <span class="spin" id="scan-spin" style="display:none"></span>
                        Avvia scansione
                    </button>
                    <div class="filter-sep"></div>
                    <button class="btn btn-danger" id="btn-bulk-del" onclick="RPMM.bulkDeleteOrphans()" style="display:none">Elimina selezionati</button>
                    <span class="stat" id="sel-stat" style="display:none"><span id="sel-n">0</span> selezionati</span>
                </div>
                <div class="stats-bar" id="orphan-stats" style="display:none">
                    <div class="stat">Orfani: <span class="red" id="st-orphans">0</span></div>
                    <div class="stat">In uso: <span class="green" id="st-used">0</span></div>
                    <div class="stat">Spazio recuperabile: <span id="st-size">0</span></div>
                </div>
                <div class="media-grid" id="orphan-grid">
                    <div class="empty-state" style="grid-column:1/-1">
                        <div class="empty-icon">&#9888;</div>
                        <div class="empty-text">Premi "Avvia scansione" per trovare le immagini orfane</div>
                    </div>
                </div>
                <div class="gen-overlay" id="scan-overlay">
                    <div class="gen-spinner"></div>
                    <div class="gen-text">Scansione in corso...</div>
                </div>
            </div>

            <!-- WHITELIST -->
            <div class="panel" id="panel-whitelist">
                <div class="toolbar">
                    <button class="btn btn-primary" onclick="RPMM.loadWhitelist()">Aggiorna</button>
                </div>
                <div class="wl-wrap" id="wl-area">
                    <div class="empty-state">
                        <div class="empty-icon">&#9737;</div>
                        <div class="empty-text">La whitelist protegge le immagini dall'eliminazione</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="rpmm-toasts" class="toast-wrap"></div>
</div>

<script>
const RPMM = (function() {
    const AJAX  = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';

    let state = {
        orphans: [], selected: new Set(),
        mapping: [], whitelist: [], browseResults: [],
    };

    async function ajax(action, body = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        Object.entries(body).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(AJAX, { method: 'POST', body: fd });
        return r.json();
    }

    function toast(msg, type = 'ok', ms = 3000) {
        const wrap = document.getElementById('rpmm-toasts');
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(() => t.remove(), ms);
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function switchTab(name, el) {
        document.querySelectorAll('#rpmm .tab-item').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('#rpmm .panel').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('panel-' + name).classList.add('active');
    }

    // ── MAPPING ─────────────────────────────────────────────
    async function loadMapping() {
        const btn = document.getElementById('btn-map');
        const spin = document.getElementById('map-spin');
        btn.disabled = true; spin.style.display = '';
        try {
            const res = await ajax('rp_mm_ajax_mapping');
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            state.mapping = res.data;
            renderMapping(res.data);
            toast(res.data.length + ' prodotti caricati', 'ok');
        } catch (e) { toast('Errore di rete', 'err'); }
        finally { btn.disabled = false; spin.style.display = 'none'; }
    }

    function renderMapping(data) {
        const area = document.getElementById('map-area');
        if (!data.length) { area.innerHTML = '<div class="empty-state"><div class="empty-text">Nessun prodotto</div></div>'; return; }

        let html = '<table class="maptable"><thead><tr><th>Featured</th><th>Prodotto</th><th>Gallery</th><th>Tot</th></tr></thead><tbody>';
        for (const p of data) {
            const feat = p.featured_image
                ? '<img class="map-thumb" src="' + esc(p.featured_image.thumbnail_url) + '" />'
                : '<span class="map-none">nessuna</span>';
            const gal = p.gallery_images.length
                ? '<div class="map-gallery">' + p.gallery_images.slice(0, 5).map(g => '<img src="' + esc(g.thumbnail_url) + '" />').join('') + (p.gallery_images.length > 5 ? '<span class="map-none">+' + (p.gallery_images.length - 5) + '</span>' : '') + '</div>'
                : '<span class="map-none">\u2013</span>';
            html += '<tr><td>' + feat + '</td><td><div class="map-name">' + esc(p.name) + '</div><div class="map-sku">' + esc(p.sku || '') + ' \u00b7 #' + p.product_id + '</div></td><td>' + gal + '</td><td>' + p.total_images + '</td></tr>';
        }
        html += '</tbody></table>';
        area.innerHTML = html;
    }

    // ── BROWSE ──────────────────────────────────────────────
    let browseTimer;
    function debounceBrowse() {
        clearTimeout(browseTimer);
        browseTimer = setTimeout(browseMedia, 300);
    }

    async function browseMedia() {
        const q = document.getElementById('browse-search').value.trim();
        const res = await ajax('rp_mm_ajax_browse', { query: q, limit: 60 });
        if (!res.success) { toast('Errore', 'err'); return; }
        state.browseResults = res.data;
        renderBrowseGrid(res.data);
    }

    function renderBrowseGrid(data) {
        const grid = document.getElementById('browse-grid');
        if (!data.length) { grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="empty-text">Nessun risultato</div></div>'; return; }
        grid.innerHTML = data.map(a =>
            '<div class="media-card" onclick="RPMM.showUsage(' + a.id + ')">' +
            '<img class="media-thumb" src="' + esc(a.thumbnail_url) + '" loading="lazy" />' +
            '<div class="media-info"><div class="media-filename">' + esc(a.filename) + '</div><div class="media-size">' + a.filesize_human + ' \u00b7 #' + a.id + '</div></div></div>'
        ).join('');
    }

    async function showUsage(id) {
        const res = await ajax('rp_mm_ajax_usage', { attachment_id: id });
        if (!res.success) { toast('Errore', 'err'); return; }
        if (!res.data.length) { toast('Immagine #' + id + ': non usata da nessun prodotto', 'inf'); return; }
        const uses = res.data.map(u => u.name + ' (' + u.usage + ')').join(', ');
        toast('#' + id + ' usata da: ' + uses, 'inf', 5000);
    }

    // ── ORPHANS ─────────────────────────────────────────────
    async function scanOrphans() {
        const overlay = document.getElementById('scan-overlay');
        const btn = document.getElementById('btn-scan');
        const spin = document.getElementById('scan-spin');
        overlay.classList.add('visible');
        btn.disabled = true; spin.style.display = '';
        try {
            const res = await ajax('rp_mm_ajax_scan');
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
            state.orphans = res.data.orphans;
            state.selected = new Set();
            renderOrphanStats(res.data);
            renderOrphanGrid(res.data.orphans);
            toast('Scansione: ' + res.data.orphan_count + ' orfani trovati', res.data.orphan_count ? 'err' : 'ok');
        } catch (e) { toast('Errore di rete', 'err'); }
        finally { overlay.classList.remove('visible'); btn.disabled = false; spin.style.display = 'none'; }
    }

    function renderOrphanStats(data) {
        document.getElementById('orphan-stats').style.display = 'flex';
        document.getElementById('st-orphans').textContent = data.orphan_count;
        document.getElementById('st-used').textContent = data.used_count;
        document.getElementById('st-size').textContent = data.estimated_size.total_human;
    }

    function renderOrphanGrid(orphans) {
        const grid = document.getElementById('orphan-grid');
        if (!orphans.length) { grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">\u2713</div><div class="empty-text">Nessun orfano trovato</div></div>'; return; }
        grid.innerHTML = orphans.map(a => {
            const wl = a.is_whitelisted;
            return '<div class="media-card' + (wl ? ' whitelisted' : '') + (state.selected.has(a.id) ? ' selected' : '') + '">' +
                (wl ? '<span class="media-badge badge-wl">WL</span>' : '<input type="checkbox" class="media-check" ' + (state.selected.has(a.id) ? 'checked' : '') + ' onclick="event.stopPropagation();RPMM.toggleOrphanSelect(' + a.id + ')" />') +
                '<img class="media-thumb" src="' + esc(a.thumbnail_url) + '" loading="lazy" onclick="RPMM.orphanAction(' + a.id + ',' + wl + ')" />' +
                '<div class="media-info"><div class="media-filename">' + esc(a.filename) + '</div><div class="media-size">' + a.filesize_human + '</div></div></div>';
        }).join('');
        updateSelectionUI();
    }

    function toggleOrphanSelect(id) {
        if (state.selected.has(id)) state.selected.delete(id);
        else state.selected.add(id);
        updateSelectionUI();
        renderOrphanGrid(state.orphans);
    }

    function updateSelectionUI() {
        const n = state.selected.size;
        document.getElementById('btn-bulk-del').style.display = n ? '' : 'none';
        document.getElementById('sel-stat').style.display = n ? '' : 'none';
        document.getElementById('sel-n').textContent = n;
    }

    function orphanAction(id, isWhitelisted) {
        if (isWhitelisted) {
            if (confirm('Rimuovere #' + id + ' dalla whitelist?')) removeWhitelist(id);
        } else {
            const reason = prompt('Aggiungere #' + id + ' alla whitelist? Inserisci motivo (o annulla):');
            if (reason !== null) addWhitelist(id, reason);
        }
    }

    async function bulkDeleteOrphans() {
        const ids = Array.from(state.selected);
        if (!ids.length) return;
        if (!confirm('Eliminare definitivamente ' + ids.length + ' immagini orfane?\nQuesta azione non e reversibile.')) return;
        const res = await ajax('rp_mm_ajax_bulk_delete', { ids: JSON.stringify(ids) });
        if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }
        const d = res.data;
        toast('Eliminati: ' + d.deleted.length + ', Errori: ' + Object.keys(d.errors).length + ', Spazio liberato: ' + d.freed_human, d.deleted.length ? 'ok' : 'err', 5000);
        state.orphans = state.orphans.filter(a => !d.deleted.includes(a.id));
        state.selected = new Set();
        renderOrphanGrid(state.orphans);
    }

    // ── WHITELIST ───────────────────────────────────────────
    async function loadWhitelist() {
        const res = await ajax('rp_mm_ajax_get_whitelist');
        if (!res.success) { toast('Errore', 'err'); return; }
        state.whitelist = res.data;
        renderWhitelist(res.data);
    }

    function renderWhitelist(list) {
        const area = document.getElementById('wl-area');
        if (!list.length) { area.innerHTML = '<div class="empty-state"><div class="empty-text">Whitelist vuota</div></div>'; return; }
        area.innerHTML = list.map(e => {
            const thumbUrl = e.url || '';
            return '<div class="wl-row">' +
                '<img class="wl-thumb" src="' + esc(thumbUrl) + '" />' +
                '<div class="wl-info"><div class="wl-name">' + esc(e.reason || 'Nessun motivo') + '</div><div class="wl-reason">' + esc(thumbUrl) + '</div></div>' +
                '<span class="wl-id">#' + (e.id || '?') + '</span>' +
                '<button class="btn btn-ghost" onclick="RPMM.removeWhitelist(' + e.id + ')">Rimuovi</button>' +
                '</div>';
        }).join('');
    }

    async function addWhitelist(id, reason) {
        const res = await ajax('rp_mm_ajax_add_whitelist', { attachment_id: id, reason: reason });
        if (!res.success) { toast('Errore', 'err'); return; }
        toast('#' + id + ' aggiunto alla whitelist', 'ok');
        scanOrphans(); // Refresh
    }

    async function removeWhitelist(id) {
        const res = await ajax('rp_mm_ajax_remove_whitelist', { attachment_id: id });
        if (!res.success) { toast('Errore', 'err'); return; }
        toast('#' + id + ' rimosso dalla whitelist', 'ok');
        loadWhitelist();
    }

    return {
        switchTab, loadMapping, browseMedia, debounceBrowse, showUsage,
        scanOrphans, toggleOrphanSelect, orphanAction, bulkDeleteOrphans,
        loadWhitelist, addWhitelist, removeWhitelist,
    };
})();
</script>
<?php
}
