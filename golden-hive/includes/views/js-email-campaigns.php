// ═══ EMAIL — Wizard campagne (6 step) ══════════════════════════════════════

(function(){
    const ajax = GH.ajax, toast = GH.toast, esc = GH.esc;
    const $ = id => document.getElementById(id);

    let campaigns = [], editing = null, tplIndex = [], productCache = {};

    // ── LIST ────────────────────────────────────────────────────
    GH.emCampaignsLoad = async function() {
        const sp = $('em-camp-spin'); if (sp) sp.style.display = '';
        try {
            const [rc, rt] = await Promise.all([
                ajax('rp_em_ajax_campaign_list'),
                ajax('rp_em_ajax_template_list'),
            ]);
            if (!rc.success) { toast('Errore campagne', 'err'); return; }
            campaigns = rc.data || [];
            tplIndex  = rt.success ? (rt.data || []) : [];
            renderList();
        } finally { if (sp) sp.style.display = 'none'; }
    };

    function renderList() {
        $('em-camp-list-view').style.display = '';
        $('em-camp-wizard-view').style.display = 'none';
        const c = $('em-camp-list');
        if (!campaigns.length) {
            c.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9758;</div><div class="empty-text">Nessuna campagna.</div></div>';
            return;
        }
        c.innerHTML = campaigns.map(k => {
            const st = k.status || 'draft';
            const s = k.stats || {};
            return '<div class="rpem-camp-card" onclick="GH.emCampaignEdit(\'' + esc(k.id) + '\')">' +
                '<div class="rpem-camp-card-head"><span class="rpem-camp-name">' + esc(k.name || '(senza nome)') + '</span><span class="em-st em-st-' + esc(st) + '">' + esc(st) + '</span></div>' +
                '<div class="rpem-camp-card-subj">' + esc(k.subject || '') + '</div>' +
                '<div class="rpem-camp-card-meta"><span>ID ' + esc(k.id) + '</span><span>Tpl: ' + esc(k.template_id || '—') + '</span>' +
                '<span>Sent: ' + (s.sent|0) + '</span>' + (s.failed ? '<span style="color:var(--red)">Failed: ' + s.failed + '</span>' : '') +
                (k.scheduled_at ? '<span>Sched: ' + esc(k.scheduled_at) + '</span>' : '') + '</div></div>';
        }).join('');
    }

    // ── WIZARD OPEN ─────────────────────────────────────────────
    GH.emCampaignNew = function() {
        editing = { id:'', name:'', subject:'', preheader:'', template_id:'', payload:{}, product_ids:[], source_type:'hustle', module_ids:[], csv_contacts:'', rate_limit:200000, scheduled_at:'' };
        productCache = {};
        openWizard('Nuova campagna', false);
    };

    // Hand-off entry: apre il wizard in modalita nuova con product_ids pre-popolati.
    // Chiamato da Filtra & Agisci (GH.sendFilterSelectionToEmail) o altre sorgenti.
    GH.emCampaignOpenWithProducts = async function(productIds) {
        if (!Array.isArray(productIds) || !productIds.length) {
            toast('Nessun prodotto passato', 'err'); return;
        }
        // Carica la lista template se non in memoria (serve per il select)
        if (!tplIndex.length) {
            const rt = await ajax('rp_em_ajax_template_list');
            tplIndex = rt.success ? (rt.data || []) : [];
        }
        // Switcha alla tab Campagne
        const btn = document.querySelector('#gh .tab-item[onclick*="\'email-campaigns\'"]');
        if (btn) btn.click();
        // Apri wizard con product_ids pre-popolati
        editing = {
            id:'', name:'', subject:'', preheader:'', template_id:'', payload:{},
            product_ids: productIds.slice(),
            source_type:'hustle', module_ids:[], csv_contacts:'',
            rate_limit:200000, scheduled_at:''
        };
        productCache = {};
        openWizard('Nuova campagna · ' + productIds.length + ' prodotti', false);
        hydrateProductCache(productIds);
        toast(productIds.length + ' prodotti pronti nel wizard', 'ok');
    };

    GH.emCampaignEdit = async function(id) {
        const r = await ajax('rp_em_ajax_campaign_get', { id });
        if (!r.success) { toast('Errore', 'err'); return; }
        editing = r.data || {};
        if (!editing.payload)     editing.payload = {};
        if (!editing.product_ids) editing.product_ids = [];
        productCache = {};
        openWizard(editing.name || 'Campagna', true);
        // Pre-popola la cache dei prodotti gia associati alla campagna,
        // cosi lo step 4 mostra subito nome/SKU/prezzo/thumb invece di "(caricamento)".
        if (editing.product_ids.length) hydrateProductCache(editing.product_ids);
    };

    async function hydrateProductCache(ids) {
        const r = await ajax('rp_em_ajax_product_resolve', { product_ids: JSON.stringify(ids) });
        if (!r.success || !Array.isArray(r.data)) return;
        for (const row of r.data) {
            const rs = row.resolved || {};
            const slot = row.slot;
            productCache[row.product_id] = {
                id:        row.product_id,
                name:      rs['PRODUCT_' + slot + '_NAME']      || '(ID ' + row.product_id + ')',
                sku:       rs['PRODUCT_' + slot + '_SKU']       || '',
                price:     rs['PRODUCT_' + slot + '_PRICE']     || '',
                image_url: rs['PRODUCT_' + slot + '_IMAGE_URL'] || '',
            };
        }
        renderProductSlots();
    }

    GH.emCampaignBackToList = function() {
        GH.clearShortcuts();
        GH.clearDirty();
        GH.updateHash('email-campaigns');
        renderList();
    };

    GH.emCampaignCopyJSON = function() {
        if (!editing) { toast('Nessuna campagna aperta', 'err'); return; }
        GH.copyJSON(editing, 'Campagna');
    };

    GH.registerDeepOpener('email-campaigns', (id) => {
        if (id === 'new') return GH.emCampaignNew();
        GH.emCampaignEdit(id);
    });

    function openWizard(title, isExisting) {
        $('em-camp-list-view').style.display   = 'none';
        $('em-camp-wizard-view').style.display = 'flex';
        $('em-camp-wizard-title').textContent  = title;
        $('em-camp-delete-btn').style.display  = isExisting ? '' : 'none';
        GH.wireDirtyInputs('em-camp-wizard-view');
        GH.clearDirty();
        GH.registerShortcuts({ close: () => GH.emCampaignBackToList(), save: () => GH.emCampaignSave() });
        GH.updateHash('email-campaigns', editing && editing.id ? editing.id : 'new');

        // populate template select
        const sel = $('em-camp-template');
        sel.innerHTML = '<option value="">— seleziona —</option>' +
            tplIndex.map(t => '<option value="' + esc(t.id) + '"' + (t.id === editing.template_id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('');

        $('em-camp-name').value      = editing.name || '';
        $('em-camp-subject').value   = editing.subject || '';
        $('em-camp-preheader').value = editing.preheader || '';
        $('em-camp-source').value    = editing.source_type || 'hustle';
        $('em-camp-rate').value      = String(editing.rate_limit || 200000);
        $('em-camp-csv').value       = editing.csv_contacts || '';
        $('em-camp-scheduled').value = (editing.scheduled_at || '').replace(' ', 'T').slice(0, 16);
        GH.emCampaignOnSourceChange();

        if (editing.template_id) GH.emCampaignOnTemplateChange();
        else renderPayloadForm([]);

        renderProductSlots();
        renderValidation(editing.last_validation || null);
        $('em-camp-preview-frame').srcdoc = '';
        $('em-camp-preview-subject').textContent = '';
    }

    GH.emCampaignOnSourceChange = function() {
        $('em-camp-csv-row').style.display = $('em-camp-source').value === 'hustle' ? 'none' : '';
    };

    GH.emCampaignOnTemplateChange = async function() {
        editing.template_id = $('em-camp-template').value;
        if (!editing.template_id) { renderPayloadForm([]); return; }
        const r = await ajax('rp_em_ajax_template_get', { id: editing.template_id });
        if (!r.success) { toast('Errore template', 'err'); return; }
        const html = r.data.html || '';
        const pr = await ajax('rp_em_ajax_template_extract_placeholders', { html });
        const grouped = pr.success ? (pr.data.grouped || {}) : {};
        const campaignKeys = grouped.CAMPAIGN || [];
        renderPayloadForm(campaignKeys);
    };

    function renderPayloadForm(keys) {
        const c = $('em-camp-payload');
        if (!keys.length) { c.innerHTML = '<div class="em-hint">Seleziona un template con placeholder {CAMPAIGN_*}.</div>'; return; }
        c.innerHTML = keys.map(k => {
            const v = editing.payload[k] || '';
            return '<div class="cfg-row"><span class="cfg-label"><code>{' + esc(k) + '}</code></span>' +
                '<input class="cfg-input" data-payload-key="' + esc(k) + '" value="' + esc(v) + '" placeholder="Valore per ' + esc(k) + '" /></div>';
        }).join('');
        c.querySelectorAll('[data-payload-key]').forEach(el => {
            el.addEventListener('input', () => { editing.payload[el.dataset.payloadKey] = el.value; });
        });
    }

    // ── PRODUCT PICKER ──────────────────────────────────────────
    GH.emCampaignProductSearch = async function() {
        const q = $('em-camp-product-query').value.trim();
        const r = await ajax('rp_em_ajax_product_picker', { query: q, limit: 15 });
        if (!r.success) { toast('Errore ricerca', 'err'); return; }
        const results = r.data || [];
        const c = $('em-camp-product-results');
        if (!results.length) { c.innerHTML = '<div class="em-hint">Nessun prodotto trovato.</div>'; return; }
        c.innerHTML = results.map(p =>
            '<div class="rpem-product-row" onclick="GH.emCampaignProductAdd(' + p.id + ')">' +
            (p.image_url ? '<img src="' + esc(p.image_url) + '" alt="" class="rpem-product-thumb" />' : '<span class="rpem-product-thumb"></span>') +
            '<div class="rpem-product-meta"><div class="rpem-product-name">' + esc(p.name) + '</div>' +
            '<div class="rpem-product-sub">#' + p.id + ' · SKU ' + esc(p.sku || '—') + ' · ' + esc(p.price) + '</div></div></div>'
        ).join('');
        results.forEach(p => { productCache[p.id] = p; });
    };

    GH.emCampaignProductAdd = function(id) {
        if (editing.product_ids.includes(id)) { toast('Gia in lista', 'inf'); return; }
        editing.product_ids.push(id);
        renderProductSlots();
    };

    GH.emCampaignProductRemove = function(id) {
        editing.product_ids = editing.product_ids.filter(x => x !== id);
        renderProductSlots();
    };

    GH.emCampaignProductMove = function(id, delta) {
        const i = editing.product_ids.indexOf(id);
        const j = i + delta;
        if (i < 0 || j < 0 || j >= editing.product_ids.length) return;
        [editing.product_ids[i], editing.product_ids[j]] = [editing.product_ids[j], editing.product_ids[i]];
        renderProductSlots();
    };

    function renderProductSlots() {
        const c = $('em-camp-products');
        if (!editing.product_ids.length) { c.innerHTML = '<div class="em-hint">Nessun prodotto. Cercane uno qui sotto.</div>'; return; }
        c.innerHTML = editing.product_ids.map((id, i) => {
            const p = productCache[id] || { name: '(caricamento ID ' + id + ')', sku: '—', price: '—', image_url: '' };
            return '<div class="rpem-product-slot"><span class="rpem-slot-num">PRODUCT_' + (i + 1) + '</span>' +
                (p.image_url ? '<img src="' + esc(p.image_url) + '" alt="" class="rpem-product-thumb" />' : '<span class="rpem-product-thumb"></span>') +
                '<div class="rpem-product-meta"><div class="rpem-product-name">' + esc(p.name) + '</div>' +
                '<div class="rpem-product-sub">#' + id + ' · ' + esc(p.sku || '—') + ' · ' + esc(p.price || '—') + '</div></div>' +
                '<div class="rpem-slot-actions">' +
                '<button class="btn btn-ghost" onclick="GH.emCampaignProductMove(' + id + ',-1)">&uarr;</button>' +
                '<button class="btn btn-ghost" onclick="GH.emCampaignProductMove(' + id + ',1)">&darr;</button>' +
                '<button class="btn btn-ghost" onclick="GH.emCampaignProductRemove(' + id + ')" style="color:var(--red)">&times;</button>' +
                '</div></div>';
        }).join('');
    }

    // ── SAVE / VALIDATE / PREVIEW / SCHEDULE / SEND ─────────────
    function collectEditing() {
        editing.name      = $('em-camp-name').value.trim();
        editing.subject   = $('em-camp-subject').value.trim();
        editing.preheader = $('em-camp-preheader').value.trim();
        editing.template_id = $('em-camp-template').value;
        editing.source_type = $('em-camp-source').value;
        editing.rate_limit  = parseInt($('em-camp-rate').value, 10) || 200000;
        editing.csv_contacts = $('em-camp-csv').value;
        // payload e gia sincronizzato dagli input listener
        return editing;
    }

    GH.emCampaignSave = async function() {
        const sp = $('em-camp-save-spin'); sp.style.display = '';
        try {
            const data = collectEditing();
            if (!data.name)        { toast('Nome obbligatorio', 'err'); return; }
            if (!data.template_id) { toast('Template obbligatorio', 'err'); return; }
            const r = await ajax('rp_em_ajax_campaign_save', { campaign: JSON.stringify(data) });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            editing = r.data || editing;
            $('em-camp-delete-btn').style.display = '';
            $('em-camp-wizard-title').textContent = editing.name || 'Campagna';
            GH.clearDirty();
            GH.updateHash('email-campaigns', editing.id);
            toast('Campagna salvata', 'ok');
        } finally { sp.style.display = 'none'; }
    };

    GH.emCampaignDelete = async function() {
        if (!editing?.id) return;
        if (!await GH.confirm('Eliminare la campagna "' + (editing.name || editing.id) + '"?\nSe schedulata, il cron verra rimosso.', { title:'Elimina campagna', danger:true, okLabel:'Elimina' })) return;
        const r = await ajax('rp_em_ajax_campaign_delete', { id: editing.id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Eliminata', 'ok');
        editing = null;
        GH.emCampaignsLoad();
    };

    GH.emCampaignValidate = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        const sp = $('em-camp-validate-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_campaign_validate', { id: editing.id });
            if (!r.success) { toast('Errore validate', 'err'); return; }
            renderValidation(r.data);
            toast(r.data.ok ? 'Valida' : 'Errori presenti', r.data.ok ? 'ok' : 'err');
        } finally { sp.style.display = 'none'; }
    };

    function renderValidation(v) {
        const c = $('em-camp-validation');
        if (!v) { c.innerHTML = '<div class="em-hint">Clicca Valida per controllare la campagna.</div>'; return; }
        let h = '';
        if (v.errors && v.errors.length) {
            h += '<div class="rpem-v-errors"><div class="rpem-v-head">Errori (' + v.errors.length + ')</div>';
            h += v.errors.map(e => '<div class="rpem-v-row"><code>' + esc(e.code) + '</code><code>' + esc(e.key || '') + '</code><span>' + esc(e.message) + '</span></div>').join('');
            h += '</div>';
        }
        if (v.warnings && v.warnings.length) {
            h += '<div class="rpem-v-warns"><div class="rpem-v-head">Warnings (' + v.warnings.length + ')</div>';
            h += v.warnings.map(w => '<div class="rpem-v-row"><code>' + esc(w.code) + '</code><code>' + esc(w.key || '') + '</code><span>' + esc(w.message) + '</span></div>').join('');
            h += '</div>';
        }
        if (!h) h = '<div class="rpem-v-ok">OK — nessun errore, nessun warning.</div>';
        c.innerHTML = h;
    }

    // Memo dell'ultimo render per hand-off verso Test Email.
    let lastPreview = { html:'', subject:'', preheader:'' };

    GH.emCampaignPreview = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        const sp = $('em-camp-preview-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_campaign_preview', { id: editing.id });
            if (!r.success) { toast('Errore preview', 'err'); return; }
            lastPreview = { html: r.data.html || '', subject: r.data.subject || '', preheader: r.data.preheader || '' };
            $('em-camp-preview-frame').srcdoc = lastPreview.html;
            $('em-camp-preview-subject').textContent = lastPreview.subject;
        } finally { sp.style.display = 'none'; }
    };

    // Hand-off: l'HTML renderizzato diventa il body della tab Test Email.
    // Se il preview non e ancora stato eseguito, lo esegue prima.
    GH.emCampaignSendPreviewAsTest = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        if (!lastPreview.html) {
            toast('Rendering preview...', 'ok', 1500);
            await GH.emCampaignPreview();
            if (!lastPreview.html) return;
        }
        // Popola i campi della tab Test Email
        const to = $('em-test-to');       if (to && !to.value) to.value = '';
        const subj = $('em-test-subject'); if (subj) subj.value = lastPreview.subject;
        const body = $('em-test-body');    if (body) body.value = lastPreview.html;
        // Switcha tab
        const btn = document.querySelector('#gh .tab-item[onclick*="\'email-test\'"]');
        if (btn) btn.click();
        toast('HTML pronto in Test Email: inserisci destinatario e invia', 'ok');
    };

    GH.emCampaignSchedule = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        const scheduled_at = $('em-camp-scheduled').value;
        if (!scheduled_at) { toast('Imposta data/ora', 'err'); return; }
        const r = await ajax('rp_em_ajax_campaign_schedule', { id: editing.id, scheduled_at });
        if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
        editing = r.data || editing;
        toast('Schedulata', 'ok');
    };

    GH.emCampaignSend = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        if (!await GH.confirm('Invio IMMEDIATO a tutti i contatti della sorgente.\nNon si puo annullare una volta partiti gli invii. Procedere?', { title:'Invia campagna', danger:true, okLabel:'Invia ora' })) return;
        const sp = $('em-camp-send-spin'); sp.style.display = '';
        try {
            let r;
            try { r = await ajax('rp_em_ajax_campaign_send', { id: editing.id }); }
            catch (e) { toast('Errore di rete: ' + (e.message || 'fetch fallita'), 'err', 0); return; }
            if (!r || !r.success) {
                const msg = (r && r.data) ? (typeof r.data === 'string' ? r.data : JSON.stringify(r.data)) : 'invio fallito';
                toast('Errore invio: ' + msg, 'err', 0);
                return;
            }
            const d   = r.data || {};
            const sent   = d.sent|0;
            const failed = d.failed|0;
            const errs   = Array.isArray(d.errors) ? d.errors : [];
            // Se il server ha restituito errori, mostrali in una sticky
            // red toast: il vecchio comportamento "Inviate: 0 · Fallite: 0"
            // verde era indistinguibile da un successo reale.
            if (errs.length) {
                toast('Errore campagna: ' + errs.slice(0, 3).join(' | '), 'err', 0);
            } else if (sent === 0 && failed === 0) {
                toast('Nessun invio effettuato (0 sent, 0 failed). Controlla la sorgente contatti.', 'err', 0);
            } else {
                toast('Inviate: ' + sent + ' · Fallite: ' + failed, failed ? 'err' : 'ok', failed ? 0 : 3000);
            }
        } finally { sp.style.display = 'none'; }
    };

    // Diagnostic: read-only, esegue gli stessi check di Invia ora ma senza
    // inviare nulla. Mostra contatti risolti, render, lock, blockers nel
    // pannello validation cosi non devi leggere debug.log per capire perche
    // Invia fallisce.
    GH.emCampaignDiagnose = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        const sp = $('em-camp-diag-spin'); if (sp) sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_campaign_diagnose', { id: editing.id });
            if (!r || !r.success) {
                const msg = (r && r.data) ? (typeof r.data === 'string' ? r.data : JSON.stringify(r.data)) : 'diagnose fallita';
                toast('Errore diagnose: ' + msg, 'err', 0);
                return;
            }
            const d = r.data || {};
            const c = $('em-camp-validation');
            const blockers = Array.isArray(d.blockers) ? d.blockers : [];
            const rows = [
                ['Status campagna',      esc(d.campaign_status || '(vuoto)')],
                ['Template esiste',      d.template_exists ? 'si' : 'NO'],
                ['Render HTML',          d.render_ok ? (d.render_bytes|0) + ' bytes' : 'FALLITO · ' + esc(d.render_error || '')],
                ['Sorgente contatti',    esc(d.source_type || '')],
                ['Contatti risolti',     (d.contacts_count|0) + ' (sample: ' + (Array.isArray(d.contacts_sample) ? d.contacts_sample.map(esc).join(', ') : '') + ')'],
                ['Hustle installato',    d.hustle_installed ? 'si · tot subs ' + (d.hustle_subs_total|0) : 'NO'],
                ['Hustle moduli',        Array.isArray(d.hustle_modules) ? d.hustle_modules.length + ' (' + d.hustle_modules.map(m => '#' + m.module_id + ' ' + esc(m.module_name)).join(', ') + ')' : '0'],
                ['CSV contenuto',        d.csv_has_content ? 'si' : 'no'],
                ['Lock transient',       d.lock_active ? 'ATTIVO — blocca Invia' : 'libero'],
            ];
            let h = '<div class="rpem-v-errors"><div class="rpem-v-head">Diagnose campagna ' + esc(d.campaign_id || '') + '</div>';
            h += rows.map(([k, v]) => '<div class="rpem-v-row"><code>' + esc(k) + '</code><span>' + v + '</span></div>').join('');
            h += '</div>';
            if (blockers.length) {
                h += '<div class="rpem-v-errors"><div class="rpem-v-head">Blockers (' + blockers.length + ') — questi fermerebbero Invia ora</div>';
                h += blockers.map(b => '<div class="rpem-v-row"><code>BLOCK</code><span>' + esc(b) + '</span></div>').join('');
                h += '</div>';
            } else {
                h += '<div class="rpem-v-ok">Nessun blocker — Invia ora dovrebbe partire.</div>';
            }
            c.innerHTML = h;
            toast(blockers.length ? 'Diagnose: ' + blockers.length + ' blocker(s)' : 'Diagnose OK', blockers.length ? 'err' : 'ok', blockers.length ? 0 : 3000);
        } finally { if (sp) sp.style.display = 'none'; }
    };

    GH.emCampaignSendTest = async function() {
        if (!editing?.id) { toast('Salva prima la campagna', 'err'); return; }
        const to = $('em-camp-test-to').value.trim();
        if (!to) { toast('Destinatario test?', 'err'); return; }
        let r;
        try { r = await ajax('rp_em_ajax_campaign_send_test', { id: editing.id, to }); }
        catch (e) { toast('Errore di rete: ' + (e.message || 'fetch fallita'), 'err', 0); return; }
        if (!r || !r.success) {
            const msg = (r && r.data) ? (typeof r.data === 'string' ? r.data : JSON.stringify(r.data)) : 'test fallito';
            toast('Errore test: ' + msg, 'err', 0);
            return;
        }
        const d = r.data || {};
        toast(d.message || 'Test inviato', d.success ? 'ok' : 'err', d.success ? 3000 : 0);
    };
})();
