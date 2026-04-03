<?php
/**
 * Admin page — registrazione menu e render UI.
 * Tre tab: Test Email, Campagne, Contatti.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
    add_menu_page(
        'RP Email Marketing',
        'RP Email',
        'manage_woocommerce',
        'rp-email-marketing',
        'rp_em_render_page',
        'dashicons-email-alt',
        62
    );
} );

function rp_em_render_page(): void {
    $nonce = wp_create_nonce( 'rp_em_nonce' );
    $ajax  = admin_url( 'admin-ajax.php' );
    $admin_email = get_option( 'admin_email', '' );
    ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
#rpem { all: initial; }
#rpem *, #rpem *::before, #rpem *::after {
    box-sizing: border-box; margin: 0; padding: 0;
    font-family: 'DM Sans', system-ui, sans-serif;
}
#rpem {
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
#rpem .em-header {
    background: var(--s1); border-bottom: 1px solid var(--b1);
    padding: 12px 20px; display: flex; align-items: center; gap: 16px; flex-shrink: 0;
}
#rpem .em-logo {
    font-family: var(--mono); font-size: 10px; font-weight: 600;
    letter-spacing: .2em; color: var(--acc); text-transform: uppercase; white-space: nowrap;
}
#rpem .em-tabs { display: flex; gap: 2px; margin-left: 24px; }
#rpem .em-tab {
    padding: 7px 16px; border-radius: 5px 5px 0 0; cursor: pointer;
    font-size: 12px; font-weight: 500; color: var(--dim);
    background: transparent; border: none; transition: all .15s;
}
#rpem .em-tab:hover { color: var(--txt); background: var(--s2); }
#rpem .em-tab.active { color: var(--acc); background: var(--s2); border-bottom: 2px solid var(--acc); }
#rpem .em-route { color: var(--dim); font-family: var(--mono); font-size: 10px; margin-left: auto; }

/* Main content */
#rpem .em-body { flex: 1; overflow-y: auto; padding: 20px; }
#rpem .em-panel { display: none; }
#rpem .em-panel.active { display: block; }

/* Cards */
#rpem .card {
    background: var(--s2); border: 1px solid var(--b1); border-radius: 8px;
    padding: 20px; margin-bottom: 16px;
}
#rpem .card h3 {
    font-size: 14px; font-weight: 600; color: var(--txt); margin-bottom: 16px;
    padding-bottom: 10px; border-bottom: 1px solid var(--b1);
}

/* Form elements */
#rpem label { display: block; font-size: 11px; font-weight: 500; color: var(--dim); margin-bottom: 4px; text-transform: uppercase; letter-spacing: .05em; }
#rpem input[type="text"], #rpem input[type="email"], #rpem input[type="datetime-local"],
#rpem select, #rpem textarea {
    width: 100%; background: var(--s3); border: 1px solid var(--b1); border-radius: 5px;
    padding: 8px 12px; font-size: 13px; color: var(--txt); outline: none;
    transition: border-color .15s;
}
#rpem input:focus, #rpem select:focus, #rpem textarea:focus { border-color: var(--acc); }
#rpem input::placeholder, #rpem textarea::placeholder { color: var(--mut); }
#rpem textarea { font-family: var(--mono); font-size: 12px; resize: vertical; min-height: 120px; }
#rpem .form-row { margin-bottom: 14px; }
#rpem .form-hint { font-size: 11px; color: var(--dim); margin-top: 4px; }

/* Buttons */
#rpem .btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
    border: none; border-radius: 5px; font-size: 12px; font-weight: 600;
    cursor: pointer; transition: all .15s; white-space: nowrap;
}
#rpem .btn-primary { background: var(--acc); color: #fff; }
#rpem .btn-primary:hover { background: #5090ff; }
#rpem .btn-primary:disabled { background: var(--mut); color: var(--dim); cursor: not-allowed; }
#rpem .btn-secondary { background: var(--s3); color: var(--txt); border: 1px solid var(--b1); }
#rpem .btn-secondary:hover { border-color: var(--b2); background: var(--b1); }
#rpem .btn-danger { background: rgba(232,93,93,.15); color: var(--red); }
#rpem .btn-danger:hover { background: rgba(232,93,93,.25); }
#rpem .btn-success { background: rgba(34,199,139,.15); color: var(--grn); }
#rpem .btn-success:hover { background: rgba(34,199,139,.25); }
#rpem .btn-sm { padding: 5px 10px; font-size: 11px; }
#rpem .btn-group { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; }

