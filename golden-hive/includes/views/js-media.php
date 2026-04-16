// ═══ MEDIA LIBRARY ════════════════════════════════════════════════════════

(function() {

    // ── STATE ───────────────────────────────────────────────────────────────
    const mlState = {
        page: 1,
        per_page: 100,
        total: 0,
        total_pages: 0,
        items: [],
        selected: new Set(), // ID set (persistent across pages)
        loaded: false,
    };

    function esc(s) { if (s == null) return ''; const d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
    function fmtBytes(b) { b=+b||0; if(b<1024)return b+' B'; if(b<1048576)return (b/1024).toFixed(1)+' KB'; if(b<1073741824)return (b/1048576).toFixed(1)+' MB'; return (b/1073741824).toFixed(2)+' GB'; }

    function currentFilters() {
        return {
            filename:  (document.getElementById('ml-search')?.value || '').trim(),
            usage:     document.getElementById('ml-usage')?.value || 'all',
            whitelist: document.getElementById('ml-whitelist')?.value || 'all',
            orderby:   document.getElementById('ml-orderby')?.value || 'date',
            order:     'DESC',
        };
    }

    // ── QUERY ───────────────────────────────────────────────────────────────
    GH.mlQuery = async function(resetPage) {
        if (resetPage) mlState.page = 1;
        const sp = document.getElementById('ml-spin');
        const ov = document.getElementById('ml-overlay');
        const ot = document.getElementById('ml-overlay-text');
        if (sp) sp.style.display = '';
        if (ov) ov.classList.add('visible');
        if (ot) ot.textContent = 'Caricamento media...';

        const filters = currentFilters();
        const body = Object.assign({}, filters, {
            page: mlState.page,
            per_page: mlState.per_page,
        });

        try {
            const r = await GH.ajax('gh_ajax_media_query', body);
            if (!r.success) { GH.toast(r.data || 'Query fallita', 'err'); return; }
            mlState.items       = r.data.items || [];
            mlState.total       = r.data.total || 0;
            mlState.total_pages = r.data.total_pages || 1;
            mlState.loaded      = true;
            renderTable();
            renderPagination();
            updateStatsBar();
            updateBulkBar();
        } catch (e) {
            GH.toast('Errore query: ' + e.message, 'err');
        } finally {
            if (sp) sp.style.display = 'none';
            if (ov) ov.classList.remove('visible');
        }
    };

    // ── RENDER TABLE ────────────────────────────────────────────────────────
    function renderTable() {
        const wrap = document.getElementById('ml-results');
        if (!mlState.items.length) {
            wrap.innerHTML = '<div class="empty-state"><div class="empty-icon">&#8709;</div><div class="empty-text">Nessun media corrisponde ai filtri</div></div>';
            return;
        }
        let h = '<table class="ml-table"><thead><tr>';
        h += '<th style="width:28px"><input type="checkbox" id="ml-chk-all" onchange="GH.mlTogglePage(this.checked)"></th>';
        h += '<th style="width:56px"></th>';
        h += '<th>Nome file</th>';
        h += '<th style="width:70px">ID</th>';
        h += '<th style="width:80px">Dim.</th>';
        h += '<th>Usato da</th>';
        h += '<th style="width:40px">WL</th>';
        h += '<th style="width:70px"></th>';
        h += '</tr></thead><tbody>';

        mlState.items.forEach(function(item) {
            const sel = mlState.selected.has(item.id);
            const rowClass = (sel ? 'ml-row-sel ' : '') + (item.is_whitelisted ? 'ml-row-wl' : '');
            h += '<tr class="ml-row ' + rowClass + '" data-id="' + item.id + '">';
            h += '<td><input type="checkbox" class="ml-row-chk"' + (sel ? ' checked' : '') + ' onchange="GH.mlToggleRow(' + item.id + ',this.checked)"></td>';
            h += '<td><img class="ml-thumb" src="' + esc(item.thumbnail_url) + '" loading="lazy" onerror="this.style.visibility=\'hidden\'"></td>';
            h += '<td class="ml-name mono" title="' + esc(item.url) + '">' + esc(item.filename || '—') + '</td>';
            h += '<td class="mono dim">#' + item.id + '</td>';
            h += '<td class="mono dim">' + esc(item.filesize_human || '—') + '</td>';

            // Usage badges
            h += '<td class="ml-usages">';
            if (!item.usage || !item.usage.length) {
                h += '<span class="ml-unmapped">non mappato</span>';
            } else {
                item.usage.forEach(function(u) {
                    const label = u.sku || ('#' + u.pid);
                    h += '<span class="ml-usage role-' + esc(u.role) + '" title="' + esc(u.name) + '">';
                    h += '<span class="ml-role">' + esc(u.role) + '</span>';
                    h += '<span class="ml-pid">' + esc(label) + '</span>';
                    if (u.permalink) {
                        h += '<a class="ml-eye" href="' + esc(u.permalink) + '" target="_blank" rel="noopener" title="Apri prodotto: ' + esc(u.name) + '">&#128065;</a>';
                    }
                    h += '</span>';
                });
            }
            h += '</td>';

            // WL badge
            h += '<td>';
            if (item.is_whitelisted) {
                h += '<span class="ml-wl-badge" title="' + esc(item.whitelist_reason || '') + '">WL</span>';
            }
            h += '</td>';

            // Row actions
            h += '<td class="ml-row-actions">';
            if (item.is_whitelisted) {
                h += '<button class="btn btn-ghost btn-sm" onclick="GH.mlToggleWhitelistRow(' + item.id + ',true)" title="Rimuovi da whitelist">&minus;WL</button>';
            } else {
                h += '<button class="btn btn-ghost btn-sm" onclick="GH.mlToggleWhitelistRow(' + item.id + ',false)" title="Aggiungi a whitelist">+WL</button>';
            }
            h += '</td>';
            h += '</tr>';
        });
        h += '</tbody></table>';
        wrap.innerHTML = h;
        syncHeaderCheckbox();
    }

    // ── PAGINATION ──────────────────────────────────────────────────────────
    function renderPagination() {
        const el = document.getElementById('ml-pagination');
        if (mlState.total_pages <= 1) { el.style.display = 'none'; return; }
        el.style.display = 'flex';
        let h = '';
        const p = mlState.page, tp = mlState.total_pages;
        h += '<button class="btn btn-ghost btn-sm"' + (p <= 1 ? ' disabled' : '') + ' onclick="GH.mlGoPage(1)">&laquo;</button>';
        h += '<button class="btn btn-ghost btn-sm"' + (p <= 1 ? ' disabled' : '') + ' onclick="GH.mlGoPage(' + (p-1) + ')">&lsaquo;</button>';
        h += '<span class="mono" style="font-size:11px;color:var(--dim)">' + p + ' / ' + tp + '</span>';
        h += '<button class="btn btn-ghost btn-sm"' + (p >= tp ? ' disabled' : '') + ' onclick="GH.mlGoPage(' + (p+1) + ')">&rsaquo;</button>';
        h += '<button class="btn btn-ghost btn-sm"' + (p >= tp ? ' disabled' : '') + ' onclick="GH.mlGoPage(' + tp + ')">&raquo;</button>';
        el.innerHTML = h;
    }
    GH.mlGoPage = function(n) { n = Math.max(1, Math.min(mlState.total_pages, n|0)); if (n === mlState.page) return; mlState.page = n; GH.mlQuery(false); };

    // ── STATS / SELECTION ──────────────────────────────────────────────────
    function updateStatsBar() {
        const bar = document.getElementById('ml-stats-bar');
        bar.style.display = mlState.loaded ? 'flex' : 'none';
        document.getElementById('ml-total').textContent = mlState.total;
        document.getElementById('ml-page').textContent = mlState.page;
        document.getElementById('ml-total-pages').textContent = mlState.total_pages;
    }
    function updateBulkBar() {
        const n = mlState.selected.size;
        document.getElementById('ml-bulk-bar').style.display = n > 0 ? '' : 'none';
        const selStat = document.getElementById('ml-sel-stat');
        if (selStat) selStat.style.display = n > 0 ? '' : 'none';
        const selN = document.getElementById('ml-sel-n');
        if (selN) selN.textContent = n;
    }
    function syncHeaderCheckbox() {
        const chk = document.getElementById('ml-chk-all');
        if (!chk) return;
        const allOnPage = mlState.items.every(i => mlState.selected.has(i.id));
        chk.checked = mlState.items.length > 0 && allOnPage;
    }

    GH.mlToggleRow = function(id, checked) {
        if (checked) mlState.selected.add(id); else mlState.selected.delete(id);
        updateBulkBar(); syncHeaderCheckbox();
        // Update row class for visual feedback
        const tr = document.querySelector('.ml-row[data-id="' + id + '"]');
        if (tr) tr.classList.toggle('ml-row-sel', checked);
    };
    GH.mlTogglePage = function(checked) {
        mlState.items.forEach(function(i) {
            if (checked) mlState.selected.add(i.id); else mlState.selected.delete(i.id);
        });
        document.querySelectorAll('.ml-row-chk').forEach(function(c) { c.checked = checked; });
        document.querySelectorAll('.ml-row').forEach(function(r) { r.classList.toggle('ml-row-sel', checked); });
        updateBulkBar();
    };
    GH.mlClearSelection = function() {
        mlState.selected.clear();
        document.querySelectorAll('.ml-row-chk').forEach(function(c) { c.checked = false; });
        document.querySelectorAll('.ml-row').forEach(function(r) { r.classList.remove('ml-row-sel'); });
        syncHeaderCheckbox();
        updateBulkBar();
    };
    GH.mlSelectAllInFilter = async function() {
        const ov = document.getElementById('ml-overlay'), ot = document.getElementById('ml-overlay-text');
        if (ov) ov.classList.add('visible');
        if (ot) ot.textContent = 'Fetch IDs...';
        try {
            const r = await GH.ajax('gh_ajax_media_query_ids', currentFilters());
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            (r.data.ids || []).forEach(id => mlState.selected.add(id));
            GH.toast(r.data.count + ' media selezionati (totale nel filtro)', 'ok');
            renderTable();
            updateBulkBar();
        } finally {
            if (ov) ov.classList.remove('visible');
        }
    };

    // ── ROW WHITELIST TOGGLE ───────────────────────────────────────────────
    GH.mlToggleWhitelistRow = async function(id, isWl) {
        if (isWl) {
            if (!confirm('Rimuovere #' + id + ' dalla whitelist?')) return;
            const r = await GH.ajax('rp_mm_ajax_remove_whitelist', { attachment_id: id });
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            GH.toast('#' + id + ' rimosso dalla whitelist', 'ok');
        } else {
            const reason = prompt('Motivo whitelist per #' + id + ':');
            if (reason === null) return;
            if (!reason.trim()) { GH.toast('Il motivo e obbligatorio', 'err'); return; }
            const r = await GH.ajax('rp_mm_ajax_add_whitelist', { attachment_id: id, reason: reason.trim() });
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            GH.toast('#' + id + ' protetto', 'ok');
        }
        GH.mlQuery(false);
    };

    // ── BULK: WHITELIST ────────────────────────────────────────────────────
    GH.mlBulkWhitelist = async function() {
        const ids = Array.from(mlState.selected);
        if (!ids.length) { GH.toast('Nessuna selezione', 'err'); return; }
        const reason = prompt('Motivo whitelist per ' + ids.length + ' media:');
        if (reason === null) return;
        if (!reason.trim()) { GH.toast('Motivo obbligatorio', 'err'); return; }
        const r = await GH.ajax('gh_ajax_media_bulk_whitelist', {
            ids: JSON.stringify(ids),
            reason: reason.trim(),
        });
        if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
        GH.toast(r.data.added + ' media aggiunti alla whitelist', 'ok');
        mlState.selected.clear();
        GH.mlQuery(false);
    };

    // ── BULK: REMOVE FROM GALLERIES ────────────────────────────────────────
    GH.mlBulkRemoveFromGalleries = async function() {
        const ids = Array.from(mlState.selected);
        if (!ids.length) { GH.toast('Nessuna selezione', 'err'); return; }

        // Preview: which products would be affected?
        const pr = await GH.ajax('gh_ajax_media_gallery_removal_preview', { ids: JSON.stringify(ids) });
        if (!pr.success) { GH.toast(pr.data || 'Errore', 'err'); return; }
        const p = pr.data;
        if (!p.total_removals) {
            GH.toast('Nessuno dei media selezionati e in una gallery', 'inf');
            return;
        }
        // Build confirm message
        let msg = 'RIMOZIONE DA GALLERIE\n\n';
        msg += p.total_removals + ' rimozioni totali su ' + p.affected_count + ' prodotti:\n\n';
        p.products.slice(0, 30).forEach(function(prod) {
            msg += '• ' + (prod.sku ? '[' + prod.sku + '] ' : '') + prod.name + ' — ' + prod.removals + ' img\n';
        });
        if (p.products.length > 30) msg += '  ... +' + (p.products.length - 30) + ' altri\n';
        msg += '\nI media NON vengono eliminati dalla library, solo rimossi dalle gallerie. Procedere?';
        if (!confirm(msg)) return;

        const r = await GH.ajax('gh_ajax_media_remove_from_galleries', { ids: JSON.stringify(ids) });
        if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
        GH.toast(r.data.removals + ' rimozioni su ' + r.data.affected_products + ' prodotti', 'ok');
        mlState.selected.clear();
        GH.mlQuery(false);
    };

    // ── BULK: DELETE (chunked) ─────────────────────────────────────────────
    GH.mlBulkDelete = async function() {
        const ids = Array.from(mlState.selected);
        if (!ids.length) { GH.toast('Nessuna selezione', 'err'); return; }
        if (!confirm('Eliminare ' + ids.length + ' media dalla library?\n\nSafety check per-item: whitelist + rp_mm_is_used + log.\nI media whitelisted verranno saltati automaticamente.')) return;
        const result = await chunkedDelete(ids, 'Eliminazione');
        GH.toast('Eliminati: ' + result.deleted.length + ', Spazio: ' + result.freed_human, 'ok', 5000);
        mlState.selected.clear();
        GH.mlQuery(false);
    };

    // ── SAFE CLEANUP (in-panel preview + chunked delete) ──────────────────
    GH.mlSafeCleanup = async function() {
        const ov = document.getElementById('ml-overlay'), ot = document.getElementById('ml-overlay-text');
        ov.classList.add('visible'); ot.textContent = 'Analisi Safe Cleanup...';
        let data;
        try {
            const r = await GH.ajax('gh_ajax_media_safe_cleanup_preview');
            if (!r.success) { GH.toast(r.data || 'Errore', 'err'); return; }
            data = r.data;
        } finally {
            ov.classList.remove('visible');
        }
        renderSafePreview(data);
    };

    function renderSafePreview(data) {
        const panel = document.getElementById('ml-safe-preview');
        let h = '<div style="max-width:900px;margin:0 auto">';
        h += '<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">';
        h += '<button class="btn btn-ghost" onclick="GH.mlCloseSafePreview()">&larr; Torna</button>';
        h += '<h2 style="margin:0;font-family:var(--mono);font-size:14px;color:var(--txt)">SAFE CLEANUP — Preview</h2>';
        h += '</div>';

        // Stats
        h += '<div class="stats-bar" style="margin-bottom:16px">';
        h += '<div class="stat">Non mappati totali: <span class="blue">' + data.total_matched + '</span></div>';
        h += '<div class="stat">Da eliminare: <span class="red">' + data.to_delete_count + '</span></div>';
        h += '<div class="stat">Whitelisted (esclusi): <span class="green">' + data.whitelisted_count + '</span></div>';
        h += '</div>';

        // Explanation
        h += '<div style="padding:12px;background:var(--s2);border:1px solid var(--b1);border-radius:4px;font-family:var(--sans);font-size:12px;color:var(--txt);margin-bottom:16px;line-height:1.5">';
        h += '<strong style="color:var(--amb)">&#9888; Criterio:</strong> verranno eliminati <strong>' + data.to_delete_count + '</strong> media non referenziati ';
        h += 'da: featured image di prodotti/varianti/post/page, gallery di WooCommerce, o <code style="color:var(--acc)">src</code>/<code style="color:var(--acc)">href</code> nel content. ';
        h += 'I <strong>' + data.whitelisted_count + '</strong> media whitelisted qui sotto NON saranno toccati.';
        h += '</div>';

        // Whitelist excluded list
        if (data.whitelist_details && data.whitelist_details.length) {
            h += '<div style="margin-bottom:16px">';
            h += '<div style="font-family:var(--mono);font-size:11px;color:var(--grn);margin-bottom:6px;text-transform:uppercase">&#9737; Whitelist esclusi (' + data.whitelist_details.length + ')</div>';
            h += '<div style="max-height:240px;overflow-y:auto;border:1px solid var(--b1);border-radius:4px;background:var(--s2)">';
            h += '<table style="width:100%;border-collapse:collapse;font-size:11px">';
            h += '<thead><tr><th class="tbl-th">ID</th><th class="tbl-th">URL</th><th class="tbl-th">Motivo</th></tr></thead><tbody>';
            data.whitelist_details.forEach(function(w) {
                h += '<tr style="border-bottom:1px solid var(--b1)">';
                h += '<td class="tbl-td mono dim">#' + w.id + '</td>';
                h += '<td class="tbl-td mono" style="font-size:10px;word-break:break-all;color:var(--acc)">' + esc(w.url || '—') + '</td>';
                h += '<td class="tbl-td">' + esc(w.reason || '—') + '</td>';
                h += '</tr>';
            });
            h += '</tbody></table></div></div>';
        }

        // Confirm / cancel
        h += '<div style="display:flex;gap:8px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--b1)">';
        h += '<button class="btn btn-ghost" onclick="GH.mlCloseSafePreview()">Annulla</button>';
        if (data.to_delete_count > 0) {
            h += '<button class="btn btn-danger" onclick="GH.mlConfirmSafeCleanup()">&times; Elimina ' + data.to_delete_count + ' media</button>';
        } else {
            h += '<button class="btn btn-ghost" disabled>Nessun media da eliminare</button>';
        }
        h += '</div></div>';

        panel.innerHTML = h;
        panel.style.display = 'block';
        // Stash ids for confirm step
        panel.dataset.toDeleteIds = JSON.stringify(data.to_delete_ids || []);
    }

    GH.mlCloseSafePreview = function() {
        const panel = document.getElementById('ml-safe-preview');
        panel.style.display = 'none';
        panel.innerHTML = '';
        delete panel.dataset.toDeleteIds;
    };

    GH.mlConfirmSafeCleanup = async function() {
        const panel = document.getElementById('ml-safe-preview');
        const ids = JSON.parse(panel.dataset.toDeleteIds || '[]');
        if (!ids.length) { GH.toast('Nulla da eliminare', 'inf'); return; }
        if (!confirm('ULTIMA CONFERMA: stai per eliminare ' + ids.length + ' media non mappati.\n\nLa whitelist e i safety check restano attivi server-side per ogni singola cancellazione. Procedere?')) return;
        GH.mlCloseSafePreview();
        const r = await chunkedDelete(ids, 'Safe Cleanup');
        GH.toast('Safe Cleanup: ' + r.deleted.length + ' eliminati, ' + r.freed_human + ' recuperati', 'ok', 7000);
        mlState.selected.clear();
        GH.mlQuery(false);
    };

    // ── CHUNKED DELETE ──────────────────────────────────────────────────────
    async function chunkedDelete(ids, label) {
        const total = ids.length;
        const chunk = 200;
        let deleted = [], errors = {}, freedBytes = 0;
        let remaining = ids.slice();
        const ov = document.getElementById('ml-overlay'), ot = document.getElementById('ml-overlay-text');
        if (ov) ov.classList.add('visible');
        if (ot) ot.textContent = label + ' 0/' + total;
        try {
            while (remaining.length) {
                const batch = remaining.slice(0, chunk);
                const r = await GH.ajax('rp_mm_ajax_bulk_delete', {
                    ids: JSON.stringify(batch),
                    chunk_size: chunk,
                });
                if (!r.success) { GH.toast('Errore: ' + (r.data || 'delete'), 'err'); break; }
                deleted = deleted.concat(r.data.deleted || []);
                Object.assign(errors, r.data.errors || {});
                freedBytes += (r.data.freed_bytes || 0);
                remaining = remaining.slice(batch.length);
                if (ot) ot.textContent = label + ' ' + deleted.length + '/' + total;
            }
        } finally {
            if (ov) ov.classList.remove('visible');
        }
        return { deleted, errors, freed_bytes: freedBytes, freed_human: fmtBytes(freedBytes) };
    }

    // ── AUTO-LOAD ON FIRST TAB OPEN ────────────────────────────────────────
    const origSwitch = GH.switchTab;
    GH.switchTab = function(name, el) {
        origSwitch(name, el);
        if (name === 'media-library' && !mlState.loaded) {
            GH.mlQuery(true);
        }
    };

})();
