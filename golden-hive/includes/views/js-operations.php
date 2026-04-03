// ═══ FILTER & BULK OPERATIONS ═══════════════════════════════════════════════

(function(){

    // ── STATE ────────────────────────────────────────────────────
    let filterMeta = null;       // condition definitions, categories, tags, attributes
    let conditions = [];         // active filter conditions
    let filteredIds = [];        // IDs from last filter
    let filteredProducts = [];   // serialized products from last filter

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
        const idx = conditions.length;
        conditions.push({ type: '', operator: '', value: null });
        renderConditions();
    };

    GH.clearConditions = function() {
        conditions = [];
        filteredIds = [];
        filteredProducts = [];
        document.getElementById('filter-conditions').innerHTML = '';
        document.getElementById('filter-results-area').innerHTML = '<div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Aggiungi condizioni e premi "Filtra"</div></div>';
        document.getElementById('filter-action-bar').style.display = 'none';
        document.getElementById('filter-count').textContent = '';
    };

    function renderConditions() {
        const wrap = document.getElementById('filter-conditions');
        let html = '';

        conditions.forEach(function(c, i) {
            const defs = filterMeta ? filterMeta.conditions : {};
            html += '<div class="cond-row" style="display:flex;gap:6px;align-items:center;padding:6px 0;">';

            // Type selector
            html += '<select class="filter-select" onchange="GH.condTypeChanged(' + i + ',this.value)" style="min-width:140px;">';
            html += '<option value="">— Campo —</option>';
            let lastGroup = '';
            for (const [key, def] of Object.entries(defs)) {
                if (def.group !== lastGroup) {
                    if (lastGroup) html += '</optgroup>';
                    html += '<optgroup label="' + esc(def.group.toUpperCase()) + '">';
                    lastGroup = def.group;
                }
                html += '<option value="' + key + '"' + (c.type === key ? ' selected' : '') + '>' + esc(def.label) + '</option>';
            }
            if (lastGroup) html += '</optgroup>';
            html += '</select>';

            // Operator selector (populated dynamically)
            if (c.type && defs[c.type]) {
                const ops = defs[c.type].operators || [];
                html += '<select class="filter-select" onchange="GH.condOpChanged(' + i + ',this.value)" style="min-width:100px;">';
                ops.forEach(function(op) {
                    html += '<option value="' + op + '"' + (c.operator === op ? ' selected' : '') + '>' + opLabel(op) + '</option>';
                });
                html += '</select>';

                // Value input
                html += renderValueInput(i, c);
            }

            html += '<button class="btn btn-ghost" onclick="GH.removeCondition(' + i + ')" style="padding:4px 8px;color:var(--dim);">&times;</button>';
            html += '</div>';
        });

        wrap.innerHTML = html;
    }

    function renderValueInput(idx, cond) {
        if (!filterMeta || !cond.type) return '';
        const def = filterMeta.conditions[cond.type];
        if (!def) return '';
        const vt = def.value_type;
        const val = cond.value;

        if (vt === 'none') return '';

        if (vt === 'boolean') {
            return '<select class="filter-select" onchange="GH.condValueChanged(' + idx + ',this.value===\'1\')" style="min-width:80px;">'
                + '<option value="1"' + (val === true ? ' selected' : '') + '>Si</option>'
                + '<option value="0"' + (val === false ? ' selected' : '') + '>No</option></select>';
        }

        if (vt === 'select') {
            let h = '<select class="filter-select" onchange="GH.condValueChanged(' + idx + ',this.value)" style="min-width:120px;">';
            (def.options || []).forEach(function(o) {
                h += '<option value="' + o + '"' + (val === o ? ' selected' : '') + '>' + o + '</option>';
            });
            h += '</select>';
            return h;
        }

        if (vt === 'term_ids') {
            // Multi-select for categories/tags
            const items = cond.type === 'category' ? (filterMeta.categories || [])
                : cond.type === 'tag' ? (filterMeta.tags || []) : [];
            let h = '<select class="filter-select" multiple onchange="GH.condTermsChanged(' + idx + ',this)" style="min-width:200px;min-height:32px;">';
            items.forEach(function(t) {
                const sel = Array.isArray(val) && val.includes(t.id) ? ' selected' : '';
                h += '<option value="' + t.id + '"' + sel + '>' + esc(t.name) + '</option>';
            });
            h += '</select>';
            return h;
        }

        if (vt === 'text') {
            return '<input type="text" class="filter-select" placeholder="Valore..." value="' + esc(val || '') + '" onchange="GH.condValueChanged(' + idx + ',this.value)" style="min-width:140px;">';
        }

        if (vt === 'number') {
            return '<input type="number" class="filter-select" placeholder="Valore" value="' + (val || '') + '" onchange="GH.condValueChanged(' + idx + ',parseFloat(this.value))" style="width:80px;">';
        }

        if (vt === 'number_range' || vt === 'date_range') {
            const isDate = vt === 'date_range';
            const inputType = isDate ? 'date' : 'number';
            const minVal = (val && typeof val === 'object') ? (val.min || '') : (val || '');
            const maxVal = (val && typeof val === 'object') ? (val.max || '') : '';

            if (cond.operator === 'between') {
                return '<input type="' + inputType + '" class="filter-select" placeholder="Min" value="' + minVal + '" onchange="GH.condRangeChanged(' + idx + ',\'min\',this.value)" style="width:' + (isDate ? '140px' : '80px') + ';">'
                    + '<span style="color:var(--dim);font-size:11px;">—</span>'
                    + '<input type="' + inputType + '" class="filter-select" placeholder="Max" value="' + maxVal + '" onchange="GH.condRangeChanged(' + idx + ',\'max\',this.value)" style="width:' + (isDate ? '140px' : '80px') + ';">';
            }
            return '<input type="' + inputType + '" class="filter-select" placeholder="Valore" value="' + minVal + '" onchange="GH.condValueChanged(' + idx + ',' + (isDate ? 'this.value' : 'parseFloat(this.value)') + ')" style="width:' + (isDate ? '140px' : '80px') + ';">';
        }

        if (vt === 'attribute_value') {
            // Attribute name selector + value
            let h = '<select class="filter-select" onchange="GH.condAttrNameChanged(' + idx + ',this.value)" style="min-width:120px;">';
            h += '<option value="">— Attributo —</option>';
            (filterMeta.attributes || []).forEach(function(a) {
                h += '<option value="' + a.name + '"' + (cond.attribute_name === a.name ? ' selected' : '') + '>' + esc(a.label) + '</option>';
            });
            h += '</select>';

            if (cond.attribute_name && (cond.operator === 'has_value' || cond.operator === 'not_has_value')) {
                const attr = (filterMeta.attributes || []).find(function(a) { return a.name === cond.attribute_name; });
                if (attr && attr.values.length) {
                    h += '<select class="filter-select" onchange="GH.condValueChanged(' + idx + ',this.value)" style="min-width:100px;">';
                    attr.values.forEach(function(v) {
                        h += '<option value="' + esc(v.name) + '"' + (val === v.name ? ' selected' : '') + '>' + esc(v.name) + '</option>';
                    });
                    h += '</select>';
                } else {
                    h += '<input type="text" class="filter-select" placeholder="Valore" value="' + esc(val || '') + '" onchange="GH.condValueChanged(' + idx + ',this.value)" style="min-width:100px;">';
                }
            }
            return h;
        }

        return '';
    }

    // ── CONDITION EVENT HANDLERS ────────────────────────────────

    GH.condTypeChanged = function(idx, type) {
        conditions[idx].type = type;
        const defs = filterMeta ? filterMeta.conditions : {};
        if (defs[type]) {
            conditions[idx].operator = defs[type].operators[0] || '';
            conditions[idx].value = null;
            conditions[idx].attribute_name = '';
        }
        renderConditions();
    };

    GH.condOpChanged = function(idx, op) {
        conditions[idx].operator = op;
        renderConditions();
    };

    GH.condValueChanged = function(idx, val) {
        conditions[idx].value = val;
    };

    GH.condTermsChanged = function(idx, select) {
        const vals = Array.from(select.selectedOptions).map(function(o) { return parseInt(o.value); });
        conditions[idx].value = vals;
    };

    GH.condRangeChanged = function(idx, key, val) {
        if (!conditions[idx].value || typeof conditions[idx].value !== 'object') {
            conditions[idx].value = {};
        }
        conditions[idx].value[key] = val;
    };

    GH.condAttrNameChanged = function(idx, name) {
        conditions[idx].attribute_name = name;
        conditions[idx].value = null;
        renderConditions();
    };

    GH.removeCondition = function(idx) {
        conditions.splice(idx, 1);
        renderConditions();
    };

    // ── RUN FILTER ──────────────────────────────────────────────

    GH.runFilter = async function() {
        const valid = conditions.filter(function(c) { return c.type && c.operator; });
        if (!valid.length) {
            GH.toast('Aggiungi almeno una condizione.', 'err');
            return;
        }

        document.getElementById('filter-spin').style.display = '';

        const r = await GH.ajax('gh_ajax_filter_products', {
            conditions: JSON.stringify(valid),
            per_page: 100,
            page: 1,
        });

        document.getElementById('filter-spin').style.display = 'none';

        if (!r.success) {
            GH.toast(r.data || 'Errore filtro.', 'err');
            return;
        }

        filteredProducts = r.data.products;
        filteredIds = r.data.product_ids;
        document.getElementById('filter-count').textContent = r.data.total + ' prodotti trovati';

        renderFilterResults(r.data);

        // Show action bar
        document.getElementById('filter-action-bar').style.display = r.data.total > 0 ? '' : 'none';

        // Also fetch ALL IDs for bulk actions (the table may be paginated)
        if (r.data.total > 100) {
            const allR = await GH.ajax('gh_ajax_filter_ids', { conditions: JSON.stringify(valid) });
            if (allR.success) filteredIds = allR.data.product_ids;
        }
    };

    function renderFilterResults(data) {
        const area = document.getElementById('filter-results-area');

        if (!data.products.length) {
            area.innerHTML = '<div class="empty-state" style="margin-top:20px;"><div class="empty-icon">&#8709;</div><div class="empty-text">Nessun prodotto corrisponde ai filtri.</div></div>';
            return;
        }

        let html = '<table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:12px;">';
        html += '<tr style="background:var(--s1);position:sticky;top:0;">';
        html += '<th class="tbl-th">ID</th><th class="tbl-th">Nome</th><th class="tbl-th">SKU</th>';
        html += '<th class="tbl-th">Tipo</th><th class="tbl-th">Stato</th><th class="tbl-th">Prezzo</th>';
        html += '<th class="tbl-th">Stock</th><th class="tbl-th">Cat.</th><th class="tbl-th">Ord.</th>';
        html += '</tr>';

        data.products.forEach(function(p) {
            html += '<tr style="border-bottom:1px solid var(--b1);">';
            html += '<td class="tbl-td mono">' + p.id + '</td>';
            html += '<td class="tbl-td" style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(p.name) + '</td>';
            html += '<td class="tbl-td mono dim">' + esc(p.sku || '-') + '</td>';
            html += '<td class="tbl-td"><span class="badge badge-' + p.type + '">' + p.type + '</span></td>';
            html += '<td class="tbl-td"><span class="badge badge-' + p.status + '">' + p.status + '</span></td>';
            html += '<td class="tbl-td mono">' + (p.price || '-') + '</td>';
            html += '<td class="tbl-td"><span class="badge badge-' + p.stock_status + '">' + p.stock_status + '</span></td>';
            html += '<td class="tbl-td dim" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;">' + esc((p.categories || []).join(', ')) + '</td>';
            html += '<td class="tbl-td mono dim">' + p.menu_order + '</td>';
            html += '</tr>';
        });

        html += '</table>';

        if (data.total > data.products.length) {
            html += '<div style="padding:8px;text-align:center;color:var(--dim);font-size:11px;">Mostrati ' + data.products.length + ' di ' + data.total + ' prodotti</div>';
        }

        area.innerHTML = html;
    }

    // ── BULK ACTIONS ────────────────────────────────────────────

    const bulkSelect = document.getElementById('bulk-action-select');
    if (bulkSelect) {
        bulkSelect.addEventListener('change', function() {
            renderBulkParams(this.value);
            document.getElementById('btn-bulk-execute').disabled = !this.value;
        });
    }

    function renderBulkParams(action) {
        const wrap = document.getElementById('bulk-params');
        wrap.innerHTML = '';
        if (!action) return;

        const paramHtml = {
            'assign_categories': categorySelector('bulk-cat-ids'),
            'remove_categories': categorySelector('bulk-cat-ids'),
            'set_categories':    categorySelector('bulk-cat-ids'),
            'assign_tags':       tagSelector('bulk-tag-ids'),
            'remove_tags':       tagSelector('bulk-tag-ids'),
            'set_status':        '<select class="filter-select" id="bulk-status"><option value="publish">Publish</option><option value="draft">Draft</option><option value="private">Private</option></select>',
            'set_sale_percent':  '<input type="number" class="filter-select" id="bulk-percent" placeholder="%" min="1" max="99" style="width:70px;"><span style="color:var(--dim);font-size:11px;">%</span>',
            'remove_sale':       '<span style="color:var(--dim);font-size:11px;">Rimuove sale_price da tutti</span>',
            'adjust_price':      '<input type="number" class="filter-select" id="bulk-amount" placeholder="+/-" step="0.01" style="width:80px;"><select class="filter-select" id="bulk-target"><option value="regular_price">Regular</option><option value="sale_price">Sale</option></select>',
            'set_stock_status':  '<select class="filter-select" id="bulk-stock-status"><option value="instock">In stock</option><option value="outofstock">Out of stock</option></select>',
            'set_stock_quantity':'<input type="number" class="filter-select" id="bulk-qty" placeholder="Quantita" min="0" style="width:80px;">',
            'set_seo_template':  '<input type="text" class="filter-select" id="bulk-seo-title" placeholder="Meta title: {name} | {brand}" style="min-width:200px;"><input type="text" class="filter-select" id="bulk-seo-desc" placeholder="Meta desc: {name} {price}..." style="min-width:200px;">',
            'set_menu_order':    '<input type="number" class="filter-select" id="bulk-order" placeholder="Ordine" min="0" style="width:80px;">',
        };

        wrap.innerHTML = paramHtml[action] || '';
    }

    function categorySelector(id) {
        if (!filterMeta) return '';
        let h = '<select class="filter-select" id="' + id + '" multiple style="min-width:200px;min-height:32px;">';
        (filterMeta.categories || []).forEach(function(c) {
            const indent = c.parent ? '&nbsp;&nbsp;' : '';
            h += '<option value="' + c.id + '">' + indent + esc(c.name) + '</option>';
        });
        h += '</select>';
        return h;
    }

    function tagSelector(id) {
        if (!filterMeta) return '';
        let h = '<select class="filter-select" id="' + id + '" multiple style="min-width:200px;min-height:32px;">';
        (filterMeta.tags || []).forEach(function(t) {
            h += '<option value="' + t.id + '">' + esc(t.name) + '</option>';
        });
        h += '</select>';
        return h;
    }

    GH.executeBulk = async function() {
        const action = document.getElementById('bulk-action-select').value;
        if (!action) return;
        if (!filteredIds.length) { GH.toast('Nessun prodotto filtrato.', 'err'); return; }

        if (!confirm('Applicare "' + action + '" a ' + filteredIds.length + ' prodotti?\n\nQuesta azione non e reversibile.')) return;

        const params = collectBulkParams(action);
        const btn = document.getElementById('btn-bulk-execute');
        btn.disabled = true;
        btn.innerHTML = '<span class="spin"></span>';

        const r = await GH.ajax('gh_ajax_bulk_execute', {
            bulk_action: action,
            product_ids: JSON.stringify(filteredIds),
            params: JSON.stringify(params),
        });

        btn.disabled = false;
        btn.textContent = 'Applica';

        if (r.success) {
            document.getElementById('bulk-result').textContent = r.data.summary;
            GH.toast(r.data.summary, r.data.failed > 0 ? 'err' : 'ok');
            // Refresh results
            GH.runFilter();
        } else {
            GH.toast(r.data || 'Errore bulk.', 'err');
        }
    };

    function collectBulkParams(action) {
        const g = function(id) { const e = document.getElementById(id); return e ? e.value : ''; };
        const gm = function(id) {
            const e = document.getElementById(id);
            if (!e) return [];
            return Array.from(e.selectedOptions).map(function(o) { return parseInt(o.value); });
        };

        return {
            'assign_categories': { category_ids: gm('bulk-cat-ids') },
            'remove_categories': { category_ids: gm('bulk-cat-ids') },
            'set_categories':    { category_ids: gm('bulk-cat-ids') },
            'assign_tags':       { tag_ids: gm('bulk-tag-ids') },
            'remove_tags':       { tag_ids: gm('bulk-tag-ids') },
            'set_status':        { status: g('bulk-status') },
            'set_sale_percent':  { percent: parseFloat(g('bulk-percent') || 0) },
            'remove_sale':       {},
            'adjust_price':      { amount: parseFloat(g('bulk-amount') || 0), target: g('bulk-target') },
            'set_stock_status':  { stock_status: g('bulk-stock-status') },
            'set_stock_quantity':{ quantity: parseInt(g('bulk-qty') || 0) },
            'set_seo_template':  { meta_title_template: g('bulk-seo-title'), meta_description_template: g('bulk-seo-desc') },
            'set_menu_order':    { menu_order: parseInt(g('bulk-order') || 0) },
        }[action] || {};
    }

    // ── SORTING TAB ─────────────────────────────────────────────

    let sortIds = [];

    GH.sortPreview = async function() {
        const rule = document.getElementById('sort-rule').value;
        const source = document.getElementById('sort-source').value;

        document.getElementById('sort-spin').style.display = '';

        // Get IDs
        if (source === 'filtered' && filteredIds.length) {
            sortIds = filteredIds;
        } else {
            // Fetch all published product IDs
            const r = await GH.ajax('gh_ajax_filter_ids', {
                conditions: JSON.stringify([{ type: 'status', operator: 'is', value: 'publish' }]),
            });
            if (r.success) sortIds = r.data.product_ids;
        }

        const r = await GH.ajax('gh_ajax_sort_preview', {
            rule: rule,
            product_ids: JSON.stringify(sortIds),
        });

        document.getElementById('sort-spin').style.display = 'none';

        if (!r.success) {
            GH.toast(r.data || 'Errore ordinamento.', 'err');
            return;
        }

        renderSortPreview(r.data);
        document.getElementById('btn-sort-apply').disabled = false;
    };

    GH.sortApply = async function() {
        const rule = document.getElementById('sort-rule').value;

        if (!confirm('Applicare ordinamento "' + rule + '" a ' + sortIds.length + ' prodotti?\n\nVerra scritto il campo menu_order.')) return;

        const btn = document.getElementById('btn-sort-apply');
        btn.disabled = true;
        btn.innerHTML = '<span class="spin"></span> Applicazione...';

        const r = await GH.ajax('gh_ajax_sort_apply', {
            rule: rule,
            product_ids: JSON.stringify(sortIds),
        });

        btn.disabled = false;
        btn.textContent = 'Applica Ordinamento';

        if (r.success) {
            GH.toast(r.data.updated + '/' + r.data.total + ' prodotti riordinati.', 'ok');
            renderSortPreview(r.data);
        } else {
            GH.toast(r.data || 'Errore.', 'err');
        }
    };

    function renderSortPreview(data) {
        const area = document.getElementById('sort-results');

        if (!data.preview.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#8709;</div><div class="empty-text">Nessun prodotto.</div></div>';
            return;
        }

        let html = '<div style="margin-bottom:8px;font-size:12px;color:var(--dim);">' + data.total + ' prodotti — regola: <strong style="color:var(--acc);">' + data.rule + '</strong></div>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        html += '<tr style="background:var(--s1);"><th class="tbl-th">#</th><th class="tbl-th">ID</th><th class="tbl-th">Nome</th><th class="tbl-th">SKU</th><th class="tbl-th">Ordine attuale</th><th class="tbl-th">Nuovo ordine</th></tr>';

        data.preview.forEach(function(p, i) {
            const changed = p.old_order !== p.new_order;
            html += '<tr style="border-bottom:1px solid var(--b1);' + (changed ? 'background:rgba(61,127,255,.05);' : '') + '">';
            html += '<td class="tbl-td mono dim">' + (i + 1) + '</td>';
            html += '<td class="tbl-td mono">' + p.id + '</td>';
            html += '<td class="tbl-td">' + esc(p.name) + '</td>';
            html += '<td class="tbl-td mono dim">' + esc(p.sku || '-') + '</td>';
            html += '<td class="tbl-td mono">' + p.old_order + '</td>';
            html += '<td class="tbl-td mono" style="color:' + (changed ? 'var(--acc)' : 'var(--dim)') + ';">' + p.new_order + (changed ? ' &#8592;' : '') + '</td>';
            html += '</tr>';
        });

        if (data.total > data.preview.length) {
            html += '<tr><td colspan="6" style="text-align:center;padding:8px;color:var(--dim);font-size:11px;">...e altri ' + (data.total - data.preview.length) + ' prodotti</td></tr>';
        }

        html += '</table>';
        area.innerHTML = html;
    }

    // ── HELPERS ──────────────────────────────────────────────────

    function esc(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function opLabel(op) {
        return {
            'is': 'uguale a', 'is_not': 'diverso da',
            'in': 'uno di', 'not_in': 'nessuno di',
            'contains': 'contiene', 'not_contains': 'non contiene',
            'starts_with': 'inizia con', 'matches': 'regex',
            'gt': 'maggiore di', 'lt': 'minore di', 'between': 'tra',
            'after': 'dopo', 'before': 'prima',
            'exists': 'presente', 'not_exists': 'assente',
            'has_value': 'ha valore', 'not_has_value': 'non ha valore',
            'has_attribute': 'ha attributo', 'not_has_attribute': 'non ha attributo',
        }[op] || op;
    }

    // Preload filter meta when Filtra tab is first opened
    const origSwitch = GH.switchTab;
    GH.switchTab = function(tab, el) {
        origSwitch(tab, el);
        if (tab === 'filter' || tab === 'sorting') loadFilterMeta();
    };

})();
