<?php
/**
 * v2 Workflow tab — JS module.
 * Extends the GH IIFE (CONVENTIONS: external modules add methods to GH).
 *
 * Batch 5a: source picker + schema-driven config form.
 * Public surface: GH.workflowInit (called on tab open).
 */
defined( 'ABSPATH' ) || exit;
?>
// ── v2 Workflow tab ───────────────────────────────────────────────
(function () {
    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => (
        { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]
    ));

    let cachedSources = null;

    // Preview state — reset on every source change.
    const state = {
        sourceId: '',
        page:     1,
        perPage:  50,
        total:    0,
        items:    [],
        // Selection key: id (number) for local sources, sku (string)
        // for fetch sources. Mixing is impossible because state.sourceId
        // pins the active source.
        selected: new Set(),
        keyField: 'id',  // 'id' | 'sku'
    };

    GH.workflowInit = function () {
        const sel = document.getElementById('wf-source-select');
        if (!sel) return;

        // Reset on every tab open so a developer who registers a new
        // Source doesn't have to refresh the page.
        sel.innerHTML = '<option value="">— Caricamento…</option>';
        document.getElementById('wf-source-info').hidden = true;
        document.getElementById('wf-source-config').hidden = true;
        document.getElementById('wf-next-steps').hidden = true;

        GH.ajax('gh_v2_sources_list', {}).then((r) => {
            if (!r || !r.success) {
                GH.toast('Errore nel caricamento sorgenti: ' + ((r && r.data && r.data.message) || 'sconosciuto'), 'err');
                sel.innerHTML = '<option value="">— Errore —</option>';
                return;
            }
            cachedSources = r.data.sources || [];
            if (cachedSources.length === 0) {
                sel.innerHTML = '<option value="">— Nessuna sorgente registrata —</option>';
                return;
            }
            sel.innerHTML =
                '<option value="">— Seleziona una sorgente —</option>' +
                cachedSources.map(s =>
                    `<option value="${esc(s.id)}">${esc(s.label)}</option>`
                ).join('');
            sel.onchange = () => GH.workflowSelectSource(sel.value);
        });
    };

    GH.workflowSelectSource = function (id) {
        const info = document.getElementById('wf-source-info');
        const cfg  = document.getElementById('wf-source-config');
        const next = document.getElementById('wf-next-steps');
        const prev = document.getElementById('wf-preview-block');
        if (!info || !cfg || !next || !prev) return;

        // Reset preview state on every source change.
        state.sourceId = id || '';
        state.page = 1; state.total = 0; state.items = [];
        state.selected.clear();
        renderTable(); updateSelectionCount();
        document.getElementById('wf-fetched-at').textContent = '';
        document.getElementById('wf-preview-warnings').hidden = true;

        if (!id) {
            info.hidden = true; cfg.hidden = true; next.hidden = true; prev.hidden = true;
            return;
        }

        const src = (cachedSources || []).find(s => s.id === id);
        if (!src) {
            GH.toast('Sorgente non trovata: ' + id, 'err');
            return;
        }

        // Capabilities chips
        const caps = src.capabilities || {};
        const chip = (label, on) => {
            const variant = on ? 'ok' : 'dim';
            return `<span class="gh-status gh-status--${variant}">${esc(label)}</span>`;
        };
        document.getElementById('wf-source-caps').innerHTML =
            chip('fetch',          !!caps.canFetch) +
            chip('diff',           !!caps.canDiff) +
            chip('materialize',    !!caps.canMaterialize) +
            chip('select-local',   !!caps.canSelectLocal) +
            chip('image-sideload', !!caps.supportsImageSideload) +
            chip('quick-patch',    !!caps.supportsQuickPatch);
        info.hidden = false;

        // Config form (driven by configSchema)
        const schema = src.config_schema || {};
        const fields = Object.entries(schema);
        if (fields.length === 0) {
            cfg.hidden = true;
        } else {
            document.getElementById('wf-config-form').innerHTML =
                fields.map(([field, spec]) => renderField(field, spec)).join('');
            cfg.hidden = false;
        }

        // Wire the preview block based on capabilities.
        prev.hidden = false;
        const isLocal = !!caps.canSelectLocal;
        const isFetch = !!caps.canFetch;
        state.keyField = isLocal ? 'id' : 'sku';

        const refreshBtn = document.getElementById('wf-refresh-btn');
        refreshBtn.hidden = !isFetch;
        document.getElementById('wf-load-btn').textContent =
            isFetch ? 'Carica preview (fetch)' : 'Carica catalogo';

        // Selection-local sources auto-load (matches Filter & Bulk UX).
        // Fetch sources require explicit click so we don't fire a remote
        // call as a side effect of opening the dropdown.
        if (isLocal) {
            loadPreview(false);
        }

        next.hidden = false;
    };

    function renderField(field, spec) {
        const label = esc(spec.label || field);
        const required = spec.required
            ? ' <span style="color:var(--err,#e55);font-weight:600">*</span>'
            : '';
        const fid = 'wf-cfg-' + field.replace(/[^a-z0-9_-]/gi, '_');
        const max = parseInt(spec.max, 10);
        const maxAttr = max > 0 ? ` maxlength="${max}"` : '';

        let input;
        switch (spec.type) {
            case 'enum': {
                const opts = (spec.options || [])
                    .map(o => `<option value="${esc(o)}">${esc(o)}</option>`)
                    .join('');
                input = `<select id="${fid}" class="form-input">${opts}</select>`;
                break;
            }
            case 'secret':
                input = `<input type="password" id="${fid}" class="form-input"
                            autocomplete="new-password" spellcheck="false"${maxAttr}>`;
                break;
            case 'url':
                input = `<input type="url" id="${fid}" class="form-input"${maxAttr}>`;
                break;
            case 'int':
                input = `<input type="number" id="${fid}" class="form-input">`;
                break;
            case 'bool':
                input = `<label style="display:flex;align-items:center;gap:.4rem;cursor:pointer">
                            <input type="checkbox" id="${fid}">
                            <span style="opacity:.75;font-size:.85rem">${label}${required}</span>
                         </label>`;
                return `<div class="form-row">${input}</div>`;
            case 'text':
            default:
                input = `<input type="text" id="${fid}" class="form-input"${maxAttr}>`;
        }

        return `
            <div class="form-row">
                <label class="form-label" for="${fid}"
                       style="display:block;font-size:.8rem;opacity:.75;margin-bottom:.25rem">
                    ${label}${required}
                </label>
                ${input}
            </div>
        `;
    }

    // ── Preview / selection ───────────────────────────────────

    function readConfig() {
        const src = (cachedSources || []).find(s => s.id === state.sourceId);
        if (!src) return {};
        const out = {};
        Object.entries(src.config_schema || {}).forEach(([field]) => {
            const el = document.getElementById('wf-cfg-' + field.replace(/[^a-z0-9_-]/gi, '_'));
            if (!el) return;
            out[field] = (el.type === 'checkbox') ? !!el.checked : el.value;
        });
        return out;
    }

    function loadPreview(forceFresh) {
        if (!state.sourceId) return;
        const tbody = document.getElementById('wf-preview-tbody');
        tbody.innerHTML = '<tr><td colspan="7" style="padding:1.5rem;text-align:center;opacity:.6">Caricamento…</td></tr>';

        GH.ajax('gh_v2_workflow_preview', {
            source_id: state.sourceId,
            config:    JSON.stringify(readConfig()),
            search:    document.getElementById('wf-search').value || '',
            page:      state.page,
            force:     forceFresh ? '1' : '',
        }).then((r) => {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) || 'Errore preview';
                GH.toast(msg, 'err');
                tbody.innerHTML = `<tr><td colspan="7" style="padding:1.5rem;text-align:center;color:var(--err,#e55)">${esc(msg)}</td></tr>`;
                return;
            }
            const d = r.data || {};
            state.items   = d.items   || [];
            state.total   = d.total   || 0;
            state.page    = d.page    || 1;
            state.perPage = d.per_page || 50;

            // Server may clamp the page (e.g. after a search shrinks the set).
            renderTable();
            renderPagination();
            renderFetchedAt(d.fetched_at);
            renderWarnings(d.warnings || (d.message ? [d.message] : []));
        });
    }

    function renderTable() {
        const tbody = document.getElementById('wf-preview-tbody');
        if (!tbody) return;

        if (state.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="padding:1.5rem;text-align:center;opacity:.6">Nessun risultato.</td></tr>';
            document.getElementById('wf-check-all').checked = false;
            return;
        }

        const rows = state.items.map(item => {
            const key = item[state.keyField];
            const isChecked = state.selected.has(String(key));
            const status = (item.status || '').toLowerCase();
            const statusVariant = status === 'publish' ? 'ok'
                : status === 'draft' ? 'dim'
                : status === 'remote' ? 'info'
                : 'warn';
            const thumb = item.thumb
                ? `<img src="${esc(item.thumb)}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px">`
                : `<div style="width:40px;height:40px;background:var(--surface-2,#16181d);border-radius:4px"></div>`;

            return `
                <tr style="border-top:1px solid var(--bd,#2a2d33)" data-key="${esc(key)}">
                    <td style="padding:.5rem">
                        <input type="checkbox" class="wf-row-check" data-key="${esc(key)}" ${isChecked ? 'checked' : ''}>
                    </td>
                    <td style="padding:.5rem">${thumb}</td>
                    <td style="padding:.5rem;font-family:'JetBrains Mono',monospace;font-size:.8rem">${esc(item.sku || '—')}</td>
                    <td style="padding:.5rem">${esc(item.name || '')}</td>
                    <td style="padding:.5rem">${esc(item.price || '')}</td>
                    <td style="padding:.5rem"><span class="gh-status gh-status--${statusVariant}">${esc(item.status || '')}</span></td>
                    <td style="padding:.5rem;opacity:.75">${esc(item.type || '')}</td>
                </tr>
            `;
        }).join('');

        tbody.innerHTML = rows;

        // Wire row checkboxes (delegated would be cleaner; per-row direct
        // assignment keeps state.selected the single source of truth).
        tbody.querySelectorAll('.wf-row-check').forEach(cb => {
            cb.addEventListener('change', () => {
                const k = cb.getAttribute('data-key');
                if (cb.checked) state.selected.add(String(k));
                else state.selected.delete(String(k));
                updateSelectionCount();
                syncCheckAllState();
            });
        });
        syncCheckAllState();
    }

    function syncCheckAllState() {
        const allOnPage = state.items.map(i => String(i[state.keyField]));
        const cb = document.getElementById('wf-check-all');
        if (!cb) return;
        if (allOnPage.length === 0) { cb.checked = false; cb.indeterminate = false; return; }
        const sel = allOnPage.filter(k => state.selected.has(k)).length;
        cb.checked = (sel === allOnPage.length);
        cb.indeterminate = (sel > 0 && sel < allOnPage.length);
    }

    function renderPagination() {
        const totalPages = Math.max(1, Math.ceil(state.total / state.perPage));
        const info = document.getElementById('wf-page-info');
        if (info) info.textContent = state.total === 0
            ? '—'
            : `Pagina ${state.page} / ${totalPages} · ${state.total} totali`;

        const prev = document.getElementById('wf-prev');
        const next = document.getElementById('wf-next');
        if (prev) prev.disabled = state.page <= 1;
        if (next) next.disabled = state.page >= totalPages;
    }

    function renderFetchedAt(ts) {
        const el = document.getElementById('wf-fetched-at');
        if (!el) return;
        if (!ts) { el.textContent = ''; return; }
        const d = new Date(ts * 1000);
        el.textContent = 'Cache: ' + d.toLocaleTimeString();
    }

    function renderWarnings(list) {
        const el = document.getElementById('wf-preview-warnings');
        if (!el) return;
        if (!list || list.length === 0) { el.hidden = true; el.innerHTML = ''; return; }
        el.innerHTML = list.map(w => esc(String(w))).join('<br>');
        el.hidden = false;
    }

    function updateSelectionCount() {
        const el = document.getElementById('wf-selection-count');
        if (el) el.textContent = state.selected.size + ' selezionati';
    }

    // Public hooks for future sub-batches (5c pipeline builder, 5d run).
    GH.workflowGetSelection = function () {
        return {
            source_id: state.sourceId,
            mode:      'ids',
            ids:       Array.from(state.selected),
            // Future: if user picks "all matching", swap to mode='filter'
            // and serialize the search term.
        };
    };

    // ── DOM wiring (idempotent — workflowInit runs every tab open) ──
    let wired = false;
    function wireOnce() {
        if (wired) return;
        wired = true;

        document.getElementById('wf-load-btn').addEventListener('click', () => loadPreview(false));
        document.getElementById('wf-refresh-btn').addEventListener('click', () => {
            state.page = 1;
            loadPreview(true);
        });

        // Search: debounced, resets to page 1.
        let searchTimer = null;
        document.getElementById('wf-search').addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.page = 1;
                loadPreview(false);
            }, 250);
        });

        document.getElementById('wf-prev').addEventListener('click', () => {
            if (state.page > 1) { state.page--; loadPreview(false); }
        });
        document.getElementById('wf-next').addEventListener('click', () => {
            const totalPages = Math.max(1, Math.ceil(state.total / state.perPage));
            if (state.page < totalPages) { state.page++; loadPreview(false); }
        });

        document.getElementById('wf-check-all').addEventListener('change', (e) => {
            const checked = e.target.checked;
            state.items.forEach(item => {
                const k = String(item[state.keyField]);
                if (checked) state.selected.add(k);
                else state.selected.delete(k);
            });
            renderTable();
            updateSelectionCount();
        });
    }

    // Hook into workflowInit so wiring happens once the panel exists in DOM.
    const _origInit = GH.workflowInit;
    GH.workflowInit = function () {
        _origInit && _origInit();
        wireOnce();
    };
})();