/* Status badges */
#rpem .badge {
    display: inline-block; padding: 2px 8px; border-radius: 3px;
    font-family: var(--mono); font-size: 9px; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase;
}
#rpem .badge-draft { background: rgba(95,100,128,.15); color: var(--dim); }
#rpem .badge-scheduled { background: rgba(232,168,36,.12); color: var(--amb); }
#rpem .badge-sending { background: rgba(155,114,245,.15); color: var(--pur); }
#rpem .badge-sent { background: rgba(34,199,139,.12); color: var(--grn); }
#rpem .badge-failed { background: rgba(232,93,93,.12); color: var(--red); }

/* Toast */
#rpem .toasts { position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
#rpem .toast {
    padding: 10px 16px; border-radius: 6px; font-size: 12px; font-weight: 500;
    box-shadow: 0 4px 12px rgba(0,0,0,.4); animation: toastIn .2s ease;
    max-width: 360px;
}
#rpem .toast.ok { background: var(--grn); color: #fff; }
#rpem .toast.err { background: var(--red); color: #fff; }
#rpem .toast.inf { background: var(--acc); color: #fff; }
@keyframes toastIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: none; } }

/* Spinner */
#rpem .spin {
    display: inline-block; width: 9px; height: 9px;
    border: 1.5px solid var(--b2); border-top-color: var(--acc);
    border-radius: 50%; animation: sp .5s linear infinite;
}
@keyframes sp { to { transform: rotate(360deg); } }

/* Flex layouts */
#rpem .flex { display: flex; gap: 20px; }
#rpem .flex-1 { flex: 1; min-width: 0; }
#rpem .flex-sidebar { flex: 0 0 300px; }

/* Campaign list */
#rpem .campaign-item {
    padding: 10px 12px; border-bottom: 1px solid var(--b1); cursor: pointer;
    transition: background .1s; display: flex; align-items: center; gap: 10px;
}
#rpem .campaign-item:hover { background: var(--s3); }
#rpem .campaign-item.selected { background: var(--s3); border-left: 3px solid var(--acc); }
#rpem .ci-name { flex: 1; font-size: 13px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#rpem .ci-date { font-family: var(--mono); font-size: 10px; color: var(--dim); }
#rpem .ci-del { background: none; border: none; color: var(--dim); cursor: pointer; font-size: 14px; padding: 2px 6px; border-radius: 3px; }
#rpem .ci-del:hover { color: var(--red); background: rgba(232,93,93,.1); }

/* Source selector */
#rpem .source-opts { display: flex; gap: 12px; margin-bottom: 12px; }
#rpem .source-opt {
    display: flex; align-items: center; gap: 6px; cursor: pointer;
    font-size: 12px; color: var(--dim);
}
#rpem .source-opt input[type="radio"] { accent-color: var(--acc); width: auto; }
#rpem .source-opt.active { color: var(--txt); }

/* Module checkboxes */
#rpem .module-list { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
#rpem .module-chk { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--txt); cursor: pointer; }
#rpem .module-chk input[type="checkbox"] { accent-color: var(--acc); width: auto; }

/* Contact table */
#rpem .contact-table { width: 100%; border-collapse: collapse; font-size: 12px; }
#rpem .contact-table th {
    text-align: left; padding: 8px 10px; font-family: var(--mono); font-size: 10px;
    color: var(--dim); text-transform: uppercase; letter-spacing: .05em;
    border-bottom: 1px solid var(--b2); background: var(--s1);
}
#rpem .contact-table td { padding: 6px 10px; border-bottom: 1px solid var(--b1); color: var(--txt); }
#rpem .contact-table tr:hover td { background: var(--s3); }
#rpem .contact-count { font-family: var(--mono); font-size: 11px; color: var(--dim); padding: 8px 0; }

