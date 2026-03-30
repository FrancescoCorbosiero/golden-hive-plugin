<?php
/**
 * Admin page — registrazione menu e render UI.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'RP REST Caller',
        'RP REST',
        'manage_woocommerce',
        'rp-rest-caller',
        'rp_rc_render_page',
        'dashicons-rest-api',
        60
    );
} );

function rp_rc_render_page(): void {
    $nonce = wp_create_nonce( 'rp_rc_nonce' );
    $ajax  = admin_url( 'admin-ajax.php' );
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
#rprc { all: initial; }
#rprc *, #rprc *::before, #rprc *::after { box-sizing: border-box; margin: 0; padding: 0; font-family: 'DM Sans', system-ui, sans-serif; }
#rprc {
    --bg: #0c0d10; --s1: #111317; --s2: #16181d; --s3: #1c1f26;
    --b1: #232630; --b2: #2e3240;
    --acc: #3d7fff; --grn: #22c78b; --red: #e85d5d; --amb: #e8a824; --pur: #9b72f5;
    --txt: #d8dce8; --dim: #5f6480; --mut: #2a2d3a;
    --mono: 'JetBrains Mono', 'Courier New', monospace;
    display: flex; flex-direction: column; height: 100vh;
    background: var(--bg); color: var(--txt); font-size: 13px;
    margin: -10px -20px -20px -20px; overflow: hidden;
}
#rprc .header { background: var(--s1); border-bottom: 1px solid var(--b1); padding: 10px 20px; display: flex; align-items: center; gap: 16px; flex-shrink: 0; }
#rprc .header-logo { font-family: var(--mono); font-size: 10px; font-weight: 600; letter-spacing: .2em; color: var(--acc); text-transform: uppercase; }
#rprc .header-desc { font-size: 11px; color: var(--dim); font-family: var(--mono); }
#rprc .main { flex: 1; display: flex; overflow: hidden; }
#rprc .tabs-col { width: 160px; background: var(--s1); border-right: 1px solid var(--b1); display: flex; flex-direction: column; flex-shrink: 0; }
#rprc .tab-item { padding: 14px 16px; cursor: pointer; border-left: 2px solid transparent; border-bottom: 1px solid var(--b1); transition: all .15s; display: flex; align-items: center; gap: 10px; }
#rprc .tab-item:hover { background: var(--s2); }
#rprc .tab-item.active { background: var(--s3); border-left-color: var(--acc); }
#rprc .tab-icon { font-size: 14px; width: 18px; text-align: center; }
#rprc .tab-label { font-size: 12px; font-weight: 500; color: var(--dim); }
#rprc .tab-item.active .tab-label { color: var(--txt); }
#rprc .content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
#rprc .panel { display: none; flex-direction: column; flex: 1; overflow: hidden; }
#rprc .panel.active { display: flex; }
#rprc .btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; border: 1px solid transparent; border-radius: 4px; font-family: var(--mono); font-size: 10px; font-weight: 600; letter-spacing: .06em; cursor: pointer; transition: all .15s; white-space: nowrap; }
#rprc .btn:disabled { opacity: .3; cursor: not-allowed; }
#rprc .btn-primary { background: var(--acc); color: #fff; border-color: var(--acc); }
#rprc .btn-primary:hover:not(:disabled) { filter: brightness(1.15); }
#rprc .btn-ghost { background: transparent; color: var(--dim); border-color: var(--b2); }
#rprc .btn-ghost:hover:not(:disabled) { color: var(--txt); background: var(--s3); }
#rprc .btn-warn { background: rgba(232,168,36,.15); color: var(--amb); border-color: rgba(232,168,36,.4); }
#rprc .btn-warn:hover:not(:disabled) { background: rgba(232,168,36,.25); }
#rprc .toolbar { background: var(--s2); border-bottom: 1px solid var(--b1); padding: 10px 20px; display: flex; align-items: center; gap: 12px; flex-shrink: 0; flex-wrap: wrap; }
#rprc .filter-sep { width: 1px; height: 20px; background: var(--b1); flex-shrink: 0; }

/* Config form */
#rprc .config-form { padding: 20px; display: flex; flex-direction: column; gap: 12px; flex-shrink: 0; border-bottom: 1px solid var(--b1); }
#rprc .cfg-row { display: flex; align-items: center; gap: 10px; }
#rprc .cfg-label { font-family: var(--mono); font-size: 9px; letter-spacing: .1em; text-transform: uppercase; color: var(--dim); min-width: 70px; }
#rprc .cfg-input { flex: 1; background: var(--s3); border: 1px solid var(--b1); border-radius: 4px; padding: 6px 10px; font-family: var(--mono); font-size: 11px; color: var(--txt); outline: none; }
#rprc .cfg-input:focus { border-color: var(--acc); }
#rprc .cfg-input::placeholder { color: var(--dim); }
#rprc .cfg-select { background: var(--s3); border: 1px solid var(--b1); border-radius: 4px; padding: 6px 8px; font-family: var(--mono); font-size: 11px; color: var(--txt); outline: none; }

