/**
 * Hive Sync — admin UI controller.
 *
 * Vanilla JS, single namespace HSync. Reads HSyncBoot (localized by
 * includes/assets.php) for ajaxUrl + nonce. No jQuery, no React.
 */
(function () {
    'use strict';

    if (typeof window.HSyncBoot === 'undefined') {
        console.error('Hive Sync: HSyncBoot missing — bootstrap failed.');
        return;
    }

    const HSync = {
        state: {
            sources: [],
            mappings: [],
            sourceConfigs: [],
            pipelines: [],
            rules: [],
            jobs: [],
            registry: { operations: [], checks: [] },
            currentTab: 'sources',
            editingMapping: null,
            editingPipeline: null,
            editingRule: null,
            editingJob: null,
        },
    };
    window.HSync = HSync;

    // ─── AJAX ─────────────────────────────────────────────────────

    HSync.ajax = async function (action, data) {
        const body = new URLSearchParams();
        body.set('action', 'hsync_ajax_' + action);
        body.set('nonce', HSyncBoot.nonce);
        Object.entries(data || {}).forEach(([k, v]) => {
            if (v === undefined || v === null) return;
            body.set(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
        });
        const resp = await fetch(HSyncBoot.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const json = await resp.json();
        if (!json.success) {
            const msg = (json.data && json.data.message) || 'AJAX error';
            throw new Error(msg);
        }
        return json.data;
    };

    // ─── DOM helpers ──────────────────────────────────────────────

    const $  = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    // ─── Tabs ─────────────────────────────────────────────────────

    HSync.switchTab = function (name) {
        HSync.state.currentTab = name;
        $$('.hsync-tab').forEach(b => {
            const on = b.dataset.tab === name;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        $$('.hsync-panel').forEach(p => {
            p.classList.toggle('is-active', p.dataset.panel === name);
        });
        if (name === 'mappings')  HSync.loadMappings();
        if (name === 'runs')      HSync.loadRuns();
        if (name === 'pipelines') HSync.loadPipelines();
        if (name === 'rules')     HSync.loadRules();
        if (name === 'jobs')      HSync.loadJobs();
    };

    HSync.loadRegistry = async function () {
        if (HSync.state.registry.operations.length || HSync.state.registry.checks.length) return;
        try {
            const data = await HSync.ajax('registry_list', {});
            HSync.state.registry = { operations: data.operations || [], checks: data.checks || [] };
        } catch (e) { console.warn('registry_list:', e.message); }
    };

    // ─── Pipelines ────────────────────────────────────────────────

    HSync.loadPipelines = async function () {
        const region = $('[data-region="pipelines-list"]');
        try {
            await HSync.loadRegistry();
            const data = await HSync.ajax('pipelines_list', {});
            HSync.state.pipelines = data.pipelines || [];
            HSync.renderPipelinesList();
        } catch (e) {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.renderPipelinesList = function () {
        const region = $('[data-region="pipelines-list"]');
        if (!HSync.state.pipelines.length) {
            region.innerHTML = '<div class="hsync-empty">Nessuna pipeline salvata.</div>';
            return;
        }
        region.innerHTML = HSync.state.pipelines.map(p => ''
            + '<div class="hsync-mapping-row">'
            +   '<div class="hsync-row-main">'
            +     '<div class="hsync-row-name">' + esc(p.name) + '</div>'
            +     '<div class="hsync-row-meta"><code>' + esc(p.slug) + '</code> · ' + (p.steps || []).length + ' step</div>'
            +   '</div>'
            +   '<button class="button" data-action="pipeline-edit" data-slug="' + esc(p.slug) + '">Modifica</button>'
            +   '<button class="button" data-action="pipeline-delete" data-slug="' + esc(p.slug) + '">Elimina</button>'
            + '</div>'
        ).join('');
    };

    HSync.openPipelineEditor = function (pipeline) {
        HSync.state.editingPipeline = pipeline ? JSON.parse(JSON.stringify(pipeline)) : { slug: '', name: '', steps: [] };
        $('[data-region="pipelines-list"]').classList.add('is-hidden');
        $('[data-region="pipeline-editor"]').classList.remove('is-hidden');
        HSync.renderPipelineEditor();
    };

    HSync.closePipelineEditor = function () {
        HSync.state.editingPipeline = null;
        $('[data-region="pipeline-editor"]').classList.add('is-hidden');
        $('[data-region="pipelines-list"]').classList.remove('is-hidden');
    };

    HSync.renderPipelineEditor = function () {
        const p = HSync.state.editingPipeline;
        const root = $('[data-region="pipeline-editor"]');
        const opts  = HSync.state.registry.operations.map(o =>
            '<option value="' + esc(o.id) + '">' + esc(o.label) + ' (' + esc(o.id) + ')</option>'
        ).join('');
        const chkOpts = HSync.state.registry.checks.map(c =>
            '<option value="' + esc(c.id) + '">' + esc(c.label) + ' (' + esc(c.id) + ')</option>'
        ).join('');
        const stepsHtml = (p.steps || []).map((s, i) => HSync.renderPipelineStep(s, i)).join('');
        root.innerHTML = ''
            + '<h2>' + (p.slug ? 'Modifica pipeline' : 'Nuova pipeline') + '</h2>'
            + '<label>Nome <input type="text" data-field="pipeline-name" value="' + esc(p.name) + '"></label>'
            + '<div class="hsync-pipeline-steps">' + (stepsHtml || '<p class="hsync-muted">Nessuno step. Usa i pulsanti qui sotto per aggiungere.</p>') + '</div>'
            + '<div class="hsync-actions">'
            +   '<select data-field="pipeline-add-op-id"><option value="">Operazione…</option>' + opts + '</select>'
            +   '<button class="button" data-action="pipeline-add-op">+ Operation</button>'
            +   '<select data-field="pipeline-add-chk-id"><option value="">Check…</option>' + chkOpts + '</select>'
            +   '<button class="button" data-action="pipeline-add-chk">+ Check</button>'
            + '</div>'
            + '<div class="hsync-actions" style="margin-top:24px;border-top:1px solid #ccd0d4;padding-top:16px;">'
            +   '<button class="button button-primary" data-action="pipeline-save">Salva</button>'
            +   '<button class="button" data-action="pipeline-cancel">Annulla</button>'
            + '</div>';
    };

    HSync.renderPipelineStep = function (step, idx) {
        const reg = step.kind === 'check' ? HSync.state.registry.checks : HSync.state.registry.operations;
        const def = reg.find(r => r.id === step.ref_id);
        const schema = def ? def.params_schema : {};
        const kindLabel = step.kind === 'check' ? 'Check' : 'Operation';
        const fields = Object.entries(schema || {}).map(([f, spec]) => {
            const id = 'pl-step-' + idx + '-' + f;
            const val = step.params && step.params[f] !== undefined ? step.params[f] : (spec.default !== undefined ? spec.default : '');
            let input;
            if (spec.type === 'enum') {
                input = '<select data-step-idx="' + idx + '" data-step-field="' + esc(f) + '">'
                    + (spec.options || []).map(o => '<option value="' + esc(o) + '"' + (val === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('')
                    + '</select>';
            } else if (spec.type === 'bool') {
                input = '<input type="checkbox" data-step-idx="' + idx + '" data-step-field="' + esc(f) + '"' + (val ? ' checked' : '') + '>';
            } else if (spec.type === 'int') {
                input = '<input type="number" data-step-idx="' + idx + '" data-step-field="' + esc(f) + '" value="' + esc(val) + '">';
            } else {
                input = '<input type="text" data-step-idx="' + idx + '" data-step-field="' + esc(f) + '" value="' + esc(val) + '">';
            }
            return '<label style="display:inline-grid;gap:2px;margin-right:12px;font-size:12px;">' + esc(spec.label || f) + input + '</label>';
        }).join('');
        return ''
            + '<div class="hsync-mapping-row">'
            +   '<div class="hsync-row-main">'
            +     '<div class="hsync-row-name"><span class="hsync-action-pill is-' + (step.kind === 'check' ? 'updated' : 'created') + '">' + kindLabel + '</span> <code>' + esc(step.ref_id) + '</code>' + (def ? ' — ' + esc(def.label) : ' <em>(sconosciuto)</em>') + '</div>'
            +     '<div class="hsync-row-meta">' + (fields || '<em>nessun parametro</em>') + '</div>'
            +   '</div>'
            +   '<button class="button" data-action="step-up" data-idx="' + idx + '">↑</button>'
            +   '<button class="button" data-action="step-down" data-idx="' + idx + '">↓</button>'
            +   '<button class="button" data-action="step-delete" data-idx="' + idx + '">✕</button>'
            + '</div>';
    };

    HSync.collectPipelineSteps = function () {
        const p = HSync.state.editingPipeline;
        if (!p || !p.steps) return;
        $$('[data-step-idx]').forEach(el => {
            const idx = parseInt(el.dataset.stepIdx, 10);
            const f   = el.dataset.stepField;
            if (!p.steps[idx]) return;
            p.steps[idx].params = p.steps[idx].params || {};
            if (el.type === 'checkbox') p.steps[idx].params[f] = el.checked;
            else if (el.value !== '')   p.steps[idx].params[f] = el.value;
            else                        delete p.steps[idx].params[f];
        });
    };

    HSync.addPipelineStep = function (kind) {
        const sel = $(kind === 'check' ? '[data-field="pipeline-add-chk-id"]' : '[data-field="pipeline-add-op-id"]');
        const refId = sel ? sel.value : '';
        if (!refId) return;
        HSync.collectPipelineSteps();
        HSync.state.editingPipeline.steps.push({ kind: kind, ref_id: refId, params: {} });
        HSync.renderPipelineEditor();
    };

    HSync.movePipelineStep = function (idx, dir) {
        HSync.collectPipelineSteps();
        const steps = HSync.state.editingPipeline.steps;
        const target = idx + dir;
        if (target < 0 || target >= steps.length) return;
        [steps[idx], steps[target]] = [steps[target], steps[idx]];
        HSync.renderPipelineEditor();
    };

    HSync.deletePipelineStep = function (idx) {
        HSync.collectPipelineSteps();
        HSync.state.editingPipeline.steps.splice(idx, 1);
        HSync.renderPipelineEditor();
    };

    HSync.savePipeline = async function () {
        HSync.collectPipelineSteps();
        const p = HSync.state.editingPipeline;
        const name = $('[data-field="pipeline-name"]').value;
        if (!name) { alert('Nome richiesto.'); return; }
        try {
            await HSync.ajax('pipeline_save', { slug: p.slug || '', name: name, steps: p.steps });
            HSync.closePipelineEditor();
            HSync.loadPipelines();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.deletePipeline = async function (slug) {
        if (!confirm('Eliminare questa pipeline?')) return;
        try {
            await HSync.ajax('pipeline_delete', { slug: slug });
            HSync.loadPipelines();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    // ─── Rules (scoped pipelines) ─────────────────────────────────

    HSync.loadRules = async function () {
        const region = $('[data-region="rules-list"]');
        try {
            await HSync.loadRegistry();
            const data = await HSync.ajax('rules_list', {});
            HSync.state.rules = data.rules || [];
            HSync.renderRulesList();
        } catch (e) {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.renderRulesList = function () {
        const region = $('[data-region="rules-list"]');
        if (!HSync.state.rules.length) {
            region.innerHTML = '<div class="hsync-empty">Nessuna rule salvata.</div>';
            return;
        }
        region.innerHTML = HSync.state.rules.map(r => ''
            + '<div class="hsync-mapping-row">'
            +   '<div class="hsync-row-main">'
            +     '<div class="hsync-row-name">'
            +       esc(r.name)
            +       (r.enabled ? ' <span class="hsync-action-pill is-created">on</span>' : ' <span class="hsync-action-pill is-skipped">off</span>')
            +     '</div>'
            +     '<div class="hsync-row-meta"><code>' + esc(r.slug) + '</code> · '
            +       (r.operations || []).length + ' op · '
            +       (r.checks     || []).length + ' check</div>'
            +   '</div>'
            +   '<button class="button" data-action="rule-edit" data-slug="' + esc(r.slug) + '">Modifica</button>'
            +   '<button class="button" data-action="rule-delete" data-slug="' + esc(r.slug) + '">Elimina</button>'
            + '</div>'
        ).join('');
    };

    HSync.openRuleEditor = function (rule) {
        HSync.state.editingRule = rule
            ? JSON.parse(JSON.stringify(rule))
            : { slug: '', name: '', selection: { mode: 'all', filter: {} }, operations: [], checks: [], enabled: false };
        $('[data-region="rules-list"]').classList.add('is-hidden');
        $('[data-region="rule-editor"]').classList.remove('is-hidden');
        HSync.renderRuleEditor();
    };

    HSync.closeRuleEditor = function () {
        HSync.state.editingRule = null;
        $('[data-region="rule-editor"]').classList.add('is-hidden');
        $('[data-region="rules-list"]').classList.remove('is-hidden');
    };

    HSync.renderRuleEditor = function () {
        const r = HSync.state.editingRule;
        const root = $('[data-region="rule-editor"]');
        const opts = HSync.state.registry.operations.map(o =>
            '<option value="' + esc(o.id) + '">' + esc(o.label) + '</option>'
        ).join('');
        const chkOpts = HSync.state.registry.checks.map(c =>
            '<option value="' + esc(c.id) + '">' + esc(c.label) + '</option>'
        ).join('');
        const opSteps = (r.operations || []).map((s, i) => HSync.renderRuleStep(s, i, 'op')).join('');
        const chkSteps = (r.checks || []).map((s, i) => HSync.renderRuleStep(s, i, 'chk')).join('');
        const selMode = (r.selection && r.selection.mode) || 'all';
        const selFilter = JSON.stringify((r.selection && r.selection.filter) || {}, null, 2);

        root.innerHTML = ''
            + '<h2>' + (r.slug ? 'Modifica rule' : 'Nuova rule') + '</h2>'
            + '<label>Nome <input type="text" data-field="rule-name" value="' + esc(r.name) + '"></label>'
            + '<label class="hsync-dryrun"><input type="checkbox" data-field="rule-enabled"' + (r.enabled ? ' checked' : '') + '> Abilitata</label>'
            + '<h3>Scope</h3>'
            + '<label>Modalità selezione'
            +   '<select data-field="rule-sel-mode">'
            +     '<option value="all"'    + (selMode === 'all'    ? ' selected' : '') + '>Tutti i prodotti</option>'
            +     '<option value="filter"' + (selMode === 'filter' ? ' selected' : '') + '>Filtro condizionale</option>'
            +     '<option value="ids"'    + (selMode === 'ids'    ? ' selected' : '') + '>Lista ID (manuale)</option>'
            +   '</select>'
            + '</label>'
            + '<label>Filtro / IDs (JSON)'
            +   '<textarea data-field="rule-sel-payload" rows="4" style="font-family:monospace;font-size:12px;">' + esc(selFilter) + '</textarea>'
            + '</label>'
            + '<h3>Operazioni (ordinate)</h3>'
            + '<div class="hsync-pipeline-steps">' + (opSteps || '<p class="hsync-muted">Nessuna operazione.</p>') + '</div>'
            + '<div class="hsync-actions">'
            +   '<select data-field="rule-add-op-id"><option value="">Operazione…</option>' + opts + '</select>'
            +   '<button class="button" data-action="rule-add-op">+ Operation</button>'
            + '</div>'
            + '<h3>Check</h3>'
            + '<div class="hsync-pipeline-steps">' + (chkSteps || '<p class="hsync-muted">Nessun check.</p>') + '</div>'
            + '<div class="hsync-actions">'
            +   '<select data-field="rule-add-chk-id"><option value="">Check…</option>' + chkOpts + '</select>'
            +   '<button class="button" data-action="rule-add-chk">+ Check</button>'
            + '</div>'
            + '<div class="hsync-actions" style="margin-top:24px;border-top:1px solid #ccd0d4;padding-top:16px;">'
            +   '<button class="button button-primary" data-action="rule-save">Salva</button>'
            +   '<button class="button" data-action="rule-cancel">Annulla</button>'
            + '</div>';
    };

    HSync.renderRuleStep = function (step, idx, kindKey) {
        const reg = kindKey === 'chk' ? HSync.state.registry.checks : HSync.state.registry.operations;
        const def = reg.find(r => r.id === step.ref_id);
        const schema = def ? def.params_schema : {};
        const fields = Object.entries(schema || {}).map(([f, spec]) => {
            const val = step.params && step.params[f] !== undefined ? step.params[f] : (spec.default !== undefined ? spec.default : '');
            const dataAttrs = 'data-rule-step-kind="' + kindKey + '" data-rule-step-idx="' + idx + '" data-rule-step-field="' + esc(f) + '"';
            let input;
            if (spec.type === 'enum') {
                input = '<select ' + dataAttrs + '>'
                    + (spec.options || []).map(o => '<option value="' + esc(o) + '"' + (val === o ? ' selected' : '') + '>' + esc(o) + '</option>').join('')
                    + '</select>';
            } else if (spec.type === 'bool') {
                input = '<input type="checkbox" ' + dataAttrs + (val ? ' checked' : '') + '>';
            } else if (spec.type === 'int') {
                input = '<input type="number" ' + dataAttrs + ' value="' + esc(val) + '">';
            } else {
                input = '<input type="text" ' + dataAttrs + ' value="' + esc(val) + '">';
            }
            return '<label style="display:inline-grid;gap:2px;margin-right:12px;font-size:12px;">' + esc(spec.label || f) + input + '</label>';
        }).join('');
        return ''
            + '<div class="hsync-mapping-row">'
            +   '<div class="hsync-row-main">'
            +     '<div class="hsync-row-name"><code>' + esc(step.ref_id) + '</code>' + (def ? ' — ' + esc(def.label) : ' <em>(sconosciuto)</em>') + '</div>'
            +     '<div class="hsync-row-meta">' + (fields || '<em>nessun parametro</em>') + '</div>'
            +   '</div>'
            +   '<button class="button" data-action="rule-step-up"     data-kind="' + kindKey + '" data-idx="' + idx + '">↑</button>'
            +   '<button class="button" data-action="rule-step-down"   data-kind="' + kindKey + '" data-idx="' + idx + '">↓</button>'
            +   '<button class="button" data-action="rule-step-delete" data-kind="' + kindKey + '" data-idx="' + idx + '">✕</button>'
            + '</div>';
    };

    HSync.collectRuleSteps = function () {
        const r = HSync.state.editingRule;
        if (!r) return;
        $$('[data-rule-step-idx]').forEach(el => {
            const kindKey = el.dataset.ruleStepKind;
            const idx = parseInt(el.dataset.ruleStepIdx, 10);
            const f = el.dataset.ruleStepField;
            const arr = kindKey === 'chk' ? r.checks : r.operations;
            if (!arr[idx]) return;
            arr[idx].params = arr[idx].params || {};
            if (el.type === 'checkbox') arr[idx].params[f] = el.checked;
            else if (el.value !== '')   arr[idx].params[f] = el.value;
            else                        delete arr[idx].params[f];
        });
    };

    HSync.addRuleStep = function (kindKey) {
        const r = HSync.state.editingRule;
        const sel = $(kindKey === 'chk' ? '[data-field="rule-add-chk-id"]' : '[data-field="rule-add-op-id"]');
        const refId = sel ? sel.value : '';
        if (!refId) return;
        HSync.collectRuleSteps();
        const target = kindKey === 'chk' ? r.checks : r.operations;
        target.push({ ref_id: refId, params: {} });
        HSync.renderRuleEditor();
    };

    HSync.moveRuleStep = function (kindKey, idx, dir) {
        HSync.collectRuleSteps();
        const r = HSync.state.editingRule;
        const arr = kindKey === 'chk' ? r.checks : r.operations;
        const t = idx + dir;
        if (t < 0 || t >= arr.length) return;
        [arr[idx], arr[t]] = [arr[t], arr[idx]];
        HSync.renderRuleEditor();
    };

    HSync.deleteRuleStep = function (kindKey, idx) {
        HSync.collectRuleSteps();
        const r = HSync.state.editingRule;
        const arr = kindKey === 'chk' ? r.checks : r.operations;
        arr.splice(idx, 1);
        HSync.renderRuleEditor();
    };

    HSync.saveRule = async function () {
        HSync.collectRuleSteps();
        const r = HSync.state.editingRule;
        r.name    = $('[data-field="rule-name"]').value;
        r.enabled = $('[data-field="rule-enabled"]').checked;
        const mode = $('[data-field="rule-sel-mode"]').value;
        let payload = {};
        try { payload = JSON.parse($('[data-field="rule-sel-payload"]').value || '{}'); }
        catch (e) { alert('JSON selezione non valido: ' + e.message); return; }
        r.selection = { mode: mode, filter: mode === 'filter' ? payload : {}, ids: mode === 'ids' ? (payload.ids || []) : [] };
        if (!r.name) { alert('Nome richiesto.'); return; }
        try {
            await HSync.ajax('rule_save', {
                slug:       r.slug || '',
                name:       r.name,
                selection:  r.selection,
                operations: r.operations,
                checks:     r.checks,
                enabled:    r.enabled ? '1' : '0',
            });
            HSync.closeRuleEditor();
            HSync.loadRules();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.deleteRule = async function (slug) {
        if (!confirm('Eliminare questa rule?')) return;
        try {
            await HSync.ajax('rule_delete', { slug: slug });
            HSync.loadRules();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    // ─── Jobs ─────────────────────────────────────────────────────

    HSync.loadJobs = async function () {
        const region = $('[data-region="jobs-list"]');
        try {
            // Load rules + sources + configs so the editor's pickers populate.
            await HSync.loadRegistry();
            if (!HSync.state.rules.length) {
                try {
                    const r = await HSync.ajax('rules_list', {});
                    HSync.state.rules = r.rules || [];
                } catch {}
            }
            const data = await HSync.ajax('jobs_list', {});
            HSync.state.jobs = data.jobs || [];
            HSync.renderJobsList();
        } catch (e) {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.renderJobsList = function () {
        const region = $('[data-region="jobs-list"]');
        if (!HSync.state.jobs.length) {
            region.innerHTML = '<div class="hsync-empty">Nessun job schedulato.</div>';
            return;
        }
        region.innerHTML = HSync.state.jobs.map(j => ''
            + '<div class="hsync-mapping-row">'
            +   '<div class="hsync-row-main">'
            +     '<div class="hsync-row-name">'
            +       (j.enabled ? '<span class="hsync-action-pill is-created">on</span>' : '<span class="hsync-action-pill is-skipped">off</span>') + ' '
            +       '<code>' + esc(j.runnable_type) + '</code> · <code>' + esc(j.runnable_ref) + '</code>'
            +     '</div>'
            +     '<div class="hsync-row-meta">'
            +       'cron: <code>' + esc(j.cron_expr || '—') + '</code> · '
            +       'next: <code>' + esc(j.next_run_at || '—') + '</code> · '
            +       'last: <code>' + esc(j.last_run_status || '—') + '</code>'
            +     '</div>'
            +   '</div>'
            +   '<button class="button" data-action="job-run-now" data-id="' + j.id + '">Run</button>'
            +   '<button class="button" data-action="job-edit"    data-id="' + j.id + '">Modifica</button>'
            +   '<button class="button" data-action="job-delete"  data-id="' + j.id + '">Elimina</button>'
            + '</div>'
        ).join('');
    };

    HSync.openJobEditor = function (job) {
        HSync.state.editingJob = job
            ? JSON.parse(JSON.stringify(job))
            : { id: 0, runnable_type: 'source.import', runnable_ref: '', cron_expr: '', enabled: false, config: {} };
        $('[data-region="jobs-list"]').classList.add('is-hidden');
        $('[data-region="job-editor"]').classList.remove('is-hidden');
        HSync.renderJobEditor();
    };

    HSync.closeJobEditor = function () {
        HSync.state.editingJob = null;
        $('[data-region="job-editor"]').classList.add('is-hidden');
        $('[data-region="jobs-list"]').classList.remove('is-hidden');
    };

    HSync.renderJobEditor = function () {
        const j = HSync.state.editingJob;
        const root = $('[data-region="job-editor"]');

        // Build ref picker depending on runnable_type.
        const sourceOpts = HSync.state.sources.map(s => '<option value="' + esc(s.id) + '"' + (j.runnable_ref === s.id ? ' selected' : '') + '>' + esc(s.label) + ' (' + esc(s.id) + ')</option>').join('');
        const ruleOpts   = HSync.state.rules.map(r => '<option value="' + esc(r.slug) + '"' + (j.runnable_ref === r.slug ? ' selected' : '') + '>' + esc(r.name) + ' (' + esc(r.slug) + ')</option>').join('');

        const refField = j.runnable_type === 'rule'
            ? '<select data-field="job-ref"><option value="">— scegli rule —</option>' + ruleOpts + '</select>'
            : '<select data-field="job-ref"><option value="">— scegli source —</option>' + sourceOpts + '</select>';

        // Source-specific inline config selector (saved configs)
        let configField = '';
        if (j.runnable_type === 'source.import' && HSync.state.sourceConfigs.length) {
            const filtered = HSync.state.sourceConfigs.filter(c => c.source_kind === j.runnable_ref);
            const cfgOpts  = filtered.map(c => '<option value="' + esc(c.slug) + '">' + esc(c.name) + '</option>').join('');
            const stored   = (j.config && j.config.config_slug) || '';
            const wrapped  = j.runnable_ref ? cfgOpts : '<option value="">— scegli prima un source —</option>';
            configField = '<label>Saved config (optional)<select data-field="job-config-slug"><option value="">— inline —</option>' + wrapped + '</select></label>';
        }

        root.innerHTML = ''
            + '<h2>' + (j.id ? 'Modifica job #' + j.id : 'Nuovo job') + '</h2>'
            + '<label>Tipo'
            +   '<select data-field="job-type">'
            +     '<option value="source.import"' + (j.runnable_type === 'source.import' ? ' selected' : '') + '>source.import</option>'
            +     '<option value="rule"'          + (j.runnable_type === 'rule'          ? ' selected' : '') + '>rule</option>'
            +   '</select>'
            + '</label>'
            + '<label>Riferimento ' + refField + '</label>'
            + configField
            + '<label>Cron expression (5 fields)<input type="text" data-field="job-cron" value="' + esc(j.cron_expr || '') + '" placeholder="*/15 * * * *"></label>'
            + '<label class="hsync-dryrun"><input type="checkbox" data-field="job-enabled"' + (j.enabled ? ' checked' : '') + '> Abilitato</label>'
            + '<div class="hsync-actions" style="margin-top:24px;border-top:1px solid #ccd0d4;padding-top:16px;">'
            +   '<button class="button button-primary" data-action="job-save">Salva</button>'
            +   '<button class="button" data-action="job-cancel">Annulla</button>'
            + '</div>';
    };

    HSync.saveJob = async function () {
        const j = HSync.state.editingJob;
        const data = {
            id:            String(j.id || 0),
            runnable_type: $('[data-field="job-type"]').value,
            runnable_ref:  $('[data-field="job-ref"]').value,
            cron_expr:     $('[data-field="job-cron"]').value,
            enabled:       $('[data-field="job-enabled"]').checked ? '1' : '0',
            config:        {},
        };
        if (!data.runnable_ref) { alert('Scegli un riferimento.'); return; }
        const cfgSlugEl = $('[data-field="job-config-slug"]');
        if (cfgSlugEl && cfgSlugEl.value) data.config = { config_slug: cfgSlugEl.value };
        try {
            await HSync.ajax('job_save', data);
            HSync.closeJobEditor();
            HSync.loadJobs();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.deleteJob = async function (id) {
        if (!confirm('Eliminare questo job?')) return;
        try {
            await HSync.ajax('job_delete', { id: String(id) });
            HSync.loadJobs();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.runJobNow = async function (id) {
        try {
            const data = await HSync.ajax('job_run_now', { id: String(id) });
            alert('Run dispatched: ' + JSON.stringify(data, null, 2).slice(0, 400));
            HSync.loadJobs();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    // ─── Exports ──────────────────────────────────────────────────

    HSync.downloadBlob = function (filename, mime, body) {
        const blob = new Blob([body], { type: mime || 'application/octet-stream' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
    };

    HSync.exportInventory = async function (format) {
        const out = $('[data-region="exports-output"]');
        out.innerHTML = '<p class="hsync-loading">Generating ' + esc(format) + '…</p>';
        try {
            const data = await HSync.ajax('export_inventory', { format: format });
            HSync.downloadBlob(data.filename, data.mime, data.body);
            out.innerHTML = '<p>' + (data.count || 0) + ' prodotti esportati come <code>' + esc(data.filename) + '</code>.</p>';
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.exportCatalog = async function () {
        const out = $('[data-region="exports-output"]');
        out.innerHTML = '<p class="hsync-loading">Generating catalog…</p>';
        try {
            const data = await HSync.ajax('export_catalog', {});
            HSync.downloadBlob(data.filename, data.mime, data.body);
            out.innerHTML = '<p>' + (data.count || 0) + ' gruppi esportati come <code>' + esc(data.filename) + '</code>.</p>';
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    // ─── Action Scheduler health ──────────────────────────────────

    HSync.asHealth = async function () {
        const out = $('[data-region="as-health-output"]');
        out.innerHTML = '<p class="hsync-loading">Reading Action Scheduler state…</p>';
        try {
            const data = await HSync.ajax('as_health', {});
            const stat = (label, num, kind) =>
                '<div class="hsync-stat ' + (kind || '') + '"><div class="hsync-stat-num">' + (num || 0) + '</div><div class="hsync-stat-label">' + label + '</div></div>';
            const cronWarn = data.cron_disabled
                ? '<div class="hsync-warning"><code>DISABLE_WP_CRON</code> attivo. Hive Sync drena la coda ad ogni admin page load (max 1/min). In produzione: configura un cron host che pinghi <code>wp-cron.php</code>.</div>'
                : '';
            out.innerHTML = ''
                + cronWarn
                + '<div class="hsync-summary" style="grid-template-columns:repeat(3,1fr);">'
                +   stat('Pending', data.pending)
                +   stat('Past-due', data.past_due, data.past_due > 100 ? 'is-bad' : '')
                +   stat('Failed', data.failed, data.failed > 0 ? 'is-bad' : '')
                + '</div>'
                + '<div class="hsync-actions">'
                +   '<button class="button" data-action="as-run-queue">Run queue now</button>'
                +   '<button class="button" data-action="as-purge-past-due">Purge past-due (>7gg)</button>'
                + '</div>';
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.asRunQueue = async function () {
        try {
            const data = await HSync.ajax('as_run_queue', {});
            alert('Action Scheduler queue runner triggered (' + data.duration_ms + 'ms).');
            HSync.asHealth();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.asPurgePastDue = async function () {
        if (!confirm('Eliminare definitivamente le pending actions in ritardo da più di 7 giorni? Questa operazione non è reversibile.')) return;
        try {
            const data = await HSync.ajax('as_purge_past_due', {});
            alert('Eliminati ' + (data.deleted || 0) + ' record (azioni + log).');
            HSync.asHealth();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.tickNow = async function () {
        try {
            const data = await HSync.ajax('jobs_tick_now', {});
            alert('Tick: ' + data.dispatched + ' dispatched, ' + data.skipped + ' skipped' + (data.locked ? ' (LOCKED)' : ''));
            HSync.loadJobs();
        } catch (e) { alert('Errore: ' + e.message); }
    };

    // ─── Legacy migration ─────────────────────────────────────────

    HSync.legacyAudit = async function () {
        const out = $('[data-region="legacy-output"]');
        out.innerHTML = '<p class="hsync-loading">Audit…</p>';
        try {
            const data = await HSync.ajax('legacy_audit', {});
            const warns = (data.warnings || []).map(w => '<div class="hsync-warning">' + esc(w) + '</div>').join('');
            out.innerHTML = ''
                + '<div class="hsync-summary" style="grid-template-columns:repeat(3,1fr);">'
                +   '<div class="hsync-stat"><div class="hsync-stat-num">' + (data.pipelines || 0) + '</div><div class="hsync-stat-label">Pipelines</div></div>'
                +   '<div class="hsync-stat"><div class="hsync-stat-num">' + (data.mappings  || 0) + '</div><div class="hsync-stat-label">Mappings</div></div>'
                +   '<div class="hsync-stat"><div class="hsync-stat-num">' + (data.jobs      || 0) + '</div><div class="hsync-stat-label">Jobs</div></div>'
                + '</div>'
                + (warns ? '<div class="hsync-warnings">' + warns + '</div>' : '');
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.legacyImport = async function () {
        if (!confirm('Procedere con l\'import dei dati legacy? L\'operazione è idempotente.')) return;
        const out = $('[data-region="legacy-output"]');
        out.innerHTML = '<p class="hsync-loading">Import in corso…</p>';
        try {
            const data = await HSync.ajax('legacy_import', {});
            const renderBucket = (label, b) => {
                const errs = (b.errors || []).map(e => '<div class="hsync-warning">' + esc(e) + '</div>').join('');
                return ''
                    + '<h4>' + esc(label) + '</h4>'
                    + '<p>'
                    +   '<strong>' + (b.copied || 0) + '</strong> copiati · '
                    +   (b.skipped || 0) + ' saltati (già esistenti)'
                    + '</p>'
                    + errs;
            };
            out.innerHTML = ''
                + '<h3>Import completato</h3>'
                + renderBucket('Pipelines', data.pipelines || {})
                + renderBucket('Mappings',  data.mappings  || {})
                + renderBucket('Jobs',      data.jobs      || {});
            // Refresh whatever tab the user goes to next.
            HSync.state.mappings = [];
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    // ─── Sources ──────────────────────────────────────────────────

    HSync.loadSources = async function () {
        const region = $('[data-region="sources-list"]');
        try {
            const data = await HSync.ajax('sources_list', {});
            HSync.state.sources = data.sources || [];
            HSync.renderSources();
            HSync.populateSourcePickers();
        } catch (e) {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.renderSources = function () {
        const region = $('[data-region="sources-list"]');
        if (!HSync.state.sources.length) {
            region.innerHTML = '<div class="hsync-empty">Nessuna sorgente registrata.</div>';
            return;
        }
        region.innerHTML = HSync.state.sources.map(s => {
            const caps = Object.entries(s.capabilities).map(([k, v]) =>
                '<span class="hsync-cap-pill' + (v ? ' is-on' : '') + '">' + esc(k) + '</span>'
            ).join('');
            const schemaHtml = HSync.renderSchema(s.config_schema, 'src-' + s.id);
            return ''
                + '<div class="hsync-source-card">'
                +   '<h3>' + esc(s.label) + ' <span class="hsync-source-id">' + esc(s.id) + '</span></h3>'
                +   '<div class="hsync-caps">' + caps + '</div>'
                +   '<details>'
                +     '<summary>Config + test fetch</summary>'
                +     '<form class="hsync-config-form" data-source="' + esc(s.id) + '">'
                +       schemaHtml
                +       '<div class="hsync-actions">'
                +         '<button type="button" class="button" data-action="test-fetch" data-source="' + esc(s.id) + '">Test fetch</button>'
                +       '</div>'
                +       '<div data-region="test-fetch-output-' + esc(s.id) + '"></div>'
                +     '</form>'
                +   '</details>'
                + '</div>';
        }).join('');
    };

    HSync.renderSchema = function (schema, idPrefix) {
        return Object.entries(schema || {}).map(([field, spec]) => {
            const type     = spec.type || 'text';
            const label    = spec.label || field;
            const required = spec.required ? '<span class="hsync-required">*</span>' : '';
            const id = idPrefix + '-' + field;
            let input = '';
            if (type === 'enum') {
                const opts = (spec.options || []).map(o =>
                    '<option value="' + esc(o) + '"' + (spec.default === o ? ' selected' : '') + '>' + esc(o) + '</option>'
                ).join('');
                input = '<select id="' + esc(id) + '" name="' + esc(field) + '"><option value="">—</option>' + opts + '</select>';
            } else if (type === 'bool') {
                input = '<input type="checkbox" id="' + esc(id) + '" name="' + esc(field) + '"' + (spec.default ? ' checked' : '') + '>';
            } else if (type === 'int') {
                input = '<input type="number" id="' + esc(id) + '" name="' + esc(field) + '" value="' + esc(spec.default || '') + '">';
            } else if (type === 'secret') {
                input = '<input type="password" id="' + esc(id) + '" name="' + esc(field) + '" autocomplete="new-password" spellcheck="false">';
            } else if (type === 'url') {
                input = '<input type="url" id="' + esc(id) + '" name="' + esc(field) + '" placeholder="https://…">';
            } else {
                input = '<input type="text" id="' + esc(id) + '" name="' + esc(field) + '" value="' + esc(spec.default || '') + '">';
            }
            return '<label for="' + esc(id) + '">' + esc(label) + required + input + '</label>';
        }).join('');
    };

    HSync.collectConfig = function (formEl) {
        const out = {};
        $$('input, select, textarea', formEl).forEach(el => {
            if (!el.name) return;
            if (el.type === 'checkbox') out[el.name] = el.checked;
            else if (el.value !== '')   out[el.name] = el.value;
        });
        return out;
    };

    HSync.testFetch = async function (sourceId, formEl) {
        const config = HSync.collectConfig(formEl);
        const out = $('[data-region="test-fetch-output-' + sourceId + '"]', formEl);
        out.innerHTML = '<p class="hsync-loading">Fetching…</p>';
        try {
            const data = await HSync.ajax('source_test_fetch', {
                source_id: sourceId,
                config: config,
                options: {},
            });
            const warns = (data.warnings || []).map(w =>
                '<div class="hsync-warning">' + esc(w) + '</div>'
            ).join('');
            const rows  = (data.preview || []).map((p, i) =>
                '<tr><td>' + (i + 1) + '</td><td><code>' + esc(p.sku) + '</code></td><td>' + esc(JSON.stringify(p.data).slice(0, 200)) + '</td></tr>'
            ).join('');
            out.innerHTML = ''
                + '<p><strong>' + data.count + '</strong> items fetched.</p>'
                + (warns ? '<div class="hsync-warnings">' + warns + '</div>' : '')
                + (rows
                    ? '<table class="hsync-table"><thead><tr><th>#</th><th>SKU</th><th>Data (preview)</th></tr></thead><tbody>' + rows + '</tbody></table>'
                    : '');
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.populateSourcePickers = function () {
        const opts = HSync.state.sources.map(s =>
            '<option value="' + esc(s.id) + '">' + esc(s.label) + '</option>'
        ).join('');
        const blank = '<option value="">— scegli sorgente —</option>';
        const filt  = $('[data-control="mappings-filter"]');
        if (filt) filt.innerHTML = '<option value="">Tutte le sorgenti</option>' + opts;
        const mapSrc = $('[data-field="map-source"]');
        if (mapSrc) mapSrc.innerHTML = blank + opts;
        const runSrc = $('[data-field="run-source"]');
        if (runSrc) {
            runSrc.innerHTML = blank + opts;
            runSrc.addEventListener('change', HSync.onRunSourceChange);
        }
    };

    // ─── Mappings ─────────────────────────────────────────────────

    HSync.loadMappings = async function () {
        const region = $('[data-region="mappings-list"]');
        const filter = $('[data-control="mappings-filter"]').value;
        try {
            const data = await HSync.ajax('mappings_list', { source_kind: filter });
            HSync.state.mappings = data.mappings || [];
            HSync.renderMappings();
            HSync.populateRunMappings();
        } catch (e) {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.renderMappings = function () {
        const region = $('[data-region="mappings-list"]');
        if (!HSync.state.mappings.length) {
            region.innerHTML = '<div class="hsync-empty">Nessuna mapping salvata.</div>';
            return;
        }
        region.innerHTML = HSync.state.mappings.map(m => ''
            + '<div class="hsync-mapping-row">'
            +   '<div class="hsync-row-main">'
            +     '<div class="hsync-row-name">' + esc(m.name) + '</div>'
            +     '<div class="hsync-row-meta">'
            +       esc(m.source_kind) + ' · <code>' + esc(m.slug) + '</code> · '
            +       Object.keys(m.config || {}).length + ' campi mappati'
            +     '</div>'
            +   '</div>'
            +   '<button class="button" data-action="mapping-edit" data-slug="' + esc(m.slug) + '">Modifica</button>'
            +   '<button class="button" data-action="mapping-delete" data-slug="' + esc(m.slug) + '">Elimina</button>'
            + '</div>'
        ).join('');
    };

    HSync.openMappingEditor = function (mapping) {
        HSync.state.editingMapping = mapping || null;
        $('[data-region="mapping-editor"]').classList.remove('is-hidden');
        $('[data-field="map-name"]').value   = mapping ? mapping.name : '';
        $('[data-field="map-source"]').value = mapping ? mapping.source_kind : '';
        $('[data-field="map-config"]').value = mapping ? JSON.stringify(mapping.config || {}, null, 2) : '';
    };

    HSync.closeMappingEditor = function () {
        HSync.state.editingMapping = null;
        $('[data-region="mapping-editor"]').classList.add('is-hidden');
    };

    HSync.saveMapping = async function () {
        let cfg;
        try {
            cfg = JSON.parse($('[data-field="map-config"]').value || '{}');
        } catch (e) {
            alert('JSON config invalido: ' + e.message);
            return;
        }
        try {
            await HSync.ajax('mappings_save', {
                slug:        HSync.state.editingMapping ? HSync.state.editingMapping.slug : '',
                name:        $('[data-field="map-name"]').value,
                source_kind: $('[data-field="map-source"]').value,
                config:      cfg,
            });
            HSync.closeMappingEditor();
            HSync.loadMappings();
        } catch (e) {
            alert('Errore salvataggio: ' + e.message);
        }
    };

    HSync.deleteMapping = async function (slug) {
        if (!confirm('Eliminare questa mapping?')) return;
        try {
            await HSync.ajax('mappings_delete', { slug: slug });
            HSync.loadMappings();
        } catch (e) {
            alert('Errore eliminazione: ' + e.message);
        }
    };

    HSync.populateRunMappings = function () {
        const sel = $('[data-field="run-mapping"]');
        if (!sel) return;
        const sourceId = $('[data-field="run-source"]').value;
        const filtered = sourceId
            ? HSync.state.mappings.filter(m => m.source_kind === sourceId)
            : HSync.state.mappings;
        sel.innerHTML = '<option value="">— nessuna —</option>'
            + filtered.map(m => '<option value="' + esc(m.slug) + '">' + esc(m.name) + '</option>').join('');
    };

    // ─── Run ──────────────────────────────────────────────────────

    HSync.onRunSourceChange = function () {
        const sourceId = $('[data-field="run-source"]').value;
        const region   = $('[data-region="run-config-fields"]');
        if (!sourceId) {
            region.innerHTML = '';
            HSync.populateRunConfigPicker();
            return;
        }
        const src = HSync.state.sources.find(s => s.id === sourceId);
        if (!src) return;
        region.innerHTML = '<form class="hsync-config-form" data-source="' + esc(src.id) + '" data-context="run">'
            + HSync.renderSchema(src.config_schema, 'run-' + src.id)
            + '</form>';
        if (HSync.state.mappings.length === 0) HSync.loadMappings();
        else HSync.populateRunMappings();
        HSync.loadSourceConfigs(sourceId);
    };

    HSync.loadSourceConfigs = async function (sourceId) {
        try {
            const data = await HSync.ajax('source_configs_list', { source_kind: sourceId || '' });
            HSync.state.sourceConfigs = data.configs || [];
            HSync.populateRunConfigPicker();
        } catch (e) {
            console.warn('Hive Sync: source_configs_list failed —', e.message);
        }
    };

    HSync.populateRunConfigPicker = function () {
        const sel = $('[data-field="run-config-slug"]');
        if (!sel) return;
        const sourceId = $('[data-field="run-source"]').value;
        const filtered = sourceId
            ? HSync.state.sourceConfigs.filter(c => c.source_kind === sourceId)
            : [];
        sel.innerHTML = '<option value="">— inline —</option>'
            + filtered.map(c => '<option value="' + esc(c.slug) + '">' + esc(c.name) + '</option>').join('');
        sel.addEventListener('change', HSync.onRunConfigSlugChange, { once: true });
    };

    HSync.onRunConfigSlugChange = function () {
        const slug = $('[data-field="run-config-slug"]').value;
        const cfg  = slug ? (HSync.state.sourceConfigs.find(c => c.slug === slug)?.config || {}) : null;
        const form = $('[data-context="run"]');
        if (!form) return;
        if (!cfg) return;
        // Hydrate visible fields with stored values (secrets stay redacted).
        $$('input, select, textarea', form).forEach(el => {
            if (!el.name) return;
            if (Object.prototype.hasOwnProperty.call(cfg, el.name)) {
                if (el.type === 'checkbox') el.checked = !!cfg[el.name];
                else if (el.type === 'password' && /^•+/.test(String(cfg[el.name]))) {
                    el.placeholder = String(cfg[el.name]);
                    el.value = '';
                } else {
                    el.value = String(cfg[el.name] ?? '');
                }
            }
        });
        // Re-attach listener so subsequent picks still hydrate.
        $('[data-field="run-config-slug"]').addEventListener('change', HSync.onRunConfigSlugChange, { once: true });
    };

    HSync.saveCurrentConfig = async function () {
        const sourceId = $('[data-field="run-source"]').value;
        if (!sourceId) { alert('Scegli una sorgente.'); return; }
        const form = $('[data-context="run"]');
        const config = form ? HSync.collectConfig(form) : {};
        const slug = $('[data-field="run-config-slug"]').value;  // empty = new
        const name = prompt(slug ? 'Aggiorna il nome:' : 'Nome per questa config:', slug ? (HSync.state.sourceConfigs.find(c => c.slug === slug)?.name || '') : '');
        if (!name) return;
        try {
            const data = await HSync.ajax('source_configs_save', {
                slug: slug, name: name, source_kind: sourceId, config: config,
            });
            await HSync.loadSourceConfigs(sourceId);
            $('[data-field="run-config-slug"]').value = data.slug;
        } catch (e) {
            alert('Errore: ' + e.message);
        }
    };

    HSync.runNow = async function () {
        const sourceId = $('[data-field="run-source"]').value;
        if (!sourceId) { alert('Scegli una sorgente.'); return; }
        const formEl = $('[data-context="run"]');
        const config = formEl ? HSync.collectConfig(formEl) : {};
        const configSlug = $('[data-field="run-config-slug"]').value;
        const dryRun = $('[data-field="run-dry-run"]').checked;
        const mappingSlug = $('[data-field="run-mapping"]').value;
        const mapping = mappingSlug ? HSync.state.mappings.find(m => m.slug === mappingSlug) : null;
        const options = mapping ? { mapping: mapping.config } : {};

        const out = $('[data-region="run-output"]');
        out.innerHTML = '<p class="hsync-loading">Run in corso…</p>';
        try {
            const data = await HSync.ajax('run_now', {
                source_id:   sourceId,
                config_slug: configSlug,
                config:      config,
                options:     options,
                dry_run:     dryRun ? '1' : '0',
            });
            HSync.renderRunResult(data);
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.renderRunResult = function (data) {
        const out = $('[data-region="run-output"]');
        const s = data.summary || {};
        const stat = (label, num, kind) =>
            '<div class="hsync-stat ' + (kind || '') + '"><div class="hsync-stat-num">' + (num || 0) + '</div><div class="hsync-stat-label">' + label + '</div></div>';

        const summary = ''
            + '<div class="hsync-summary">'
            +   stat('Fetched',   s.fetched)
            +   stat('New',       s.new)
            +   stat('Update',    s.update)
            +   stat('Unchanged', s.unchanged)
            +   stat('Created',   s.created, 'is-good')
            +   stat('Updated',   s.updated, 'is-good')
            +   stat('Skipped',   s.skipped)
            +   stat('Failed',    s.failed,  'is-bad')
            + '</div>';

        const warns = (data.warnings || []).map(w =>
            '<div class="hsync-warning">' + esc(w) + '</div>'
        ).join('');

        const rows = (data.rows || []).map((r, i) => {
            const klass  = r.action === 'failed' ? 'is-error' : '';
            const pillKl = 'is-' + (r.action || 'skipped');
            const err    = r.error ? esc(r.error) : '';
            return '<tr class="' + klass + '"><td>' + (i + 1) + '</td>'
                + '<td>' + (r.pid || '—') + '</td>'
                + '<td><code>' + esc(r.sku) + '</code></td>'
                + '<td><span class="hsync-action-pill ' + pillKl + '">' + esc(r.action) + '</span></td>'
                + '<td>' + err + '</td></tr>';
        }).join('');

        out.innerHTML = ''
            + '<h3>Run #' + (data.run_id || '?') + ' — ' + esc(data.status) + '</h3>'
            + summary
            + (warns ? '<div class="hsync-warnings">' + warns + '</div>' : '')
            + (rows
                ? '<table class="hsync-table"><thead><tr><th>#</th><th>PID</th><th>SKU</th><th>Action</th><th>Error</th></tr></thead><tbody>' + rows + '</tbody></table>'
                : '<div class="hsync-empty">Nessuna riga processata.</div>');
    };

    HSync.testFetchFromRun = async function () {
        const sourceId = $('[data-field="run-source"]').value;
        if (!sourceId) { alert('Scegli una sorgente.'); return; }
        const formEl = $('[data-context="run"]');
        const config = formEl ? HSync.collectConfig(formEl) : {};
        const out = $('[data-region="run-output"]');
        out.innerHTML = '<p class="hsync-loading">Fetching…</p>';
        try {
            const data = await HSync.ajax('source_test_fetch', {
                source_id: sourceId,
                config:    config,
                options:   {},
            });
            const warns = (data.warnings || []).map(w => '<div class="hsync-warning">' + esc(w) + '</div>').join('');
            const rows  = (data.preview || []).map((p, i) =>
                '<tr><td>' + (i + 1) + '</td><td><code>' + esc(p.sku) + '</code></td><td>' + esc(JSON.stringify(p.data).slice(0, 300)) + '</td></tr>'
            ).join('');
            out.innerHTML = ''
                + '<p><strong>' + data.count + '</strong> items in fetch (preview limit 10).</p>'
                + (warns ? '<div class="hsync-warnings">' + warns + '</div>' : '')
                + (rows  ? '<table class="hsync-table"><thead><tr><th>#</th><th>SKU</th><th>Data</th></tr></thead><tbody>' + rows + '</tbody></table>' : '');
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    // ─── Runs history ─────────────────────────────────────────────

    HSync.loadRuns = async function () {
        const region = $('[data-region="runs-list"]');
        try {
            const data = await HSync.ajax('runs_recent', { limit: '50' });
            const runs = data.runs || [];
            if (!runs.length) {
                region.innerHTML = '<div class="hsync-empty">Nessun run registrato.</div>';
                return;
            }
            const rows = runs.map(r => ''
                + '<tr>'
                +   '<td>' + r.id + '</td>'
                +   '<td><code>' + esc(r.runnable_type) + '</code></td>'
                +   '<td><code>' + esc(r.runnable_ref) + '</code></td>'
                +   '<td><span class="hsync-action-pill is-' + esc(r.status) + '">' + esc(r.status) + '</span></td>'
                +   '<td>' + esc(r.started_at) + '</td>'
                +   '<td>' + (r.items_done   || 0) + '</td>'
                +   '<td>' + (r.items_failed || 0) + '</td>'
                + '</tr>'
            ).join('');
            region.innerHTML = '<table class="hsync-table">'
                + '<thead><tr><th>ID</th><th>Type</th><th>Ref</th><th>Status</th><th>Started</th><th>Done</th><th>Failed</th></tr></thead>'
                + '<tbody>' + rows + '</tbody></table>';
        } catch (e) {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    // ─── Event delegation ─────────────────────────────────────────

    document.addEventListener('click', function (e) {
        const t = e.target;
        if (t.matches('.hsync-tab'))                    return HSync.switchTab(t.dataset.tab);
        if (t.matches('[data-action="test-fetch"]'))    return HSync.testFetch(t.dataset.source, t.closest('form'));
        if (t.matches('[data-action="mapping-new"]'))   return HSync.openMappingEditor(null);
        if (t.matches('[data-action="mapping-edit"]')) {
            const m = HSync.state.mappings.find(x => x.slug === t.dataset.slug);
            return HSync.openMappingEditor(m);
        }
        if (t.matches('[data-action="mapping-delete"]')) return HSync.deleteMapping(t.dataset.slug);
        if (t.matches('[data-action="mapping-save"]'))   return HSync.saveMapping();
        if (t.matches('[data-action="mapping-cancel"]')) return HSync.closeMappingEditor();
        if (t.matches('[data-action="run-now"]'))        return HSync.runNow();
        if (t.matches('[data-action="run-test-fetch"]')) return HSync.testFetchFromRun();
        if (t.matches('[data-action="run-save-config"]'))return HSync.saveCurrentConfig();
        if (t.matches('[data-action="runs-refresh"]'))   return HSync.loadRuns();
        if (t.matches('[data-action="legacy-audit"]'))   return HSync.legacyAudit();
        if (t.matches('[data-action="legacy-import"]'))  return HSync.legacyImport();

        // Pipelines
        if (t.matches('[data-action="pipeline-new"]'))    return HSync.openPipelineEditor(null);
        if (t.matches('[data-action="pipeline-edit"]')) {
            const p = HSync.state.pipelines.find(x => x.slug === t.dataset.slug);
            return HSync.openPipelineEditor(p);
        }
        if (t.matches('[data-action="pipeline-delete"]')) return HSync.deletePipeline(t.dataset.slug);
        if (t.matches('[data-action="pipeline-save"]'))   return HSync.savePipeline();
        if (t.matches('[data-action="pipeline-cancel"]')) return HSync.closePipelineEditor();
        if (t.matches('[data-action="pipeline-add-op"]'))  return HSync.addPipelineStep('operation');
        if (t.matches('[data-action="pipeline-add-chk"]')) return HSync.addPipelineStep('check');
        if (t.matches('[data-action="step-up"]'))     return HSync.movePipelineStep(parseInt(t.dataset.idx, 10), -1);
        if (t.matches('[data-action="step-down"]'))   return HSync.movePipelineStep(parseInt(t.dataset.idx, 10), 1);
        if (t.matches('[data-action="step-delete"]')) return HSync.deletePipelineStep(parseInt(t.dataset.idx, 10));

        // Rules
        if (t.matches('[data-action="rule-new"]')) return HSync.openRuleEditor(null);
        if (t.matches('[data-action="rule-edit"]')) {
            const r = HSync.state.rules.find(x => x.slug === t.dataset.slug);
            return HSync.openRuleEditor(r);
        }
        if (t.matches('[data-action="rule-delete"]'))     return HSync.deleteRule(t.dataset.slug);
        if (t.matches('[data-action="rule-save"]'))       return HSync.saveRule();
        if (t.matches('[data-action="rule-cancel"]'))     return HSync.closeRuleEditor();
        if (t.matches('[data-action="rule-add-op"]'))     return HSync.addRuleStep('op');
        if (t.matches('[data-action="rule-add-chk"]'))    return HSync.addRuleStep('chk');
        if (t.matches('[data-action="rule-step-up"]'))    return HSync.moveRuleStep(t.dataset.kind, parseInt(t.dataset.idx,10), -1);
        if (t.matches('[data-action="rule-step-down"]'))  return HSync.moveRuleStep(t.dataset.kind, parseInt(t.dataset.idx,10), 1);
        if (t.matches('[data-action="rule-step-delete"]'))return HSync.deleteRuleStep(t.dataset.kind, parseInt(t.dataset.idx,10));

        // Jobs
        if (t.matches('[data-action="job-new"]'))      return HSync.openJobEditor(null);
        if (t.matches('[data-action="job-edit"]')) {
            const j = HSync.state.jobs.find(x => x.id === parseInt(t.dataset.id, 10));
            return HSync.openJobEditor(j);
        }
        if (t.matches('[data-action="job-delete"]'))   return HSync.deleteJob(parseInt(t.dataset.id, 10));
        if (t.matches('[data-action="job-run-now"]'))  return HSync.runJobNow(parseInt(t.dataset.id, 10));
        if (t.matches('[data-action="job-save"]'))     return HSync.saveJob();
        if (t.matches('[data-action="job-cancel"]'))   return HSync.closeJobEditor();
        if (t.matches('[data-action="jobs-tick-now"]'))    return HSync.tickNow();
        if (t.matches('[data-action="as-health"]'))        return HSync.asHealth();
        if (t.matches('[data-action="as-run-queue"]'))     return HSync.asRunQueue();
        if (t.matches('[data-action="as-purge-past-due"]'))return HSync.asPurgePastDue();

        // Exports
        if (t.matches('[data-action="export-inventory"]')) return HSync.exportInventory(t.dataset.format || 'csv');
        if (t.matches('[data-action="export-catalog"]'))   return HSync.exportCatalog();
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-control="mappings-filter"]')) HSync.loadMappings();
        if (e.target.matches('[data-field="run-source"]'))        HSync.populateRunMappings();
        if (e.target.matches('[data-field="job-type"]'))          { HSync.state.editingJob.runnable_type = e.target.value; HSync.renderJobEditor(); }
        if (e.target.matches('[data-field="job-ref"]'))           { HSync.state.editingJob.runnable_ref  = e.target.value; if (HSync.state.editingJob.runnable_type === 'source.import') HSync.loadSourceConfigs(e.target.value).then(() => HSync.renderJobEditor()); }
    });

    // ─── Boot ─────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        HSync.loadSources();
    });
})();
