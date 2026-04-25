// ═══ KICKSDB ═══════════════════════════════════════════════════════════════

(function(){

    const ajax  = GH.ajax;
    const toast = GH.toast;
    const esc   = (window.GH && GH.esc) || function(s){return String(s==null?'':s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));};
    const conf  = GH.confirm;

    let lastDiff = null;          // ultimo diff lookup, usato da Apply
    let currentSubtab = 'discover';

    // Discover state
    let dItems    = [];           // ultimo set di risultati
    let dSelected = new Set();    // SKU selezionati
    let dPage     = 1;
    let dLastQuery = null;        // snapshot dei params per paginazione

    // ── Sub-tab switching ───────────────────────────────────────

    function kdbSubtab(name, btnEl) {
        currentSubtab = name;
        document.querySelectorAll('#panel-kicksdb [data-kdb-section]').forEach(el => {
            el.style.display = el.dataset.kdbSection === name ? '' : 'none';
        });
        document.querySelectorAll('#panel-kicksdb [data-kdb-subtab]').forEach(el => {
            el.classList.toggle('is-active', el.dataset.kdbSubtab === name);
        });
        if (btnEl) {
            btnEl.classList.add('is-active');
        }
        if (name === 'settings') kdbLoadSettings();
        if (name === 'rules')    kdbRulesReload();
        if (name === 'mapping')  kdbMappingReload();
        if (name === 'provenance') kdbMigrateStatus();
    }

    function kdbInit() {
        // Lazy: la prima entrata carica le settings (per popolare Test connection)
        kdbLoadSettings();
    }

    // ── Discover ───────────────────────────────────────────────

    async function kdbDiscoverSearch(page) {
        const $ = id => document.getElementById(id);
        const q = {
            query: $('kdb-d-query').value.trim(),
            brand: $('kdb-d-brand').value.trim(),
            sort:  $('kdb-d-sort').value,
            order: $('kdb-d-order').value,
            limit: parseInt($('kdb-d-limit').value, 10) || 50,
            page:  Math.max(1, parseInt(page, 10) || 1),
        };
        if (!q.query && !q.brand) {
            toast('Inserisci una query o un brand', 'warn');
            return;
        }
        dLastQuery = q;
        dPage = q.page;

        const spin = $('kdb-d-spin');
        spin.style.display = '';
        try {
            const r = await ajax('gh_kicksdb_search', q);
            if (!r.success) {
                toast('Search fallita: ' + (r.data && r.data.error ? r.data.error : ''), 'err', 6000);
                return;
            }
            dItems = r.data.items || [];
            // Seleziona default "nuovi" della pagina precedente se esistono ancora?
            // No: ogni pagina reset della selezione per evitare selezioni "invisibili"
            // che l'utente non vede.
            dSelected = new Set(
                [...dSelected].filter(sku => dItems.some(i => i.sku === sku))
            );
            renderDiscoverStats(r.data);
            renderDiscoverGrid();
            renderDiscoverPager();
            updateDiscoverSelBar();
        } finally {
            spin.style.display = 'none';
        }
    }

    function renderDiscoverStats(data) {
        const $ = id => document.getElementById(id);
        $('kdb-d-stats').style.display = '';
        $('kdb-d-count').textContent    = data.count || 0;
        $('kdb-d-page').textContent     = dPage;
        $('kdb-d-duration').textContent = data.duration_ms || 0;
        $('kdb-d-selcount').textContent = dSelected.size;
    }

    function renderDiscoverGrid() {
        const wrap = document.getElementById('kdb-d-results');
        if (!dItems.length) {
            wrap.innerHTML = GH.emptyState('&#128269;', 'Nessun risultato per questa ricerca.');
            return;
        }

        const cards = dItems.map(item => {
            const stateBadge = renderDiscoverStateBadge(item.state);
            const checked = dSelected.has(item.sku) ? 'checked' : '';
            const img = item.image
                ? `<img src="${esc(item.image)}" loading="lazy" />`
                : `<div class="kdb-d-card-noimg">&#128247;</div>`;
            const safeSku = esc(item.sku);
            return `
                <div class="kdb-d-card ${dSelected.has(item.sku) ? 'is-selected' : ''}"
                     data-sku="${safeSku}"
                     data-state="${esc(item.state)}"
                     onclick="GH.kdbDiscoverToggle('${safeSku.replace(/'/g, "\\'")}')">
                    <div class="kdb-d-card-img">${img}
                        <input type="checkbox" class="kdb-d-card-check" ${checked}
                               onclick="event.stopPropagation();GH.kdbDiscoverToggle('${safeSku.replace(/'/g, "\\'")}')" />
                        <div class="kdb-d-card-badge">${stateBadge}</div>
                    </div>
                    <div class="kdb-d-card-body">
                        <div class="kdb-d-card-title">${esc(item.title)}</div>
                        <div class="kdb-d-card-meta">
                            <span class="kdb-d-card-brand">${esc(item.brand || '')}</span>
                            ${item.colorway ? ` · <span class="dim">${esc(item.colorway)}</span>` : ''}
                        </div>
                        <div class="kdb-d-card-sku">${safeSku}${item.release ? ' · <span class="dim">' + esc(item.release) + '</span>' : ''}</div>
                    </div>
                </div>`;
        }).join('');

        wrap.innerHTML = `<div class="kdb-d-grid">${cards}</div>`;
    }

    function renderDiscoverStateBadge(state) {
        const map = {
            new:         { label: 'Nuovo',       cls: 'ok'   },
            in_catalog:  { label: 'In catalogo', cls: 'info' },
            tracked:     { label: 'Tracked',     cls: 'warn' },
        };
        const m = map[state] || { label: state, cls: 'dim' };
        return `<span class="gh-status gh-status--${m.cls}">${esc(m.label)}</span>`;
    }

    function renderDiscoverPager() {
        const pager = document.getElementById('kdb-d-pager');
        if (!dItems.length) { pager.style.display = 'none'; return; }

        // L'endpoint non ritorna total/has_next. Usiamo euristica: se la pagina
        // e piena (==limit), assumiamo che ci sia una prossima. Prev abilitato
        // se page > 1.
        const limit = (dLastQuery && dLastQuery.limit) || 50;
        const hasNext = dItems.length >= limit;

        pager.style.display = '';
        document.getElementById('kdb-d-pageinfo').textContent = dPage;
        document.getElementById('kdb-d-prev').disabled = dPage <= 1;
        document.getElementById('kdb-d-next').disabled = !hasNext;
    }

    function kdbDiscoverPage(delta) {
        const next = dPage + delta;
        if (next < 1) return;
        kdbDiscoverSearch(next);
    }

    function kdbDiscoverToggle(sku) {
        if (!sku) return;
        if (dSelected.has(sku)) dSelected.delete(sku);
        else                    dSelected.add(sku);
        // Aggiorna UI del card senza re-render completo
        const card = document.querySelector(`#kdb-d-results .kdb-d-card[data-sku="${CSS.escape(sku)}"]`);
        if (card) {
            card.classList.toggle('is-selected', dSelected.has(sku));
            const cb = card.querySelector('input.kdb-d-card-check');
            if (cb) cb.checked = dSelected.has(sku);
        }
        updateDiscoverSelBar();
    }

    function kdbDiscoverSelectAll() {
        // Solo "new" di default — evita di selezionare prodotti gia in catalogo
        dItems.forEach(i => { if (i.state === 'new') dSelected.add(i.sku); });
        renderDiscoverGrid();
        updateDiscoverSelBar();
    }

    function kdbDiscoverSelectNone() {
        dSelected.clear();
        renderDiscoverGrid();
        updateDiscoverSelBar();
    }

    function updateDiscoverSelBar() {
        const bar = document.getElementById('kdb-d-selbar');
        const count = dSelected.size;
        bar.style.display = count > 0 ? '' : 'none';
        const el = document.getElementById('kdb-d-selcount');
        if (el) el.textContent = count;
    }

    async function kdbDiscoverImport() {
        if (!dSelected.size) { toast('Nessun prodotto selezionato', 'warn'); return; }

        const includeExisting = document.getElementById('kdb-d-include-existing').checked;

        // Split by state: safe-to-create vs already-in-catalog
        const selected = [...dSelected];
        const bySku = Object.fromEntries(dItems.map(i => [i.sku, i]));
        const newOnes   = selected.filter(s => (bySku[s]?.state || 'new') === 'new');
        const existing  = selected.filter(s => (bySku[s]?.state || 'new') !== 'new');

        const skusToImport = includeExisting ? selected : newOnes;
        if (!skusToImport.length) {
            toast('Selezione contiene solo prodotti esistenti. Abilita "Includi anche prodotti gia in catalogo" per procedere.', 'warn', 6000);
            return;
        }

        const msg = includeExisting
            ? `Importare ${skusToImport.length} prodotti? (${newOnes.length} nuovi, ${existing.length} aggiornamenti — rispettano le conflict rules)`
            : `Creare ${newOnes.length} nuovi prodotti? I ${existing.length} gia in catalogo verranno skippati (abilita checkbox per includerli).`;

        const ok = await conf(msg, { title: 'Importa da Discover', okLabel: 'Importa' });
        if (!ok) return;

        const spin = document.getElementById('kdb-d-import-spin');
        spin.style.display = '';
        try {
            const r = await ajax('gh_kicksdb_apply', {
                skus:            JSON.stringify(skusToImport),
                create_new:      '1',
                update_existing: includeExisting ? '1' : '0',
            });
            if (!r.success) { toast('Import fallito', 'err'); return; }
            const s = r.data.summary || {};
            toast(`Created ${s.created||0} · Updated ${s.updated||0} · Skipped ${s.skipped||0} · Errors ${s.errors||0}`, s.errors > 0 ? 'warn' : 'ok', 6000);
            // Re-search per mostrare il nuovo stato (quelli appena importati diventano 'tracked')
            dSelected.clear();
            await kdbDiscoverSearch(dPage);
        } finally {
            spin.style.display = 'none';
        }
    }

    // ── Settings ────────────────────────────────────────────────

    async function kdbLoadSettings() {
        const r = await ajax('gh_kicksdb_settings_get');
        if (!r.success) { toast('Errore caricamento settings', 'err'); return; }
        const s = r.data.settings || {};
        const $ = id => document.getElementById(id);
        if (!$('kdb-s-api-key')) return; // panel non ancora renderizzato
        $('kdb-s-api-key').placeholder = s.api_key || '(vuota)';
        $('kdb-s-api-key').value = '';
        $('kdb-s-base-url').value = s.base_url || '';
        $('kdb-s-market').value   = s.market || 'IT';
        $('kdb-s-concurrency').value = s.concurrency || 8;
        $('kdb-s-cache-ttl').value   = s.cache_ttl || 86400;
        const p = s.pricing || {};
        $('kdb-s-margin').value     = p.margin_pct ?? 20;
        $('kdb-s-floor').value      = p.floor_price ?? 0;
        $('kdb-s-round-mode').value = p.rounding_mode || 'ceil';
        $('kdb-s-round-step').value = p.rounding_step ?? 1;
        $('kdb-s-currency').value   = p.currency || 'EUR';
    }

    async function kdbSaveSettings() {
        const $ = id => document.getElementById(id);
        const apiKey = $('kdb-s-api-key').value;
        const payload = {
            base_url:    $('kdb-s-base-url').value,
            market:      $('kdb-s-market').value,
            concurrency: parseInt($('kdb-s-concurrency').value, 10) || 8,
            cache_ttl:   parseInt($('kdb-s-cache-ttl').value, 10) || 86400,
            pricing: {
                margin_pct:    parseFloat($('kdb-s-margin').value) || 0,
                floor_price:   parseFloat($('kdb-s-floor').value) || 0,
                rounding_mode: $('kdb-s-round-mode').value,
                rounding_step: parseFloat($('kdb-s-round-step').value) || 1,
                currency:      $('kdb-s-currency').value,
            },
        };
        if (apiKey && !/^•+/.test(apiKey)) payload.api_key = apiKey;

        const r = await GH.ajaxWithToast('gh_kicksdb_settings_save', {
            settings: JSON.stringify(payload),
        }, { okMsg: 'Settings salvate' });
        if (r.success) {
            await kdbLoadSettings();
        }
    }

    async function kdbTestConnection() {
        const spin = document.getElementById('kdb-test-spin');
        const out  = document.getElementById('kdb-s-test-result');
        spin.style.display = '';
        out.textContent = '';
        out.style.color = 'var(--dim)';
        try {
            const r = await ajax('gh_kicksdb_test_connection');
            if (r.success && r.data.ok) {
                out.style.color = 'var(--grn)';
                out.textContent = 'OK · HTTP ' + r.data.status + ' · ' + r.data.duration_ms + ' ms · ' + r.data.attempts + ' attempt(s)';
            } else {
                out.style.color = 'var(--red)';
                out.textContent = 'Fail · ' + (r.data && r.data.error ? r.data.error : 'errore sconosciuto');
            }
        } catch (e) {
            out.style.color = 'var(--red)';
            out.textContent = 'Errore: ' + e.message;
        } finally {
            spin.style.display = 'none';
        }
    }

    // ── Lookup ──────────────────────────────────────────────────

    async function kdbLookup() {
        const skus = document.getElementById('kdb-lookup-skus').value.trim();
        if (!skus) { toast('Incolla almeno uno SKU', 'warn'); return; }

        const force = document.getElementById('kdb-lookup-force').checked;
        const spin  = document.getElementById('kdb-lookup-spin');
        spin.style.display = '';

        try {
            const r = await ajax('gh_kicksdb_lookup', { skus, force: force ? '1' : '0' });
            if (!r.success) { toast('Lookup fallito: ' + (r.data || ''), 'err'); return; }
            lastDiff = r.data.diff;
            renderLookupStats(r.data.stats, r.data.diff.summary);
            renderLookupPreview(r.data.diff, r.data.errors || {});
            document.getElementById('btn-kdb-lookup-apply').style.display = '';
        } finally {
            spin.style.display = 'none';
        }
    }

    function renderLookupStats(stats, summary) {
        document.getElementById('kdb-lookup-stats').style.display = '';
        const $ = id => document.getElementById(id);
        $('kdb-stat-total').textContent     = stats.total || 0;
        $('kdb-stat-cached').textContent    = stats.cached || 0;
        $('kdb-stat-fetched').textContent   = stats.fetched || 0;
        $('kdb-stat-failed').textContent    = stats.failed || 0;
        $('kdb-stat-new').textContent       = summary.new || 0;
        $('kdb-stat-update').textContent    = summary.update || 0;
        $('kdb-stat-unchanged').textContent = summary.unchanged || 0;
        $('kdb-stat-duration').textContent  = stats.duration_ms || 0;
    }

    function renderLookupPreview(diff, errors) {
        const wrap = document.getElementById('kdb-lookup-preview');
        const sections = [];

        function rowsHtml(items, badge, badgeClass) {
            return items.map(p => {
                const img = p.image ? `<img src="${esc(p.image)}" style="width:40px;height:40px;object-fit:cover;border-radius:4px" />` : '';
                return `<tr>
                    <td>${img}</td>
                    <td><b>${esc(p.sku)}</b></td>
                    <td>${esc(p.name)}</td>
                    <td>${esc(p.brand || '')}</td>
                    <td>${esc(p.colorway || '')}</td>
                    <td>${p.variant_count || 0}</td>
                    <td><span class="gh-status gh-status--${badgeClass}">${badge}</span></td>
                </tr>`;
            }).join('');
        }

        if (diff.new.length) sections.push(`<h4 style="margin:8px 0;color:var(--grn)">Nuovi (${diff.new.length})</h4>
            <table class="data-table"><thead><tr><th></th><th>SKU</th><th>Name</th><th>Brand</th><th>Colorway</th><th>Var.</th><th></th></tr></thead><tbody>${rowsHtml(diff.new, 'new', 'ok')}</tbody></table>`);
        if (diff.update.length) sections.push(`<h4 style="margin:8px 0;color:var(--amb)">Aggiornare (${diff.update.length})</h4>
            <table class="data-table"><thead><tr><th></th><th>SKU</th><th>Name</th><th>Brand</th><th>Colorway</th><th>Var.</th><th></th></tr></thead><tbody>${rowsHtml(diff.update, 'update', 'warn')}</tbody></table>`);
        if (diff.unchanged.length) sections.push(`<h4 style="margin:8px 0;color:var(--dim)">Invariati (${diff.unchanged.length})</h4>
            <table class="data-table"><thead><tr><th></th><th>SKU</th><th>Name</th><th>Brand</th><th>Colorway</th><th>Var.</th><th></th></tr></thead><tbody>${rowsHtml(diff.unchanged, 'unchanged', 'dim')}</tbody></table>`);

        const errKeys = Object.keys(errors || {});
        if (errKeys.length) sections.push(`<h4 style="margin:8px 0;color:var(--red)">Errori (${errKeys.length})</h4>
            <ul style="font-family:var(--mono);font-size:11px;color:var(--red);padding-left:18px">
                ${errKeys.map(k => `<li>${esc(k)} — ${esc(errors[k])}</li>`).join('')}
            </ul>`);

        wrap.innerHTML = sections.length ? sections.join('') : GH.emptyState('&#10071;', 'Nessun risultato');
    }

    async function kdbLookupApply() {
        if (!lastDiff) { toast('Esegui prima un lookup', 'warn'); return; }
        const skus = document.getElementById('kdb-lookup-skus').value.trim();
        const create = document.getElementById('kdb-lookup-create').checked;
        const update = document.getElementById('kdb-lookup-update').checked;

        const totalAffected = (create ? lastDiff.summary.new : 0) + (update ? lastDiff.summary.update : 0);
        if (totalAffected === 0) { toast('Niente da applicare con queste opzioni', 'warn'); return; }

        const ok = await conf(`Confermi l'applicazione di ${totalAffected} prodotti? (${lastDiff.summary.new} nuovi, ${lastDiff.summary.update} aggiornamenti)`, {
            title: 'Applica KicksDB', okLabel: 'Applica', danger: false
        });
        if (!ok) return;

        const spin = document.getElementById('kdb-apply-spin');
        spin.style.display = '';
        try {
            const r = await ajax('gh_kicksdb_apply', {
                skus,
                create_new:      create ? '1' : '0',
                update_existing: update ? '1' : '0',
            });
            if (!r.success) { toast('Apply fallito', 'err'); return; }
            const s = r.data.summary || {};
            toast(`Created ${s.created||0} · Updated ${s.updated||0} · Skipped ${s.skipped||0} · Errors ${s.errors||0}`, s.errors > 0 ? 'warn' : 'ok', 6000);
            // Re-run lookup per mostrare lo stato post-apply
            await kdbLookup();
        } finally {
            spin.style.display = 'none';
        }
    }

    // ── Refresh pricing ─────────────────────────────────────────

    async function kdbRefreshPricing(skuOverride) {
        const skus = skuOverride || document.getElementById('kdb-refresh-skus').value.trim();
        if (!skus) { toast('Incolla almeno uno SKU', 'warn'); return; }

        const spin = document.getElementById('kdb-refresh-spin');
        if (spin) spin.style.display = '';
        try {
            const r = await ajax('gh_kicksdb_refresh_pricing', { skus });
            if (!r.success) { toast('Refresh fallito: ' + (r.data || ''), 'err'); return null; }
            const s = r.data.summary || {};
            toast(`Updated ${s.updated||0} · Skipped ${s.skipped||0} · Errors ${s.errors||0} · Chunks ${s.chunks||0}`, s.errors > 0 ? 'warn' : 'ok', 6000);
            const wrap = document.getElementById('kdb-refresh-preview');
            if (wrap) {
                const rows = (r.data.details || []).map(d => `
                    <tr>
                        <td><b>${esc(d.sku || '')}</b></td>
                        <td>${esc(d.action)}</td>
                        <td>${esc(d.reason || '')}</td>
                        <td>${(d.sizes || []).join(', ')}</td>
                    </tr>`).join('');
                wrap.innerHTML = `<table class="data-table"><thead><tr><th>SKU</th><th>Action</th><th>Reason</th><th>Sizes</th></tr></thead><tbody>${rows}</tbody></table>`;
            }
            return r.data;
        } finally {
            if (spin) spin.style.display = 'none';
        }
    }

    /**
     * Cross-tab handoff: chiama questo da qualsiasi tab (Filter risultati,
     * GS results, SF results, Inline editor) per refresare pricing su un set
     * di SKU. Switcha al tab KicksDB → sub Refresh → popola textarea → run.
     */
    async function kdbRefreshSelected(skus) {
        if (!skus || !skus.length) { toast('Nessuno SKU passato', 'warn'); return; }
        GH.switchTab('kicksdb', document.querySelector('[data-kdb-tab="lookup"]'));
        kdbSubtab('refresh');
        document.getElementById('kdb-refresh-skus').value = skus.join('\n');
        return kdbRefreshPricing();
    }

    // ── Provenance ──────────────────────────────────────────────

    async function kdbMigrateStatus() {
        const r = await ajax('gh_conflict_migrate_status');
        if (!r.success) return;
        const d = r.data;
        const pct = d.total > 0 ? Math.round((d.cursor / d.total) * 100) : 0;
        const status = d.complete ? 'COMPLETED' : `${d.cursor}/${d.total} (${pct}%)`;
        const el = document.getElementById('kdb-migrate-status');
        if (el) el.textContent = status;
    }

    async function kdbMigrateTick() {
        const spin = document.getElementById('kdb-migrate-spin');
        spin.style.display = '';
        try {
            const r = await ajax('gh_conflict_migrate_tick', { batch: 200 });
            if (!r.success) { toast('Tick fallito', 'err'); return; }
            const d = r.data;
            toast(`Backfilled ${d.backfilled} · Skipped ${d.skipped} · ${d.cursor_after}/${d.total}`, d.done ? 'ok' : 'info');
            await kdbMigrateStatus();
        } finally {
            spin.style.display = 'none';
        }
    }

    async function kdbProvenanceLookup() {
        const pid = parseInt(document.getElementById('kdb-prov-pid').value, 10);
        if (!pid) { toast('product_id richiesto', 'warn'); return; }
        const r = await ajax('gh_conflict_product_provenance', { product_id: pid });
        if (!r.success) { toast('Lookup fallito', 'err'); return; }
        const d = r.data;
        const sources = (d.sources || []).map(s => `<li><b>${esc(s.source)}</b> · first: ${esc(s.first_seen)} · last: ${esc(s.last_seen)}</li>`).join('');
        const slices = Object.entries(d.field_sources || {}).map(([k, v]) => `<tr><td>${esc(k)}</td><td><b>${esc(v)}</b></td></tr>`).join('');
        document.getElementById('kdb-prov-preview').innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div>
                    <h4>Sources (audit log)</h4>
                    <div style="font-size:11px;margin-bottom:8px">Primary: <b>${esc(d.primary_source || '—')}</b></div>
                    <ul style="font-family:var(--mono);font-size:11px;padding-left:18px">${sources || '<li><i>nessuna</i></li>'}</ul>
                </div>
                <div>
                    <h4>Field sources (per slice)</h4>
                    <table class="data-table"><thead><tr><th>Slice</th><th>Owner</th></tr></thead><tbody>${slices || '<tr><td colspan=2><i>vuoto</i></td></tr>'}</tbody></table>
                </div>
            </div>`;
    }

    // ── Conflict Rules (basic CRUD) ─────────────────────────────

    let _rulesCache = [];

    async function kdbRulesReload() {
        const r = await ajax('gh_conflict_rules_list');
        if (!r.success) { toast('Errore caricamento rules', 'err'); return; }
        _rulesCache = r.data.rules || [];
        renderRulesList();
    }

    function renderRulesList() {
        const wrap = document.getElementById('kdb-rules-list');
        if (!_rulesCache.length) { wrap.innerHTML = GH.emptyState('&#9881;', 'Nessuna rule. Clicca "Reset default" per installare le rule sicure di default.'); return; }
        const rows = _rulesCache.map(r => {
            const when = [];
            if (r.when.sources_contains && r.when.sources_contains.length) when.push('contains: ' + r.when.sources_contains.join(','));
            if (r.when.sources_any && r.when.sources_any.length)           when.push('any: ' + r.when.sources_any.join(','));
            if (r.when.incoming && r.when.incoming.length)                  when.push('incoming: ' + r.when.incoming.join(','));
            const slices = ['catalog','pricing','stock','media'].map(s => {
                const v = (r.then && r.then[s]) || 'allow';
                const cls = v === 'block' ? 'gh-status--err' : (v === 'allow' ? 'gh-status--ok' : 'gh-status--info');
                return `<span class="gh-status ${cls}" title="${esc(s)}">${esc(s[0].toUpperCase())}: ${esc(v)}</span>`;
            }).join(' ');
            return `<tr>
                <td>${r.priority}</td>
                <td><b>${esc(r.label)}</b><br/><span style="font-size:10px;color:var(--dim)">${esc(r.id)}</span></td>
                <td><span class="gh-status gh-status--${r.enabled ? 'ok' : 'dim'}">${r.enabled ? 'ON' : 'OFF'}</span></td>
                <td style="font-size:10px;font-family:var(--mono);color:var(--dim)">${esc(when.join(' & ') || '(always)')}</td>
                <td>${slices}</td>
                <td>
                    <button class="btn btn-ghost" onclick="GH.kdbRuleEdit('${esc(r.id)}')">Edit</button>
                    <button class="btn btn-ghost" style="color:var(--red)" onclick="GH.kdbRuleDelete('${esc(r.id)}')">&#10005;</button>
                </td>
            </tr>`;
        }).join('');
        wrap.innerHTML = `<table class="data-table"><thead><tr><th>Pri</th><th>Rule</th><th>State</th><th>When</th><th>Then (slices)</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
    }

    function kdbRuleNew() {
        renderRuleEditor({
            id: '', label: 'New rule', enabled: true, priority: 100,
            when: { sources_contains: [], sources_any: [], incoming: [] },
            then: { catalog: 'allow', pricing: 'allow', stock: 'allow', media: 'allow' },
            stop_on_match: true,
        });
    }

    function kdbRuleEdit(id) {
        const r = _rulesCache.find(x => x.id === id);
        if (r) renderRuleEditor(JSON.parse(JSON.stringify(r)));
    }

    function renderRuleEditor(rule) {
        const ed = document.getElementById('kdb-rule-editor');
        ed.style.display = '';
        document.getElementById('kdb-rules-list').style.display = 'none';

        const sliceOpts = (val) => `<select class="cfg-select" data-then="${0}">
            <option value="allow"          ${val==='allow'?'selected':''}>allow</option>
            <option value="block"          ${val==='block'?'selected':''}>block</option>
            <option value="manual"         ${val==='manual'?'selected':''}>only manual</option>
            <option value="goldensneakers" ${val==='goldensneakers'?'selected':''}>only goldensneakers</option>
            <option value="stockfirmati"   ${val==='stockfirmati'?'selected':''}>only stockfirmati</option>
            <option value="kicksdb"        ${val==='kicksdb'?'selected':''}>only kicksdb</option>
            <option value="csv"            ${val==='csv'?'selected':''}>only csv</option>
        </select>`;

        ed.innerHTML = `
            <div class="config-form" style="border-top:1px solid var(--brd);margin-top:6px">
                <div class="cfg-row"><span class="cfg-label">Label</span>
                    <input class="cfg-input" id="ke-label" value="${esc(rule.label)}" />
                    <span class="cfg-label">Priority</span>
                    <input class="cfg-input" id="ke-priority" type="number" value="${rule.priority}" style="max-width:80px" />
                    <label style="font-family:var(--mono);font-size:10px;display:flex;align-items:center;gap:4px"><input type="checkbox" id="ke-enabled" ${rule.enabled?'checked':''}/> Enabled</label>
                    <label style="font-family:var(--mono);font-size:10px;display:flex;align-items:center;gap:4px"><input type="checkbox" id="ke-stop" ${rule.stop_on_match?'checked':''}/> Stop on match</label>
                </div>
                <div class="cfg-row"><span class="cfg-label">When · contains</span>
                    <input class="cfg-input" id="ke-w-contains" value="${esc((rule.when.sources_contains||[]).join(','))}" placeholder="csv: tutte le source che devono esserci nel prodotto" />
                </div>
                <div class="cfg-row"><span class="cfg-label">When · any</span>
                    <input class="cfg-input" id="ke-w-any" value="${esc((rule.when.sources_any||[]).join(','))}" placeholder="csv: almeno una di queste deve esserci" />
                </div>
                <div class="cfg-row"><span class="cfg-label">When · incoming</span>
                    <input class="cfg-input" id="ke-w-incoming" value="${esc((rule.when.incoming||[]).join(','))}" placeholder="csv: source che sta provando a scrivere (es. 'kicksdb')" />
                </div>
                <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:8px"><span class="cfg-label">Then · catalog</span>${sliceOpts(rule.then.catalog).replace('data-then="0"','id="ke-t-catalog"')}</div>
                <div class="cfg-row"><span class="cfg-label">Then · pricing</span>${sliceOpts(rule.then.pricing).replace('data-then="0"','id="ke-t-pricing"')}</div>
                <div class="cfg-row"><span class="cfg-label">Then · stock</span>${sliceOpts(rule.then.stock).replace('data-then="0"','id="ke-t-stock"')}</div>
                <div class="cfg-row"><span class="cfg-label">Then · media</span>${sliceOpts(rule.then.media).replace('data-then="0"','id="ke-t-media"')}</div>
                <div class="cfg-row"><span class="cfg-label"></span>
                    <div style="flex:1"></div>
                    <button class="btn btn-ghost" onclick="GH.kdbRuleCancel()">Annulla</button>
                    <button class="btn btn-primary" onclick="GH.kdbRuleSave('${esc(rule.id)}')">Salva</button>
                </div>
            </div>`;
    }

    function kdbRuleCancel() {
        document.getElementById('kdb-rule-editor').style.display = 'none';
        document.getElementById('kdb-rules-list').style.display = '';
    }

    async function kdbRuleSave(id) {
        const $ = i => document.getElementById(i);
        const csv = i => $(i).value.split(/[,\s]+/).map(s => s.trim()).filter(Boolean);
        const payload = {
            id,
            label:    $('ke-label').value,
            priority: parseInt($('ke-priority').value, 10) || 100,
            enabled:  $('ke-enabled').checked,
            stop_on_match: $('ke-stop').checked,
            when: {
                sources_contains: csv('ke-w-contains'),
                sources_any:      csv('ke-w-any'),
                incoming:         csv('ke-w-incoming'),
            },
            then: {
                catalog: $('ke-t-catalog').value,
                pricing: $('ke-t-pricing').value,
                stock:   $('ke-t-stock').value,
                media:   $('ke-t-media').value,
            },
        };
        const r = await GH.ajaxWithToast('gh_conflict_rule_save', {
            rule: JSON.stringify(payload),
        }, { okMsg: 'Rule salvata' });
        if (r.success) {
            kdbRuleCancel();
            kdbRulesReload();
        }
    }

    async function kdbRuleDelete(id) {
        const ok = await conf('Eliminare questa rule? La modifica e immediata.', { title: 'Conferma', danger: true });
        if (!ok) return;
        const r = await GH.ajaxWithToast('gh_conflict_rule_delete', { id }, { okMsg: 'Rule eliminata' });
        if (r.success) kdbRulesReload();
    }

    async function kdbRulesReset() {
        const ok = await conf('Reinstallare le rules di default? Le rules attuali verranno SOSTITUITE.', { title: 'Reset rules', danger: true });
        if (!ok) return;
        const r = await GH.ajaxWithToast('gh_conflict_rules_reset', {}, { okMsg: 'Default ripristinati' });
        if (r.success) kdbRulesReload();
    }

    // ── Field Mapping (profiles) ────────────────────────────────

    let _mappingCache = { profiles: [], active_id: '', fields: {} };
    let _mappingEditing = null;   // profile being edited (deep-cloned)
    let _mappingSampleCache = null; // { sku, paths, values } for live preview

    async function kdbMappingReload() {
        const r = await ajax('gh_kicksdb_profiles_list');
        if (!r.success) { toast('Errore caricamento profiles', 'err'); return; }
        _mappingCache = r.data;
        renderMappingList();
    }

    function renderMappingList() {
        const wrap = document.getElementById('kdb-m-list');
        const profiles = _mappingCache.profiles || [];
        if (!profiles.length) {
            wrap.innerHTML = GH.emptyState('&#9881;', 'Nessun profile. Click "+ Nuovo profile" per iniziare.');
            return;
        }
        const rows = profiles.map(p => {
            const req = (p.required_fields || []).join(', ') || '—';
            const tmpl = (p.description_template || '').slice(0, 80).replace(/\n/g, ' ');
            return `<tr>
                <td>${p.active ? '<span class="gh-status gh-status--ok">ACTIVE</span>' : '<button class="btn btn-ghost" onclick="GH.kdbMappingSetActive(\'' + esc(p.id) + '\')">Attiva</button>'}</td>
                <td><b>${esc(p.name)}</b><br/><span style="font-size:10px;color:var(--dim)">${esc(p.id)}</span></td>
                <td style="font-size:10px;font-family:var(--mono);color:var(--dim)">${esc(req)}</td>
                <td style="font-size:10px;font-family:var(--mono);color:var(--dim)">${esc(tmpl || '—')}${tmpl && tmpl.length >= 80 ? '…' : ''}</td>
                <td>
                    <button class="btn btn-ghost" onclick="GH.kdbMappingEdit('${esc(p.id)}')">Edit</button>
                    <button class="btn btn-ghost" style="color:var(--red)" onclick="GH.kdbMappingDelete('${esc(p.id)}')">&#10005;</button>
                </td>
            </tr>`;
        }).join('');
        wrap.innerHTML = `<table class="data-table"><thead><tr><th>State</th><th>Name</th><th>Required</th><th>Description template</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
    }

    function kdbMappingNew() {
        _mappingEditing = {
            id: '', name: 'New profile', active: false,
            required_fields: [],
            description_template: '',
            gallery_opts: { include_main: true, include_360: false, every_nth_360: 6 },
        };
        _mappingSampleCache = null;
        renderMappingEditor();
    }

    function kdbMappingEdit(id) {
        const p = (_mappingCache.profiles || []).find(x => x.id === id);
        if (!p) return;
        _mappingEditing = JSON.parse(JSON.stringify(p));
        _mappingEditing.gallery_opts = _mappingEditing.gallery_opts || { include_main: true, include_360: false, every_nth_360: 6 };
        _mappingEditing.required_fields = _mappingEditing.required_fields || [];
        _mappingSampleCache = null;
        renderMappingEditor();
    }

    function renderMappingEditor() {
        const p = _mappingEditing;
        const fields = _mappingCache.fields || {};
        const ed = document.getElementById('kdb-m-editor');
        ed.style.display = '';
        document.getElementById('kdb-m-list').style.display = 'none';

        const reqBoxes = Object.entries(fields).map(([key, meta]) => {
            const checked = (p.required_fields || []).includes(key) ? 'checked' : '';
            const ph = meta.placeholder ? ' <span style="color:var(--dim);font-family:var(--mono);font-size:10px">' + esc(meta.placeholder) + '</span>' : '';
            return `<label style="display:flex;align-items:center;gap:6px;font-size:11px;padding:2px 0">
                <input type="checkbox" data-req="${esc(key)}" ${checked} />
                <b>${esc(meta.label)}</b>${ph}
                <span style="color:var(--dim);font-family:var(--mono);font-size:10px">${esc(meta.path || '')}</span>
            </label>`;
        }).join('');

        const placeholderHints = Object.entries(fields)
            .filter(([k, m]) => m.placeholder)
            .map(([k, m]) => `<button type="button" class="btn btn-ghost" style="font-size:10px;padding:2px 6px" onclick="GH.kdbMappingInsertPlaceholder('${esc(m.placeholder)}')">${esc(m.placeholder)}</button>`)
            .join(' ');

        ed.innerHTML = `
            <div class="config-form" style="border-top:1px solid var(--brd);margin-top:6px">
                <div class="cfg-row"><span class="cfg-label">Name</span>
                    <input class="cfg-input" id="me-name" value="${esc(p.name)}" />
                    <label style="font-family:var(--mono);font-size:10px;display:flex;align-items:center;gap:4px"><input type="checkbox" id="me-active" ${p.active?'checked':''}/> Set active</label>
                </div>

                <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:10px">
                    <span class="cfg-label" style="color:var(--acc)">Data model</span>
                    <input class="cfg-input" id="me-sample-sku" placeholder="Inserisci uno SKU reale per introspezione" style="flex:1" />
                    <button class="btn btn-ghost" onclick="GH.kdbMappingLoadSample()"><span class="spin" id="me-sample-spin" style="display:none"></span> Carica sample</button>
                </div>
                <div id="me-tree-wrap" style="display:none">
                    <div class="cfg-row" style="padding-top:4px">
                        <span class="cfg-label">Paths</span>
                        <div style="flex:1;font-family:var(--mono);font-size:10px;color:var(--dim)">
                            Field paths (dot-notation) estratti dal sample. Informativo — il binding e hardcoded nel normalizer.
                        </div>
                    </div>
                    <div id="me-tree" style="max-height:260px;overflow:auto;background:var(--bg,#0c0d10);border:1px solid var(--brd);border-radius:4px;padding:8px;font-family:var(--mono);font-size:10px;line-height:1.5"></div>
                </div>

                <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:10px">
                    <span class="cfg-label" style="color:var(--acc)">Required fields</span>
                    <span style="font-size:10px;color:var(--dim)">Se uno di questi e vuoto nella response KicksDB, l'import del prodotto fallisce (WP_Error).</span>
                </div>
                <div class="cfg-row" style="padding-top:0">
                    <div style="flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:4px 16px">${reqBoxes}</div>
                </div>

                <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:10px">
                    <span class="cfg-label" style="color:var(--acc)">Description template</span>
                    <div style="display:flex;gap:4px;flex-wrap:wrap">${placeholderHints}</div>
                </div>
                <div class="cfg-row" style="padding-top:0">
                    <textarea class="cfg-input" id="me-tmpl" rows="4" placeholder="Es. {brand} {model} — colorway {colorway}. {description}">${esc(p.description_template || '')}</textarea>
                </div>
                <div class="cfg-row">
                    <button class="btn btn-ghost" onclick="GH.kdbMappingPreviewTemplate()">Preview (usa sample SKU)</button>
                    <div id="me-preview-out" style="flex:1;font-family:var(--sans);font-size:11px;color:var(--dim);padding:6px 8px;background:var(--bg,#0c0d10);border:1px solid var(--brd);border-radius:4px;white-space:pre-wrap"></div>
                </div>

                <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:10px">
                    <span class="cfg-label" style="color:var(--acc)">Gallery</span>
                    <label style="font-family:var(--mono);font-size:10px;display:flex;align-items:center;gap:4px"><input type="checkbox" id="me-gal-main" ${p.gallery_opts.include_main?'checked':''}/> Main image</label>
                    <label style="font-family:var(--mono);font-size:10px;display:flex;align-items:center;gap:4px"><input type="checkbox" id="me-gal-360" ${p.gallery_opts.include_360?'checked':''}/> 360-frames</label>
                    <span class="cfg-label">Every</span>
                    <input class="cfg-input" id="me-gal-every" type="number" min="1" max="60" value="${p.gallery_opts.every_nth_360 || 6}" style="max-width:70px" />
                </div>

                <div class="cfg-row">
                    <div style="flex:1"></div>
                    <button class="btn btn-ghost" onclick="GH.kdbMappingCancel()">Annulla</button>
                    <button class="btn btn-primary" onclick="GH.kdbMappingSave()">Salva profile</button>
                </div>
            </div>`;
    }

    function kdbMappingCancel() {
        document.getElementById('kdb-m-editor').style.display = 'none';
        document.getElementById('kdb-m-list').style.display = '';
        _mappingEditing = null;
    }

    async function kdbMappingLoadSample() {
        const sku = document.getElementById('me-sample-sku').value.trim();
        if (!sku) { toast('Inserisci uno SKU reale per introspezione', 'warn'); return; }
        const spin = document.getElementById('me-sample-spin');
        spin.style.display = '';
        try {
            const r = await ajax('gh_kicksdb_profile_sample_paths', { sku });
            if (!r.success) {
                toast('Sample fetch fallito: ' + (r.data && r.data.error ? r.data.error : ''), 'err', 6000);
                return;
            }
            _mappingSampleCache = { sku, paths: r.data.paths, values: r.data.sample };
            renderSampleTree(r.data.paths);
        } finally {
            spin.style.display = 'none';
        }
    }

    function renderSampleTree(paths) {
        const wrap = document.getElementById('me-tree-wrap');
        const tree = document.getElementById('me-tree');
        wrap.style.display = '';
        if (!paths || !paths.length) {
            tree.innerHTML = '<i style="color:var(--dim)">Nessun path estratto</i>';
            return;
        }
        tree.innerHTML = paths.map(p => {
            const sample = p.sample === null || p.sample === undefined ? 'null' : String(p.sample).slice(0, 80);
            const type = `<span style="color:var(--dim)">(${esc(p.type)})</span>`;
            return `<div><b style="color:var(--acc)">${esc(p.path)}</b> ${type} <span style="color:var(--dim)">→ ${esc(sample)}</span></div>`;
        }).join('');
    }

    async function kdbMappingPreviewTemplate() {
        const sku  = document.getElementById('me-sample-sku').value.trim();
        const tmpl = document.getElementById('me-tmpl').value;
        if (!sku) { toast('Inserisci uno SKU nel campo sample e premi "Carica sample"', 'warn'); return; }
        if (!tmpl) { toast('Il template e vuoto', 'warn'); return; }
        const r = await ajax('gh_kicksdb_profile_preview_description', { sku, template: tmpl });
        if (!r.success) { toast('Preview fallita', 'err'); return; }
        document.getElementById('me-preview-out').textContent = r.data.rendered || '(vuoto)';
    }

    function kdbMappingInsertPlaceholder(ph) {
        const ta = document.getElementById('me-tmpl');
        if (!ta) return;
        const start = ta.selectionStart || 0;
        const end   = ta.selectionEnd || 0;
        ta.value = ta.value.slice(0, start) + ph + ta.value.slice(end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + ph.length;
    }

    async function kdbMappingSave() {
        const $ = id => document.getElementById(id);
        const required = [];
        document.querySelectorAll('#kdb-m-editor input[data-req]:checked').forEach(cb => required.push(cb.dataset.req));

        const payload = {
            id: _mappingEditing.id,
            name: $('me-name').value,
            active: $('me-active').checked,
            required_fields: required,
            description_template: $('me-tmpl').value,
            gallery_opts: {
                include_main:  $('me-gal-main').checked,
                include_360:   $('me-gal-360').checked,
                every_nth_360: parseInt($('me-gal-every').value, 10) || 6,
            },
        };
        const r = await GH.ajaxWithToast('gh_kicksdb_profile_save', {
            profile: JSON.stringify(payload),
        }, { okMsg: 'Profile salvato' });
        if (r.success) {
            _mappingEditing = null;
            await kdbMappingReload();
            document.getElementById('kdb-m-editor').style.display = 'none';
            document.getElementById('kdb-m-list').style.display = '';
        }
    }

    async function kdbMappingDelete(id) {
        const ok = await conf('Eliminare questo profile? L\'operazione e immediata.', { title: 'Conferma', danger: true });
        if (!ok) return;
        const r = await GH.ajaxWithToast('gh_kicksdb_profile_delete', { id }, { okMsg: 'Profile eliminato' });
        if (r.success) kdbMappingReload();
    }

    async function kdbMappingSetActive(id) {
        const r = await GH.ajaxWithToast('gh_kicksdb_profile_set_active', { id }, { okMsg: 'Profile attivato' });
        if (r.success) kdbMappingReload();
    }

    // ── Public API ──────────────────────────────────────────────

    Object.assign(GH, {
        kdbInit,
        kdbSubtab,
        kdbLoadSettings,
        kdbSaveSettings,
        kdbTestConnection,
        kdbLookup,
        kdbLookupApply,
        kdbRefreshPricing,
        kdbRefreshSelected,        // hand-off pubblico per altri tab
        kdbMigrateStatus,
        kdbMigrateTick,
        kdbProvenanceLookup,
        kdbRulesReload,
        kdbRulesReset,
        kdbRuleNew,
        kdbRuleEdit,
        kdbRuleCancel,
        kdbRuleSave,
        kdbRuleDelete,
        // Discover
        kdbDiscoverSearch,
        kdbDiscoverPage,
        kdbDiscoverToggle,
        kdbDiscoverSelectAll,
        kdbDiscoverSelectNone,
        kdbDiscoverImport,
        // Field Mapping
        kdbMappingReload,
        kdbMappingNew,
        kdbMappingEdit,
        kdbMappingDelete,
        kdbMappingSetActive,
        kdbMappingCancel,
        kdbMappingSave,
        kdbMappingLoadSample,
        kdbMappingPreviewTemplate,
        kdbMappingInsertPlaceholder,
    });

})();