/* Preview table */
#rprc .preview-wrap { flex: 1; overflow-y: auto; }
#rprc table.ptable { width: 100%; border-collapse: collapse; }
#rprc .ptable thead th { background: var(--s2); border-bottom: 2px solid var(--b1); padding: 8px 10px; font-family: var(--mono); font-size: 9px; letter-spacing: .1em; text-transform: uppercase; color: var(--dim); text-align: left; font-weight: 600; position: sticky; top: 0; z-index: 10; }
#rprc .ptable tbody tr { border-bottom: 1px solid var(--b1); }
#rprc .ptable tbody tr:hover { background: rgba(255,255,255,.02); }
#rprc .ptable td { padding: 6px 10px; font-family: var(--mono); font-size: 11px; vertical-align: middle; }
#rprc .st-new { color: var(--acc); }
#rprc .st-update { color: var(--amb); }
#rprc .st-unchanged { color: var(--dim); }
#rprc .st-created { color: var(--grn); }
#rprc .st-updated { color: var(--grn); }
#rprc .st-error { color: var(--red); }
#rprc .changes-list { font-size: 9px; color: var(--dim); }

/* Stats */
#rprc .stats-bar { background: var(--s1); border-bottom: 1px solid var(--b1); padding: 8px 20px; display: flex; gap: 20px; flex-shrink: 0; }
#rprc .stat { font-family: var(--mono); font-size: 10px; color: var(--dim); }
#rprc .stat span { font-weight: 600; }
#rprc .stat .blue { color: var(--acc); }
#rprc .stat .amber { color: var(--amb); }
#rprc .stat .green { color: var(--grn); }

/* Confirm bar */
#rprc .confirm-bar { background: var(--s1); border-top: 1px solid var(--b1); padding: 10px 20px; display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
#rprc .confirm-bar .summary-text { font-family: var(--mono); font-size: 11px; color: var(--txt); flex: 1; }
#rprc .confirm-bar .summary-text span { font-weight: 600; }

/* Empty state */
#rprc .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: var(--dim); }
#rprc .empty-icon { font-size: 32px; }
#rprc .empty-text { font-family: var(--mono); font-size: 12px; letter-spacing: .08em; text-align: center; }

