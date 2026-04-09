// ═══ UI MAPPER ══════════════════════════════════════════════════════════════

(function(){

    // ── STATE ────────────────────────────────────────────────────
    let mapperMeta     = null;   // { target_fields, transform_types }
    let sourcePaths    = [];     // [ { path, type, sample } ]
    let sourceSample   = null;   // raw parsed JSON
    let mappingRows    = [];     // [ { source, target, transforms[] } ]
    let editingRuleId  = '';     // '' = new rule
    let editingTransformIdx = -1; // which mapping row's transforms we're editing
    let previewResults = [];

    // ── LOAD META ───────────────────────────────────────────────
    async function loadMeta() {
        if (mapperMeta) return mapperMeta;
        const r = await GH.ajax('gh_ajax_mapper_meta');
        if (r.success) mapperMeta = r.data;
        return mapperMeta;
    }

    // ── RULES LIST ──────────────────────────────────────────────
    GH.mapperLoadRules = async function() {
        const sp = document.getElementById('mpr-spin');
        sp.style.display = '';
        try {
            const r = await GH.ajax('gh_ajax_mapper_list_rules');
            if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }
            renderRulesList(r.data);
            if (r.data.length) GH.toast(r.data.length + ' regole', 'ok');
        } catch(e) { GH.toast('Errore', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    function renderRulesList(rules) {
        const el = document.getElementById('mp-rules-list');
        if (!rules.length) {
            el.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Nessuna regola di mapping salvata.<br>Crea la prima regola per iniziare.</div></div>';
            return;
        }
        let h = '<div class="mp-rules-grid">';
        for (const r of rules) {
            const dt = r.updated_at ? r.updated_at.slice(0,10) : '';
            h += '<div class="mp-rule-card" data-id="' + GH.esc(r.id) + '">'
               + '<div class="mp-rule-card-head">'
               +   '<span class="mp-rule-card-name">' + GH.esc(r.name) + '</span>'
               +   '<span class="mp-rule-card-count">' + r.mapping_count + ' mapping</span>'
               + '</div>'
               + '<div class="mp-rule-card-desc">' + GH.esc(r.description || 'Nessuna descrizione') + '</div>'
               + (r.items_path ? '<div class="mp-rule-card-path">path: ' + GH.esc(r.items_path) + '</div>' : '')
               + '<div class="mp-rule-card-meta">' + dt + '</div>'
               + '<div class="mp-rule-card-actions">'
               +   '<button class="btn btn-ghost" onclick="GH.mapperEditRule(\'' + r.id + '\')">Modifica</button>'
               +   '<button class="btn btn-ghost" onclick="GH.mapperDuplicateRule(\'' + r.id + '\')">Duplica</button>'
               +   '<button class="btn btn-ghost" style="color:var(--red)" onclick="GH.mapperDeleteRule(\'' + r.id + '\',\'' + GH.esc(r.name).replace(/'/g,"\\'") + '\')">Elimina</button>'
               + '</div>'
               + '</div>';
        }
        h += '</div>';
        el.innerHTML = h;
    }

    // ── NEW RULE ────────────────────────────────────────────────
    GH.mapperNewRule = function() {
        editingRuleId = '';
        sourcePaths   = [];
        sourceSample  = null;
        mappingRows   = [];
        previewResults= [];
        document.getElementById('mp-rule-name').value = '';
        document.getElementById('mp-rule-desc').value = '';
        document.getElementById('mp-items-path').value = '';
        document.getElementById('mp-source-textarea').value = '';
        document.getElementById('mp-source-filename').textContent = '';
        document.getElementById('mp-source-fields').innerHTML = '';
        document.getElementById('mp-mapping-rows').innerHTML = '<div class="empty-state" style="padding:40px 0"><div class="empty-text">Clicca "Aggiungi" per creare<br>la prima regola di mapping</div></div>';
        document.getElementById('mp-target-fields').innerHTML = '';
        document.getElementById('mp-preview-area').innerHTML = '';
        document.getElementById('mp-apply-bar').style.display = 'none';
        GH.mapperGoStep(1);
        GH.switchTab('mapper-editor', document.querySelector('.tab-item[data-mp-tab="editor"]'));
    };

    // ── EDIT EXISTING RULE ──────────────────────────────────────
    GH.mapperEditRule = async function(ruleId) {
        const r = await GH.ajax('gh_ajax_mapper_get_rule', { rule_id: ruleId });
        if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }

        const rule = r.data;
        editingRuleId = rule.id;
        mappingRows   = rule.mappings || [];
        sourceSample  = rule.source_sample || null;

        document.getElementById('mp-rule-name').value  = rule.name || '';
        document.getElementById('mp-rule-desc').value   = rule.description || '';
        document.getElementById('mp-items-path').value  = rule.items_path || '';

        if (sourceSample) {
            document.getElementById('mp-source-textarea').value = JSON.stringify(sourceSample, null, 2);
            // Auto-parse
            await parseSourceInternal(sourceSample);
        }

        GH.switchTab('mapper-editor', document.querySelector('.tab-item[data-mp-tab="editor"]'));
        GH.mapperGoStep(2);
        renderMappingRows();
    };

    // ── DELETE RULE ─────────────────────────────────────────────
    GH.mapperDeleteRule = async function(id, name) {
        if (!confirm('Eliminare la regola "' + name + '"?')) return;
        const r = await GH.ajax('gh_ajax_mapper_delete_rule', { rule_id: id });
        if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }
        GH.toast('Eliminata', 'ok');
        GH.mapperLoadRules();
    };

    // ── DUPLICATE RULE ──────────────────────────────────────────
    GH.mapperDuplicateRule = async function(id) {
        const r = await GH.ajax('gh_ajax_mapper_duplicate_rule', { rule_id: id });
        if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }
        GH.toast('Duplicata: ' + r.data.name, 'ok');
        GH.mapperLoadRules();
    };

    // ── STEP NAVIGATION ─────────────────────────────────────────
    GH.mapperGoStep = function(n) {
        document.querySelectorAll('#panel-mapper-editor .mp-step').forEach(s => {
            s.classList.toggle('active', parseInt(s.dataset.step) <= n);
        });
        document.querySelectorAll('#panel-mapper-editor .mp-stage').forEach(s => s.classList.remove('active'));
        document.getElementById('mp-stage-' + n).classList.add('active');
    };

    // ── SOURCE FILE UPLOAD ──────────────────────────────────────
    function initSourceUpload() {
        const drop = document.getElementById('mp-source-drop');
        const inp  = document.getElementById('mp-source-file');
        if (!drop || !inp) return;

        inp.addEventListener('change', () => { if (inp.files.length) handleSourceFile(inp.files[0]); });
        drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('dragover'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
        drop.addEventListener('drop', e => {
            e.preventDefault(); drop.classList.remove('dragover');
            if (e.dataTransfer.files.length) handleSourceFile(e.dataTransfer.files[0]);
        });
    }

    function handleSourceFile(f) {
        if (!f.name.endsWith('.json')) { GH.toast('Solo file .json', 'err'); return; }
        const reader = new FileReader();
        reader.onload = () => {
            document.getElementById('mp-source-textarea').value = reader.result;
            document.getElementById('mp-source-filename').textContent = f.name;
        };
        reader.readAsText(f);
    }

    // ── PARSE SOURCE JSON ───────────────────────────────────────
    GH.mapperParseSource = async function() {
        const raw = document.getElementById('mp-source-textarea').value.trim();
        if (!raw) { GH.toast('Incolla o carica un JSON sorgente', 'err'); return; }

        let data;
        try { data = JSON.parse(raw); } catch(e) { GH.toast('JSON non valido: ' + e.message, 'err'); return; }

        const sp = document.getElementById('mp-parse-spin');
        sp.style.display = '';
        try {
            await parseSourceInternal(data);
            GH.toast(sourcePaths.length + ' campi trovati', 'ok');
            GH.mapperGoStep(2);
            renderMappingRows();
        } catch(e) { GH.toast('Errore analisi', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    async function parseSourceInternal(data) {
        sourceSample = data;
        await loadMeta();

        // Extract paths via AJAX (server-side, handles edge cases)
        const r = await GH.ajax('gh_ajax_mapper_extract', { json_sample: JSON.stringify(data) });
        if (r.success) {
            sourcePaths = r.data.paths;
        } else {
            sourcePaths = [];
        }

        renderSourceFields();
        renderTargetFields();
    }

    // ── RENDER SOURCE FIELDS COLUMN ─────────────────────────────
    function renderSourceFields() {
        const el = document.getElementById('mp-source-fields');
        document.getElementById('mp-src-count').textContent = sourcePaths.length + ' campi';

        if (!sourcePaths.length) {
            el.innerHTML = '<div class="empty-state" style="padding:20px"><div class="empty-text">Nessun campo rilevato</div></div>';
            return;
        }

        let h = '';
        for (const f of sourcePaths) {
            const typeClass = 'mp-type-' + f.type;
            const sample = f.sample !== null && f.sample !== undefined
                ? '<span class="mp-field-sample">' + GH.esc(String(f.sample)).substring(0, 40) + '</span>'
                : '';
            const connected = mappingRows.some(m => m.source === f.path) ? ' mp-connected' : '';
            h += '<div class="mp-field-item' + connected + '" data-path="' + GH.esc(f.path) + '" title="' + GH.esc(f.path) + '">'
               + '<span class="mp-field-dot"></span>'
               + '<span class="mp-field-path">' + GH.esc(f.path) + '</span>'
               + '<span class="mp-field-type ' + typeClass + '">' + f.type + '</span>'
               + sample
               + '</div>';
        }
        el.innerHTML = h;
    }

    // ── RENDER TARGET FIELDS COLUMN ─────────────────────────────
    function renderTargetFields() {
        const el = document.getElementById('mp-target-fields');
        if (!mapperMeta) return;

        const fields = mapperMeta.target_fields;
        const keys   = Object.keys(fields);
        document.getElementById('mp-tgt-count').textContent = keys.length + ' campi';

        let h = '';
        let lastGroup = '';
        for (const key of keys) {
            const f = fields[key];
            if (f.group !== lastGroup) {
                lastGroup = f.group;
                h += '<div class="mp-field-group">' + f.group.toUpperCase() + '</div>';
            }
            const connected = mappingRows.some(m => m.target === key) ? ' mp-connected' : '';
            h += '<div class="mp-field-item' + connected + '" data-target="' + key + '" title="' + GH.esc(f.desc) + '">'
               + '<span class="mp-field-dot"></span>'
               + '<span class="mp-field-path">' + GH.esc(f.label) + '</span>'
               + '<span class="mp-field-type mp-type-' + f.type + '">' + f.type + '</span>'
               + '</div>';
        }
        el.innerHTML = h;
    }

    // ── MAPPING ROWS ────────────────────────────────────────────
    GH.mapperAddRow = function() {
        mappingRows.push({ source: '', target: '', transforms: [] });
        renderMappingRows();
    };

    GH.mapperRemoveRow = function(idx) {
        mappingRows.splice(idx, 1);
        renderMappingRows();
        renderSourceFields();
        renderTargetFields();
    };

    function renderMappingRows() {
        const el = document.getElementById('mp-mapping-rows');
        if (!mappingRows.length) {
            el.innerHTML = '<div class="empty-state" style="padding:40px 0"><div class="empty-text">Clicca "Aggiungi" per creare<br>la prima regola di mapping</div></div>';
            return;
        }

        let h = '';
        for (let i = 0; i < mappingRows.length; i++) {
            const m = mappingRows[i];
            h += '<div class="mp-map-row">'
               + '<div class="mp-map-row-head">'
               +   '<span class="mp-map-row-num">#' + (i + 1) + '</span>'
               +   '<button class="btn btn-ghost" style="padding:2px 6px;font-size:9px;color:var(--red)" onclick="GH.mapperRemoveRow(' + i + ')">&times;</button>'
               + '</div>'
               + '<div class="mp-map-row-body">'
               +   '<select class="mp-map-select" onchange="GH.mapperUpdateRow(' + i + ',\'source\',this.value)">'
               +     '<option value="">-- sorgente --</option>';

            for (const p of sourcePaths) {
                const sel = m.source === p.path ? ' selected' : '';
                h += '<option value="' + GH.esc(p.path) + '"' + sel + '>' + GH.esc(p.path) + '</option>';
            }
            // Also allow empty source for static values
            h += '</select>'
               + '<div class="mp-map-arrow">&rarr;</div>'
               + '<select class="mp-map-select" onchange="GH.mapperUpdateRow(' + i + ',\'target\',this.value)">'
               + '<option value="">-- target WC --</option>';

            if (mapperMeta) {
                let lastG = '';
                for (const [key, f] of Object.entries(mapperMeta.target_fields)) {
                    if (f.group !== lastG) { lastG = f.group; h += '<optgroup label="' + f.group.toUpperCase() + '">'; }
                    const sel = m.target === key ? ' selected' : '';
                    h += '<option value="' + key + '"' + sel + '>' + GH.esc(f.label) + '</option>';
                }
            }
            h += '</select></div>';

            // Transforms
            const tc = m.transforms || [];
            h += '<div class="mp-map-transforms">';
            if (tc.length) {
                for (const t of tc) {
                    const tDef = mapperMeta?.transform_types?.[t.type];
                    const label = tDef ? tDef.label : t.type;
                    h += '<span class="mp-transform-pill" title="' + GH.esc(t.value || '') + '">' + GH.esc(label)
                       + (t.value ? ': ' + GH.esc(String(t.value)).substring(0, 20) : '') + '</span>';
                }
            }
            h += '<button class="mp-transform-btn" onclick="GH.mapperOpenTransforms(' + i + ')">'
               + (tc.length ? '&#9998; Modifica' : '+ Trasformazione') + '</button>';
            h += '</div></div>';
        }
        el.innerHTML = h;

        // Update column highlights
        renderSourceFields();
        renderTargetFields();
    }

    GH.mapperUpdateRow = function(idx, field, value) {
        mappingRows[idx][field] = value;
        renderSourceFields();
        renderTargetFields();
    };

    // ── TRANSFORM MODAL ─────────────────────────────────────────
    GH.mapperOpenTransforms = async function(rowIdx) {
        await loadMeta();
        editingTransformIdx = rowIdx;
        const m = mappingRows[rowIdx];
        const modal = document.getElementById('mp-transform-modal');
        modal.style.display = 'flex';

        // Populate add-transform dropdown
        const sel = document.getElementById('mp-add-transform-type');
        sel.innerHTML = '<option value="">+ Aggiungi trasformazione...</option>';
        for (const [key, t] of Object.entries(mapperMeta.transform_types)) {
            sel.innerHTML += '<option value="' + key + '">' + GH.esc(t.label) + ' \u2014 ' + GH.esc(t.desc) + '</option>';
        }
        sel.onchange = function() {
            if (!this.value) return;
            mappingRows[editingTransformIdx].transforms.push({ type: this.value, value: '' });
            renderTransformList();
            this.value = '';
        };

        renderTransformList();
    };

    function renderTransformList() {
        const el = document.getElementById('mp-transform-list');
        const transforms = mappingRows[editingTransformIdx]?.transforms || [];

        if (!transforms.length) {
            el.innerHTML = '<div class="empty-state" style="padding:20px 0"><div class="empty-text">Nessuna trasformazione</div></div>';
            return;
        }

        let h = '';
        for (let i = 0; i < transforms.length; i++) {
            const t = transforms[i];
            const tDef = mapperMeta.transform_types[t.type] || {};
            h += '<div class="mp-transform-row">'
               + '<span class="mp-transform-label">' + GH.esc(tDef.label || t.type) + '</span>';

            if (tDef.param_type !== 'none') {
                h += '<input class="cfg-input" style="flex:1;font-size:11px" '
                   + 'placeholder="' + GH.esc(tDef.param_label || '') + '" '
                   + 'value="' + GH.esc(String(t.value || '')) + '" '
                   + 'onchange="GH.mapperSetTransformValue(' + i + ',this.value)" />';
            }

            h += '<button class="btn btn-ghost" style="padding:2px 6px;color:var(--red);font-size:11px" '
               + 'onclick="GH.mapperRemoveTransform(' + i + ')">&times;</button>'
               + '</div>';
        }
        el.innerHTML = h;
    }

    GH.mapperSetTransformValue = function(idx, val) {
        if (editingTransformIdx >= 0 && mappingRows[editingTransformIdx]) {
            mappingRows[editingTransformIdx].transforms[idx].value = val;
        }
    };

    GH.mapperRemoveTransform = function(idx) {
        if (editingTransformIdx >= 0 && mappingRows[editingTransformIdx]) {
            mappingRows[editingTransformIdx].transforms.splice(idx, 1);
            renderTransformList();
        }
    };

    GH.mapperCloseTransforms = function() {
        document.getElementById('mp-transform-modal').style.display = 'none';
        editingTransformIdx = -1;
        renderMappingRows();
    };

    GH.mapperSaveTransforms = function() {
        GH.mapperCloseTransforms();
    };

    // ── PREVIEW ─────────────────────────────────────────────────
    GH.mapperPreview = async function() {
        if (!sourceSample) { GH.toast('Carica prima un JSON sorgente', 'err'); return; }
        if (!mappingRows.filter(m => m.source || m.transforms.some(t => t.type === 'static')).length) {
            GH.toast('Aggiungi almeno un mapping', 'err'); return;
        }

        const sp = document.getElementById('mp-preview-spin');
        sp.style.display = '';
        try {
            const itemsPath = document.getElementById('mp-items-path').value.trim();
            const r = await GH.ajax('gh_ajax_mapper_preview', {
                source_data: JSON.stringify(sourceSample),
                mappings:    JSON.stringify(mappingRows),
                items_path:  itemsPath
            });

            if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }

            previewResults = r.data.results;
            const total = r.data.total;
            const limited = r.data.limited;

            document.getElementById('mp-preview-summary').innerHTML =
                '<span style="font-weight:600">' + total + '</span> prodott' + (total === 1 ? 'o' : 'i') + ' mappati'
                + (limited ? ' (preview limitato a 20)' : '');

            renderPreview(previewResults);
            document.getElementById('mp-apply-bar').style.display = 'flex';
            GH.mapperGoStep(3);
            GH.toast(total + ' prodotti in preview', 'ok');
        } catch(e) { GH.toast('Errore preview', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    function renderPreview(results) {
        const el = document.getElementById('mp-preview-area');
        if (!results.length) {
            el.innerHTML = '<div class="empty-state"><div class="empty-text">Nessun risultato</div></div>';
            return;
        }

        // Build table from result keys
        const allKeys = new Set();
        results.forEach(r => Object.keys(r).forEach(k => allKeys.add(k)));
        const keys = Array.from(allKeys);

        let h = '<table class="ptable"><thead><tr><th>#</th>';
        for (const k of keys) h += '<th>' + GH.esc(k) + '</th>';
        h += '</tr></thead><tbody>';

        for (let i = 0; i < results.length; i++) {
            const r = results[i];
            h += '<tr><td style="color:var(--dim)">' + (i + 1) + '</td>';
            for (const k of keys) {
                let v = r[k];
                if (v === null || v === undefined) v = '<span class="dim">\u2013</span>';
                else if (typeof v === 'object') v = '<span class="mp-json-mini">' + GH.esc(JSON.stringify(v)) + '</span>';
                else v = GH.esc(String(v));
                h += '<td>' + v + '</td>';
            }
            h += '</tr>';
        }
        h += '</tbody></table>';
        el.innerHTML = h;
    }

    // ── SAVE RULE ───────────────────────────────────────────────
    GH.mapperSaveRule = async function() {
        const name = document.getElementById('mp-rule-name').value.trim();
        if (!name) { GH.toast('Inserisci un nome per la regola', 'err'); return; }

        const sp = document.getElementById('mp-save-spin');
        sp.style.display = '';
        try {
            const rule = {
                id:            editingRuleId,
                name:          name,
                description:   document.getElementById('mp-rule-desc').value.trim(),
                items_path:    document.getElementById('mp-items-path').value.trim(),
                source_sample: sourceSample,
                mappings:      mappingRows,
            };

            const r = await GH.ajax('gh_ajax_mapper_save_rule', { rule: JSON.stringify(rule) });
            if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }

            editingRuleId = r.data.id;
            GH.toast('Regola "' + name + '" salvata', 'ok');
        } catch(e) { GH.toast('Errore salvataggio', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    // ── APPLY TO WOOCOMMERCE ────────────────────────────────────
    GH.mapperApply = async function() {
        if (!editingRuleId) { GH.toast('Salva prima la regola', 'err'); return; }
        if (!sourceSample) { GH.toast('Nessun dato sorgente', 'err'); return; }
        if (!confirm('Applicare il mapping a WooCommerce?')) return;

        const ov = document.getElementById('mp-overlay');
        const ot = document.getElementById('mp-overlay-text');
        const sp = document.getElementById('mp-apply-spin');
        ot.textContent = 'Applicazione mapping...';
        ov.classList.add('visible');
        sp.style.display = '';

        try {
            const mode = document.getElementById('mp-apply-mode').value;
            const r = await GH.ajax('gh_ajax_mapper_apply', {
                source_data: JSON.stringify(sourceSample),
                rule_id:     editingRuleId,
                items_path:  document.getElementById('mp-items-path').value.trim(),
                mode:        mode
            });

            if (!r.success) { GH.toast('Errore: ' + r.data, 'err'); return; }

            const d = r.data;
            let h = '<table class="ptable"><thead><tr><th>Risultato</th><th>ID</th><th>Nome</th><th>SKU</th></tr></thead><tbody>';
            for (const det of d.details) {
                const c = det.status === 'created' ? 'st-created' : det.status === 'updated' ? 'st-updated' : det.status === 'skipped' ? 'st-skipped' : 'st-error';
                const l = det.status === 'created' ? '+ Creato' : det.status === 'updated' ? '\u2713 Agg.' : det.status === 'skipped' ? '\u2013 Skip' : '\u2717 Err';
                h += '<tr><td class="' + c + '">' + l + '</td><td>' + (det.id || '\u2013') + '</td><td>' + GH.esc(det.name || '') + '</td><td>' + GH.esc(det.sku || '') + '</td></tr>';
            }
            h += '</tbody></table>';
            document.getElementById('mp-preview-area').innerHTML = h;
            document.getElementById('mp-apply-bar').style.display = 'none';

            GH.toast(d.created + ' creati, ' + d.updated + ' aggiornati' + (d.errors ? ', ' + d.errors + ' errori' : ''), d.errors ? 'err' : 'ok', 5000);
        } catch(e) { GH.toast('Errore applicazione', 'err'); }
        finally { ov.classList.remove('visible'); sp.style.display = 'none'; }
    };

    // ── INIT ────────────────────────────────────────────────────
    initSourceUpload();

})();
