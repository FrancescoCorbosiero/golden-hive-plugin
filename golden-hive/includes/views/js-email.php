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
            toast('Brand salvato', 'ok');
        } finally { sp.style.display = 'none'; }
    };

    GH.emBrandReset = async function() {
        if (!confirm('Ripristinare il brand ai valori di default?')) return;
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
    };

    GH.emTplBackToList = function() { renderTplList(); };

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
            toast('Template salvato', 'ok');
        } finally { sp.style.display = 'none'; }
    };

    GH.emTplDelete = async function() {
        if (!editingTpl?.id) return;
        if (!confirm('Eliminare "' + (editingTpl.name || editingTpl.id) + '"?')) return;
        const r = await ajax('rp_em_ajax_template_delete', { id: editingTpl.id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Template eliminato', 'ok');
        editingTpl = null;
        GH.emTplLoad();
    };

    let tplTimer = null;
    GH.emTplExtractPlaceholders = function() {
        clearTimeout(tplTimer);
        tplTimer = setTimeout(async () => {
            const html = $('em-tpl-html').value || '';
            const r = await ajax('rp_em_ajax_template_extract_placeholders', { html });
            if (!r.success) return;
            renderTplPlaceholders(r.data.grouped || {});
        }, 300);
    };

    function renderTplPlaceholders(grouped) {
        const c = $('em-tpl-ph-body');
        if (!c) return;
        const order = ['BRAND', 'CAMPAIGN', 'PRODUCT', 'RECIPIENT', 'META', 'UNKNOWN'];
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
        if (!confirm('Svuotare completamente lo storico email?')) return;
        const r = await ajax('rp_em_ajax_clear_log');
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Storico svuotato', 'ok');
        GH.emHistoryLoad();
    };
})();