/* Toast + Spinner + Overlay */
#rprc .toast-wrap { position: fixed; bottom: 20px; right: 20px; display: flex; flex-direction: column; gap: 6px; z-index: 9999; pointer-events: none; }
#rprc .toast { font-family: var(--mono); font-size: 11px; padding: 9px 14px; border-radius: 5px; border: 1px solid; pointer-events: none; max-width: 360px; animation: rprc-tin .18s ease; }
@keyframes rprc-tin { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
#rprc .toast.ok  { background: rgba(34,199,139,.15); border-color: rgba(34,199,139,.4); color: var(--grn); }
#rprc .toast.err { background: rgba(232,93,93,.15);  border-color: rgba(232,93,93,.4);  color: var(--red); }
#rprc .toast.inf { background: rgba(61,127,255,.15); border-color: rgba(61,127,255,.4); color: var(--acc); }
#rprc .spin { display: inline-block; width: 9px; height: 9px; border: 1.5px solid var(--b2); border-top-color: var(--acc); border-radius: 50%; animation: rprc-sp .5s linear infinite; }
@keyframes rprc-sp { to { transform: rotate(360deg); } }
#rprc .gen-overlay { display: none; position: absolute; inset: 0; background: rgba(12,13,16,.85); z-index: 50; align-items: center; justify-content: center; flex-direction: column; gap: 12px; }
#rprc .gen-overlay.visible { display: flex; }
#rprc .gen-text { font-family: var(--mono); font-size: 12px; color: var(--dim); }
#rprc .gen-spinner { width: 24px; height: 24px; border: 2px solid var(--b2); border-top-color: var(--acc); border-radius: 50%; animation: rprc-sp .6s linear infinite; }
#rprc *::-webkit-scrollbar { width: 4px; height: 4px; }
#rprc *::-webkit-scrollbar-thumb { background: var(--b2); border-radius: 2px; }
#rprc * { scrollbar-width: thin; scrollbar-color: var(--b2) transparent; }
@media (max-width: 768px) { #rprc .tabs-col { width: 48px; } #rprc .tab-label { display: none; } #rprc .tab-item { justify-content: center; padding: 14px 8px; } }
</style>

<div id="rprc">
    <div class="header">
        <div class="header-logo">RP &middot; REST</div>
        <div class="header-desc">Feed import &amp; API client</div>
    </div>

    <div class="main">
        <div class="tabs-col">
            <div class="tab-item active" onclick="RPRC.switchTab('gs', this)">
                <span class="tab-icon">&#9733;</span>
                <span class="tab-label">GS Feed</span>
            </div>
            <div class="tab-item" onclick="RPRC.switchTab('client', this)">
                <span class="tab-icon">&#8680;</span>
                <span class="tab-label">HTTP Client</span>
            </div>
        </div>

        <div class="content">
            <!-- GOLDEN SNEAKERS FEED -->
            <div class="panel active" id="panel-gs" style="position:relative">
                <div class="config-form">
                    <div class="cfg-row">
                        <span class="cfg-label">URL</span>
                        <input class="cfg-input" id="gs-url" placeholder="https://www.goldensneakers.net/api/assortment/?rounding_type=whole&amp;vat_percentage=22&amp;markup_percentage=30" />
                    </div>
                    <div class="cfg-row">
                        <span class="cfg-label">Token</span>
                        <input class="cfg-input" id="gs-token" type="password" placeholder="Bearer token" />
                    </div>
                    <div class="cfg-row">
                        <span class="cfg-label">Cookie</span>
                        <input class="cfg-input" id="gs-cookie" placeholder="csrftoken=... (opzionale)" />
                    </div>
                    <div class="cfg-row">
                        <span class="cfg-label">Formato</span>
                        <select class="cfg-select" id="gs-format">
                            <option value="hierarchical">Gerarchico (assortment/)</option>
                            <option value="flat">Flat (assortment-flat/)</option>
                        </select>
                        <button class="btn btn-primary" id="btn-gs-fetch" onclick="RPRC.gsFetch()">
                            <span class="spin" id="gs-fetch-spin" style="display:none"></span>
                            Fetch assortimento
                        </button>
                    </div>
                </div>

                <div class="stats-bar" id="gs-stats" style="display:none">
                    <div class="stat">Prodotti nel feed: <span class="blue" id="gs-total">0</span></div>
                    <div class="stat">Nuovi: <span class="blue" id="gs-new">0</span></div>
                    <div class="stat">Da aggiornare: <span class="amber" id="gs-update">0</span></div>
                    <div class="stat">Invariati: <span class="green" id="gs-unchanged">0</span></div>
                </div>

                <div class="preview-wrap" id="gs-preview">
                    <div class="empty-state">
                        <div class="empty-icon">&#9733;</div>
                        <div class="empty-text">Configura l'endpoint Golden Sneakers e premi "Fetch assortimento"</div>
                    </div>
                </div>

                <div class="confirm-bar" id="gs-confirm" style="display:none">
                    <div class="summary-text" id="gs-confirm-text"></div>
                    <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px">
                        <input type="checkbox" id="gs-opt-images" checked /> Sideload immagini
                    </label>
                    <button class="btn btn-ghost" onclick="RPRC.gsCancel()">Annulla</button>
                    <button class="btn btn-warn" id="btn-gs-apply" onclick="RPRC.gsApply()">
                        <span class="spin" id="gs-apply-spin" style="display:none"></span>
                        Importa
                    </button>
                </div>

                <div class="gen-overlay" id="gs-overlay">
                    <div class="gen-spinner"></div>
                    <div class="gen-text" id="gs-overlay-text">Fetch in corso...</div>
                </div>
            </div>

            <!-- HTTP CLIENT -->
            <div class="panel" id="panel-client">
                <div class="config-form">
                    <div class="cfg-row">
                        <span class="cfg-label">URL</span>
                        <input class="cfg-input" id="hc-url" placeholder="https://api.example.com/endpoint" />
                    </div>
                    <div class="cfg-row">
                        <span class="cfg-label">Metodo</span>
                        <select class="cfg-select" id="hc-method">
                            <option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option>
                        </select>
                        <span class="cfg-label">Headers</span>
                        <input class="cfg-input" id="hc-headers" placeholder='{"Authorization":"Bearer ..."}' />
                    </div>
                    <div class="cfg-row">
                        <span class="cfg-label">Body</span>
                        <input class="cfg-input" id="hc-body" placeholder="POST body (JSON)" />
                        <button class="btn btn-primary" onclick="RPRC.hcExecute()">
                            <span class="spin" id="hc-spin" style="display:none"></span>
                            Esegui
                        </button>
                    </div>
                </div>
                <div style="flex:1;overflow-y:auto;padding:16px;font-family:var(--mono);font-size:11px;line-height:1.7;white-space:pre-wrap;word-break:break-all" id="hc-response">
                    <div class="empty-state">
                        <div class="empty-icon">&#8680;</div>
                        <div class="empty-text">Configura ed esegui una richiesta HTTP</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="rprc-toasts" class="toast-wrap"></div>
</div>

<script>
const RPRC = (function() {
    const AJAX  = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';
    let gsProducts = null;
    let gsDiff = null;

    async function ajax(action, body = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', NONCE);
        Object.entries(body).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(AJAX, { method: 'POST', body: fd });
        return r.json();
    }

    function toast(msg, type = 'ok', ms = 3000) {
        const wrap = document.getElementById('rprc-toasts');
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(() => t.remove(), ms);
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function switchTab(name, el) {
        document.querySelectorAll('#rprc .tab-item').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('#rprc .panel').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('panel-' + name).classList.add('active');
    }

    // ── GOLDEN SNEAKERS: Fetch ──────────────────────────────
    async function gsFetch() {
        const overlay = document.getElementById('gs-overlay');
        const otext = document.getElementById('gs-overlay-text');
        const btn = document.getElementById('btn-gs-fetch');
        const spin = document.getElementById('gs-fetch-spin');
        otext.textContent = 'Fetch assortimento in corso...';
        overlay.classList.add('visible');
        btn.disabled = true; spin.style.display = '';

        try {
            const config = {
                url: document.getElementById('gs-url').value,
                token: document.getElementById('gs-token').value,
                cookie: document.getElementById('gs-cookie').value,
                format: document.getElementById('gs-format').value,
            };
            const res = await ajax('rp_rc_ajax_gs_fetch', { config: JSON.stringify(config) });
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }

            gsProducts = res.data.products;
            toast('Feed caricato: ' + res.data.product_count + ' prodotti', 'ok');

            // Diff
            otext.textContent = 'Confronto con WooCommerce...';
            const diffRes = await ajax('rp_rc_ajax_gs_preview', { products: JSON.stringify(gsProducts) });
            if (!diffRes.success) { toast('Errore diff: ' + diffRes.data, 'err'); return; }

            gsDiff = diffRes.data;
            renderGsPreview(diffRes.data);
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            overlay.classList.remove('visible');
            btn.disabled = false; spin.style.display = 'none';
        }
    }

    function renderGsPreview(diff) {
        const s = diff.summary;
        document.getElementById('gs-stats').style.display = 'flex';
        document.getElementById('gs-total').textContent = s.total;
        document.getElementById('gs-new').textContent = s.new;
        document.getElementById('gs-update').textContent = s.update;
        document.getElementById('gs-unchanged').textContent = s.unchanged;

        const area = document.getElementById('gs-preview');
        const all = [
            ...diff.new.map(p => ({ ...p, _action: 'new' })),
            ...diff.update.map(p => ({ ...p, _action: 'update' })),
            ...diff.unchanged.map(p => ({ ...p, _action: 'unchanged' })),
        ];

        let html = '<table class="ptable"><thead><tr><th>Azione</th><th>SKU</th><th>Nome</th><th>Brand</th><th>Modello</th><th>Taglie</th><th>Disponibili</th><th>Dettagli</th></tr></thead><tbody>';

        for (const p of all) {
            const cls = p._action === 'new' ? 'st-new' : p._action === 'update' ? 'st-update' : 'st-unchanged';
            const label = p._action === 'new' ? '+ Nuovo' : p._action === 'update' ? '\u21bb Aggiorna' : '\u2713 Invariato';
            const sizes = p.sizes ? p.sizes.length : (p.variations ? p.variations.length : 0);
            const avail = p.total_available ?? '?';
            const changes = (p._changes || []).slice(0, 3).join(', ');

            html += '<tr><td class="' + cls + '">' + label + '</td>' +
                '<td>' + esc(p.sku || '') + '</td>' +
                '<td>' + esc(p.name || '') + '</td>' +
                '<td>' + esc(p.brand || p._gs_brand || '') + '</td>' +
                '<td>' + esc(p.model || p._gs_model || '') + '</td>' +
                '<td>' + sizes + '</td>' +
                '<td>' + avail + '</td>' +
                '<td><span class="changes-list">' + esc(changes) + '</span></td></tr>';
        }

        html += '</tbody></table>';
        area.innerHTML = html;

        // Confirm bar
        if (s.new > 0 || s.update > 0) {
            const bar = document.getElementById('gs-confirm');
            const txt = document.getElementById('gs-confirm-text');
            let msg = '';
            if (s.new) msg += '<span>' + s.new + '</span> nuovi';
            if (s.new && s.update) msg += ', ';
            if (s.update) msg += '<span>' + s.update + '</span> da aggiornare';
            txt.innerHTML = msg;
            bar.style.display = 'flex';
        }
    }

    // ── GOLDEN SNEAKERS: Apply ──────────────────────────────
    async function gsApply() {
        if (!gsProducts) { toast('Nessun feed caricato', 'err'); return; }
        const overlay = document.getElementById('gs-overlay');
        const otext = document.getElementById('gs-overlay-text');
        const btn = document.getElementById('btn-gs-apply');
        const spin = document.getElementById('gs-apply-spin');
        otext.textContent = 'Importazione in corso...';
        overlay.classList.add('visible');
        btn.disabled = true; spin.style.display = '';

        try {
            const opts = {
                create_new: true,
                update_existing: true,
                sideload_images: document.getElementById('gs-opt-images').checked,
            };
            const res = await ajax('rp_rc_ajax_gs_apply', {
                products: JSON.stringify(gsProducts),
                options: JSON.stringify(opts),
            });
            if (!res.success) { toast('Errore: ' + res.data, 'err'); return; }

            renderGsResult(res.data);
            const s = res.data.summary;
            toast('Import completato: ' + s.created + ' creati, ' + s.updated + ' aggiornati, ' + s.errors + ' errori', s.errors ? 'err' : 'ok', 5000);
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            overlay.classList.remove('visible');
            btn.disabled = false; spin.style.display = 'none';
        }
    }

    function renderGsResult(data) {
        const area = document.getElementById('gs-preview');
        let html = '<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>SKU</th><th>Nome</th><th>Dettagli</th></tr></thead><tbody>';

        for (const d of data.details) {
            const cls = d.action === 'created' ? 'st-created' : d.action === 'updated' ? 'st-updated' : 'st-error';
            const label = d.action === 'created' ? '+ Creato' : d.action === 'updated' ? '\u2713 Aggiornato' : '\u2717 Errore';
            const extra = d.reason ? esc(d.reason) : (d.changes || []).join(', ');

            html += '<tr><td class="' + cls + '">' + label + '</td>' +
                '<td>' + (d.id || '\u2013') + '</td>' +
                '<td>' + esc(d.sku || '') + '</td>' +
                '<td>' + esc(d.name || '') + '</td>' +
                '<td><span class="changes-list">' + extra + '</span></td></tr>';
        }

        html += '</tbody></table>';
        area.innerHTML = html;
        document.getElementById('gs-confirm').style.display = 'none';
    }

    function gsCancel() {
        document.getElementById('gs-confirm').style.display = 'none';
        toast('Import annullato', 'inf');
    }

    // ── HTTP CLIENT ─────────────────────────────────────────
    function hl(json) {
        return String(json).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, m => {
                let c='jn'; if(/^"/.test(m)) c=/:$/.test(m)?'jk':'js'; else if(/true|false/.test(m)) c='jb'; else if(/null/.test(m)) c='jx';
                return '<span style="color:' + ({jk:'#a78bfa',js:'var(--grn)',jn:'var(--amb)',jb:'var(--acc)',jx:'var(--red)'}[c]) + '">' + m + '</span>';
            });
    }

    async function hcExecute() {
        const btn = document.querySelector('#panel-client .btn-primary');
        const spin = document.getElementById('hc-spin');
        btn.disabled = true; spin.style.display = '';

        try {
            const hdrs = document.getElementById('hc-headers').value;
            const config = {
                url: document.getElementById('hc-url').value,
                method: document.getElementById('hc-method').value,
                headers: hdrs ? JSON.parse(hdrs) : {},
                body: document.getElementById('hc-body').value,
            };
            const res = await ajax('rp_rc_ajax_execute', { config: JSON.stringify(config) });
            const out = document.getElementById('hc-response');
            if (!res.success) { out.textContent = 'Errore: ' + res.data; return; }

            let html = '<div style="margin-bottom:12px;color:var(--dim)">HTTP ' + res.data.status + ' \u00b7 ' + res.data.duration_ms + 'ms \u00b7 ' + res.data.format + '</div>';
            if (res.data.parsed) {
                html += hl(JSON.stringify(res.data.parsed, null, 2));
            } else {
                html += '<div style="color:var(--dim)">' + esc(res.data.body_raw || '') + '</div>';
            }
            out.innerHTML = html;
        } catch (e) {
            toast('Errore', 'err');
        } finally {
            btn.disabled = false; spin.style.display = 'none';
        }
    }

    return { switchTab, gsFetch, gsApply, gsCancel, hcExecute };
})();
</script>
<?php
}
