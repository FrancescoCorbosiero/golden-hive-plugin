// ═══ INLINE EDITOR ════════════════════════════════════════════════════════
//
// Focused single-product editor. Three sub-views:
//   Form       — validated fields for name/sku/prices/stock/SEO/status
//   JSON       — raw payload textarea, dev-first: edit and Apply
//   Variations — inline table (only for variable products)
//
// Public surface:
//   GH.openInlineEditor(productId)  — callable from Filtra & Agisci rows
//   GH.ieSearch / ieSearchKey       — search bar handlers
//   GH.ieSwitch / ieSave / ieReload / ieUnload
//   GH.ieFormChanged / ieJsonApply  — form ↔ JSON

(function() {

    let ie = {
        product: null,       // rp_get_product payload
        variations: [],      // rp_get_product_variations payload
        dirty: {},           // field → new value (form mode)
        varDirty: {},        // variation_id → { field → val }
        activeTab: 'form',   // form | json | variations
        searchTimer: null,
    };

    function esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }

    // ── SEARCH ──────────────────────────────────────────────────────────────

    GH.ieSearch = function() {
        clearTimeout(ie.searchTimer);
        const q = document.getElementById('ie-search').value.trim();
        if (q.length < 1) { closeDrop(); return; }
        ie.searchTimer = setTimeout(() => doSearch(q), 280);
    };

    GH.ieSearchKey = function(e) {
        const drop = document.getElementById('ie-search-drop');
        const items = drop.querySelectorAll('.ie-sr');
        const focused = drop.querySelector('.ie-sr-focus');
        if (e.key === 'Escape') { closeDrop(); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); const next = focused ? (focused.nextElementSibling || items[0]) : items[0]; if (focused) focused.classList.remove('ie-sr-focus'); if (next) next.classList.add('ie-sr-focus'); return; }
        if (e.key === 'ArrowUp')   { e.preventDefault(); const prev = focused ? (focused.previousElementSibling || items[items.length-1]) : items[items.length-1]; if (focused) focused.classList.remove('ie-sr-focus'); if (prev) prev.classList.add('ie-sr-focus'); return; }
        if (e.key === 'Enter') { e.preventDefault(); const t = focused || items[0]; if (t) t.click(); }
    };

    async function doSearch(q) {
        const drop = document.getElementById('ie-search-drop');
        drop.innerHTML = '<div class="ie-sr-empty"><span class="spin"></span></div>';
        drop.classList.add('open');
        const r = await GH.ajax('gh_ajax_product_search', { query: q });
        if (!r.success) { drop.innerHTML = '<div class="ie-sr-empty">Errore</div>'; return; }
        if (!r.data.length) { drop.innerHTML = '<div class="ie-sr-empty">Nessun risultato</div>'; return; }
        drop.innerHTML = r.data.map(p =>
            '<div class="ie-sr" onclick="GH.openInlineEditor(' + p.id + ')">' +
            '<span class="ie-sr-id">#' + p.id + '</span>' +
            '<span class="ie-sr-name">' + esc(p.name) + '</span>' +
            '<span class="ie-sr-sku">' + esc(p.sku || '—') + '</span>' +
            '<span class="badge badge-' + p.type + '">' + p.type + '</span>' +
            '</div>'
        ).join('');
    }

    function closeDrop() {
        const d = document.getElementById('ie-search-drop');
        if (d) { d.classList.remove('open'); d.innerHTML = ''; }
    }

    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#ie-search') && !e.target.closest('#ie-search-drop')) closeDrop();
    });

    // ── LOAD / UNLOAD ───────────────────────────────────────────────────────

    GH.openInlineEditor = async function(id) {
        // If called from Filtra & Agisci, switch to the inline-editor tab first
        const tab = document.querySelector('.tab-item[onclick*="inline-editor"]');
        if (tab && !document.getElementById('panel-inline-editor').classList.contains('active')) {
            GH.switchTab('inline-editor', tab);
        }
        closeDrop();
        document.getElementById('ie-search').value = '';
        const ov = document.getElementById('ie-overlay'), ot = document.getElementById('ie-overlay-text');
        if (ov) ov.classList.add('visible');
        if (ot) ot.textContent = 'Caricamento prodotto #' + id + '...';
        try {
            const r = await GH.ajax('gh_ajax_product_load', { product_id: id });
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            ie.product    = r.data.product;
            ie.variations = r.data.variations || [];
            ie.dirty      = {};
            ie.varDirty   = {};
            ie.activeTab  = 'form';
            renderHeader();
            renderCurrentTab();
            updateDirtyState();
        } finally {
            if (ov) ov.classList.remove('visible');
        }
    };

    GH.ieUnload = function() {
        if (Object.keys(ie.dirty).length || Object.keys(ie.varDirty).length) {
            if (!confirm('Modifiche non salvate. Chiudere comunque?')) return;
        }
        ie.product = null; ie.variations = []; ie.dirty = {}; ie.varDirty = {};
        document.getElementById('ie-header').style.display = 'none';
        document.getElementById('ie-tabs').style.display = 'none';
        document.getElementById('ie-content').innerHTML =
            '<div class="empty-state"><div class="empty-icon">&#9783;</div><div class="empty-text">Cerca un prodotto per iniziare. Da Filtra & Agisci puoi usare "Edit" per aprirlo direttamente qui.</div></div>';
    };

    GH.ieReload = function() {
        if (!ie.product) return;
        GH.openInlineEditor(ie.product.id);
    };

    // ── HEADER ──────────────────────────────────────────────────────────────

    function renderHeader() {
        if (!ie.product) return;
        const p = ie.product;
        document.getElementById('ie-header').style.display = '';
        document.getElementById('ie-tabs').style.display = '';
        document.getElementById('ie-h-id').textContent = '#' + p.id;
        document.getElementById('ie-h-name').textContent = p.name;
        document.getElementById('ie-h-sku').textContent = p.sku ? 'SKU: ' + p.sku : '';
        document.getElementById('ie-h-type').innerHTML = '<span class="badge badge-' + p.type + '">' + p.type + '</span>';
        document.getElementById('ie-h-status').innerHTML = '<span class="badge badge-' + p.status + '">' + p.status + '</span>';
        document.getElementById('ie-h-link').href = p.permalink || '#';
        // Show variations tab only for variable
        document.getElementById('ie-subtab-variations').style.display = p.type === 'variable' ? '' : 'none';
        // Reset sub-tab active states
        document.querySelectorAll('.ie-subtab').forEach(b => b.classList.remove('active'));
        document.querySelector('.ie-subtab[data-ie-tab="form"]').classList.add('active');
    }

    // ── SUB-TAB SWITCHING ───────────────────────────────────────────────────

    GH.ieSwitch = function(tab) {
        ie.activeTab = tab;
        document.querySelectorAll('.ie-subtab').forEach(b => b.classList.toggle('active', b.dataset.ieTab === tab));
        renderCurrentTab();
    };

    function renderCurrentTab() {
        if (!ie.product) return;
        const area = document.getElementById('ie-content');
        if (ie.activeTab === 'form')       renderForm(area);
        else if (ie.activeTab === 'json')  renderJson(area);
        else if (ie.activeTab === 'variations') renderVariations(area);
    }

    // ── FORM TAB ────────────────────────────────────────────────────────────

    function renderForm(area) {
        const p = ie.product;
        const v = (field) => ie.dirty[field] !== undefined ? ie.dirty[field] : (p[field] ?? '');
        const strip = s => String(s || '').replace(/<[^>]+>/g, '');
        let h = '<div class="ie-form-grid">';
        h += field('Nome', 'text', 'name', v('name'));
        h += field('SKU', 'text', 'sku', v('sku'));
        h += field('Stato', 'select', 'status', v('status'), ['publish', 'draft', 'private']);
        h += field('Prezzo Regolare', 'number', 'regular_price', v('regular_price'));
        h += field('Prezzo Scontato', 'number', 'sale_price', v('sale_price'));
        h += field('Stock Status', 'select', 'stock_status', v('stock_status'), ['instock', 'outofstock', 'onbackorder']);
        h += field('Stock Qty', 'number', 'stock_quantity', v('stock_quantity'));
        h += field('Peso', 'text', 'weight', v('weight'));
        h += field('Slug', 'text', 'slug', v('slug'));
        h += '</div>';
        h += '<div class="ie-form-grid ie-form-wide">';
        h += field('Descrizione breve', 'textarea', 'short_description', strip(v('short_description')));
        h += field('Meta Title', 'text', 'meta_title', v('meta_title'));
        h += field('Meta Description', 'text', 'meta_description', v('meta_description'));
        h += field('Focus Keyword', 'text', 'focus_keyword', v('focus_keyword'));
        h += '</div>';

        // Read-only info
        h += '<div style="padding-top:12px;border-top:1px solid var(--b1);margin-top:12px;font-family:var(--mono);font-size:10px;color:var(--dim)">';
        h += 'ID: ' + p.id + ' &middot; Tipo: ' + p.type;
        h += ' &middot; Creato: ' + (p.date_created || '—') + ' &middot; Modificato: ' + (p.date_modified || '—');
        h += ' &middot; Categorie: ' + esc((p.categories || []).join(', ') || '—');
        h += ' &middot; Brand: ' + esc((p.brands || []).join(', ') || '—');
        h += '</div>';

        area.innerHTML = h;
    }

    function field(label, type, name, value, options) {
        let h = '<div class="ie-field">';
        h += '<label class="ie-label">' + esc(label) + '</label>';
        if (type === 'select') {
            h += '<select class="ie-input" onchange="GH.ieFormChanged(\'' + name + '\',this.value)">';
            (options || []).forEach(o => {
                h += '<option value="' + o + '"' + (String(value) === o ? ' selected' : '') + '>' + o + '</option>';
            });
            h += '</select>';
        } else if (type === 'textarea') {
            h += '<textarea class="ie-input ie-textarea" oninput="GH.ieFormChanged(\'' + name + '\',this.value)">' + esc(value) + '</textarea>';
        } else {
            h += '<input class="ie-input" type="' + type + '"' + (type === 'number' ? ' step="0.01"' : '') + ' value="' + esc(value) + '" oninput="GH.ieFormChanged(\'' + name + '\',this.value)" />';
        }
        h += '</div>';
        return h;
    }

    GH.ieFormChanged = function(name, value) {
        if (!ie.product) return;
        const orig = ie.product[name];
        if (String(value) === String(orig ?? '')) {
            delete ie.dirty[name];
        } else {
            ie.dirty[name] = value;
        }
        updateDirtyState();
    };

    // ── JSON TAB ────────────────────────────────────────────────────────────

    function renderJson(area) {
        const merged = Object.assign({}, ie.product, ie.dirty);
        const json = JSON.stringify(merged, null, 2);
        let h = '<div style="display:flex;gap:8px;margin-bottom:8px;align-items:center">';
        h += '<button class="btn btn-ghost" onclick="GH.ieJsonFormat()">Format</button>';
        h += '<button class="btn btn-ghost" onclick="GH.ieJsonCopy()">&#9112; Copia</button>';
        h += '<button class="btn btn-primary" onclick="GH.ieJsonApply()">Applica JSON &rarr; Salva</button>';
        h += '<span style="font-family:var(--mono);font-size:10px;color:var(--dim)">Modifica il JSON e premi "Applica" per aggiornare il prodotto.</span>';
        h += '</div>';
        h += '<textarea class="ie-json-editor" id="ie-json-editor" spellcheck="false">' + esc(json) + '</textarea>';
        area.innerHTML = h;
    }

    GH.ieJsonFormat = function() {
        const ed = document.getElementById('ie-json-editor');
        if (!ed) return;
        try { ed.value = JSON.stringify(JSON.parse(ed.value), null, 2); }
        catch (e) { GH.toast('JSON non valido: ' + e.message, 'err'); }
    };

    GH.ieJsonCopy = function() {
        const ed = document.getElementById('ie-json-editor');
        if (ed) navigator.clipboard.writeText(ed.value).then(() => GH.toast('Copiato', 'ok'));
    };

    GH.ieJsonApply = async function() {
        const ed = document.getElementById('ie-json-editor');
        if (!ed || !ie.product) return;
        let payload;
        try { payload = JSON.parse(ed.value); }
        catch (e) { GH.toast('JSON non valido: ' + e.message, 'err'); return; }
        if (typeof payload !== 'object' || Array.isArray(payload)) {
            GH.toast('Il payload deve essere un oggetto {}', 'err'); return;
        }
        if (!confirm('Applicare il JSON modificato al prodotto #' + ie.product.id + '?')) return;
        const ov = document.getElementById('ie-overlay'), ot = document.getElementById('ie-overlay-text');
        if (ov) ov.classList.add('visible');
        if (ot) ot.textContent = 'Salvataggio...';
        try {
            const r = await GH.ajax('gh_ajax_product_save', {
                product_id: ie.product.id,
                payload: JSON.stringify(payload),
            });
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            ie.product = r.data.product;
            ie.dirty = {};
            renderHeader();
            renderCurrentTab();
            updateDirtyState();
            GH.toast('Prodotto aggiornato da JSON', 'ok');
        } finally {
            if (ov) ov.classList.remove('visible');
        }
    };

    // ── VARIATIONS TAB ──────────────────────────────────────────────────────

    function renderVariations(area) {
        if (!ie.variations.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-text">Nessuna variazione</div></div>';
            return;
        }
        let h = '<div style="display:flex;gap:8px;margin-bottom:8px;align-items:center">';
        h += '<span id="ie-var-dirty" class="ie-dirty-badge" style="display:none">&#9679; Varianti modificate</span>';
        h += '<button class="btn btn-primary" id="btn-ie-var-save" onclick="GH.ieVarSave()" disabled><span class="spin" id="ie-var-spin" style="display:none"></span> Salva varianti</button>';
        h += '</div>';
        h += '<table class="ie-var-table"><thead><tr>';
        h += '<th>Taglia</th><th>SKU</th><th>Regolare</th><th>Saldo</th>';
        h += '<th>Stock</th><th>Qty</th><th>Stato</th>';
        h += '</tr></thead><tbody>';
        ie.variations.forEach(function(v) {
            const vid = v.variation_id;
            const d = ie.varDirty[vid] || {};
            const val = (f, def) => d[f] !== undefined ? d[f] : (def ?? '');
            h += '<tr data-vid="' + vid + '">';
            h += '<td class="ie-var-size">' + esc(v.size) + '</td>';
            h += '<td><input class="ie-var-input" value="' + esc(val('sku', v.sku)) + '" oninput="GH.ieVarChanged(' + vid + ',\'sku\',this.value)" /></td>';
            h += '<td><input class="ie-var-input" type="number" step="0.01" value="' + esc(val('regular_price', v.regular_price)) + '" oninput="GH.ieVarChanged(' + vid + ',\'regular_price\',this.value)" /></td>';
            h += '<td><input class="ie-var-input ie-var-sale" type="number" step="0.01" value="' + esc(val('sale_price', v.sale_price)) + '" oninput="GH.ieVarChanged(' + vid + ',\'sale_price\',this.value)" /></td>';
            h += '<td><select class="ie-var-input" onchange="GH.ieVarChanged(' + vid + ',\'stock_status\',this.value)">';
            h += '<option value="instock"' + (val('stock_status', v.stock_status) === 'instock' ? ' selected' : '') + '>instock</option>';
            h += '<option value="outofstock"' + (val('stock_status', v.stock_status) === 'outofstock' ? ' selected' : '') + '>outofstock</option>';
            h += '</select></td>';
            h += '<td><input class="ie-var-input" type="number" style="width:60px" value="' + esc(val('stock_quantity', v.stock_quantity ?? '')) + '" oninput="GH.ieVarChanged(' + vid + ',\'stock_quantity\',this.value)" /></td>';
            h += '<td><select class="ie-var-input" onchange="GH.ieVarChanged(' + vid + ',\'status\',this.value)">';
            h += '<option value="publish"' + (val('status', v.status) === 'publish' ? ' selected' : '') + '>publish</option>';
            h += '<option value="private"' + (val('status', v.status) === 'private' ? ' selected' : '') + '>private</option>';
            h += '</select></td>';
            h += '</tr>';
        });
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    GH.ieVarChanged = function(vid, field, value) {
        if (!ie.varDirty[vid]) ie.varDirty[vid] = {};
        const orig = ie.variations.find(v => v.variation_id === vid);
        if (orig && String(value) === String(orig[field] ?? '')) {
            delete ie.varDirty[vid][field];
            if (!Object.keys(ie.varDirty[vid]).length) delete ie.varDirty[vid];
        } else {
            ie.varDirty[vid][field] = value;
        }
        const hasDirty = Object.keys(ie.varDirty).length > 0;
        const badge = document.getElementById('ie-var-dirty');
        const btn = document.getElementById('btn-ie-var-save');
        if (badge) badge.style.display = hasDirty ? '' : 'none';
        if (btn) btn.disabled = !hasDirty;
    };

    GH.ieVarSave = async function() {
        const updates = Object.entries(ie.varDirty).map(([vid, fields]) => ({
            variation_id: parseInt(vid), ...fields
        }));
        if (!updates.length) return;
        const sp = document.getElementById('ie-var-spin');
        const btn = document.getElementById('btn-ie-var-save');
        if (sp) sp.style.display = '';
        if (btn) btn.disabled = true;
        try {
            const r = await GH.ajax('gh_ajax_product_variations_save', {
                product_id: ie.product.id,
                updates: JSON.stringify(updates),
            });
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            ie.variations = r.data.variations || ie.variations;
            ie.varDirty = {};
            renderVariations(document.getElementById('ie-content'));
            GH.toast(r.data.ok + ' varianti salvate' + (r.data.errors ? ', ' + r.data.errors + ' errori' : ''), r.data.errors ? 'err' : 'ok');
        } finally {
            if (sp) sp.style.display = 'none';
        }
    };

    // ── SAVE (form mode) ────────────────────────────────────────────────────

    GH.ieSave = async function() {
        if (!ie.product || !Object.keys(ie.dirty).length) return;
        const sp = document.getElementById('ie-save-spin');
        const btn = document.getElementById('btn-ie-save');
        if (sp) sp.style.display = '';
        if (btn) btn.disabled = true;
        try {
            const r = await GH.ajax('gh_ajax_product_save', {
                product_id: ie.product.id,
                payload: JSON.stringify(ie.dirty),
            });
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            ie.product = r.data.product;
            ie.dirty = {};
            renderHeader();
            renderCurrentTab();
            updateDirtyState();
            GH.toast('Salvato', 'ok');
        } finally {
            if (sp) sp.style.display = 'none';
        }
    };

    function updateDirtyState() {
        const n = Object.keys(ie.dirty).length;
        const badge = document.getElementById('ie-dirty-badge');
        const btn = document.getElementById('btn-ie-save');
        if (badge) badge.style.display = n > 0 ? '' : 'none';
        if (btn) btn.disabled = n === 0;
    }

})();
