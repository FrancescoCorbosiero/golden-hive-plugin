// ═══ FILTER & BULK OPERATIONS ═══════════════════════════════════════════════

(function(){

    // ── STATE ────────────────────────────────────────────────────
    let filterMeta = null;
    let conditions = [];
    let filteredIds = [];
    let filteredProducts = [];
    let selectedIds = new Set();
    let expandedRow = null;

    // ── LOAD FILTER META ────────────────────────────────────────
    async function loadFilterMeta() {
        if (filterMeta) return filterMeta;
        const r = await GH.ajax('gh_ajax_filter_meta');
        if (r.success) filterMeta = r.data;
        return filterMeta;
    }

    // ── CONDITION BUILDER ───────────────────────────────────────
    GH.addCondition = async function() {
        await loadFilterMeta();
        conditions.push({ type: '', operator: '', value: null });
        renderConditions();
    };

    GH.clearConditions = function() {
        conditions = [];
        filteredIds = [];
        filteredProducts = [];
        selectedIds.clear();
        expandedRow = null;
        document.getElementById('filter-conditions').innerHTML = '';
        document.getElementById('filter-results-area').innerHTML = '<div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Aggiungi condizioni e premi "Filtra"</div></div>';
        document.getElementById('filter-action-bar').style.display = 'none';
        document.getElementById('filter-count').textContent = '';
    };

    function renderConditions() {
        const wrap = document.getElementById('filter-conditions');
        if (!filterMeta) { wrap.innerHTML = ''; return; }
        const defs = filterMeta.conditions;
        let html = '';
        conditions.forEach(function(c, i) {
            html += '<div class="cond-row" style="display:flex;gap:6px;align-items:center;padding:6px 0;">';
            html += '<select class="filter-select" onchange="GH.condTypeChanged(' + i + ',this.value)" style="min-width:140px;"><option value="">— Campo —</option>';
            let lg = '';
            for (const [key, def] of Object.entries(defs)) {
                if (def.group !== lg) { if (lg) html += '</optgroup>'; html += '<optgroup label="' + esc(def.group.toUpperCase()) + '">'; lg = def.group; }
                html += '<option value="' + key + '"' + (c.type === key ? ' selected' : '') + '>' + esc(def.label) + '</option>';
            }
            if (lg) html += '</optgroup>';
            html += '</select>';
            if (c.type && defs[c.type]) {
                const ops = defs[c.type].operators || [];
                html += '<select class="filter-select" onchange="GH.condOpChanged(' + i + ',this.value)" style="min-width:100px;">';
                ops.forEach(function(op) { html += '<option value="' + op + '"' + (c.operator === op ? ' selected' : '') + '>' + opLabel(op) + '</option>'; });
                html += '</select>';
                html += renderValueInput(i, c);
            }
            html += '<button class="btn btn-ghost" onclick="GH.removeCondition(' + i + ')" style="padding:4px 8px;color:var(--dim);">&times;</button></div>';
        });
        wrap.innerHTML = html;
    }

    function renderValueInput(idx, cond) {
        if (!filterMeta || !cond.type) return '';
        const def = filterMeta.conditions[cond.type];
        if (!def) return '';
        const vt = def.value_type, val = cond.value;
        if (vt === 'none') return '';
        if (vt === 'boolean') return '<select class="filter-select" onchange="GH.condValueChanged('+idx+',this.value===\'1\')" style="min-width:80px;"><option value="1"'+(val===true?' selected':'')+'>Si</option><option value="0"'+(val===false?' selected':'')+'>No</option></select>';
        if (vt === 'select') { let h='<select class="filter-select" onchange="GH.condValueChanged('+idx+',this.value)" style="min-width:120px;">'; (def.options||[]).forEach(function(o){h+='<option value="'+o+'"'+(val===o?' selected':'')+'>'+o+'</option>';}); return h+'</select>'; }
        if (vt === 'term_ids') {
            const items = cond.type==='category'?(filterMeta.categories||[])
                        : cond.type==='brand'?(filterMeta.brands||[])
                        : cond.type==='tag'?(filterMeta.tags||[])
                        : [];
            let h='<select class="filter-select" multiple onchange="GH.condTermsChanged('+idx+',this)" style="min-width:200px;min-height:32px;">';
            items.forEach(function(t){h+='<option value="'+t.id+'"'+(Array.isArray(val)&&val.includes(t.id)?' selected':'')+'>'+esc(t.name)+'</option>';});
            return h+'</select>';
        }
        if (vt === 'text') return '<input type="text" class="filter-select" placeholder="Valore..." value="'+esc(val||'')+'" onchange="GH.condValueChanged('+idx+',this.value)" style="min-width:140px;">';
        if (vt === 'number') return '<input type="number" class="filter-select" placeholder="Valore" value="'+(val||'')+'" onchange="GH.condValueChanged('+idx+',parseFloat(this.value))" style="width:80px;">';
        if (vt === 'number_range' || vt === 'date_range') {
            const isD = vt==='date_range', it = isD?'date':'number', w = isD?'140px':'80px';
            const mn = (val&&typeof val==='object')?(val.min||''):(val||''), mx = (val&&typeof val==='object')?(val.max||''):'';
            if (cond.operator==='between') return '<input type="'+it+'" class="filter-select" placeholder="Min" value="'+mn+'" onchange="GH.condRangeChanged('+idx+',\'min\',this.value)" style="width:'+w+';"><span style="color:var(--dim);font-size:11px;">—</span><input type="'+it+'" class="filter-select" placeholder="Max" value="'+mx+'" onchange="GH.condRangeChanged('+idx+',\'max\',this.value)" style="width:'+w+';">';
            return '<input type="'+it+'" class="filter-select" placeholder="Valore" value="'+mn+'" onchange="GH.condValueChanged('+idx+','+(isD?'this.value':'parseFloat(this.value)')+')" style="width:'+w+';">';
        }
        if (vt === 'attribute_value') {
            let h='<select class="filter-select" onchange="GH.condAttrNameChanged('+idx+',this.value)" style="min-width:120px;"><option value="">— Attributo —</option>';
            (filterMeta.attributes||[]).forEach(function(a){h+='<option value="'+a.name+'"'+(cond.attribute_name===a.name?' selected':'')+'>'+esc(a.label)+'</option>';});
            h+='</select>';
            if (cond.attribute_name && (cond.operator==='has_value'||cond.operator==='not_has_value')) {
                const attr=(filterMeta.attributes||[]).find(function(a){return a.name===cond.attribute_name;});
                if (attr&&attr.values.length) { h+='<select class="filter-select" onchange="GH.condValueChanged('+idx+',this.value)" style="min-width:100px;">'; attr.values.forEach(function(v){h+='<option value="'+esc(v.name)+'"'+(val===v.name?' selected':'')+'>'+esc(v.name)+'</option>';}); h+='</select>'; }
                else h+='<input type="text" class="filter-select" placeholder="Valore" value="'+esc(val||'')+'" onchange="GH.condValueChanged('+idx+',this.value)" style="min-width:100px;">';
            }
            return h;
        }
        return '';
    }

    // ── CONDITION EVENTS ────────────────────────────────────────
    GH.condTypeChanged = function(i,t) { conditions[i].type=t; const d=filterMeta?filterMeta.conditions:{}; if(d[t]){conditions[i].operator=d[t].operators[0]||'';conditions[i].value=null;conditions[i].attribute_name='';} renderConditions(); };
    GH.condOpChanged = function(i,o) { conditions[i].operator=o; renderConditions(); };
    GH.condValueChanged = function(i,v) { conditions[i].value=v; };
    GH.condTermsChanged = function(i,sel) { conditions[i].value=Array.from(sel.selectedOptions).map(function(o){return parseInt(o.value);}); };
    GH.condRangeChanged = function(i,k,v) { if(!conditions[i].value||typeof conditions[i].value!=='object')conditions[i].value={}; conditions[i].value[k]=v; };
    GH.condAttrNameChanged = function(i,n) { conditions[i].attribute_name=n; conditions[i].value=null; renderConditions(); };
    GH.removeCondition = function(i) { conditions.splice(i,1); renderConditions(); };

    // ── RUN FILTER ──────────────────────────────────────────────
    GH.runFilter = async function() {
        const valid = conditions.filter(function(c){return c.type&&c.operator;});
        if (!valid.length) { GH.toast('Aggiungi almeno una condizione.','err'); return; }
        document.getElementById('filter-spin').style.display = '';
        const r = await GH.ajax('gh_ajax_filter_products', { conditions: JSON.stringify(valid), per_page: 200, page: 1 });
        document.getElementById('filter-spin').style.display = 'none';
        if (!r.success) { GH.toast(r.data||'Errore filtro.','err'); return; }
        filteredProducts = r.data.products;
        filteredIds = r.data.product_ids;
        selectedIds.clear();
        expandedRow = null;
        document.getElementById('filter-count').textContent = r.data.total + ' prodotti trovati';
        renderFilterResults(r.data);
        document.getElementById('filter-action-bar').style.display = r.data.total > 0 ? '' : 'none';
        updateSelectionCount();
        if (r.data.total > 200) {
            const allR = await GH.ajax('gh_ajax_filter_ids', { conditions: JSON.stringify(valid) });
            if (allR.success) filteredIds = allR.data.product_ids;
        }
    };

    // ── SELECTION ───────────────────────────────────────────────
    GH.toggleSelectAll = function(checked) {
        if (checked) { filteredProducts.forEach(function(p){ selectedIds.add(p.id); }); }
        else { selectedIds.clear(); }
        document.querySelectorAll('#filter-results-area .row-chk').forEach(function(c){ c.checked = checked; });
        updateSelectionCount();
    };
    GH.toggleSelectRow = function(id, checked) {
        if (checked) selectedIds.add(id); else selectedIds.delete(id);
        const allChk = document.getElementById('chk-select-all');
        if (allChk) allChk.checked = selectedIds.size === filteredProducts.length;
        updateSelectionCount();
    };
    GH.selectAllFiltered = function() {
        filteredIds.forEach(function(id){ selectedIds.add(id); });
        document.querySelectorAll('#filter-results-area .row-chk').forEach(function(c){ c.checked = true; });
        const allChk = document.getElementById('chk-select-all');
        if (allChk) allChk.checked = true;
        updateSelectionCount();
    };
    function updateSelectionCount() {
        const el = document.getElementById('selection-count');
        if (el) el.textContent = selectedIds.size > 0 ? selectedIds.size + ' selezionati' : '';
        const btn = document.getElementById('btn-bulk-execute');
        if (btn) btn.disabled = selectedIds.size === 0 || !document.getElementById('bulk-action-select').value;
        const bjson = document.getElementById('btn-bulk-json');
        if (bjson) bjson.disabled = selectedIds.size === 0;
    }
    function getSelectedIds() { return Array.from(selectedIds); }

    // ── RENDER RESULTS TABLE ────────────────────────────────────
    function renderFilterResults(data) {
        const area = document.getElementById('filter-results-area');
        if (!data.products.length) { area.innerHTML = '<div class="empty-state" style="margin-top:20px;"><div class="empty-icon">&#8709;</div><div class="empty-text">Nessun prodotto corrisponde ai filtri.</div></div>'; return; }
        let html = '<div style="display:flex;gap:8px;align-items:center;padding:4px 0;"><button class="btn btn-ghost btn-sm" onclick="GH.selectAllFiltered()">Seleziona tutti (' + data.total + ')</button><span id="selection-count" style="font-size:11px;color:var(--grn);font-weight:600;"></span></div>';
        html += '<table style="width:100%;border-collapse:collapse;margin-top:4px;font-size:12px;">';
        html += '<tr style="background:var(--s1);position:sticky;top:0;z-index:10;">';
        html += '<th class="tbl-th" style="width:28px;"><input type="checkbox" id="chk-select-all" onchange="GH.toggleSelectAll(this.checked)"></th>';
        html += '<th class="tbl-th">ID</th><th class="tbl-th">Nome</th><th class="tbl-th">SKU</th>';
        html += '<th class="tbl-th">Tipo</th><th class="tbl-th">Stato</th><th class="tbl-th">Prezzo</th><th class="tbl-th">Saldo</th>';
        html += '<th class="tbl-th">Stock</th><th class="tbl-th">Cat.</th><th class="tbl-th">Ord.</th><th class="tbl-th" style="width:80px;"></th>';
        html += '</tr>';
        data.products.forEach(function(p) {
            const sel = selectedIds.has(p.id);
            html += '<tr class="frow" data-id="' + p.id + '" style="border-bottom:1px solid var(--b1);">';
            html += '<td class="tbl-td"><input type="checkbox" class="row-chk"' + (sel?' checked':'') + ' onchange="GH.toggleSelectRow(' + p.id + ',this.checked)"></td>';
            html += '<td class="tbl-td mono" style="font-size:10px;color:var(--dim);">' + p.id + '</td>';
            html += '<td class="tbl-td ecell" data-field="name" data-id="' + p.id + '" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;" title="Click per modificare">' + esc(p.name) + '</td>';
            html += '<td class="tbl-td ecell mono" data-field="sku" data-id="' + p.id + '" style="font-size:11px;color:var(--dim);cursor:pointer;">' + esc(p.sku||'-') + '</td>';
            html += '<td class="tbl-td"><span class="badge badge-' + p.type + '">' + p.type + '</span></td>';
            html += '<td class="tbl-td ecell" data-field="status" data-id="' + p.id + '" data-type="select" data-options="publish,draft,private" style="cursor:pointer;"><span class="badge badge-' + p.status + '">' + p.status + '</span></td>';
            html += '<td class="tbl-td ecell mono" data-field="regular_price" data-id="' + p.id + '" style="cursor:pointer;">' + (p.regular_price||'-') + '</td>';
            html += '<td class="tbl-td ecell mono" data-field="sale_price" data-id="' + p.id + '" style="cursor:pointer;color:' + (p.sale_price?'var(--grn)':'var(--dim)') + ';">' + (p.sale_price||'-') + '</td>';
            html += '<td class="tbl-td ecell" data-field="stock_status" data-id="' + p.id + '" data-type="select" data-options="instock,outofstock" style="cursor:pointer;"><span class="badge badge-' + p.stock_status + '">' + p.stock_status + '</span></td>';
            html += '<td class="tbl-td dim" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;font-size:10px;">' + esc((p.categories||[]).join(', ')) + '</td>';
            html += '<td class="tbl-td ecell mono" data-field="menu_order" data-id="' + p.id + '" style="font-size:10px;cursor:pointer;">' + p.menu_order + '</td>';
            html += '<td class="tbl-td" style="white-space:nowrap">';
            if (p.variant_count > 0) html += '<button class="btn btn-ghost btn-sm" onclick="GH.toggleExpand(' + p.id + ')" style="padding:2px 6px;font-size:9px;">' + p.variant_count + ' var</button> ';
            html += '<button class="btn btn-ghost btn-sm" onclick="GH.openInlineEditor(' + p.id + ')" style="padding:2px 6px;font-size:9px;color:var(--acc)">Edit</button>';
            html += '</td>';
            html += '</tr>';
            // Expanded variations row (hidden by default)
            html += '<tr class="var-row" data-parent="' + p.id + '" style="display:none;"><td colspan="12" style="padding:0 0 0 40px;background:var(--s1);"></td></tr>';
        });
        html += '</table>';
        if (data.total > data.products.length) html += '<div style="padding:8px;text-align:center;color:var(--dim);font-size:11px;">Mostrati ' + data.products.length + ' di ' + data.total + '</div>';
        area.innerHTML = html;
        // Attach inline edit listeners
        area.querySelectorAll('.ecell').forEach(function(cell) { cell.addEventListener('dblclick', function(){ startInlineEdit(cell); }); });
    }

    // ── INLINE EDIT ─────────────────────────────────────────────
    function startInlineEdit(cell) {
        if (cell.querySelector('input,select')) return; // already editing
        const field = cell.dataset.field, id = parseInt(cell.dataset.id), type = cell.dataset.type || 'text';
        const oldVal = cell.textContent.trim();

        if (type === 'select') {
            const opts = (cell.dataset.options || '').split(',');
            let h = '<select class="filter-select" style="font-size:11px;padding:2px 4px;">';
            opts.forEach(function(o) { h += '<option value="' + o + '"' + (o===oldVal?' selected':'') + '>' + o + '</option>'; });
            h += '</select>';
            cell.innerHTML = h;
            const sel = cell.querySelector('select');
            sel.focus();
            sel.addEventListener('change', function() { commitInlineEdit(cell, id, field, sel.value); });
            sel.addEventListener('blur', function() { setTimeout(function(){ if(cell.contains(sel)) commitInlineEdit(cell, id, field, sel.value); }, 100); });
        } else {
            cell.innerHTML = '<input type="text" class="filter-select" value="' + esc(oldVal === '-' ? '' : oldVal) + '" style="font-size:11px;padding:2px 4px;width:100%;min-width:60px;">';
            const inp = cell.querySelector('input');
            inp.focus();
            inp.select();
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); commitInlineEdit(cell, id, field, inp.value); }
                if (e.key === 'Escape') { cell.textContent = oldVal; }
            });
            inp.addEventListener('blur', function() { commitInlineEdit(cell, id, field, inp.value); });
        }
    }

    async function commitInlineEdit(cell, productId, field, value) {
        cell.innerHTML = '<span class="spin"></span>';
        const r = await GH.ajax('gh_ajax_inline_update', { product_id: productId, field: field, value: value });
        if (r.success && r.data.product) {
            // Update the product in local state
            const idx = filteredProducts.findIndex(function(p){return p.id===productId;});
            if (idx !== -1) filteredProducts[idx] = r.data.product;
            // Re-render just this row
            updateRowInPlace(r.data.product);
            GH.toast(field + ' aggiornato', 'ok', 1500);
        } else {
            GH.toast(r.data || 'Errore aggiornamento', 'err');
            // Restore old value
            const p = filteredProducts.find(function(p){return p.id===productId;});
            if (p) updateRowInPlace(p);
        }
    }

    function updateRowInPlace(p) {
        const row = document.querySelector('.frow[data-id="' + p.id + '"]');
        if (!row) return;
        const cells = row.querySelectorAll('.ecell');
        cells.forEach(function(cell) {
            const f = cell.dataset.field;
            let val = p[f];
            if (f === 'status' || f === 'stock_status') {
                cell.innerHTML = '<span class="badge badge-' + val + '">' + val + '</span>';
            } else if (f === 'sale_price') {
                cell.textContent = val || '-';
                cell.style.color = val ? 'var(--grn)' : 'var(--dim)';
            } else {
                cell.textContent = val || '-';
            }
        });
        // Re-attach dblclick
        row.querySelectorAll('.ecell').forEach(function(cell) {
            cell.ondblclick = function(){ startInlineEdit(cell); };
        });
    }

    // ── EXPAND VARIATIONS ───────────────────────────────────────
    GH.toggleExpand = async function(pid) {
        const varRow = document.querySelector('.var-row[data-parent="' + pid + '"]');
        if (!varRow) return;
        if (expandedRow === pid) { varRow.style.display = 'none'; expandedRow = null; return; }
        // Collapse previous
        document.querySelectorAll('.var-row').forEach(function(r){r.style.display='none';});
        expandedRow = pid;
        varRow.style.display = '';
        const td = varRow.querySelector('td');
        td.innerHTML = '<div style="padding:8px;"><span class="spin"></span> Caricamento varianti...</div>';
        const r = await GH.ajax('gh_ajax_product_detail', { product_id: pid });
        if (!r.success) { td.innerHTML = '<div style="padding:8px;color:var(--red);">Errore: ' + (r.data||'') + '</div>'; return; }
        const vars = r.data.variations;
        if (!vars.length) { td.innerHTML = '<div style="padding:8px;color:var(--dim);">Nessuna variante.</div>'; return; }
        let h = '<table style="width:100%;border-collapse:collapse;font-size:11px;margin:4px 0 8px;">';
        h += '<tr><th class="tbl-th">Taglia</th><th class="tbl-th">SKU</th><th class="tbl-th">Prezzo</th><th class="tbl-th">Saldo</th><th class="tbl-th">Stock</th><th class="tbl-th">Qty</th><th class="tbl-th">Stato</th></tr>';
        vars.forEach(function(v) {
            h += '<tr style="border-bottom:1px solid var(--b1);">';
            h += '<td class="tbl-td" style="font-weight:600;">' + esc(v.size) + '</td>';
            h += '<td class="tbl-td vcell mono dim" data-vid="'+v.variation_id+'" data-field="sku">' + esc(v.sku||'-') + '</td>';
            h += '<td class="tbl-td vcell mono" data-vid="'+v.variation_id+'" data-field="regular_price">' + (v.regular_price||'-') + '</td>';
            h += '<td class="tbl-td vcell mono" data-vid="'+v.variation_id+'" data-field="sale_price" style="color:'+(v.sale_price?'var(--grn)':'var(--dim)')+';">' + (v.sale_price||'-') + '</td>';
            h += '<td class="tbl-td vcell" data-vid="'+v.variation_id+'" data-field="stock_status" data-type="select" data-options="instock,outofstock"><span class="badge badge-' + v.stock_status + '">' + v.stock_status + '</span></td>';
            h += '<td class="tbl-td vcell mono" data-vid="'+v.variation_id+'" data-field="stock_quantity">' + (v.stock_quantity!==null?v.stock_quantity:'-') + '</td>';
            h += '<td class="tbl-td"><span class="badge badge-' + v.status + '">' + v.status + '</span></td>';
            h += '</tr>';
        });
        h += '</table>';
        td.innerHTML = h;
        // Attach variation inline edit
        td.querySelectorAll('.vcell').forEach(function(cell) { cell.style.cursor = 'pointer'; cell.addEventListener('dblclick', function(){ startVarInlineEdit(cell); }); });
    };

    function startVarInlineEdit(cell) {
        if (cell.querySelector('input,select')) return;
        const field = cell.dataset.field, vid = parseInt(cell.dataset.vid), type = cell.dataset.type || 'text';
        const oldVal = cell.textContent.trim();
        if (type === 'select') {
            const opts = (cell.dataset.options||'').split(',');
            let h = '<select class="filter-select" style="font-size:10px;padding:2px 4px;">';
            opts.forEach(function(o){h+='<option value="'+o+'"'+(o===oldVal?' selected':'')+'>'+o+'</option>';});
            h += '</select>';
            cell.innerHTML = h;
            const sel = cell.querySelector('select');
            sel.focus();
            sel.addEventListener('change', function(){commitVarEdit(cell,vid,field,sel.value,oldVal);});
            sel.addEventListener('blur', function(){setTimeout(function(){if(cell.contains(sel))commitVarEdit(cell,vid,field,sel.value,oldVal);},100);});
        } else {
            cell.innerHTML = '<input type="text" class="filter-select" value="'+esc(oldVal==='-'?'':oldVal)+'" style="font-size:10px;padding:2px 4px;width:100%;min-width:50px;">';
            const inp = cell.querySelector('input');
            inp.focus(); inp.select();
            inp.addEventListener('keydown', function(e){if(e.key==='Enter'){e.preventDefault();commitVarEdit(cell,vid,field,inp.value,oldVal);}if(e.key==='Escape')cell.textContent=oldVal;});
            inp.addEventListener('blur', function(){commitVarEdit(cell,vid,field,inp.value,oldVal);});
        }
    }

    async function commitVarEdit(cell, vid, field, value, oldVal) {
        cell.innerHTML = '<span class="spin"></span>';
        const r = await GH.ajax('gh_ajax_inline_update_variation', { variation_id: vid, field: field, value: value });
        if (r.success) {
            if (field==='stock_status') cell.innerHTML='<span class="badge badge-'+value+'">'+value+'</span>';
            else { cell.textContent = value || '-'; if(field==='sale_price') cell.style.color = value?'var(--grn)':'var(--dim)'; }
            cell.style.cursor = 'pointer';
            cell.addEventListener('dblclick', function(){startVarInlineEdit(cell);});
            GH.toast('Variante aggiornata','ok',1500);
        } else {
            GH.toast(r.data||'Errore','err');
            cell.textContent = oldVal;
        }
    }

    // ── BULK ACTIONS ────────────────────────────────────────────
    const bulkSelect = document.getElementById('bulk-action-select');
    if (bulkSelect) {
        bulkSelect.addEventListener('change', function() {
            renderBulkParams(this.value);
            document.getElementById('btn-bulk-execute').disabled = !this.value || selectedIds.size === 0;
        });
    }

    function renderBulkParams(action) {
        const wrap = document.getElementById('bulk-params');
        wrap.innerHTML = '';
        if (!action) return;
        const percentChangeUI = function(label) {
            return '<input type="number" class="filter-select" id="bulk-percent" placeholder="' + label + '" min="0" step="0.1" style="width:80px;"><span style="color:var(--dim);font-size:11px;">%</span>'
                 + '<select class="filter-select" id="bulk-target"><option value="regular_price">Regular</option><option value="sale_price">Sale</option></select>'
                 + '<select class="filter-select" id="bulk-rounding" title="Arrotondamento">'
                 +   '<option value="2dec">2 decimali</option>'
                 +   '<option value="none">Nessuno</option>'
                 +   '<option value="99">Termina .99</option>'
                 +   '<option value="00">Termina .00</option>'
                 +   '<option value="nearest_5">Multiplo 5</option>'
                 +   '<option value="nearest_10">Multiplo 10</option>'
                 + '</select>';
        };
        const pm = {
            'assign_categories': categorySelector('bulk-cat-ids'),
            'remove_categories': categorySelector('bulk-cat-ids'),
            'set_categories':    categorySelector('bulk-cat-ids'),
            'assign_brands':     brandSelector('bulk-brand-ids'),
            'remove_brands':     brandSelector('bulk-brand-ids'),
            'set_brands':        brandSelector('bulk-brand-ids'),
            'assign_tags':       tagSelector('bulk-tag-ids'),
            'remove_tags':       tagSelector('bulk-tag-ids'),
            'set_status':        '<select class="filter-select" id="bulk-status"><option value="publish">Publish</option><option value="draft">Draft</option><option value="private">Private</option></select>',
            'set_sale_percent':  '<input type="number" class="filter-select" id="bulk-percent" placeholder="%" min="1" max="99" style="width:70px;"><span style="color:var(--dim);font-size:11px;">%</span>',
            'remove_sale':       '<span style="color:var(--dim);font-size:11px;">Rimuove sale_price</span>',
            'adjust_price':      '<input type="number" class="filter-select" id="bulk-amount" placeholder="+/-" step="0.01" style="width:80px;"><select class="filter-select" id="bulk-target"><option value="regular_price">Regular</option><option value="sale_price">Sale</option></select>',
            'markup_percent':    percentChangeUI('+%'),
            'discount_percent':  percentChangeUI('-%'),
            'set_stock_status':  '<select class="filter-select" id="bulk-stock-status"><option value="instock">In stock</option><option value="outofstock">Out of stock</option></select>',
            'set_stock_quantity':'<input type="number" class="filter-select" id="bulk-qty" placeholder="Qty" min="0" style="width:80px;">',
            'set_seo_template':  '<input type="text" class="filter-select" id="bulk-seo-title" placeholder="Meta title: {name} | {brand}" style="min-width:200px;"><input type="text" class="filter-select" id="bulk-seo-desc" placeholder="Meta desc" style="min-width:200px;">',
            'remove_first_gallery_image': '<span style="color:var(--dim);font-size:11px;">Rimuove la prima immagine della gallery (non tocca la featured)</span>',
            'clear_gallery':     '<span style="color:var(--dim);font-size:11px;">Svuota la gallery completamente (non tocca la featured)</span>',
            'set_menu_order':    '<input type="number" class="filter-select" id="bulk-order" placeholder="Ordine" min="0" style="width:80px;">',
        };
        wrap.innerHTML = pm[action] || '';
    }

    function categorySelector(id) {
        if (!filterMeta) return '';
        let h = '<select class="filter-select" id="'+id+'" multiple style="min-width:200px;min-height:32px;">';
        (filterMeta.categories||[]).forEach(function(c){h+='<option value="'+c.id+'">'+(c.parent?'&nbsp;&nbsp;':'')+esc(c.name)+'</option>';});
        return h + '</select>';
    }
    function brandSelector(id) {
        if (!filterMeta) return '';
        const brands = filterMeta.brands || [];
        if (!brands.length) return '<span style="color:var(--dim);font-size:11px;">Nessun brand (product_brand non registrato)</span>';
        let h = '<select class="filter-select" id="'+id+'" multiple style="min-width:200px;min-height:32px;">';
        brands.forEach(function(b){h+='<option value="'+b.id+'">'+(b.parent?'&nbsp;&nbsp;':'')+esc(b.name)+'</option>';});
        return h + '</select>';
    }
    function tagSelector(id) {
        if (!filterMeta) return '';
        let h = '<select class="filter-select" id="'+id+'" multiple style="min-width:200px;min-height:32px;">';
        (filterMeta.tags||[]).forEach(function(t){h+='<option value="'+t.id+'">'+esc(t.name)+'</option>';});
        return h + '</select>';
    }

    GH.executeBulk = async function() {
        const action = document.getElementById('bulk-action-select').value;
        if (!action) return;
        const ids = getSelectedIds();
        if (!ids.length) { GH.toast('Seleziona almeno un prodotto.','err'); return; }
        if (!confirm('Applicare "'+action+'" a '+ids.length+' prodotti?\n\nQuesta azione non e reversibile.')) return;
        const params = collectBulkParams(action);
        const btn = document.getElementById('btn-bulk-execute');
        btn.disabled = true; btn.innerHTML = '<span class="spin"></span>';
        const r = await GH.ajax('gh_ajax_bulk_execute', { bulk_action: action, product_ids: JSON.stringify(ids), params: JSON.stringify(params) });
        btn.disabled = false; btn.textContent = 'Applica';
        if (r.success) {
            document.getElementById('bulk-result').textContent = r.data.summary;
            GH.toast(r.data.summary, r.data.failed > 0 ? 'err' : 'ok');
            GH.runFilter();
        } else { GH.toast(r.data||'Errore bulk.','err'); }
    };

    function collectBulkParams(action) {
        const g=function(id){const e=document.getElementById(id);return e?e.value:'';};
        const gm=function(id){const e=document.getElementById(id);if(!e)return[];return Array.from(e.selectedOptions).map(function(o){return parseInt(o.value);});};
        return {
            'assign_categories':{category_ids:gm('bulk-cat-ids')}, 'remove_categories':{category_ids:gm('bulk-cat-ids')}, 'set_categories':{category_ids:gm('bulk-cat-ids')},
            'assign_brands':{brand_ids:gm('bulk-brand-ids')}, 'remove_brands':{brand_ids:gm('bulk-brand-ids')}, 'set_brands':{brand_ids:gm('bulk-brand-ids')},
            'assign_tags':{tag_ids:gm('bulk-tag-ids')}, 'remove_tags':{tag_ids:gm('bulk-tag-ids')},
            'set_status':{status:g('bulk-status')}, 'set_sale_percent':{percent:parseFloat(g('bulk-percent')||0)}, 'remove_sale':{},
            'adjust_price':{amount:parseFloat(g('bulk-amount')||0),target:g('bulk-target')},
            'markup_percent':{percent:parseFloat(g('bulk-percent')||0),target:g('bulk-target'),rounding:g('bulk-rounding')},
            'discount_percent':{percent:parseFloat(g('bulk-percent')||0),target:g('bulk-target'),rounding:g('bulk-rounding')},
            'set_stock_status':{stock_status:g('bulk-stock-status')}, 'set_stock_quantity':{quantity:parseInt(g('bulk-qty')||0)},
            'set_seo_template':{meta_title_template:g('bulk-seo-title'),meta_description_template:g('bulk-seo-desc')},
            'remove_first_gallery_image':{}, 'clear_gallery':{},
            'set_menu_order':{menu_order:parseInt(g('bulk-order')||0)},
        }[action] || {};
    }

    // ── SORTING TAB ─────────────────────────────────────────────
    let sortIds = [];

    GH.sortPreview = async function() {
        const rule = document.getElementById('sort-rule').value;
        const source = document.getElementById('sort-source').value;
        document.getElementById('sort-spin').style.display = '';
        if (source === 'filtered' && filteredIds.length) { sortIds = filteredIds; }
        else {
            const r = await GH.ajax('gh_ajax_filter_ids', { conditions: JSON.stringify([{type:'status',operator:'is',value:'publish'}]) });
            if (r.success) sortIds = r.data.product_ids;
        }
        const r = await GH.ajax('gh_ajax_sort_preview', { rule: rule, product_ids: JSON.stringify(sortIds) });
        document.getElementById('sort-spin').style.display = 'none';
        if (!r.success) { GH.toast(r.data||'Errore.','err'); return; }
        renderSortPreview(r.data);
        document.getElementById('btn-sort-apply').disabled = false;
    };

    GH.sortApply = async function() {
        const rule = document.getElementById('sort-rule').value;
        if (!confirm('Applicare ordinamento "'+rule+'" a '+sortIds.length+' prodotti?')) return;
        const btn = document.getElementById('btn-sort-apply');
        btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Applicazione...';
        const r = await GH.ajax('gh_ajax_sort_apply', { rule: rule, product_ids: JSON.stringify(sortIds) });
        btn.disabled = false; btn.textContent = 'Applica Ordinamento';
        if (r.success) { GH.toast(r.data.updated+'/'+r.data.total+' riordinati.','ok'); renderSortPreview(r.data); }
        else GH.toast(r.data||'Errore.','err');
    };

    function renderSortPreview(data) {
        const area = document.getElementById('sort-results');
        if (!data.preview.length) { area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#8709;</div><div class="empty-text">Nessun prodotto.</div></div>'; return; }
        let html = '<div style="margin-bottom:8px;font-size:12px;color:var(--dim);">'+data.total+' prodotti — <strong style="color:var(--acc);">'+data.rule+'</strong></div>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px;"><tr style="background:var(--s1);"><th class="tbl-th">#</th><th class="tbl-th">ID</th><th class="tbl-th">Nome</th><th class="tbl-th">SKU</th><th class="tbl-th">Attuale</th><th class="tbl-th">Nuovo</th></tr>';
        data.preview.forEach(function(p,i) {
            const ch = p.old_order !== p.new_order;
            html += '<tr style="border-bottom:1px solid var(--b1);'+(ch?'background:rgba(61,127,255,.05);':'')+'"><td class="tbl-td mono dim">'+(i+1)+'</td><td class="tbl-td mono">'+p.id+'</td><td class="tbl-td">'+esc(p.name)+'</td><td class="tbl-td mono dim">'+esc(p.sku||'-')+'</td><td class="tbl-td mono">'+p.old_order+'</td><td class="tbl-td mono" style="color:'+(ch?'var(--acc)':'var(--dim)')+';">'+p.new_order+(ch?' &#8592;':'')+'</td></tr>';
        });
        if (data.total > data.preview.length) html += '<tr><td colspan="6" style="text-align:center;padding:8px;color:var(--dim);font-size:11px;">...e altri '+(data.total-data.preview.length)+'</td></tr>';
        html += '</table>';
        area.innerHTML = html;
    }

    // ── BULK JSON EDITOR ────────────────────────────────────────

    GH.openBulkJson = async function() {
        const ids = getSelectedIds();
        if (!ids.length) { GH.toast('Seleziona almeno un prodotto.','err'); return; }
        const overlay = document.getElementById('bulk-json-overlay');
        const editor = document.getElementById('bjson-editor');
        const status = document.getElementById('bjson-status');
        const result = document.getElementById('bjson-result');
        overlay.style.display = 'flex';
        editor.value = '';
        result.style.display = 'none';
        status.textContent = 'Caricamento ' + ids.length + ' prodotti...';
        try {
            const r = await GH.ajax('gh_ajax_product_bulk_load', { product_ids: JSON.stringify(ids) });
            if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
            editor.value = JSON.stringify(r.data, null, 2);
            status.textContent = r.data.length + ' prodotti caricati';
            document.getElementById('bjson-title').textContent = 'Bulk JSON Editor — ' + r.data.length + ' prodotti';
        } catch (e) { GH.toast('Errore caricamento', 'err'); }
    };

    GH.closeBulkJson = function() {
        document.getElementById('bulk-json-overlay').style.display = 'none';
    };

    GH.bulkJsonCopy = function() {
        const editor = document.getElementById('bjson-editor');
        navigator.clipboard.writeText(editor.value).then(
            function() { GH.toast('Copiato', 'ok'); },
            function() { editor.select(); document.execCommand('copy'); GH.toast('Copiato', 'ok'); }
        );
    };

    GH.bulkJsonPaste = async function() {
        try {
            const text = await navigator.clipboard.readText();
            document.getElementById('bjson-editor').value = text;
            GH.toast('Incollato', 'ok');
        } catch (e) { GH.toast('Usa Ctrl+V per incollare', 'inf'); }
    };

    GH.bulkJsonFormat = function() {
        const editor = document.getElementById('bjson-editor');
        try {
            const parsed = JSON.parse(editor.value);
            editor.value = JSON.stringify(parsed, null, 2);
            const count = Array.isArray(parsed) ? parsed.length : 1;
            document.getElementById('bjson-status').textContent = count + ' prodotti — JSON valido';
        } catch (e) {
            GH.toast('JSON non valido: ' + e.message, 'err');
        }
    };

    GH.bulkJsonApply = async function() {
        const editor = document.getElementById('bjson-editor');
        let products;
        try {
            products = JSON.parse(editor.value);
        } catch (e) {
            GH.toast('JSON non valido: ' + e.message, 'err'); return;
        }
        if (!Array.isArray(products)) products = [products];
        if (!products.length) { GH.toast('Nessun prodotto nel JSON', 'err'); return; }
        if (!confirm('Applicare modifiche a ' + products.length + ' prodotti?\n\nProdotti esistenti (by ID/SKU) verranno aggiornati.\nNuovi prodotti verranno creati.')) return;

        const btn = document.getElementById('btn-bjson-apply');
        const sp = document.getElementById('bjson-apply-spin');
        const status = document.getElementById('bjson-status');
        btn.disabled = true; sp.style.display = '';
        status.textContent = 'Upsert ' + products.length + ' prodotti...';

        try {
            const r = await GH.ajax('gh_ajax_product_bulk_upsert', { products: JSON.stringify(products) });
            if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
            const s = r.data.summary;
            status.textContent = s.updated + ' aggiornati, ' + s.created + ' creati, ' + s.errors + ' errori';
            GH.toast(status.textContent, s.errors ? 'err' : 'ok', 5000);

            const resEl = document.getElementById('bjson-result');
            let h = '<table style="width:100%;border-collapse:collapse;"><tr style="color:var(--dim)"><th style="text-align:left;padding:2px 6px">Azione</th><th style="text-align:left;padding:2px 6px">ID</th><th style="text-align:left;padding:2px 6px">SKU</th><th style="text-align:left;padding:2px 6px">Nome</th></tr>';
            for (const d of r.data.details) {
                const c = d.action === 'updated' ? 'color:var(--grn)' : d.action === 'created' ? 'color:var(--acc)' : 'color:var(--red)';
                const lb = d.action === 'updated' ? '\u2713 Agg.' : d.action === 'created' ? '+ Nuovo' : '\u2717 ' + (d.reason || 'Err');
                h += '<tr><td style="padding:2px 6px;' + c + '">' + lb + '</td><td style="padding:2px 6px">' + (d.id || '-') + '</td><td style="padding:2px 6px">' + esc(d.sku || '') + '</td><td style="padding:2px 6px">' + esc(d.name || '') + '</td></tr>';
            }
            h += '</table>';
            resEl.innerHTML = h;
            resEl.style.display = '';

            GH.runFilter();
        } catch (e) { GH.toast('Errore: ' + (e.message || e), 'err'); }
        finally { btn.disabled = false; sp.style.display = 'none'; }
    };

    // ── HELPERS ──────────────────────────────────────────────────
    function esc(s) { if(!s)return''; const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
    function opLabel(op) {
        return {'is':'uguale a','is_not':'diverso da','in':'uno di','not_in':'nessuno di','contains':'contiene','not_contains':'non contiene','starts_with':'inizia con','matches':'regex','gt':'maggiore di','lt':'minore di','between':'tra','after':'dopo','before':'prima','exists':'presente','not_exists':'assente','has_value':'ha valore','not_has_value':'non ha valore','has_attribute':'ha attributo','not_has_attribute':'non ha attributo'}[op]||op;
    }
    const origSwitch = GH.switchTab;
    GH.switchTab = function(tab, el) { origSwitch(tab, el); if (tab==='filter'||tab==='sorting') loadFilterMeta(); };

    // Preload filter meta: "Filtra & Agisci" e ora la tab attiva all'apertura.
    loadFilterMeta();
})();
