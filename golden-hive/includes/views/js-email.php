// ═══ EMAIL — Brand + Templates + Contacts + Test + History + Seed ══════════
// Wizard campagne in js-email-campaigns.php. IIFE che attacca a GH.

(function(){
    const ajax = GH.ajax, toast = GH.toast, esc = GH.esc;
    const $ = id => document.getElementById(id);

    // ── BRAND ───────────────────────────────────────────────────
    let brandSchema = [], brandData = {};

    GH.emBrandLoad = async function() {
        const r = await ajax('rp_em_ajax_brand_get');
        if (!r.success) { toast('Errore brand', 'err'); return; }
        brandSchema = r.data.schema || [];
        brandData   = r.data.brand || {};
        renderBrandForm();
        GH.wireDirtyInputs('em-brand-form');
        GH.clearDirty();
        GH.registerShortcuts({ save: () => GH.emBrandSave() });
    };

    GH.emBrandCopyJSON = function() {
        GH.copyJSON(brandData || {}, 'Brand');
    };

    function renderBrandForm() {
        const c = $('em-brand-form');
        if (!c) return;
        let h = '';
        for (const sec of brandSchema) {
            h += '<div class="rpem-brand-section"><h3>' + esc(sec.section) + '</h3>';
            for (const f of sec.fields) {
                const v = brandData[f.key] || '';
                const req = f.required ? ' <span class="rpem-req">*</span>' : '';
                h += '<div class="cfg-row"><span class="cfg-label">' + esc(f.label) + req + '</span>';
                if (f.type === 'textarea') {
                    h += '<textarea class="cfg-input em-textarea-sm" data-brand-key="' + esc(f.key) + '">' + esc(v) + '</textarea>';
                } else if (f.type === 'color') {
                    h += '<input class="cfg-input rpem-color" type="color" data-brand-key="' + esc(f.key) + '" value="' + esc(v || '#000000') + '" />';
                    h += '<input class="cfg-input rpem-color-hex" type="text" data-brand-key-mirror="' + esc(f.key) + '" value="' + esc(v) + '" placeholder="#000000" />';
                } else {
                    const t = f.type === 'email' ? 'email' : (f.type === 'url' ? 'url' : 'text');
                    h += '<input class="cfg-input" type="' + t + '" data-brand-key="' + esc(f.key) + '" value="' + esc(v) + '" />';
                }
                h += '<code class="rpem-brand-key">{' + esc(f.key) + '}</code></div>';
            }
            h += '</div>';
        }
        c.innerHTML = h;
        c.querySelectorAll('.rpem-color').forEach(el => {
            const key = el.dataset.brandKey;
            const mirror = c.querySelector('[data-brand-key-mirror="' + key + '"]');
            if (!mirror) return;
            el.addEventListener('input',     () => { mirror.value = el.value; });
            mirror.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(mirror.value)) el.value = mirror.value; });
        });
    }

    GH.emBrandSave = async function() {
        const sp = $('em-brand-save-spin'); sp.style.display = '';
        const data = {};
        document.querySelectorAll('#em-brand-form [data-brand-key]').forEach(el => {
            if (el.type === 'color') return;
            data[el.dataset.brandKey] = el.value;
        });
        document.querySelectorAll('#em-brand-form [data-brand-key-mirror]').forEach(el => {
            data[el.dataset.brandKeyMirror] = el.value;
        });
        try {
            const r = await ajax('rp_em_ajax_brand_save', { brand: JSON.stringify(data) });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            brandData = r.data.brand || {};
            GH.clearDirty();
            toast('Brand salvato', 'ok');
        } finally { sp.style.display = 'none'; }
    };

    GH.emBrandReset = async function() {
        if (!await GH.confirm('Ripristinare il brand ai valori di default?\nLe tue impostazioni attuali andranno perse.', { title:'Reset brand', danger:true, okLabel:'Ripristina' })) return;
        const r = await ajax('rp_em_ajax_brand_reset');
        if (!r.success) { toast('Errore', 'err'); return; }
        brandData = r.data.brand || {};
        renderBrandForm();
        toast('Brand ripristinato', 'ok');
    };

    // ── TEMPLATES ────────────────────────────────────────────────
    let templates = [], editingTpl = null;

    GH.emTplLoad = async function() {
        const r = await ajax('rp_em_ajax_template_list');
        if (!r.success) { toast('Errore templates', 'err'); return; }
        templates = r.data || [];
        renderTplList();
        // Tieni in sync il dropdown della Test Email, se presente.
        if (typeof GH.emTestPopulateTemplates === 'function') GH.emTestPopulateTemplates();
    };

    function renderTplList() {
        $('em-tpl-list-view').style.display = '';
        $('em-tpl-editor-view').style.display = 'none';
        const c = $('em-tpl-list');
        if (!templates.length) {
            c.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Nessun template.</div></div>';
            return;
        }
        c.innerHTML = templates.map(t =>
            '<div class="rpem-tpl-card" onclick="GH.emTplEdit(\'' + esc(t.id) + '\')">' +
            '<div class="rpem-tpl-card-name">' + esc(t.name || '(senza nome)') + '</div>' +
            '<div class="rpem-tpl-card-desc">' + esc(t.description || '') + '</div>' +
            '<div class="rpem-tpl-card-meta"><span>ID ' + esc(t.id) + '</span><span>' + (t.placeholder_count|0) + ' placeholder</span></div>' +
            '</div>'
        ).join('');
    }

    GH.emTplNew = function() {
        editingTpl = null;
        $('em-tpl-editor-title').textContent = 'Nuovo template';
        $('em-tpl-delete-btn').style.display = 'none';
        $('em-tpl-name').value = '';
        $('em-tpl-desc').value = '';
        $('em-tpl-html').value = '';
        $('em-tpl-list-view').style.display = 'none';
        $('em-tpl-editor-view').style.display = 'flex';
        GH.emTplExtractPlaceholders();
        GH.wireDirtyInputs('em-tpl-editor-view');
        GH.clearDirty();
        GH.registerShortcuts({ close: () => GH.emTplBackToList(), save: () => GH.emTplSave() });
        GH.updateHash('email-templates', 'new');
    };

    GH.emTplEdit = async function(id) {
        const r = await ajax('rp_em_ajax_template_get', { id });
        if (!r.success) { toast('Errore', 'err'); return; }
        editingTpl = r.data;
        $('em-tpl-editor-title').textContent = editingTpl.name || 'Template';
        $('em-tpl-delete-btn').style.display = '';
        $('em-tpl-name').value = editingTpl.name || '';
        $('em-tpl-desc').value = editingTpl.description || '';
        $('em-tpl-html').value = editingTpl.html || '';
        $('em-tpl-list-view').style.display = 'none';
        $('em-tpl-editor-view').style.display = 'flex';
        GH.emTplExtractPlaceholders();
        GH.wireDirtyInputs('em-tpl-editor-view');
        GH.clearDirty();
        GH.registerShortcuts({ close: () => GH.emTplBackToList(), save: () => GH.emTplSave() });
        GH.updateHash('email-templates', id);
    };

    GH.emTplBackToList = function() {
        GH.clearShortcuts();
        GH.clearDirty();
        GH.updateHash('email-templates');
        renderTplList();
    };

    GH.emTplCopyJSON = function() {
        if (!editingTpl) { toast('Salva prima', 'err'); return; }
        GH.copyJSON(editingTpl, 'Template');
    };

    // Deep-link opener: apri l'editor se l'URL e #/email-templates/<id>
    GH.registerDeepOpener('email-templates', (id) => {
        if (id === 'new') return GH.emTplNew();
        GH.emTplEdit(id);
    });

    GH.emTplSave = async function() {
        const sp = $('em-tpl-save-spin'); sp.style.display = '';
        const payload = {
            id: editingTpl?.id || '',
            name: $('em-tpl-name').value.trim(),
            description: $('em-tpl-desc').value.trim(),
            html: $('em-tpl-html').value,
        };
        if (!payload.name) { toast('Nome obbligatorio', 'err'); sp.style.display = 'none'; return; }
        try {
            const r = await ajax('rp_em_ajax_template_save', { template: JSON.stringify(payload) });
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            editingTpl = r.data;
            $('em-tpl-editor-title').textContent = editingTpl.name;
            $('em-tpl-delete-btn').style.display = '';
            GH.clearDirty();
            GH.updateHash('email-templates', editingTpl.id);
            toast('Template salvato', 'ok');
        } finally { sp.style.display = 'none'; }
    };

    GH.emTplDelete = async function() {
        if (!editingTpl?.id) return;
        if (!await GH.confirm('Eliminare il template "' + (editingTpl.name || editingTpl.id) + '"?\nLe campagne che lo usano smetteranno di validare.', { title:'Elimina template', danger:true, okLabel:'Elimina' })) return;
        const r = await ajax('rp_em_ajax_template_delete', { id: editingTpl.id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Template eliminato', 'ok');
        editingTpl = null;
        GH.emTplLoad();
    };

    // ── TEMPLATE EXPORT (download HTML grezzo / renderizzato demo) ──────

    function downloadText(filename, text, mime) {
        const blob = new Blob([text], { type: (mime || 'text/html') + ';charset=utf-8' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = filename; document.body.appendChild(a); a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 100);
    }

    function slugify(s) {
        return (s || 'template').toString()
            .toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'template';
    }

    GH.emTplDownloadRaw = function() {
        const html = $('em-tpl-html').value || '';
        if (!html) { toast('HTML vuoto', 'err'); return; }
        const name = $('em-tpl-name').value.trim() || 'template';
        downloadText(slugify(name) + '.raw.html', html);
        toast('HTML grezzo scaricato', 'ok');
    };

    GH.emTplDownloadDemo = async function() {
        if (!editingTpl?.id) { toast('Salva il template prima di esportarlo con dati demo', 'err'); return; }
        const sp = $('em-tpl-demo-spin'); if (sp) sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_template_render_demo', { id: editingTpl.id });
            if (!r.success) { toast('Errore: ' + (r.data || 'render fallito'), 'err'); return; }
            const name = editingTpl.name || 'template';
            downloadText(slugify(name) + '.demo.html', r.data.html || '');
            const unres = (r.data.unresolved_keys || []).length;
            toast('HTML demo scaricato' + (unres ? ' (' + unres + ' placeholder non coperti)' : ''), 'ok');
        } finally { if (sp) sp.style.display = 'none'; }
    };

    let tplTimer = null;
    let tplAsideMode = 'ph';  // 'ph' | 'preview'
    GH.emTplExtractPlaceholders = function() {
        clearTimeout(tplTimer);
        tplTimer = setTimeout(async () => {
            const html = $('em-tpl-html').value || '';
            // Sempre aggiorna i placeholder (servono anche quando preview attivo)
            const r = await ajax('rp_em_ajax_template_extract_placeholders', { html });
            if (r.success) renderTplPlaceholders(r.data.grouped || {});
            // Se la modalita aside e "preview", renderizza anche l'iframe.
            if (tplAsideMode === 'preview' && editingTpl && editingTpl.id) {
                refreshTplPreview();
            }
        }, 350);
    };

    async function refreshTplPreview() {
        if (!editingTpl || !editingTpl.id) return;
        const iframe = $('em-tpl-preview-iframe');
        if (!iframe) return;
        const r = await ajax('rp_em_ajax_template_render_demo', { id: editingTpl.id });
        if (!r.success) return;
        iframe.srcdoc = r.data.html || '';
    }

    GH.emTplSetAsideMode = function(mode) {
        tplAsideMode = (mode === 'preview') ? 'preview' : 'ph';
        const body = $('em-tpl-ph-body'), iframe = $('em-tpl-preview-iframe');
        const tPh = $('em-tpl-tab-ph'), tPv = $('em-tpl-tab-preview');
        if (tplAsideMode === 'preview') {
            body.style.display = 'none';
            iframe.style.display = 'block';
            tPh.classList.remove('is-active');
            tPv.classList.add('is-active');
            if (!editingTpl || !editingTpl.id) {
                iframe.srcdoc = '<div style="font-family:system-ui;padding:20px;color:#888">Salva il template prima per vedere l\'anteprima live.</div>';
            } else {
                refreshTplPreview();
            }
        } else {
            body.style.display = '';
            iframe.style.display = 'none';
            tPh.classList.add('is-active');
            tPv.classList.remove('is-active');
        }
    };

    function renderTplPlaceholders(grouped) {
        const c = $('em-tpl-ph-body');
        if (!c) return;
        const order = ['BRAND', 'CAMPAIGN', 'PRODUCT', 'ORDER', 'RECIPIENT', 'META', 'UNKNOWN'];
        const hasAny = order.some(ns => (grouped[ns] || []).length > 0);
        if (!hasAny) { c.innerHTML = '<div class="em-hint">Nessun placeholder trovato.</div>'; return; }
        let h = '';
        for (const ns of order) {
            const keys = grouped[ns] || [];
            if (!keys.length) continue;
            const cls = ns === 'UNKNOWN' ? 'rpem-ns-unknown' : 'rpem-ns-' + ns.toLowerCase();
            h += '<div class="rpem-ph-group ' + cls + '"><div class="rpem-ph-head">' + ns + ' <span>' + keys.length + '</span></div><div class="rpem-ph-list">';
            h += keys.map(k => '<code>{' + esc(k) + '}</code>').join('');
            h += '</div></div>';
        }
        c.innerHTML = h;
    }

    // ── TEST + SEED ─────────────────────────────────────────────

    // Inizializza il tab Test Email: popola il dropdown template.
    // Se la lista e gia in memoria la usa; altrimenti la carica.
    GH.emTestInit = async function() {
        if (!templates || !templates.length) {
            const r = await ajax('rp_em_ajax_template_list');
            if (r.success) templates = r.data || [];
        }
        GH.emTestPopulateTemplates();
    };

    GH.emSendTest = async function() {
        const to = $('em-test-to').value.trim();
        const subject = $('em-test-subject').value.trim();
        const body = $('em-test-body').value.trim();
        if (!to) { toast('Destinatario?', 'err'); return; }
        const sp = $('em-test-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_send_test', { to, subject, body });
            if (r.success) toast(r.data.message || 'Inviata', 'ok');
            else toast('Errore: ' + (r.data || 'invio fallito'), 'err');
        } finally { sp.style.display = 'none'; }
    };

    // ── TEST: template selector + load with demo values ─────────
    GH.emTestPopulateTemplates = function() {
        const sel = $('em-test-template');
        if (!sel) return;
        const current = sel.value;
        sel.innerHTML = '<option value="">&mdash; HTML libero &mdash;</option>' +
            templates.map(t => '<option value="' + esc(t.id) + '"' + (current === t.id ? ' selected' : '') + '>' + esc(t.name || t.id) + '</option>').join('');
        // Abilita "Carica" solo se un template e selezionato
        const btn = $('em-test-load-btn'); if (btn) btn.disabled = !sel.value;
    };

    GH.emTestOnTemplateChange = function() {
        const btn = $('em-test-load-btn');
        const sel = $('em-test-template');
        if (btn && sel) btn.disabled = !sel.value;
    };

    GH.emTestLoadTemplate = async function() {
        const id = $('em-test-template').value;
        if (!id) { toast('Seleziona un template', 'err'); return; }
        const sp = $('em-test-load-spin'); if (sp) sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_template_render_demo', { id });
            if (!r.success) { toast('Errore: ' + (r.data || 'render fallito'), 'err'); return; }
            const d = r.data;
            $('em-test-body').value    = d.html || '';
            $('em-test-subject').value = d.subject || '';
            const unres = d.unresolved_keys || [];
            const box = $('em-test-unresolved');
            if (box) {
                if (unres.length) {
                    box.innerHTML = '<strong>' + unres.length + ' placeholder senza valore demo:</strong> ' +
                        unres.map(k => '<code>{' + esc(k) + '}</code>').join(' ') +
                        '<br><em>Sostituiti con marker <code>[KEY]</code> nel body. Modifica il body prima di inviare.</em>';
                    box.style.display = '';
                } else {
                    box.innerHTML = '<strong>&#10003; Tutti i placeholder sono stati risolti con dati demo.</strong>';
                    box.style.display = '';
                }
            }
            toast('Template caricato con dati demo', 'ok');
        } finally { if (sp) sp.style.display = 'none'; }
    };

    GH.emSeedDemo = async function() {
        const reset_brand = $('em-seed-reset-brand').checked ? '1' : '';
        const sp = $('em-seed-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_seed_demo', { reset_brand });
            if (!r.success) { toast('Errore seed', 'err'); return; }
            const d = r.data;
            let h = '<div class="rpem-seed-ok">';
            h += '<div>Template: <code>' + esc(d.template_id || '—') + '</code></div>';
            h += '<div>Campaign: <code>' + esc(d.campaign_id || '—') + '</code></div>';
            h += '<div>Prodotti: ' + (d.product_ids || []).join(', ') + '</div>';
            h += '<ul>' + (d.messages || []).map(m => '<li>' + esc(m) + '</li>').join('') + '</ul>';
            h += '</div>';
            $('em-seed-result').innerHTML = h;
            toast('Seed completato', 'ok');
        } finally { sp.style.display = 'none'; }
    };

    // ── CONTACTS ────────────────────────────────────────────────
    GH.emContactsInit = function() { GH.emContactsLoad(); };

    GH.emContactsLoad = async function() {
        const source = $('em-ct-source').value;
        $('em-ct-upload').style.display = source === 'csv' ? '' : 'none';
        if (source === 'csv') return;
        const sp = $('em-ct-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_get_contacts', { source_type: source });
            if (!r.success) { toast('Errore contatti', 'err'); return; }
            renderContacts(r.data.contacts || [], r.data.counts || {});
        } finally { sp.style.display = 'none'; }
    };

    GH.emContactsUploadFile = function() {
        toast('CSV upload via file: usa la textarea CSV nella campagna (wizard step 5).', 'inf', 5000);
    };

    function renderContacts(contacts, counts) {
        const s = $('em-ct-stats');
        if (counts && counts.total > 0) {
            s.style.display = '';
            $('em-ct-total').textContent  = counts.total;
            $('em-ct-hustle').textContent = counts.hustle || 0;
            $('em-ct-csv').textContent    = counts.csv || 0;
        } else { s.style.display = 'none'; }
        const c = $('em-ct-list');
        if (!contacts.length) {
            c.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9786;</div><div class="empty-text">Nessun contatto.</div></div>';
            return;
        }
        c.innerHTML = contacts.slice(0, 500).map(ct =>
            '<div class="rpem-ct-row"><span class="rpem-ct-email">' + esc(ct.email || '') + '</span>' +
            '<span class="rpem-ct-name">' + esc(ct.display_name || '—') + '</span>' +
            '<span class="rpem-ct-src">' + esc(ct.source || 'hustle') + '</span></div>'
        ).join('');
    }

    // ── HISTORY ─────────────────────────────────────────────────
    let historyTimer = null;
    GH.emHistoryDebounce = function() { clearTimeout(historyTimer); historyTimer = setTimeout(GH.emHistoryLoad, 300); };

    GH.emHistoryLoad = async function() {
        const sp = $('em-h-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_get_log', {
                type: $('em-h-type').value,
                status: $('em-h-status').value,
                search: $('em-h-search').value,
                limit: 200,
            });
            if (!r.success) { toast('Errore storico', 'err'); return; }
            renderHistory(r.data.entries || [], r.data.stats || {});
        } finally { sp.style.display = 'none'; }
    };

    function renderHistory(entries, stats) {
        const s = $('em-h-stats');
        if (stats.total > 0) {
            s.style.display = '';
            $('em-h-total').textContent  = stats.total;
            $('em-h-sent').textContent   = stats.sent || 0;
            $('em-h-failed').textContent = stats.failed || 0;
        } else { s.style.display = 'none'; }
        const c = $('em-h-list');
        if (!entries.length) {
            c.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9202;</div><div class="empty-text">Nessuna email nel log.</div></div>';
            return;
        }
        c.innerHTML = entries.map(e =>
            '<div class="rpem-h-row rpem-h-' + esc(e.status || '') + '">' +
            '<span class="rpem-h-status">' + esc(e.status || '') + '</span>' +
            '<span class="rpem-h-to">' + esc(e.to || '') + '</span>' +
            '<span class="rpem-h-subject">' + esc(e.subject || '') + '</span>' +
            '<span class="rpem-h-type">' + esc(e.type || '') + '</span>' +
            '<span class="rpem-h-date">' + esc(e.sent_at || '') + '</span>' +
            '</div>'
        ).join('');
    }

    GH.emHistoryClear = async function() {
        if (!await GH.confirm('Svuotare completamente lo storico email?\nI log storici di test e campagne verranno cancellati (non recuperabili).', { title:'Svuota storico', danger:true, okLabel:'Svuota' })) return;
        const r = await ajax('rp_em_ajax_clear_log');
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Storico svuotato', 'ok');
        GH.emHistoryLoad();
    };
})();
