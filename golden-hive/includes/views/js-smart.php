// ═══ SMART TAXONOMY ═══════════════════════════════════════════════════════
//
// Regole automatiche per popolare termini tassonomia. Stessa logica di Shopify
// "Smart Collections": definisci condizioni → il sistema assegna i prodotti.
//
// Riutilizza gh_get_filter_meta() per le stesse opzioni (categorie, brand,
// tag, attributi) e gh_filter_product_ids() per l'esecuzione.
//
// Il JS estende la tax-detail area del Taxonomy panel: quando l'utente
// seleziona un termine, il pannello Smart Rule si popola.

(function() {

    let srMeta = null; // filter meta cache (stessa di Filtra & Agisci)
    let srRule = null;  // current rule for selected term
    let srConditions = []; // editing state
    let srEditing = false;

    function esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }

    // ── LOAD FILTER META (shared with filter engine, same endpoint) ─────────

    async function loadMeta() {
        if (srMeta) return srMeta;
        const r = await GH.ajax('gh_ajax_filter_meta');
        if (r.success) srMeta = r.data;
        return srMeta;
    }

    // ── HOOK INTO TAX SELECT ────────────────────────────────────────────────
    // Extend the existing GH.taxSelect to also load the smart rule for the
    // selected term. The original taxSelect lives in js.php's IIFE, but we
    // can hook after the fact by watching the tax-detail panel.

    const origTaxSelect = GH.taxSelect;
    GH.taxSelect = async function(id) {
        await origTaxSelect(id);
        // After the original renders the product list, load smart rule data
        await loadSmartRuleForTerm(id);
    };

    async function loadSmartRuleForTerm(termId) {
        const taxonomy = document.getElementById('tax-source')?.value || 'product_cat';
        const r = await GH.ajax('gh_ajax_smart_rule_for_term', { term_id: termId, taxonomy: taxonomy });
        srRule = r.success ? r.data : null;
        srEditing = false;
        renderSmartRule();
    }

    // ── RENDER ──────────────────────────────────────────────────────────────

    function renderSmartRule() {
        const area = document.getElementById('sr-content');
        const status = document.getElementById('sr-status');
        if (!area) return;

        if (srEditing) {
            renderEditor(area);
            if (status) status.textContent = '';
            return;
        }

        if (!srRule) {
            // No rule exists for this term
            if (status) status.textContent = 'Nessuna regola';
            area.innerHTML =
                '<div style="padding:8px 0;font-size:11px;color:var(--dim)">' +
                'Nessuna smart rule per questo termine. Crea una regola per popolare automaticamente i prodotti.' +
                '</div>' +
                '<button class="btn btn-primary" onclick="GH.smartCreate()">+ Crea Smart Rule</button>';
            return;
        }

        // Rule exists: show info
        if (status) status.innerHTML = srRule.enabled
            ? '<span style="color:var(--grn)">&#9679; Attiva</span>'
            : '<span style="color:var(--dim)">&#9675; Disattivata</span>';

        let h = '<div class="sr-info">';
        // Conditions summary
        h += '<div class="sr-conditions-summary">';
        (srRule.conditions || []).forEach(function(c) {
            h += '<span class="sr-cond-badge">' + esc(c.type) + ' ' + esc(c.operator) + ' ' + esc(formatCondValue(c)) + '</span>';
        });
        h += '</div>';

        if (srRule.last_sync) {
            h += '<div style="font-size:10px;color:var(--dim);margin-top:4px">Ultimo sync: ' + esc(srRule.last_sync) + ' — ' + srRule.last_count + ' prodotti</div>';
        }

        h += '<div style="display:flex;gap:6px;margin-top:8px">';
        h += '<button class="btn btn-primary" onclick="GH.smartSync()"><span class="spin" id="sr-sync-spin" style="display:none"></span> Sync ora</button>';
        h += '<button class="btn btn-ghost" onclick="GH.smartEdit()">Modifica</button>';
        h += '<button class="btn btn-ghost" onclick="GH.smartDelete()" style="color:var(--red)">Elimina regola</button>';
        h += '</div></div>';
        area.innerHTML = h;
    }

    function formatCondValue(c) {
        const v = c.value;
        if (v === null || v === undefined) return '';
        if (Array.isArray(v)) return v.length + ' selezionati';
        if (typeof v === 'object') {
            if (v.min !== undefined && v.max !== undefined) return v.min + '–' + v.max;
            if (v.min !== undefined) return '>' + v.min;
            if (v.max !== undefined) return '<' + v.max;
        }
        return String(v);
    }

    // ── EDITOR ──────────────────────────────────────────────────────────────

    GH.smartCreate = async function() {
        await loadMeta();
        srConditions = [];
        srEditing = true;
        renderSmartRule();
    };

    GH.smartEdit = async function() {
        await loadMeta();
        srConditions = JSON.parse(JSON.stringify(srRule?.conditions || []));
        srEditing = true;
        renderSmartRule();
    };

    function renderEditor(area) {
        if (!srMeta) { area.innerHTML = '<span class="spin"></span>'; return; }

        let h = '<div class="sr-editor">';
        h += '<div style="font-family:var(--mono);font-size:10px;color:var(--acc);margin-bottom:8px;text-transform:uppercase">Condizioni (AND — tutte devono matchare)</div>';

        // Conditions rows
        h += '<div id="sr-cond-rows">';
        srConditions.forEach(function(c, i) {
            h += renderCondRow(i, c);
        });
        h += '</div>';

        h += '<button class="btn btn-ghost" onclick="GH.smartAddCond()" style="margin-top:6px">+ Condizione</button>';

        // Preview + action buttons
        h += '<div style="display:flex;gap:6px;margin-top:12px;align-items:center">';
        h += '<button class="btn btn-ghost" onclick="GH.smartPreview()"><span class="spin" id="sr-preview-spin" style="display:none"></span> Anteprima</button>';
        h += '<span id="sr-preview-count" style="font-family:var(--mono);font-size:11px;color:var(--grn)"></span>';
        h += '<div class="filter-sep"></div>';
        h += '<button class="btn btn-primary" onclick="GH.smartSave()">Salva regola</button>';
        h += '<button class="btn btn-ghost" onclick="GH.smartCancelEdit()">Annulla</button>';
        h += '</div>';
        h += '</div>';
        area.innerHTML = h;
    }

    function renderCondRow(i, c) {
        const defs = srMeta ? srMeta.conditions : {};
        let h = '<div class="sr-cond-row" style="display:flex;gap:6px;align-items:center;padding:4px 0">';

        // Type selector
        h += '<select class="filter-select" onchange="GH.smartCondType(' + i + ',this.value)" style="min-width:130px"><option value="">— Campo —</option>';
        let lg = '';
        for (const [key, def] of Object.entries(defs)) {
            if (def.group !== lg) { if (lg) h += '</optgroup>'; h += '<optgroup label="' + esc(def.group.toUpperCase()) + '">'; lg = def.group; }
            h += '<option value="' + key + '"' + (c.type === key ? ' selected' : '') + '>' + esc(def.label) + '</option>';
        }
        if (lg) h += '</optgroup>';
        h += '</select>';

        // Operator selector (if type selected)
        if (c.type && defs[c.type]) {
            const ops = defs[c.type].operators || [];
            h += '<select class="filter-select" onchange="GH.smartCondOp(' + i + ',this.value)" style="min-width:90px">';
            ops.forEach(function(op) { h += '<option value="' + op + '"' + (c.operator === op ? ' selected' : '') + '>' + esc(opLabel(op)) + '</option>'; });
            h += '</select>';

            // Value input
            h += renderValueInput(i, c);
        }

        h += '<button class="btn btn-ghost" onclick="GH.smartRemoveCond(' + i + ')" style="padding:4px 8px;color:var(--dim)">&times;</button>';
        h += '</div>';
        return h;
    }

    function renderValueInput(i, c) {
        if (!srMeta || !c.type) return '';
        const def = srMeta.conditions[c.type];
        if (!def) return '';
        const vt = def.value_type, val = c.value;
        if (vt === 'none') return '';
        if (vt === 'boolean') return '<select class="filter-select" onchange="GH.smartCondVal(' + i + ',this.value===\'1\')" style="min-width:70px"><option value="1"' + (val === true ? ' selected' : '') + '>Si</option><option value="0"' + (val === false ? ' selected' : '') + '>No</option></select>';
        if (vt === 'select') { let h = '<select class="filter-select" onchange="GH.smartCondVal(' + i + ',this.value)" style="min-width:110px">'; (def.options || []).forEach(function(o) { h += '<option value="' + o + '"' + (val === o ? ' selected' : '') + '>' + o + '</option>'; }); return h + '</select>'; }
        if (vt === 'term_ids') {
            const items = c.type === 'category' ? (srMeta.categories || []) : c.type === 'brand' ? (srMeta.brands || []) : c.type === 'tag' ? (srMeta.tags || []) : [];
            let h = '<select class="filter-select" multiple onchange="GH.smartCondTerms(' + i + ',this)" style="min-width:180px;min-height:28px">';
            items.forEach(function(t) { h += '<option value="' + t.id + '"' + (Array.isArray(val) && val.includes(t.id) ? ' selected' : '') + '>' + esc(t.name) + '</option>'; });
            return h + '</select>';
        }
        if (vt === 'text') return '<input type="text" class="filter-select" placeholder="Valore..." value="' + esc(val || '') + '" onchange="GH.smartCondVal(' + i + ',this.value)" style="min-width:130px">';
        if (vt === 'number') return '<input type="number" class="filter-select" value="' + (val || '') + '" onchange="GH.smartCondVal(' + i + ',parseFloat(this.value))" style="width:80px">';
        if (vt === 'number_range' || vt === 'date_range') {
            const isD = vt === 'date_range', it = isD ? 'date' : 'number', w = isD ? '130px' : '80px';
            const mn = (val && typeof val === 'object') ? (val.min || '') : (val || ''), mx = (val && typeof val === 'object') ? (val.max || '') : '';
            if (c.operator === 'between') return '<input type="' + it + '" class="filter-select" placeholder="Min" value="' + mn + '" onchange="GH.smartCondRange(' + i + ',\'min\',this.value)" style="width:' + w + '"><span style="color:var(--dim);font-size:10px">—</span><input type="' + it + '" class="filter-select" placeholder="Max" value="' + mx + '" onchange="GH.smartCondRange(' + i + ',\'max\',this.value)" style="width:' + w + '">';
            return '<input type="' + it + '" class="filter-select" placeholder="Valore" value="' + mn + '" onchange="GH.smartCondVal(' + i + ',' + (isD ? 'this.value' : 'parseFloat(this.value)') + ')" style="width:' + w + '">';
        }
        if (vt === 'attribute_value') {
            let h = '<select class="filter-select" onchange="GH.smartCondAttr(' + i + ',this.value)" style="min-width:110px"><option value="">— Attr —</option>';
            (srMeta.attributes || []).forEach(function(a) { h += '<option value="' + a.name + '"' + (c.attribute_name === a.name ? ' selected' : '') + '>' + esc(a.label) + '</option>'; });
            h += '</select>';
            if (c.attribute_name && (c.operator === 'has_value' || c.operator === 'not_has_value')) {
                const attr = (srMeta.attributes || []).find(function(a) { return a.name === c.attribute_name; });
                if (attr && attr.values.length) { h += '<select class="filter-select" onchange="GH.smartCondVal(' + i + ',this.value)" style="min-width:100px">'; attr.values.forEach(function(v) { h += '<option value="' + esc(v.name) + '"' + (val === v.name ? ' selected' : '') + '>' + esc(v.name) + '</option>'; }); h += '</select>'; }
                else h += '<input type="text" class="filter-select" placeholder="Valore" value="' + esc(val || '') + '" onchange="GH.smartCondVal(' + i + ',this.value)" style="min-width:100px">';
            }
            return h;
        }
        return '';
    }

    function opLabel(op) {
        return { 'is': 'uguale a', 'is_not': 'diverso da', 'in': 'uno di', 'not_in': 'nessuno di', 'contains': 'contiene', 'not_contains': 'non contiene', 'starts_with': 'inizia con', 'matches': 'regex', 'gt': '>', 'lt': '<', 'between': 'tra', 'after': 'dopo', 'before': 'prima', 'exists': 'presente', 'not_exists': 'assente', 'has_value': 'ha valore', 'not_has_value': 'non ha', 'has_attribute': 'ha attr', 'not_has_attribute': 'non ha attr' }[op] || op;
    }

    // ── CONDITION EVENTS ────────────────────────────────────────────────────

    GH.smartAddCond = function() { srConditions.push({ type: '', operator: '', value: null }); rerenderConds(); };
    GH.smartRemoveCond = function(i) { srConditions.splice(i, 1); rerenderConds(); };
    GH.smartCondType = function(i, t) {
        srConditions[i].type = t;
        const d = srMeta ? srMeta.conditions : {};
        if (d[t]) { srConditions[i].operator = d[t].operators[0] || ''; srConditions[i].value = null; srConditions[i].attribute_name = ''; }
        rerenderConds();
    };
    GH.smartCondOp = function(i, o) { srConditions[i].operator = o; rerenderConds(); };
    GH.smartCondVal = function(i, v) { srConditions[i].value = v; };
    GH.smartCondTerms = function(i, sel) { srConditions[i].value = Array.from(sel.selectedOptions).map(function(o) { return parseInt(o.value); }); };
    GH.smartCondRange = function(i, k, v) { if (!srConditions[i].value || typeof srConditions[i].value !== 'object') srConditions[i].value = {}; srConditions[i].value[k] = v; };
    GH.smartCondAttr = function(i, n) { srConditions[i].attribute_name = n; srConditions[i].value = null; rerenderConds(); };

    function rerenderConds() {
        const wrap = document.getElementById('sr-cond-rows');
        if (!wrap) return;
        let h = '';
        srConditions.forEach(function(c, i) { h += renderCondRow(i, c); });
        wrap.innerHTML = h;
    }

    // ── ACTIONS ─────────────────────────────────────────────────────────────

    GH.smartPreview = async function() {
        const valid = srConditions.filter(function(c) { return c.type && c.operator; });
        if (!valid.length) { GH.toast('Aggiungi almeno una condizione', 'err'); return; }
        const sp = document.getElementById('sr-preview-spin');
        if (sp) sp.style.display = '';
        const r = await GH.ajax('gh_ajax_smart_rule_preview', { conditions: JSON.stringify(valid) });
        if (sp) sp.style.display = 'none';
        const el = document.getElementById('sr-preview-count');
        if (el) el.textContent = r.success ? r.data.count + ' prodotti corrispondono' : 'Errore';
    };

    GH.smartSave = async function() {
        const valid = srConditions.filter(function(c) { return c.type && c.operator; });
        if (!valid.length) { GH.toast('Aggiungi almeno una condizione', 'err'); return; }

        // Get current selected term from the taxonomy panel
        const termTitle = document.getElementById('tax-detail-title')?.textContent || '';
        const termIdText = document.getElementById('tax-detail-id')?.textContent || '';
        const termId = parseInt(termIdText.replace('#', ''));
        const taxonomy = document.getElementById('tax-source')?.value || 'product_cat';

        if (!termId) { GH.toast('Nessun termine selezionato', 'err'); return; }

        const rule = {
            id: srRule?.id || undefined,
            term_id: termId,
            taxonomy: taxonomy,
            conditions: valid,
            enabled: true,
        };

        const r = await GH.ajax('gh_ajax_smart_rule_save', { rule: JSON.stringify(rule) });
        if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }

        GH.toast('Smart rule salvata per "' + termTitle + '"', 'ok');
        // Reload the rule and exit editing
        await loadSmartRuleForTerm(termId);
    };

    GH.smartCancelEdit = function() {
        srEditing = false;
        renderSmartRule();
    };

    GH.smartDelete = async function() {
        if (!srRule?.id) return;
        if (!confirm('Eliminare la smart rule? I prodotti gia assegnati NON verranno rimossi dal termine.')) return;
        const r = await GH.ajax('gh_ajax_smart_rule_delete', { rule_id: srRule.id });
        if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
        GH.toast('Smart rule eliminata', 'ok');
        srRule = null;
        renderSmartRule();
    };

    GH.smartSync = async function() {
        if (!srRule?.id) return;
        const sp = document.getElementById('sr-sync-spin');
        if (sp) sp.style.display = '';
        try {
            const r = await GH.ajax('gh_ajax_smart_rule_sync', { rule_id: srRule.id });
            if (!r.success) { GH.toast(r.data || 'Errore sync', 'err'); return; }
            GH.toast(r.data.assigned + ' assegnati, ' + r.data.already + ' gia presenti (su ' + r.data.matched + ' match)', 'ok', 5000);
            // Re-fetch to update last_sync info and product list
            const termId = srRule.term_id;
            GH.taxSelect(termId);
        } finally {
            if (sp) sp.style.display = 'none';
        }
    };

    GH.smartSyncAll = async function() {
        if (!confirm('Eseguire tutte le smart rules abilitate? I prodotti verranno assegnati ai termini corrispondenti.')) return;
        GH.toast('Sync all in corso...', 'inf', 2000);
        const r = await GH.ajax('gh_ajax_smart_sync_all');
        if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
        const results = r.data;
        const keys = Object.keys(results);
        let totalAssigned = 0;
        keys.forEach(function(k) { totalAssigned += results[k].assigned || 0; });
        GH.toast(keys.length + ' regole eseguite, ' + totalAssigned + ' nuove assegnazioni', 'ok', 5000);
    };

})();
