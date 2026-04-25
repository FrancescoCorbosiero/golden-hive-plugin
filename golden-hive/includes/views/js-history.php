// ═══ CATALOG HISTORY (DIFF VISUALIZATION) ═══════════════════════════════════

(function(){
    const ajax  = GH.ajax;
    const toast = GH.toast;
    const esc   = GH.esc;

    // state
    let snapshots = [];
    let lastDiff  = null;   // raw response from gh_history_diff
    let expanded  = new Set(); // product_ids whose change list is expanded

    // ── List management ─────────────────────────────────────────
    GH.histRefreshList = async function() {
        const r = await ajax('gh_history_list');
        if (!r.success) { toast('Errore caricamento snapshot: ' + (r.data || ''), 'err'); return; }

        snapshots = r.data.snapshots || [];
        const retentionNote = document.getElementById('hist-retention-note');
        if (retentionNote && r.data.retention_days) {
            retentionNote.textContent = 'Retention ' + r.data.retention_days + ' giorni';
        }

        renderSnapshotPickers();
        renderSnapshotBar();
    };

    function renderSnapshotPickers() {
        const a = document.getElementById('hist-snap-a');
        const b = document.getElementById('hist-snap-b');
        if (!a || !b) return;
        const prevA = a.value, prevB = b.value;

        const opts = ['<option value="">— Seleziona snapshot —</option>'];
        snapshots.forEach(s => {
            const lbl = s.snapshot_date + '  (' + s.product_count + ' prodotti, ' + s.trigger_type + ')';
            opts.push('<option value="' + s.id + '">' + esc(lbl) + '</option>');
        });
        a.innerHTML = opts.join('');
        b.innerHTML = opts.join('');

        // Restore previous selection if still valid; else default to (newest, prev-newest).
        if (prevA && snapshots.find(s => String(s.id) === prevA)) a.value = prevA;
        if (prevB && snapshots.find(s => String(s.id) === prevB)) b.value = prevB;

        if (!a.value && !b.value && snapshots.length >= 2) {
            // newest is index 0 (DESC order from server), pick (oldest-of-2, newest)
            a.value = String(snapshots[1].id);
            b.value = String(snapshots[0].id);
        }
    }

    function renderSnapshotBar() {
        const bar = document.getElementById('hist-list-bar');
        if (!bar) return;
        if (!snapshots.length) { bar.style.display = 'none'; return; }
        bar.style.display = '';
        document.getElementById('hist-snap-count').textContent = snapshots.length;
        const newest = snapshots[0];
        const oldest = snapshots[snapshots.length - 1];
        document.getElementById('hist-newest-stat').style.display = '';
        document.getElementById('hist-newest').textContent = newest.snapshot_date;
        document.getElementById('hist-oldest-stat').style.display = '';
        document.getElementById('hist-oldest').textContent = oldest.snapshot_date;
    }

    // ── Manual capture ──────────────────────────────────────────
    GH.histCaptureNow = async function() {
        if (!await GH.confirm('Cattura uno snapshot del catalogo ora? Sostituira lo snapshot di oggi se ne esiste gia uno.')) return;
        const sp = document.getElementById('hist-cap-spin');
        sp.style.display = '';
        try {
            const r = await ajax('gh_history_capture');
            if (!r.success) { toast('Errore cattura: ' + (r.data || ''), 'err', 0); return; }
            const d = r.data;
            toast('Snapshot ' + d.snapshot_date + ' creato — ' + d.product_count + ' prodotti in ' + d.duration_ms + ' ms', 'ok', 5000);
            await GH.histRefreshList();
        } finally {
            sp.style.display = 'none';
        }
    };

    // ── Run diff ────────────────────────────────────────────────
    GH.histRunDiff = async function() {
        const a = document.getElementById('hist-snap-a').value;
        const b = document.getElementById('hist-snap-b').value;
        if (!a || !b) { toast('Seleziona entrambi gli snapshot', 'err'); return; }
        if (a === b)  { toast('Gli snapshot devono essere diversi', 'err'); return; }

        const sp = document.getElementById('hist-diff-spin');
        sp.style.display = '';
        try {
            const r = await ajax('gh_history_diff', { snapshot_a: a, snapshot_b: b });
            if (!r.success) { toast('Errore diff: ' + (r.data || ''), 'err', 0); return; }
            lastDiff = r.data;
            expanded.clear();
            renderDiff();
        } finally {
            sp.style.display = 'none';
        }
    };

    function renderDiff() {
        if (!lastDiff) return;
        document.getElementById('hist-empty').style.display = 'none';
        document.getElementById('hist-diff-content').style.display = '';

        const s = lastDiff.summary || {};
        document.getElementById('hist-sum-added').textContent     = s.added         || 0;
        document.getElementById('hist-sum-removed').textContent   = s.removed       || 0;
        document.getElementById('hist-sum-changed').textContent   = s.changed       || 0;
        document.getElementById('hist-sum-unchanged').textContent = s.unchanged     || 0;
        document.getElementById('hist-sum-total').textContent     = s.total_changes || 0;

        const ma = lastDiff.meta?.snapshot_a;
        const mb = lastDiff.meta?.snapshot_b;
        if (ma && mb) {
            document.getElementById('hist-sum-range').textContent =
                ma.snapshot_date + '  →  ' + mb.snapshot_date;
        }

        // Populate source filter from observed sources
        const srcSet = new Set();
        ['added', 'removed', 'changed'].forEach(bucket => {
            (lastDiff[bucket] || []).forEach(row => {
                if (row.primary_source) srcSet.add(row.primary_source);
                (row.sources || []).forEach(s => { if (s) srcSet.add(s); });
            });
        });
        const srcSel = document.getElementById('hist-filter-source');
        const prevSrc = srcSel.value;
        const srcOpts = ['<option value="">Tutte</option>'];
        Array.from(srcSet).sort().forEach(s => {
            srcOpts.push('<option value="' + esc(s) + '">' + esc(s) + '</option>');
        });
        srcSel.innerHTML = srcOpts.join('');
        if (prevSrc) srcSel.value = prevSrc;

        GH.histRenderDiffTable();
    }

    // ── Render table (filtered) ─────────────────────────────────
    GH.histRenderDiffTable = function() {
        if (!lastDiff) return;
        const wrap = document.getElementById('hist-diff-table-wrap');
        const bucket  = document.getElementById('hist-filter-bucket').value;
        const source  = document.getElementById('hist-filter-source').value;
        const group   = document.getElementById('hist-filter-group').value;
        const search  = (document.getElementById('hist-filter-search').value || '').toLowerCase().trim();

        const buckets = bucket === 'all' ? ['added','removed','changed'] : [bucket];
        let rows = [];
        buckets.forEach(b => {
            (lastDiff[b] || []).forEach(r => {
                rows.push(Object.assign({ _bucket: b }, r));
            });
        });

        if (source) {
            rows = rows.filter(r =>
                r.primary_source === source ||
                (r.sources || []).includes(source)
            );
        }
        if (group) {
            rows = rows.filter(r => {
                if (r._bucket !== 'changed') return false; // group is field-level
                return (r.changes || []).some(c => c.group === group);
            });
        }
        if (search) {
            rows = rows.filter(r =>
                (r.sku || '').toLowerCase().includes(search) ||
                (r.name || '').toLowerCase().includes(search) ||
                String(r.product_id || '').includes(search)
            );
        }

        if (!rows.length) {
            wrap.innerHTML = '<div class="empty-state" style="padding:40px"><div class="empty-text">Nessuna modifica corrispondente ai filtri.</div></div>';
            return;
        }

        const html = ['<table class="hist-table" style="width:100%;border-collapse:collapse;font-family:var(--mono);font-size:12px">'];
        html.push('<thead><tr>',
            '<th style="text-align:left;padding:8px;border-bottom:1px solid var(--b1);color:var(--dim);font-weight:500;width:80px">TIPO</th>',
            '<th style="text-align:left;padding:8px;border-bottom:1px solid var(--b1);color:var(--dim);font-weight:500;width:80px">ID</th>',
            '<th style="text-align:left;padding:8px;border-bottom:1px solid var(--b1);color:var(--dim);font-weight:500;width:140px">SKU</th>',
            '<th style="text-align:left;padding:8px;border-bottom:1px solid var(--b1);color:var(--dim);font-weight:500">NOME</th>',
            '<th style="text-align:left;padding:8px;border-bottom:1px solid var(--b1);color:var(--dim);font-weight:500;width:200px">SOURCE</th>',
            '<th style="text-align:right;padding:8px;border-bottom:1px solid var(--b1);color:var(--dim);font-weight:500;width:120px">CAMBI</th>',
        '</tr></thead><tbody>');

        rows.forEach(r => {
            const badge = r._bucket === 'added'   ? '<span class="gh-status gh-status--ok">+ Aggiunto</span>'
                        : r._bucket === 'removed' ? '<span class="gh-status gh-status--err">− Rimosso</span>'
                        : '<span class="gh-status gh-status--warn">~ Modificato</span>';
            const isOpen = expanded.has(r.product_id);
            const changeCount = r.changes ? r.changes.length : (r._bucket === 'added' || r._bucket === 'removed' ? 'tutto' : 0);
            const expandable  = r._bucket === 'changed' && r.changes && r.changes.length;
            const arrow       = expandable ? (isOpen ? '▼' : '▶') : '';
            const srcCell     = r.primary_source
                ? esc(r.primary_source) + ((r.sources && r.sources.length > 1) ? ' <span style="color:var(--dim)">+' + (r.sources.length - 1) + '</span>' : '')
                : '<span style="color:var(--dim)">—</span>';

            const onclick = expandable ? ' onclick="GH.histToggleRow(' + r.product_id + ')"' : '';
            const cursor  = expandable ? ';cursor:pointer' : '';
            html.push(
                '<tr class="hist-row" data-pid="' + r.product_id + '" style="border-bottom:1px solid var(--b1)' + cursor + '"' + onclick + '>',
                    '<td style="padding:8px">' + badge + '</td>',
                    '<td style="padding:8px;color:var(--dim)">#' + r.product_id + '</td>',
                    '<td style="padding:8px">' + esc(r.sku || '') + '</td>',
                    '<td style="padding:8px;color:var(--txt)">' + esc(r.name || '(senza nome)') + '</td>',
                    '<td style="padding:8px">' + srcCell + '</td>',
                    '<td style="padding:8px;text-align:right;color:var(--dim)">',
                        (typeof changeCount === 'number' ? changeCount + ' ' + arrow : changeCount),
                    '</td>',
                '</tr>'
            );

            if (expandable && isOpen) {
                html.push('<tr class="hist-row-detail"><td colspan="6" style="padding:0;background:var(--s1)">',
                    renderChangeList(r.changes, group),
                '</td></tr>');
            }
        });
        html.push('</tbody></table>');
        wrap.innerHTML = html.join('');
    };

    function renderChangeList(changes, groupFilter) {
        const filtered = groupFilter ? changes.filter(c => c.group === groupFilter) : changes;
        if (!filtered.length) {
            return '<div style="padding:12px 20px;color:var(--dim);font-size:11px">Nessun cambio nel gruppo selezionato.</div>';
        }
        const out = ['<div style="padding:8px 20px"><table style="width:100%;border-collapse:collapse;font-family:var(--mono);font-size:11px">'];
        out.push('<thead><tr>',
            '<th style="text-align:left;padding:6px 8px;color:var(--dim);font-weight:500;width:160px">CAMPO</th>',
            '<th style="text-align:left;padding:6px 8px;color:var(--dim);font-weight:500;width:90px">GRUPPO</th>',
            '<th style="text-align:left;padding:6px 8px;color:var(--red);font-weight:500">PRIMA</th>',
            '<th style="text-align:left;padding:6px 8px;color:var(--grn);font-weight:500">DOPO</th>',
        '</tr></thead><tbody>');
        filtered.forEach(c => {
            out.push('<tr style="border-top:1px solid var(--b1)">',
                '<td style="padding:6px 8px;color:var(--txt)">' + esc(c.label || c.field) + '</td>',
                '<td style="padding:6px 8px;color:var(--dim)">' + esc(c.group) + '</td>',
                '<td style="padding:6px 8px;color:var(--red);word-break:break-word;max-width:0">' + renderVal(c.before) + '</td>',
                '<td style="padding:6px 8px;color:var(--grn);word-break:break-word;max-width:0">' + renderVal(c.after) + '</td>',
            '</tr>');
        });
        out.push('</tbody></table></div>');
        return out.join('');
    }

    function renderVal(v) {
        if (v === null || v === undefined) return '<span style="color:var(--dim)">∅</span>';
        if (typeof v === 'boolean') return v ? 'true' : 'false';
        if (typeof v === 'object') {
            const json = JSON.stringify(v);
            const trim = json.length > 200 ? json.slice(0, 200) + '…' : json;
            return esc(trim);
        }
        const s = String(v);
        if (s === '') return '<span style="color:var(--dim)">∅</span>';
        return esc(s.length > 200 ? s.slice(0, 200) + '…' : s);
    }

    GH.histToggleRow = function(pid) {
        if (expanded.has(pid)) expanded.delete(pid); else expanded.add(pid);
        GH.histRenderDiffTable();
    };

    // Auto-load list on tab open
    GH.histInit = function() {
        GH.histRefreshList();
    };
})();