/* Preview */
#rpem .preview-box {
    background: #fff; color: #333; padding: 20px; border-radius: 6px;
    border: 1px solid var(--b2); max-height: 400px; overflow-y: auto;
    margin-top: 12px;
}

/* CSV area */
#rpem .csv-area { min-height: 80px; font-family: var(--mono); font-size: 11px; }
#rpem .file-upload { margin-top: 8px; }
#rpem .file-upload input[type="file"] { font-size: 12px; color: var(--dim); width: auto; }

/* Responsive */
@media (max-width: 768px) {
    #rpem .flex { flex-direction: column; }
    #rpem .flex-sidebar { flex: none; width: 100%; }
    #rpem .em-tabs { margin-left: 0; flex-wrap: wrap; }
    #rpem .em-route { display: none; }
    #rpem .btn-group { flex-direction: column; }
}
</style>

<div id="rpem">
    <div class="em-header">
        <span class="em-logo">RP Email</span>
        <div class="em-tabs">
            <button class="em-tab active" data-tab="test">Test Email</button>
            <button class="em-tab" data-tab="campaigns">Campagne</button>
            <button class="em-tab" data-tab="contacts">Contatti</button>
        </div>
        <span class="em-route">wp_mail() &rarr; SES</span>
    </div>
    <div class="toasts" id="rpem-toasts"></div>

    <div class="em-body">

        <!-- ── TAB: TEST EMAIL ── -->
        <div class="em-panel active" data-panel="test">
            <div class="card" style="max-width:600px;">
                <h3>Invia Email di Test</h3>
                <div class="form-row">
                    <label for="test-to">Destinatario</label>
                    <input type="email" id="test-to" value="<?php echo esc_attr( $admin_email ); ?>" placeholder="email@example.com">
                </div>
                <div class="form-row">
                    <label for="test-subject">Oggetto (opzionale)</label>
                    <input type="text" id="test-subject" placeholder="Lascia vuoto per oggetto di default">
                </div>
                <div class="form-row">
                    <label for="test-body">Corpo HTML (opzionale)</label>
                    <textarea id="test-body" rows="6" class="csv-area" placeholder="Lascia vuoto per template di test standard"></textarea>
                    <div class="form-hint">Se vuoto, viene usato un template di test con diagnostica del routing.</div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" id="btn-send-test">Invia Test</button>
                </div>
            </div>
        </div>

        <!-- ── TAB: CAMPAIGNS ── -->
        <div class="em-panel" data-panel="campaigns">
            <div class="flex">
                <div class="flex-sidebar">
                    <div class="card" style="padding:0;">
                        <div style="padding:12px 16px;border-bottom:1px solid var(--b1);display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-size:12px;font-weight:600;">Campagne</span>
                            <button class="btn btn-sm btn-secondary" id="btn-new-campaign">+ Nuova</button>
                        </div>
                        <div id="campaign-list" style="max-height:calc(100vh - 240px);overflow-y:auto;">
                            <div style="padding:16px;color:var(--dim);font-size:12px;text-align:center;">Caricamento...</div>
                        </div>
                    </div>
                </div>
                <div class="flex-1">
                    <div class="card" id="campaign-editor">
                        <h3 id="editor-title">Nuova Campagna</h3>
                        <input type="hidden" id="camp-id" value="">
                        <div class="form-row">
                            <label for="camp-name">Nome campagna</label>
                            <input type="text" id="camp-name" placeholder="Es: Lancio Jordan 4 Travis Scott">
                        </div>
                        <div class="form-row">
                            <label for="camp-subject">Oggetto email</label>
                            <input type="text" id="camp-subject" placeholder="Es: Nuovi arrivi esclusivi">
                        </div>
                        <div class="form-row">
                            <label for="camp-body">Corpo email (HTML)</label>
                            <textarea id="camp-body" rows="12" placeholder="<h1>Ciao {{first_name}}</h1>&#10;&#10;<p>Scopri le nostre novita...</p>"></textarea>
                            <div class="form-hint">Placeholder: <code style="color:var(--acc);">{{first_name}}</code> <code style="color:var(--acc);">{{email}}</code> <code style="color:var(--acc);">{{site_name}}</code></div>
                        </div>

                        <div class="form-row">
                            <label>Sorgente contatti</label>
                            <div class="source-opts">
                                <label class="source-opt active"><input type="radio" name="camp-source" value="hustle" checked> Hustle</label>
                                <label class="source-opt"><input type="radio" name="camp-source" value="csv"> CSV</label>
                                <label class="source-opt"><input type="radio" name="camp-source" value="mixed"> Misto</label>
                            </div>
                        </div>

                        <div class="form-row" id="hustle-section">
                            <label>Moduli Hustle</label>
                            <div id="module-list" class="module-list">
                                <span style="color:var(--dim);font-size:12px;">Caricamento moduli...</span>
                            </div>
                        </div>

                        <div class="form-row" id="csv-section" style="display:none;">
                            <label for="camp-csv">Contatti CSV</label>
                            <textarea id="camp-csv" class="csv-area" rows="4" placeholder="email,name&#10;john@example.com,John&#10;jane@example.com,Jane"></textarea>
                            <div class="file-upload">
                                <input type="file" id="csv-upload" accept=".csv,.txt">
                            </div>
                        </div>

                        <div class="flex" style="gap:12px;">
                            <div class="form-row flex-1">
                                <label for="camp-rate">Rate limit</label>
                                <select id="camp-rate">
                                    <option value="50000">Veloce — ~20/sec</option>
                                    <option value="200000" selected>Normale — ~5/sec</option>
                                    <option value="1000000">Lento — 1/sec</option>
                                </select>
                            </div>
                            <div class="form-row flex-1">
                                <label for="camp-schedule">Programmazione (opzionale)</label>
                                <input type="datetime-local" id="camp-schedule">
                            </div>
                        </div>

                        <div id="contact-summary" class="contact-count"></div>

                        <div class="btn-group">
                            <button class="btn btn-secondary" id="btn-save-draft">Salva Bozza</button>
                            <button class="btn btn-secondary" id="btn-preview">Anteprima</button>
                            <button class="btn btn-success" id="btn-schedule" style="display:none;">Programma Invio</button>
                            <button class="btn btn-primary" id="btn-send-now" disabled>Invia Ora (0 dest.)</button>
                        </div>

                        <div id="preview-container" style="display:none;margin-top:16px;">
                            <label>Anteprima email</label>
                            <div class="preview-box" id="preview-html"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── TAB: CONTACTS ── -->
        <div class="em-panel" data-panel="contacts">
            <div class="card">
                <h3>Contatti</h3>
                <div class="form-row">
                    <label>Moduli Hustle</label>
                    <div id="contacts-module-list" class="module-list">
                        <span style="color:var(--dim);font-size:12px;">Caricamento...</span>
                    </div>
                </div>
                <div class="btn-group" style="margin-bottom:16px;">
                    <button class="btn btn-secondary" id="btn-load-contacts">Carica Contatti</button>
                    <button class="btn btn-secondary" id="btn-export-csv">Esporta CSV</button>
                </div>
                <div id="contacts-summary" class="contact-count"></div>
                <div style="max-height:calc(100vh - 380px);overflow-y:auto;">
                    <table class="contact-table" id="contacts-table">
                        <thead>
                            <tr><th>Email</th><th>Nome</th><th>Modulo</th><th>Data</th></tr>
                        </thead>
                        <tbody id="contacts-tbody">
                            <tr><td colspan="4" style="text-align:center;color:var(--dim);padding:20px;">Seleziona i moduli e clicca "Carica Contatti"</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /em-body -->
