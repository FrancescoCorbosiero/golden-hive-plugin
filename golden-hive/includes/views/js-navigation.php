// ═══ TAXONOMY QUERY + NAVIGATION MANAGER ══════════════════════════════════
//
// Due moduli strettamente imparentati:
//
//   GH.tq*  → Tax Query tab: lista filtrabile con selezione (feeds navigation)
//   GH.nav* → Navigation tab: read/write dei menu WP
//
// Il link tra i due: GH.tqSendToNav() passa gli ID selezionati allo state del
// populator, cambia tab e pre-compila il campo ancestor. L'utente poi decide
// se fare Preview/Populate.

(function() {

    let tqRows = [];      // current query result
    let tqSelected = new Set();

    let navMenus = [];
    let navItems = [];
    let navPreviewTerms = []; // [{ id, name, count, path }]
    let navSelectedTaxonomy = 'product_cat';

    function esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }

    // ────────────────────────────────────────────────────────────
    // TAXONOMY QUERY
    // ────────────────────────────────────────────────────────────

    function tqReadArgs() {
        const v = id => document.getElementById(id)?.value ?? '';
        return {
            taxonomy:  v('tq-taxonomy'),
            search:    v('tq-search'),
            parent:    v('tq-parent'),
            depth_min: v('tq-depth-min'),
            depth_max: v('tq-depth-max'),
            min_count: v('tq-min-count'),
            max_count: v('tq-max-count'),
            orderby:   v('tq-orderby'),
            order:     v('tq-order'),
            limit:     v('tq-limit') || 50,
        };
    }

    async function tqRun() {
        const btn = document.querySelector('#panel-tax-query .btn-primary');
        const sp  = document.getElementById('tq-spin');
        btn.disabled = true; sp.style.display = '';
        try {
            const args = tqReadArgs();
            const r = await GH.ajax('gh_ajax_taxonomy_query', args);
            if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
            tqRows = r.data.items || [];
            tqSelected = new Set();
            tqRender(r.data.total || 0);
        } catch (e) {
            GH.toast('Errore query', 'err');
        } finally {
            btn.disabled = false; sp.style.display = 'none';
        }
    }

    function tqRender(total) {
        const area = document.getElementById('tq-results');
        const stats = document.getElementById('tq-stats');
        if (!tqRows.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-text">Nessun risultato</div></div>';
            stats.style.display = 'none';
            return;
        }
        document.getElementById('tq-total').textContent = total;
        stats.style.display = 'flex';

        let h = '<table class="ptable" style="width:100%"><thead><tr>';
        h += '<th style="width:30px"></th><th>ID</th><th>Nome</th><th>Path</th><th>Profondita</th><th>Prodotti</th>';
        h += '</tr></thead><tbody>';
        for (const r of tqRows) {
            const sel = tqSelected.has(r.id) ? ' checked' : '';
            h += '<tr><td><input type="checkbox" onchange="GH.tqToggle(' + r.id + ')"' + sel + ' /></td>';
            h += '<td style="font-family:var(--mono);color:var(--dim)">#' + r.id + '</td>';
            h += '<td>' + esc(r.name) + '</td>';
            h += '<td style="font-family:var(--mono);color:var(--dim);font-size:10px">' + esc(r.path) + '</td>';
            h += '<td style="font-family:var(--mono)">' + r.depth + '</td>';
            h += '<td style="font-family:var(--mono)" class="' + (r.count > 0 ? 'green' : 'dim') + '">' + r.count + '</td></tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
        tqUpdateSelStat();
    }

    function tqToggle(id) {
        id = parseInt(id, 10);
        if (tqSelected.has(id)) tqSelected.delete(id); else tqSelected.add(id);
        tqUpdateSelStat();
    }
    function tqSelectAll() {
        tqRows.forEach(r => tqSelected.add(r.id));
        tqRender(tqRows.length);
    }
    function tqSelectNone() {
        tqSelected.clear();
        tqRender(tqRows.length);
    }
    function tqUpdateSelStat() {
        const n = tqSelected.size;
        const stat = document.getElementById('tq-sel-stat');
        if (!stat) return;
        stat.style.display = n > 0 ? '' : 'none';
        document.getElementById('tq-sel-n').textContent = n;
    }

    function tqPreset(name) {
        if (name === 'top15') {
            document.getElementById('tq-orderby').value = 'count';
            document.getElementById('tq-order').value   = 'desc';
            document.getElementById('tq-limit').value   = '15';
            document.getElementById('tq-min-count').value = '1';
            tqRun();
        }
    }

    function tqSendToNav() {
        if (!tqSelected.size) { GH.toast('Seleziona almeno un termine', 'err'); return; }
        const tax = document.getElementById('tq-taxonomy').value;
        // Build a preview-ready list from the current rows
        navPreviewTerms = tqRows.filter(r => tqSelected.has(r.id));
        navSelectedTaxonomy = tax;
        // Switch tab
        const navTab = document.querySelector('#gh .tab-item[data-gh-tab="navigation"]');
        if (navTab) navTab.click();
        // Set taxonomy selector in nav panel
        const navTaxSel = document.getElementById('nav-taxonomy');
        if (navTaxSel) navTaxSel.value = tax;
        renderNavPreview();
        document.getElementById('btn-nav-populate').disabled = !(navMenus.length && navPreviewTerms.length);
        GH.toast(navPreviewTerms.length + ' termini pronti per populate', 'ok');
    }

    // ────────────────────────────────────────────────────────────
    // NAVIGATION MANAGER
    // ────────────────────────────────────────────────────────────

    async function navLoadMenus() {
        const sp = document.getElementById('nav-spin');
        sp.style.display = '';
        try {
            const r = await GH.ajax('gh_ajax_nav_menus');
            if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
            navMenus = r.data || [];
            const sel = document.getElementById('nav-menu');
            if (!navMenus.length) {
                sel.innerHTML = '<option value="0">(nessun menu trovato)</option>';
                document.getElementById('nav-items-area').innerHTML = '<div class="empty-state"><div class="empty-text">Nessun menu WP registrato. Creane uno da Aspetto &rarr; Menu.</div></div>';
                return;
            }
            sel.innerHTML = navMenus.map(m => '<option value="' + m.id + '">' + esc(m.name) + ' (' + m.count + ')</option>').join('');
            await navLoadItems();
        } finally { sp.style.display = 'none'; }
    }

    async function navLoadItems() {
        const mid = parseInt(document.getElementById('nav-menu').value || '0', 10);
        if (!mid) return;
        const r = await GH.ajax('gh_ajax_nav_items', { menu_id: mid });
        if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
        navItems = r.data || [];
        // Populate parent selector (top-level items + "(root)")
        const pSel = document.getElementById('nav-parent');
        let opt = '<option value="0">(root del menu)</option>';
        // Show all items as possible parents, indented by depth for clarity
        const byId = {}; navItems.forEach(i => byId[i.id] = i);
        const depthOf = (item) => { let d = 0, p = item.parent; while (p && byId[p]) { d++; p = byId[p].parent; } return d; };
        const sorted = [...navItems].sort((a, b) => a.order - b.order);
        sorted.forEach(i => {
            const d = depthOf(i);
            opt += '<option value="' + i.id + '">' + ('\u00a0'.repeat(d * 3)) + (d ? '\u21b3 ' : '') + esc(i.title) + '</option>';
        });
        pSel.innerHTML = opt;
        renderNavItems();
    }

    function renderNavItems() {
        const area = document.getElementById('nav-items-area');
        if (!navItems.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-text">Menu vuoto</div></div>';
            return;
        }
        const byId = {}; navItems.forEach(i => byId[i.id] = i);
        const depthOf = (it) => { let d = 0, p = it.parent; while (p && byId[p]) { d++; p = byId[p].parent; } return d; };
        const sorted = [...navItems].sort((a, b) => a.order - b.order);
        let h = '<table class="ptable" style="width:100%"><thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Object</th><th>Managed</th><th></th></tr></thead><tbody>';
        for (const it of sorted) {
            const d = depthOf(it);
            const indent = d ? ('\u00a0'.repeat(d * 3) + '\u21b3 ') : '';
            h += '<tr><td style="font-family:var(--mono);color:var(--dim)">#' + it.id + '</td>';
            h += '<td>' + indent + esc(it.title) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px;color:var(--dim)">' + esc(it.type) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px;color:var(--dim)">' + esc(it.object) + (it.object_id ? ' #' + it.object_id : '') + '</td>';
            h += '<td>' + (it.managed ? '<span class="green" style="font-family:var(--mono);font-size:10px">GH</span>' : '') + '</td>';
            h += '<td><button class="tax-btn del" onclick="GH.navDeleteItem(' + it.id + ',\'' + esc(it.title).replace(/'/g, "\\'") + '\')">elimina</button></td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    async function navDeleteItem(id, title) {
        if (!confirm('Eliminare "' + title + '" dal menu?')) return;
        const r = await GH.ajax('gh_ajax_nav_delete_item', { item_id: id });
        if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
        GH.toast('Item eliminato', 'ok');
        navLoadItems();
    }

    async function navPreview() {
        const btn = document.getElementById('btn-nav-populate');
        const sp  = document.getElementById('nav-prev-spin');
        sp.style.display = '';
        try {
            const args = {
                taxonomy: document.getElementById('nav-taxonomy').value,
                orderby:  document.getElementById('nav-orderby').value,
                order:    document.getElementById('nav-order').value,
                limit:    document.getElementById('nav-limit').value || 15,
            };
            const anc = document.getElementById('nav-ancestor').value;
            const mc  = document.getElementById('nav-min-count').value;
            if (anc) args.ancestor  = anc;
            if (mc)  args.min_count = mc;

            const r = await GH.ajax('gh_ajax_taxonomy_query', args);
            if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
            navPreviewTerms = r.data.items || [];
            navSelectedTaxonomy = args.taxonomy;
            renderNavPreview();
            btn.disabled = !navPreviewTerms.length;
        } finally { sp.style.display = 'none'; }
    }

    function renderNavPreview() {
        const area = document.getElementById('nav-preview-area');
        if (!navPreviewTerms.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-text">Nessun termine — allarga i criteri</div></div>';
            return;
        }
        let h = '<div style="font-family:var(--mono);font-size:10px;color:var(--dim);margin-bottom:8px">' + navPreviewTerms.length + ' termini in ' + esc(navSelectedTaxonomy) + '</div>';
        h += '<table class="ptable" style="width:100%"><thead><tr><th>ID</th><th>Nome</th><th>Path</th><th>Prodotti</th></tr></thead><tbody>';
        for (const t of navPreviewTerms) {
            h += '<tr><td style="font-family:var(--mono);color:var(--dim)">#' + t.id + '</td>';
            h += '<td>' + esc(t.name) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px;color:var(--dim)">' + esc(t.path || '') + '</td>';
            h += '<td style="font-family:var(--mono)" class="green">' + (t.count ?? 0) + '</td></tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    async function navPopulate() {
        if (!navPreviewTerms.length) { GH.toast('Esegui prima un Preview', 'err'); return; }
        const menuId = parseInt(document.getElementById('nav-menu').value || '0', 10);
        const parentId = parseInt(document.getElementById('nav-parent').value || '0', 10);
        if (!menuId) { GH.toast('Seleziona un menu', 'err'); return; }

        const replace = document.getElementById('nav-replace-managed').checked ? 1 : 0;
        const targetLabel = document.querySelector('#nav-parent option[value="' + parentId + '"]')?.textContent?.trim() || '(root)';
        const msg = 'Aggiungere ' + navPreviewTerms.length + ' termini sotto "' + targetLabel + '"?' +
                    (replace ? '\n\nI figli GH esistenti verranno prima rimossi (gli item manuali restano).' : '');
        if (!confirm(msg)) return;

        const sp = document.getElementById('nav-pop-spin');
        sp.style.display = '';
        try {
            const r = await GH.ajax('gh_ajax_nav_populate', {
                menu_id:         menuId,
                parent_item_id:  parentId,
                taxonomy:        navSelectedTaxonomy,
                replace_managed: replace,
                term_ids:        JSON.stringify(navPreviewTerms.map(t => t.id)),
            });
            if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
            const d = r.data;
            GH.toast((d.created?.length || 0) + ' item creati, ' + (d.removed || 0) + ' rimossi' + (d.skipped ? ' (' + d.skipped + ' skip)' : ''), 'ok', 4000);
            if (d.errors?.length) {
                console.warn('[gh-nav]', d.errors);
            }
            navLoadItems();
        } finally { sp.style.display = 'none'; }
    }

    async function navClearManaged() {
        const menuId = parseInt(document.getElementById('nav-menu').value || '0', 10);
        const parentId = parseInt(document.getElementById('nav-parent').value || '0', 10);
        if (!menuId) { GH.toast('Seleziona un menu', 'err'); return; }
        const targetLabel = document.querySelector('#nav-parent option[value="' + parentId + '"]')?.textContent?.trim() || '(root)';
        if (!confirm('Eliminare tutti i figli GH-managed di "' + targetLabel + '"?\n(gli item manuali restano intoccati)')) return;
        const r = await GH.ajax('gh_ajax_nav_clear_managed', { menu_id: menuId, parent_item_id: parentId });
        if (!r.success) { GH.toast('Errore: ' + (r.data || ''), 'err'); return; }
        GH.toast((r.data.removed || 0) + ' item rimossi', 'ok');
        navLoadItems();
    }

    // ── Extend public API ───────────────────────────────────────
    Object.assign(GH, {
        tqRun, tqToggle, tqSelectAll, tqSelectNone, tqPreset, tqSendToNav,
        navLoadMenus, navLoadItems, navDeleteItem, navPreview, navPopulate, navClearManaged,
    });
})();
