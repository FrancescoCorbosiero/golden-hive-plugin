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
    let cachedOperations = null;
    let cachedChecks = null;

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
        // Pipeline being built. Steps shape mirrors the AJAX contract:
        // { kind, ref_id, params, note? }. Survives source changes
        // unless explicitly cleared (the user might want to reuse a
        // pipeline against a different selection).
        pipeline: { id: '', name: '', steps: [] },
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

        // Pipeline builder is source-agnostic — show it as soon as any
        // source is picked. Load operations + saved-recipe list once.
        showPipelineBlock();
        loadOperationsAndChecks();
        loadPipelineList();

        // Credential round-trip: pre-fill form with stored redacted
        // values; show the Save button only for sources that the
        // feed-credentials store accepts (server gates anyway).
        if (Object.keys(src.config_schema || {}).length > 0) {
            loadCredentials(id);
            showCredentialsSaveButton(true);
        } else {
            showCredentialsSaveButton(false);
        }

        // Run block. Buttons enable themselves once selection + pipeline
        // are non-empty (updateRunSummary handles the gating).
        showRunBlock();

        next.hidden = true; // legacy "Prossimo" placeholder no longer relevant
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
        updateRunSummary();
    }

    // Public hooks for future sub-batches (5d run).
    GH.workflowGetSelection = function () {
        return {
            source_id: state.sourceId,
            mode:      'ids',
            ids:       Array.from(state.selected),
            // Future: if user picks "all matching", swap to mode='filter'
            // and serialize the search term.
        };
    };

    GH.workflowGetPipeline = function () {
        return {
            id:    state.pipeline.id,
            name:  state.pipeline.name,
            steps: state.pipeline.steps.map(s => ({
                kind:   s.kind,
                ref_id: s.ref_id,
                params: { ...s.params },
                note:   s.note ?? null,
            })),
        };
    };

    // ── Credentials round-trip ───────────────────────────────

    function loadCredentials(sourceId) {
        if (!sourceId) return;
        GH.ajax('gh_v2_workflow_credentials_load', { source_id: sourceId }).then(r => {
            if (!r || !r.success) return;
            const cfg = (r.data && r.data.config) || {};
            // Populate form fields with stored (redacted) values.
            Object.entries(cfg).forEach(([field, val]) => {
                const el = document.getElementById('wf-cfg-' + field.replace(/[^a-z0-9_-]/gi, '_'));
                if (!el) return;
                if (el.type === 'checkbox') el.checked = !!val;
                else el.value = val ?? '';
            });
        });
    }

    function showCredentialsSaveButton(visible) {
        const btn = document.getElementById('wf-creds-save');
        if (btn) btn.hidden = !visible;
    }

    function saveCredentials() {
        if (!state.sourceId) return;
        const cfg = readConfig();
        GH.ajax('gh_v2_workflow_credentials_save', {
            source_id: state.sourceId,
            config:    JSON.stringify(cfg),
        }).then(r => {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) || 'Errore salvataggio credenziali';
                GH.toast(msg, 'err');
                return;
            }
            // Repaint form with redacted values returned by the server
            // so secrets immediately show as ••••XXXX.
            const cfg2 = (r.data && r.data.config) || {};
            Object.entries(cfg2).forEach(([field, val]) => {
                const el = document.getElementById('wf-cfg-' + field.replace(/[^a-z0-9_-]/gi, '_'));
                if (el && el.type !== 'checkbox') el.value = val ?? '';
            });
            const stamp = document.getElementById('wf-creds-saved');
            stamp.textContent = '✓ salvate ' + new Date().toLocaleTimeString();
            GH.toast('Credenziali salvate', 'ok');
        });
    }

    // ── Run flow ─────────────────────────────────────────────

    function showRunBlock() {
        document.getElementById('wf-run-block').hidden = false;
        updateRunSummary();
    }

    function updateRunSummary() {
        const sumEl = document.getElementById('wf-run-summary');
        if (!sumEl) return;
        const selN  = state.selected.size;
        const stepN = state.pipeline.steps.length;
        const ready = selN > 0 && stepN > 0;

        sumEl.textContent = ready
            ? `${selN} prodotti × ${stepN} step → ${selN * stepN} esecuzioni totali`
            : `Servono almeno 1 prodotto e 1 step (selezionati: ${selN}, step: ${stepN})`;

        ['wf-run-dry', 'wf-run-now', 'wf-run-sched'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.disabled = !ready;
        });
    }

    function postRun(mode, extra) {
        const payload = {
            mode,
            selection: JSON.stringify(GH.workflowGetSelection()),
            pipeline:  JSON.stringify(GH.workflowGetPipeline()),
            ...(extra || {}),
        };
        GH.ajax('gh_v2_workflow_run', payload).then(r => {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) || 'Errore esecuzione';
                GH.toast(msg, 'err');
                return;
            }
            const d = r.data || {};
            // Persist the (auto-)saved pipeline id so subsequent saves
            // update rather than duplicate.
            if (d.pipeline_id) {
                state.pipeline.id = d.pipeline_id;
                document.getElementById('wf-pipeline-id').value = d.pipeline_id;
            }
            const verb = mode === 'dry_run' ? 'Dry-run' : (mode === 'schedule' ? 'Schedulato' : 'Eseguito');
            GH.toast(`${verb} → job ${d.job_id}`, 'ok');

            // Hand off to the Jobs tab. The existing Jobs UI uses a hash
            // router (#/jobs/<id>) per CONVENTIONS; switchTab + updateHash
            // gives the user a direct view of the freshly-created job.
            if (typeof GH.switchTab === 'function') {
                const jobsTab = document.querySelector('[onclick*="switchTab(\'jobs\'"]');
                if (jobsTab) GH.switchTab('jobs', jobsTab);
                if (typeof GH.updateHash === 'function') {
                    GH.updateHash('jobs', d.job_id);
                }
            }
        });
    }

    function openSchedulePanel() {
        document.getElementById('wf-schedule-panel').hidden = false;
    }
    function closeSchedulePanel() {
        document.getElementById('wf-schedule-panel').hidden = true;
    }
    function selectedCronPreset() {
        const r = document.querySelector('input[name="wf-cron-preset"]:checked');
        return r ? r.value : 'daily';
    }

    // ── Pipeline builder ─────────────────────────────────────

    function showPipelineBlock() {
        document.getElementById('wf-pipeline-block').hidden = false;
    }

    function loadOperationsAndChecks() {
        // Cache once per page load — operations don't change at runtime.
        if (cachedOperations !== null) {
            renderOpPicker();
            return;
        }
        Promise.all([
            GH.ajax('gh_v2_operations_list', {}),
            GH.ajax('gh_v2_checks_list', {}),
        ]).then(([opsR, checksR]) => {
            cachedOperations = (opsR && opsR.success) ? (opsR.data.operations || []) : [];
            cachedChecks     = (checksR && checksR.success) ? (checksR.data.checks || []) : [];
            renderOpPicker();
        });
    }

    function renderOpPicker() {
        const sel = document.getElementById('wf-op-picker');
        if (!sel) return;
        if (!cachedOperations || cachedOperations.length === 0) {
            sel.innerHTML = '<option value="">— Nessuna operation registrata —</option>';
            return;
        }
        sel.innerHTML =
            '<option value="">— Aggiungi operation —</option>' +
            cachedOperations.map(op => {
                const tag = op.is_import_rule ? ' [import]' : '';
                return `<option value="${esc(op.id)}">${esc(op.label)}${tag}</option>`;
            }).join('');
    }

    function loadPipelineList() {
        GH.ajax('gh_v2_pipeline_list', {}).then(r => {
            const sel = document.getElementById('wf-pipeline-load');
            if (!sel) return;
            const items = (r && r.success) ? (r.data.pipelines || []) : [];
            if (items.length === 0) {
                sel.innerHTML = '<option value="">— Nessun recipe salvato —</option>';
                return;
            }
            sel.innerHTML =
                '<option value="">— Carica recipe salvato —</option>' +
                items.map(p => `<option value="${esc(p.id)}">${esc(p.name)} (${p.step_count})</option>`).join('');
        });
    }

    function loadPipelineById(id) {
        if (!id) return;
        GH.ajax('gh_v2_pipeline_load', { id }).then(r => {
            if (!r || !r.success) {
                GH.toast('Errore caricamento recipe', 'err');
                return;
            }
            const p = r.data.pipeline || {};
            state.pipeline.id    = p.id    || '';
            state.pipeline.name  = p.name  || '';
            state.pipeline.steps = (p.steps || []).map(s => ({
                kind:   s.kind,
                ref_id: s.ref_id,
                params: s.params || {},
                note:   s.note ?? null,
            }));
            document.getElementById('wf-pipeline-id').value   = state.pipeline.id;
            document.getElementById('wf-pipeline-name').value = state.pipeline.name;
            renderSteps();
        });
    }

    function addOperationStep(opId) {
        if (!opId) return;
        const op = (cachedOperations || []).find(o => o.id === opId);
        if (!op) return;
        // Initialize params with schema defaults.
        const params = {};
        Object.entries(op.params_schema || {}).forEach(([field, spec]) => {
            if (spec.default !== undefined) params[field] = spec.default;
            else if (spec.type === 'bool') params[field] = false;
            else params[field] = '';
        });
        state.pipeline.steps.push({
            kind:   op.is_import_rule ? 'import_rule' : 'operation',
            ref_id: opId,
            params,
            note:   null,
        });
        renderSteps();
    }

    function removeStep(idx) {
        state.pipeline.steps.splice(idx, 1);
        renderSteps();
    }

    function moveStep(idx, delta) {
        const newIdx = idx + delta;
        if (newIdx < 0 || newIdx >= state.pipeline.steps.length) return;
        const [moved] = state.pipeline.steps.splice(idx, 1);
        state.pipeline.steps.splice(newIdx, 0, moved);
        renderSteps();
    }

    function renderSteps() {
        updateRunSummary();
        const wrap  = document.getElementById('wf-steps');
        const empty = document.getElementById('wf-steps-empty');
        if (!wrap || !empty) return;

        const steps = state.pipeline.steps;
        if (steps.length === 0) {
            // Hide all child step rows by re-rendering with the empty marker.
            wrap.innerHTML = '';
            wrap.appendChild(empty);
            empty.hidden = false;
            return;
        }
        empty.hidden = true;

        // Resolve metadata per step (label + paramsSchema) from the
        // appropriate registry. Unknown refIds render as a red error
        // row that can still be removed.
        const html = steps.map((step, idx) => renderStepRow(step, idx, steps.length)).join('');
        wrap.innerHTML = html;

        // Wire per-row controls.
        wrap.querySelectorAll('[data-step-action]').forEach(btn => {
            btn.addEventListener('click', () => {
                const i = parseInt(btn.getAttribute('data-step-idx'), 10);
                const a = btn.getAttribute('data-step-action');
                if      (a === 'remove') removeStep(i);
                else if (a === 'up')     moveStep(i, -1);
                else if (a === 'down')   moveStep(i,  1);
            });
        });
        wrap.querySelectorAll('[data-step-param]').forEach(input => {
            input.addEventListener('input', () => {
                const i     = parseInt(input.getAttribute('data-step-idx'), 10);
                const field = input.getAttribute('data-step-param');
                const v     = input.type === 'checkbox' ? !!input.checked : input.value;
                state.pipeline.steps[i].params[field] = v;
            });
        });
    }

    function renderStepRow(step, idx, total) {
        const lookup = step.kind === 'check'
            ? (cachedChecks || []).find(c => c.id === step.ref_id)
            : (cachedOperations || []).find(o => o.id === step.ref_id);

        const knownLabel = lookup ? lookup.label : `[unknown] ${step.ref_id}`;
        const schema     = lookup ? (lookup.params_schema || {}) : {};
        const tag        = step.kind === 'import_rule' ? '<span class="gh-status gh-status--info">import</span>'
                         : step.kind === 'check'       ? '<span class="gh-status gh-status--warn">check</span>'
                         :                                '<span class="gh-status gh-status--dim">op</span>';

        const paramsHtml = Object.entries(schema).map(([field, spec]) => {
            const id = `wf-step-${idx}-${field.replace(/[^a-z0-9_-]/gi, '_')}`;
            const label = esc(spec.label || field);
            const cur = step.params[field] ?? '';
            let input;
            switch (spec.type) {
                case 'enum': {
                    input = `<select id="${id}" data-step-idx="${idx}" data-step-param="${esc(field)}" class="form-input">` +
                        (spec.options || []).map(o => {
                            const sel = String(cur) === String(o) ? ' selected' : '';
                            return `<option value="${esc(o)}"${sel}>${esc(o)}</option>`;
                        }).join('') + `</select>`;
                    break;
                }
                case 'int':
                    input = `<input type="number" id="${id}" data-step-idx="${idx}" data-step-param="${esc(field)}" class="form-input" value="${esc(cur)}">`;
                    break;
                case 'bool':
                    input = `<label style="display:flex;align-items:center;gap:.4rem">
                                <input type="checkbox" id="${id}" data-step-idx="${idx}" data-step-param="${esc(field)}"${cur ? ' checked' : ''}>
                                <span style="font-size:.8rem;opacity:.75">${label}</span>
                             </label>`;
                    return `<div class="form-row" style="flex:1;min-width:160px">${input}</div>`;
                default:
                    input = `<input type="text" id="${id}" data-step-idx="${idx}" data-step-param="${esc(field)}" class="form-input" value="${esc(cur)}">`;
            }
            return `
                <div class="form-row" style="flex:1;min-width:160px">
                    <label class="form-label" for="${id}" style="display:block;font-size:.7rem;opacity:.6;margin-bottom:.15rem">${label}</label>
                    ${input}
                </div>
            `;
        }).join('');

        const upDisabled   = idx === 0 ? 'disabled' : '';
        const downDisabled = idx >= total - 1 ? 'disabled' : '';

        return `
            <div style="border:1px solid var(--bd,#2a2d33);border-radius:6px;padding:.6rem .75rem;background:var(--surface,#111317)">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem">
                    <span style="font-size:.7rem;opacity:.5;width:1.5rem">${idx + 1}.</span>
                    ${tag}
                    <strong style="font-size:.85rem">${esc(knownLabel)}</strong>
                    <code style="font-size:.7rem;opacity:.5;margin-left:auto">${esc(step.ref_id)}</code>
                    <button type="button" class="button" data-step-action="up"     data-step-idx="${idx}" ${upDisabled}   title="Sposta su">&uarr;</button>
                    <button type="button" class="button" data-step-action="down"   data-step-idx="${idx}" ${downDisabled} title="Sposta giu">&darr;</button>
                    <button type="button" class="button" data-step-action="remove" data-step-idx="${idx}" title="Rimuovi" style="color:var(--err,#e55)">&times;</button>
                </div>
                ${paramsHtml ? `<div style="display:flex;gap:.5rem;flex-wrap:wrap">${paramsHtml}</div>` : ''}
            </div>
        `;
    }

    function savePipeline() {
        const nameEl = document.getElementById('wf-pipeline-name');
        const idEl   = document.getElementById('wf-pipeline-id');
        const name   = (nameEl.value || '').trim();
        if (name === '') { GH.toast('Inserisci un nome', 'err'); nameEl.focus(); return; }
        if (state.pipeline.steps.length === 0) { GH.toast('Pipeline vuota', 'err'); return; }

        state.pipeline.name = name;
        GH.ajax('gh_v2_pipeline_save', {
            id:    idEl.value || '',
            name,
            steps: JSON.stringify(state.pipeline.steps.map(s => ({
                kind:   s.kind,
                ref_id: s.ref_id,
                params: s.params,
                note:   s.note ?? null,
            }))),
        }).then(r => {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) || 'Errore salvataggio';
                GH.toast(msg, 'err');
                return;
            }
            state.pipeline.id = r.data.id;
            idEl.value = r.data.id;
            const stamp = document.getElementById('wf-pipeline-saved');
            stamp.textContent = '✓ salvato ' + new Date().toLocaleTimeString();
            GH.toast('Recipe salvato', 'ok');
            loadPipelineList();
        });
    }


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

        // Pipeline builder wiring.
        document.getElementById('wf-add-op').addEventListener('click', () => {
            const sel = document.getElementById('wf-op-picker');
            addOperationStep(sel.value);
            sel.value = '';
        });
        document.getElementById('wf-pipeline-save').addEventListener('click', savePipeline);
        document.getElementById('wf-pipeline-load').addEventListener('change', (e) => {
            if (e.target.value) loadPipelineById(e.target.value);
        });

        // Credentials save.
        document.getElementById('wf-creds-save').addEventListener('click', saveCredentials);

        // Run buttons.
        document.getElementById('wf-run-dry').addEventListener('click', () => postRun('dry_run'));
        document.getElementById('wf-run-now').addEventListener('click', () => postRun('now'));
        document.getElementById('wf-run-sched').addEventListener('click', openSchedulePanel);
        document.getElementById('wf-run-sched-cancel').addEventListener('click', closeSchedulePanel);
        document.getElementById('wf-run-sched-confirm').addEventListener('click', () => {
            const preset = selectedCronPreset();
            const custom = document.getElementById('wf-cron-custom').value || '';
            if (preset === 'custom' && custom.trim() === '') {
                GH.toast('Inserisci un cron custom', 'err');
                return;
            }
            postRun('schedule', { schedule_preset: preset, custom_cron: custom });
            closeSchedulePanel();
        });
        // Toggle the custom cron input visibility on radio change.
        document.querySelectorAll('input[name="wf-cron-preset"]').forEach(r => {
            r.addEventListener('change', () => {
                document.getElementById('wf-cron-custom-row').hidden =
                    (selectedCronPreset() !== 'custom');
            });
        });
    }

    // Hook into workflowInit so wiring happens once the panel exists in DOM.
    const _origInit = GH.workflowInit;
    GH.workflowInit = function () {
        _origInit && _origInit();
        wireOnce();
    };
})();