</div><!-- /rpem -->

<script>
const RPEM = (function(){
    const AJAX = '<?php echo esc_js( $ajax ); ?>';
    const NONCE = '<?php echo esc_js( $nonce ); ?>';

    const state = {
        campaigns: [],
        currentId: null,
        modules: [],
        contactCount: 0,
    };

    // ── HELPERS ──────────────────────────────────────────────────

    function toast(msg, type, ms) {
        type = type || 'ok'; ms = ms || 3000;
        const wrap = document.getElementById('rpem-toasts');
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(function(){ t.remove(); }, ms);
    }

    function fd(data) {
        const f = new FormData();
        f.append('nonce', NONCE);
        for (const k in data) f.append(k, data[k]);
        return f;
    }

    async function ajax(action, data, method) {
        data = data || {};
        method = method || 'POST';
        data.action = action;
        const opts = { method: method, body: fd(data) };
        const res = await fetch(AJAX, opts);
        return res.json();
    }

    function $(sel) { return document.querySelector(sel); }
    function $$(sel) { return document.querySelectorAll(sel); }

    // ── TABS ────────────────────────────────────────────────────

    function initTabs() {
        $$('#rpem .em-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                $$('#rpem .em-tab').forEach(function(t){ t.classList.remove('active'); });
                $$('#rpem .em-panel').forEach(function(p){ p.classList.remove('active'); });
                tab.classList.add('active');
                const panel = $('#rpem .em-panel[data-panel="' + tab.dataset.tab + '"]');
                if (panel) panel.classList.add('active');
            });
        });
    }

    // ── TEST EMAIL ──────────────────────────────────────────────

    function initTest() {
        $('#btn-send-test').addEventListener('click', async function() {
            const btn = this;
            const to = $('#test-to').value.trim();
            if (!to) { toast('Inserisci un destinatario.', 'err'); return; }

            btn.disabled = true;
            btn.innerHTML = '<span class="spin"></span> Invio...';

            try {
                const r = await ajax('rp_em_ajax_send_test', {
                    to: to,
                    subject: $('#test-subject').value,
                    body: $('#test-body').value,
                });
                if (r.success) {
                    toast(r.data.message, 'ok');
                } else {
                    toast(r.data || 'Invio fallito.', 'err');
                }
            } catch(e) {
                toast('Errore di rete.', 'err');
            }
            btn.disabled = false;
            btn.textContent = 'Invia Test';
        });
    }

    // ── MODULES ─────────────────────────────────────────────────

    async function loadModules() {
        try {
            const r = await ajax('rp_em_ajax_get_modules');
            if (r.success) {
                state.modules = r.data;
                renderModules('module-list');
                renderModules('contacts-module-list');
            }
        } catch(e) {}
    }

    function renderModules(containerId) {
        const el = document.getElementById(containerId);
        if (!el) return;
        if (!state.modules.length) {
            el.innerHTML = '<span style="color:var(--dim);font-size:12px;">Nessun modulo Hustle trovato. Installa Hustle o usa CSV.</span>';
            return;
        }
        let html = '<label class="module-chk"><input type="checkbox" value="0" checked> Tutti</label>';
        state.modules.forEach(function(m) {
            html += '<label class="module-chk"><input type="checkbox" value="' + m.module_id + '"> ' + m.module_name + ' (' + m.module_type + ')</label>';
        });
        el.innerHTML = html;

        // "Tutti" toggle
        const allChk = el.querySelector('input[value="0"]');
        allChk.addEventListener('change', function() {
            el.querySelectorAll('input[type="checkbox"]').forEach(function(c) {
                if (c.value !== '0') c.checked = !allChk.checked;
            });
        });
    }

    function getSelectedModuleIds(containerId) {
        const el = document.getElementById(containerId);
        const allChk = el.querySelector('input[value="0"]');
        if (allChk && allChk.checked) return [];
        const ids = [];
        el.querySelectorAll('input[type="checkbox"]:checked').forEach(function(c) {
            if (c.value !== '0') ids.push(parseInt(c.value));
        });
        return ids;
    }

    // ── SOURCE TOGGLE ───────────────────────────────────────────

    function initSourceToggle() {
        $$('input[name="camp-source"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                $$('.source-opt').forEach(function(o){ o.classList.remove('active'); });
                radio.closest('.source-opt').classList.add('active');

                const v = radio.value;
                $('#hustle-section').style.display = (v === 'hustle' || v === 'mixed') ? '' : 'none';
                $('#csv-section').style.display = (v === 'csv' || v === 'mixed') ? '' : 'none';
                updateContactCount();
            });
        });
    }

    // ── CONTACT COUNT ───────────────────────────────────────────

    async function updateContactCount() {
        const source = document.querySelector('input[name="camp-source"]:checked').value;
        const moduleIds = getSelectedModuleIds('module-list');

        try {
            const r = await ajax('rp_em_ajax_get_contacts', {
                source_type: source,
                module_ids: JSON.stringify(moduleIds),
                csv_raw: (source === 'csv' || source === 'mixed') ? $('#camp-csv').value : '',
            });
            if (r.success) {
                const c = r.data.counts;
                state.contactCount = c.total;
                $('#contact-summary').textContent = c.total + ' contatti (' + c.hustle + ' Hustle, ' + c.csv + ' CSV)';
                $('#btn-send-now').textContent = 'Invia Ora (' + c.total + ' dest.)';
                $('#btn-send-now').disabled = c.total === 0;
            }
        } catch(e) {}
    }

    // ── CAMPAIGNS ───────────────────────────────────────────────

    async function loadCampaigns() {
        try {
            const r = await ajax('rp_em_ajax_get_campaigns');
            if (r.success) {
                state.campaigns = r.data;
                renderCampaignList();
            }
        } catch(e) {}
    }

    function renderCampaignList() {
        const el = document.getElementById('campaign-list');
        if (!state.campaigns.length) {
            el.innerHTML = '<div style="padding:16px;color:var(--dim);font-size:12px;text-align:center;">Nessuna campagna. Crea la prima!</div>';
            return;
        }
        let html = '';
        state.campaigns.forEach(function(c) {
            const sel = c.id === state.currentId ? ' selected' : '';
            html += '<div class="campaign-item' + sel + '" data-id="' + c.id + '">'
                + '<span class="ci-name">' + esc(c.name || 'Senza nome') + '</span>'
                + '<span class="badge badge-' + c.status + '">' + c.status + '</span>'
                + '<span class="ci-date">' + (c.created_at || '').substring(0, 10) + '</span>'
                + '<button class="ci-del" data-id="' + c.id + '" title="Elimina">&times;</button>'
                + '</div>';
        });
        el.innerHTML = html;

        // Click handlers
        el.querySelectorAll('.campaign-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                if (e.target.classList.contains('ci-del')) return;
                loadCampaignIntoEditor(item.dataset.id);
            });
        });
        el.querySelectorAll('.ci-del').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (confirm('Eliminare questa campagna?')) deleteCampaign(btn.dataset.id);
            });
        });
    }

    function loadCampaignIntoEditor(id) {
        const c = state.campaigns.find(function(x){ return x.id === id; });
        if (!c) return;

        state.currentId = id;
        renderCampaignList(); // update selection

        $('#camp-id').value = c.id;
        $('#camp-name').value = c.name || '';
        $('#camp-subject').value = c.subject || '';
        $('#camp-body').value = c.body || '';
        $('#camp-rate').value = c.rate_limit || 200000;
        $('#camp-schedule').value = (c.scheduled_at || '').replace(' ', 'T');
        $('#editor-title').textContent = 'Modifica: ' + (c.name || c.id);

        // Source
        const src = c.source_type || 'hustle';
        const radio = document.querySelector('input[name="camp-source"][value="' + src + '"]');
        if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change')); }

        // CSV
        if (c.csv_contacts) $('#camp-csv').value = c.csv_contacts;

        // Modules
        if (c.module_ids && c.module_ids.length) {
            const allChk = document.querySelector('#module-list input[value="0"]');
            if (allChk) allChk.checked = false;
            document.querySelectorAll('#module-list input[type="checkbox"]').forEach(function(chk) {
                if (chk.value !== '0') chk.checked = c.module_ids.includes(parseInt(chk.value));
            });
        }

        updateContactCount();
    }

    function resetEditor() {
        state.currentId = null;
        $('#camp-id').value = '';
        $('#camp-name').value = '';
        $('#camp-subject').value = '';
        $('#camp-body').value = '';
        $('#camp-rate').value = 200000;
        $('#camp-schedule').value = '';
        $('#editor-title').textContent = 'Nuova Campagna';
        $('#preview-container').style.display = 'none';
        const radio = document.querySelector('input[name="camp-source"][value="hustle"]');
        if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change')); }
        state.contactCount = 0;
        $('#contact-summary').textContent = '';
        $('#btn-send-now').textContent = 'Invia Ora (0 dest.)';
        $('#btn-send-now').disabled = true;
        renderCampaignList();
    }

    function buildCampaignPayload() {
        const source = document.querySelector('input[name="camp-source"]:checked').value;
        const data = {
            name: $('#camp-name').value,
            subject: $('#camp-subject').value,
            body: $('#camp-body').value,
            source_type: source,
            module_ids: getSelectedModuleIds('module-list'),
            csv_contacts: (source === 'csv' || source === 'mixed') ? $('#camp-csv').value : '',
            rate_limit: parseInt($('#camp-rate').value),
            scheduled_at: ($('#camp-schedule').value || '').replace('T', ' '),
        };
        if ($('#camp-id').value) data.id = $('#camp-id').value;
        return data;
    }

    async function saveDraft() {
        const data = buildCampaignPayload();
        if (!data.name || !data.subject || !data.body) {
            toast('Nome, oggetto e corpo sono obbligatori.', 'err');
            return;
        }
        try {
            const r = await ajax('rp_em_ajax_save_campaign', { campaign: JSON.stringify(data) });
            if (r.success) {
                toast('Campagna salvata.', 'ok');
                state.currentId = r.data.id;
                $('#camp-id').value = r.data.id;
                loadCampaigns();
            } else {
                toast(r.data || 'Errore nel salvataggio.', 'err');
            }
        } catch(e) { toast('Errore di rete.', 'err'); }
    }

    async function deleteCampaign(id) {
        try {
            const r = await ajax('rp_em_ajax_delete_campaign', { campaign_id: id });
            if (r.success) {
                toast('Campagna eliminata.', 'ok');
                if (state.currentId === id) resetEditor();
                loadCampaigns();
            } else {
                toast(r.data || 'Errore.', 'err');
            }
        } catch(e) { toast('Errore di rete.', 'err'); }
    }

    async function previewCampaign() {
        const id = $('#camp-id').value;
        if (!id) { toast('Salva prima la campagna.', 'err'); return; }

        try {
            const r = await ajax('rp_em_ajax_preview_campaign', { campaign_id: id });
            if (r.success) {
                $('#preview-html').innerHTML = r.data.html;
                $('#preview-container').style.display = '';
                toast('Anteprima per ' + r.data.contact_count + ' contatti.', 'inf');
            } else {
                toast(r.data || 'Errore anteprima.', 'err');
            }
        } catch(e) { toast('Errore di rete.', 'err'); }
    }

    async function sendNow() {
        const id = $('#camp-id').value;
        if (!id) { toast('Salva prima la campagna.', 'err'); return; }
        if (!confirm('Inviare la campagna a ' + state.contactCount + ' destinatari?\n\nQuesta azione non e reversibile.')) return;

        const btn = $('#btn-send-now');
        btn.disabled = true;
        btn.innerHTML = '<span class="spin"></span> Invio in corso...';

        try {
            const r = await ajax('rp_em_ajax_send_campaign', { campaign_id: id });
            if (r.success) {
                let msg = r.data.sent + ' email inviate';
                if (r.data.failed) msg += ', ' + r.data.failed + ' fallite';
                toast(msg, r.data.failed ? 'err' : 'ok', 5000);
                loadCampaigns();
            } else {
                toast(r.data || 'Errore invio.', 'err');
            }
        } catch(e) { toast('Errore di rete.', 'err'); }

        btn.disabled = false;
        btn.textContent = 'Invia Ora (' + state.contactCount + ' dest.)';
    }

    async function scheduleCampaign() {
        const id = $('#camp-id').value;
        const dt = $('#camp-schedule').value;
        if (!id) { toast('Salva prima la campagna.', 'err'); return; }
        if (!dt) { toast('Seleziona data/ora di programmazione.', 'err'); return; }

        try {
            const r = await ajax('rp_em_ajax_schedule_campaign', {
                campaign_id: id,
                scheduled_at: dt.replace('T', ' '),
            });
            if (r.success) {
                toast(r.data.message, 'ok');
                loadCampaigns();
            } else {
                toast(r.data || 'Errore schedulazione.', 'err');
            }
        } catch(e) { toast('Errore di rete.', 'err'); }
    }

    // ── CAMPAIGN BUTTONS ────────────────────────────────────────

    function initCampaignButtons() {
        $('#btn-new-campaign').addEventListener('click', resetEditor);
        $('#btn-save-draft').addEventListener('click', saveDraft);
        $('#btn-preview').addEventListener('click', previewCampaign);
        $('#btn-send-now').addEventListener('click', sendNow);
        $('#btn-schedule').addEventListener('click', scheduleCampaign);

        // Show/hide schedule button
        $('#camp-schedule').addEventListener('input', function() {
            $('#btn-schedule').style.display = this.value ? '' : 'none';
        });

        // CSV upload
        $('#csv-upload').addEventListener('change', async function() {
            const file = this.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('action', 'rp_em_ajax_upload_csv');
            formData.append('nonce', NONCE);
            formData.append('csv_file', file);

            try {
                const res = await fetch(AJAX, { method: 'POST', body: formData });
                const r = await res.json();
                if (r.success) {
                    // Build CSV text from contacts
                    let csv = 'email,name\n';
                    r.data.contacts.forEach(function(c) {
                        csv += c.email + ',' + (c.display_name || '') + '\n';
                    });
                    $('#camp-csv').value = csv;
                    toast(r.data.count + ' contatti importati da ' + r.data.filename, 'ok');
                    updateContactCount();
                } else {
                    toast(r.data || 'Errore upload.', 'err');
                }
            } catch(e) { toast('Errore di rete.', 'err'); }
        });
    }

    // ── CONTACTS TAB ────────────────────────────────────────────

    function initContacts() {
        $('#btn-load-contacts').addEventListener('click', async function() {
            const moduleIds = getSelectedModuleIds('contacts-module-list');
            try {
                const r = await ajax('rp_em_ajax_get_contacts', {
                    source_type: 'hustle',
                    module_ids: JSON.stringify(moduleIds),
                });
                if (r.success) {
                    const contacts = r.data.contacts;
                    const c = r.data.counts;
                    $('#contacts-summary').textContent = c.total + ' contatti trovati (' + c.hustle + ' Hustle, ' + c.csv + ' CSV)';
                    renderContactsTable(contacts);
                }
            } catch(e) { toast('Errore di rete.', 'err'); }
        });

        $('#btn-export-csv').addEventListener('click', function() {
            const moduleIds = getSelectedModuleIds('contacts-module-list');
            const url = AJAX + '?action=rp_em_ajax_export_csv&nonce=' + NONCE
                + (moduleIds.length ? '&module_ids=' + moduleIds.join(',') : '');
            window.location.href = url;
        });
    }

    function renderContactsTable(contacts) {
        const tbody = document.getElementById('contacts-tbody');
        if (!contacts.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--dim);padding:20px;">Nessun contatto trovato.</td></tr>';
            return;
        }
        let html = '';
        const limit = Math.min(contacts.length, 200);
        for (let i = 0; i < limit; i++) {
            const c = contacts[i];
            html += '<tr>'
                + '<td style="font-family:var(--mono);font-size:11px;">' + esc(c.email) + '</td>'
                + '<td>' + esc(c.display_name || '-') + '</td>'
                + '<td style="font-family:var(--mono);font-size:10px;color:var(--dim);">' + (c.module_id || '-') + '</td>'
                + '<td style="font-family:var(--mono);font-size:10px;color:var(--dim);">' + esc((c.date_created || '-').substring(0, 10)) + '</td>'
                + '</tr>';
        }
        if (contacts.length > 200) {
            html += '<tr><td colspan="4" style="text-align:center;color:var(--dim);padding:10px;">...e altri ' + (contacts.length - 200) + ' contatti</td></tr>';
        }
        tbody.innerHTML = html;
    }

    // ── UTIL ────────────────────────────────────────────────────

    function esc(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ── INIT ────────────────────────────────────────────────────

    function init() {
        initTabs();
        initTest();
        initSourceToggle();
        initCampaignButtons();
        initContacts();
        loadModules();
        loadCampaigns();
    }

    return { init: init };
})();

document.addEventListener('DOMContentLoaded', RPEM.init);
</script>
<?php } ?>
