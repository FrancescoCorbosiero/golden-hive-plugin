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
        if (name === 'media')     HSync.loadMedia();
    };

    HSync.loadRegistry = async function () {
        if (HSync.state.registry.operations.length || HSync.state.registry.checks.length) return;
        try {
            const data = await HSync.ajax('registry_list', {});
            HSync.state.registry = {
                operations:    data.operations    || [],
                import_rules:  data.import_rules  || [],
                checks:        data.checks        || [],
                import_checks: data.import_checks || [],
            };
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
        const opts = HSync.state.registry.operations
            .filter(o => !o.is_import_rule)
            .map(o => '<option value="' + esc(o.id) + '">' + esc(o.label) + ' (' + esc(o.id) + ')</option>')
            .join('');
        const importRules = HSync.state.registry.operations
            .filter(o => o.is_import_rule)
            .map(o => '<option value="' + esc(o.id) + '">' + esc(o.label) + ' (' + esc(o.id) + ')</option>')
            .join('');
        const chkOpts = HSync.state.registry.checks.map(c =>
            '<option value="' + esc(c.id) + '">' + esc(c.label) + ' (' + esc(c.id) + ')</option>'
        ).join('');
        const preChkOpts = (HSync.state.registry.import_checks || []).map(c =>
            '<option value="' + esc(c.id) + '">' + esc(c.label) + ' (' + esc(c.id) + ')</option>'
        ).join('');

        const stepsHtml = (p.steps || []).map((s, i) => HSync.renderPipelineStep(s, i)).join('');

        root.innerHTML = ''
            + '<h2>' + (p.slug ? 'Modifica pipeline' : 'Nuova pipeline') + '</h2>'
            + '<label>Nome <input type="text" data-field="pipeline-name" value="' + esc(p.name) + '"></label>'
            + '<p class="hsync-muted">Step ordinati. Ciclo per item: <code>pre_check</code> → <code>import_rule</code> → materialize → <code>check</code>.</p>'
            + '<div class="hsync-pipeline-steps">' + (stepsHtml || '<p class="hsync-muted">Nessuno step. Aggiungi qui sotto.</p>') + '</div>'
            + '<div class="hsync-actions" style="flex-wrap:wrap;">'
            +   '<select data-field="pipeline-add-pre-id"><option value="">Pre-check (FeedItem)…</option>' + preChkOpts + '</select>'
            +   '<button class="button" data-action="pipeline-add-pre">+ Pre-check</button>'
            +   '<select data-field="pipeline-add-rule-id"><option value="">Import-rule (mutate draft)…</option>' + importRules + '</select>'
            +   '<button class="button" data-action="pipeline-add-rule">+ Import-rule</button>'
            +   '<select data-field="pipeline-add-op-id"><option value="">Post-import operation…</option>' + opts + '</select>'
            +   '<button class="button" data-action="pipeline-add-op">+ Operation</button>'
            +   '<select data-field="pipeline-add-chk-id"><option value="">Post-check (productId)…</option>' + chkOpts + '</select>'
            +   '<button class="button" data-action="pipeline-add-chk">+ Post-check</button>'
            + '</div>'
            + '<div class="hsync-actions" style="margin-top:24px;border-top:1px solid #ccd0d4;padding-top:16px;">'
            +   '<button class="button button-primary" data-action="pipeline-save">Salva</button>'
            +   '<button class="button" data-action="pipeline-cancel">Annulla</button>'
            + '</div>';
    };

    HSync.renderPipelineStep = function (step, idx) {
        let reg, kindLabel, pillKind;
        switch (step.kind) {
            case 'pre_check':
                reg = HSync.state.registry.import_checks || [];
                kindLabel = 'Pre-check';
                pillKind = 'is-skipped';
                break;
            case 'import_rule':
                reg = HSync.state.registry.import_rules || HSync.state.registry.operations.filter(o => o.is_import_rule);
                kindLabel = 'Import-rule';
                pillKind = 'is-updated';
                break;
            case 'check':
                reg = HSync.state.registry.checks;
                kindLabel = 'Post-check';
                pillKind = 'is-skipped';
                break;
            default:
                reg = HSync.state.registry.operations;
                kindLabel = 'Operation';
                pillKind = 'is-created';
        }
        const def = reg.find(r => r.id === step.ref_id);
        const schema = def ? def.params_schema : {};
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
            +     '<div class="hsync-row-name"><span class="hsync-action-pill ' + pillKind + '">' + kindLabel + '</span> <code>' + esc(step.ref_id) + '</code>' + (def ? ' — ' + esc(def.label) : ' <em>(sconosciuto)</em>') + '</div>'
            +     '<div class="hsync-row-meta">' + (fields || '<em>nessun parametro</em>') + '</div>'
            +   '</div>'
            +   '<button class="button" data-action="step-up" data-idx="' + idx + '">↑</button>'
            +   '<button class="button" data-action="step-down" data-idx="' + idx + '">↓</button>'
            +   '<button class="button" data-action="step-delete" data-idx="' + idx + '">✕</button>'
            + '</div>';
    };

    HSync.addPipelineStep = function (kind) {
        let selSelector;
        switch (kind) {
            case 'pre_check':   selSelector = '[data-field="pipeline-add-pre-id"]'; break;
            case 'import_rule': selSelector = '[data-field="pipeline-add-rule-id"]'; break;
            case 'check':       selSelector = '[data-field="pipeline-add-chk-id"]'; break;
            default:            selSelector = '[data-field="pipeline-add-op-id"]';
        }
        const sel = $(selSelector);
        const refId = sel ? sel.value : '';
        if (!refId) return;
        HSync.collectPipelineSteps();
        HSync.state.editingPipeline.steps.push({ kind: kind, ref_id: refId, params: {} });
        HSync.renderPipelineEditor();
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
            alert('Coda riavviata (' + data.duration_ms + ' ms).');
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
            alert('Esecuzione lanciata: ' + data.dispatched + ' avviati, ' + data.skipped + ' saltati' + (data.locked ? ' (sistema occupato — riprova tra poco)' : ''));
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

    HSync.renderSources = async function () {
        const region = $('[data-region="sources-list"]');
        if (!HSync.state.sources.length) {
            region.innerHTML = '<div class="hsync-empty">Nessuna sorgente registrata.</div>';
            return;
        }
        // Pre-load saved configs once so each card can show its own list.
        try {
            const data = await HSync.ajax('source_configs_list', {});
            HSync.state.sourceConfigs = data.configs || [];
        } catch (e) { /* non-fatal — render with empty config lists */ }

        region.innerHTML = HSync.state.sources.map(s => {
            const caps = Object.entries(s.capabilities).map(([k, v]) =>
                '<span class="hsync-cap-pill' + (v ? ' is-on' : '') + '">' + esc(k) + '</span>'
            ).join('');
            const schemaHtml = HSync.renderSchema(s.config_schema, 'src-' + s.id);
            const configs = HSync.state.sourceConfigs.filter(c => c.source_kind === s.id);
            const configRows = configs.length
                ? configs.map(c => ''
                    + '<div class="hsync-config-row">'
                    +   '<div class="hsync-row-main">'
                    +     '<div class="hsync-row-name">' + esc(c.name) + '</div>'
                    +     '<div class="hsync-row-meta"><code>' + esc(c.slug) + '</code> · '
                    +       Object.keys(c.config || {}).length + ' campi'
                    +     '</div>'
                    +   '</div>'
                    +   '<button class="button" data-action="src-config-load" data-source="' + esc(s.id) + '" data-slug="' + esc(c.slug) + '">Modifica</button>'
                    +   '<button class="button" data-action="src-config-delete" data-slug="' + esc(c.slug) + '">Elimina</button>'
                    + '</div>'
                ).join('')
                : '<div class="hsync-empty hsync-empty-compact">Nessuna config salvata. Compila qui sotto e clicca <em>Salva</em>.</div>';

            return ''
                + '<div class="hsync-source-card" data-source-card="' + esc(s.id) + '">'
                +   '<h3>' + esc(s.label) + ' <span class="hsync-source-id">' + esc(s.id) + '</span></h3>'
                +   '<div class="hsync-caps">' + caps + '</div>'
                +   '<div class="hsync-source-section">'
                +     '<div class="hsync-summary-label">Config salvate</div>'
                +     '<div data-region="src-configs-' + esc(s.id) + '">' + configRows + '</div>'
                +   '</div>'
                +   '<div class="hsync-source-section">'
                +     '<div class="hsync-summary-label">Editor config</div>'
                +     '<form class="hsync-config-form" data-source="' + esc(s.id) + '" data-context="src">'
                +       '<input type="hidden" data-field="src-slug-' + esc(s.id) + '" value="">'
                +       '<label>Nome <input type="text" data-field="src-name-' + esc(s.id) + '" placeholder="Es. GS produzione"></label>'
                +       schemaHtml
                +       '<div class="hsync-actions">'
                +         '<button type="button" class="button button-primary" data-action="src-config-save" data-source="' + esc(s.id) + '">Salva</button>'
                +         '<button type="button" class="button" data-action="src-config-reset" data-source="' + esc(s.id) + '">Nuova</button>'
                +         '<button type="button" class="button" data-action="test-fetch" data-source="' + esc(s.id) + '">Test fetch</button>'
                +       '</div>'
                +       '<div data-region="test-fetch-output-' + esc(s.id) + '"></div>'
                +     '</form>'
                +   '</div>'
                + '</div>';
        }).join('');
    };

    HSync.loadSourceConfigEditor = function (sourceId, slug) {
        const cfg = HSync.state.sourceConfigs.find(c => c.slug === slug);
        if (!cfg) return;
        const card = document.querySelector('[data-source-card="' + sourceId + '"]');
        if (!card) return;
        card.querySelector('[data-field="src-slug-' + sourceId + '"]').value = cfg.slug;
        card.querySelector('[data-field="src-name-' + sourceId + '"]').value = cfg.name;
        const form = card.querySelector('[data-context="src"]');
        $$('input, select, textarea', form).forEach(el => {
            if (!el.name) return;
            if (Object.prototype.hasOwnProperty.call(cfg.config || {}, el.name)) {
                if (el.type === 'checkbox') el.checked = !!cfg.config[el.name];
                else if (el.type === 'password' && /^•+/.test(String(cfg.config[el.name]))) {
                    // Server returned a redacted secret: keep value empty and
                    // hint via placeholder. Empty submit = "unchanged" (server
                    // hydrates from stored value).
                    el.placeholder = String(cfg.config[el.name]);
                    el.value = '';
                } else {
                    el.value = String(cfg.config[el.name] ?? '');
                }
            }
        });
    };

    HSync.resetSourceConfigEditor = function (sourceId) {
        const card = document.querySelector('[data-source-card="' + sourceId + '"]');
        if (!card) return;
        card.querySelector('[data-field="src-slug-' + sourceId + '"]').value = '';
        card.querySelector('[data-field="src-name-' + sourceId + '"]').value = '';
        const form = card.querySelector('[data-context="src"]');
        $$('input, select, textarea', form).forEach(el => {
            if (!el.name) return;
            if (el.type === 'checkbox') el.checked = false;
            else el.value = '';
        });
    };

    HSync.saveSourceConfig = async function (sourceId) {
        const card = document.querySelector('[data-source-card="' + sourceId + '"]');
        if (!card) return;
        const form = card.querySelector('[data-context="src"]');
        const slug = card.querySelector('[data-field="src-slug-' + sourceId + '"]').value;
        const name = card.querySelector('[data-field="src-name-' + sourceId + '"]').value.trim();
        if (!name) { alert('Inserisci un nome (es. "GS produzione").'); return; }
        const config = HSync.collectConfig(form);
        try {
            const data = await HSync.ajax('source_configs_save', {
                slug: slug, name: name, source_kind: sourceId, config: config,
            });
            alert('Configurazione salvata.');
            HSync.loadSources();
            // Repopulate the Run tab picker too if cached.
            if (HSync.state.currentTab === 'run') HSync.loadSourceConfigs(sourceId);
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.deleteSourceConfig = async function (slug) {
        if (!confirm('Eliminare questa config? Le run/job che la usano dovranno essere riconfigurati.')) return;
        try {
            await HSync.ajax('source_configs_delete', { slug: slug });
            HSync.loadSources();
        } catch (e) { alert('Errore: ' + e.message); }
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

    // Canonical Woo target fields shown in the mapping editor's row picker.
    // These are the keys downstream operations (CsvSource materialize +
    // ResolveTaxonomy + DownloadMedia + AdjustPrice) typically read from
    // the mapped FeedItem.data shape. The list is intentionally small —
    // a "+ Campo personalizzato" row lets the user add anything else.
    // The Woo target schema is the FIXED spine of every mapping. The
    // user can only choose what to map INTO each slot — not what the
    // slots themselves are. Two groups: `minimal` (the fields that
    // give a usable Woo product on import) and `advanced` (SEO + meta
    // + extras you may not always need). Custom keys outside this
    // schema land in their own dedicated section below.
    HSync.WOO_SCHEMA = [
        // ── Minimal — visible by default ───────────────────────
        { key: 'sku',               label: 'SKU',                  group: 'minimal', required: true,  hint: 'Identificatore univoco del prodotto' },
        { key: 'name',              label: 'Nome prodotto',        group: 'minimal', required: true },
        { key: 'regular_price',     label: 'Prezzo listino',       group: 'minimal', required: true },
        { key: 'description',       label: 'Descrizione',          group: 'minimal', hint: 'Supporta HTML — usa template per arricchirla' },
        { key: 'image_url',         label: 'Immagine principale',  group: 'minimal' },
        { key: 'categories',        label: 'Categoria',            group: 'minimal' },
        { key: 'brand',             label: 'Brand',                group: 'minimal' },
        { key: 'stock_quantity',    label: 'Quantità a stock',     group: 'minimal' },

        // ── Advanced — collapsed by default ────────────────────
        { key: 'short_description', label: 'Descrizione breve',    group: 'advanced' },
        { key: 'sale_price',        label: 'Prezzo scontato',      group: 'advanced' },
        { key: 'stock_status',      label: 'Stato stock',          group: 'advanced', hint: 'instock | outofstock' },
        { key: 'manage_stock',      label: 'Gestione stock',       group: 'advanced', hint: 'true | false' },
        { key: 'status',            label: 'Stato pubblicazione',  group: 'advanced', hint: 'publish | draft' },
        { key: 'gallery_urls',      label: 'Gallery URLs',         group: 'advanced', hint: 'lista pipe-joined' },
        { key: 'tags',              label: 'Tag',                  group: 'advanced' },
        { key: 'meta_title',        label: 'SEO title',            group: 'advanced' },
        { key: 'meta_description',  label: 'SEO meta description', group: 'advanced' },
        { key: 'meta_keywords',     label: 'SEO keywords',         group: 'advanced' },
    ];

    HSync.MAPPING_SNIPPETS = [
        { label: 'Brand + nome prodotto',                  value: '{brand_name} {name}' },
        { label: 'Descrizione lunga (con HTML)',           value: '<p>{brand_name} <strong>{name}</strong> originali — {colorway}</p>' },
        { label: 'Descrizione breve',                      value: '{brand_name} {name}' },
        { label: 'Titolo SEO',                             value: '{name} | {brand_name} | Sneakers' },
        { label: 'Meta description SEO',                   value: 'Acquista {name} di {brand_name}. Modello {colorway}, taglie disponibili. Spedizione veloce.' },
        { label: 'SKU con prefisso brand',                 value: '{brand_name}-{sku}' },
    ];

    /**
     * Editor state model is keyed by Woo field name so the row order
     * is implicit (it follows the schema). Custom keys outside the
     * schema live in a parallel ordered list rendered separately.
     */
    HSync.openMappingEditor = function (mapping) {
        HSync.state.editingMapping = mapping || null;
        HSync.state.mappingValues = {};
        HSync.state.mappingCustomKeys = [];
        HSync.state.mappingProbe = { pathsRaw: [], pathsData: [], sampleRaw: null, sampleData: null };
        HSync.state.mappingFocusedKey = null;

        const cfg = (mapping && mapping.config) || {};
        const schemaKeys = new Set(HSync.WOO_SCHEMA.map(f => f.key));
        Object.entries(cfg).forEach(([k, v]) => {
            HSync.state.mappingValues[k] = String(v == null ? '' : v);
            if (!schemaKeys.has(k)) HSync.state.mappingCustomKeys.push(k);
        });

        // Auto-expand advanced if any advanced slot is already filled —
        // saves a click for users editing existing mappings that touch
        // SEO/meta.
        HSync.state.mappingShowAdvanced = HSync.WOO_SCHEMA.some(f =>
            f.group === 'advanced'
            && (HSync.state.mappingValues[f.key] || '').toString().trim() !== '',
        );

        $('[data-region="mapping-editor"]').classList.remove('is-hidden');
        $('[data-field="map-name"]').value   = mapping ? mapping.name : '';
        $('[data-field="map-source"]').value = mapping ? mapping.source_kind : '';
        $('[data-field="map-config"]').value = mapping ? JSON.stringify(mapping.config || {}, null, 2) : '';
        $('[data-region="mapping-json-view"]').classList.add('is-hidden');
        const toggle = $('[data-action="mapping-toggle-json"]');
        if (toggle) toggle.checked = false;
        $('[data-region="mapping-probe-output"]').classList.add('is-hidden');
        HSync.renderMappingRows();
    };

    HSync.closeMappingEditor = function () {
        HSync.state.editingMapping = null;
        $('[data-region="mapping-editor"]').classList.add('is-hidden');
    };

    /** Drop empty values + empty keys, then return the saved-shape object. */
    HSync.mappingValuesToConfig = function () {
        const out = {};
        Object.entries(HSync.state.mappingValues || {}).forEach(([k, v]) => {
            const key = String(k || '').trim();
            const val = String(v || '').trim();
            if (key !== '' && val !== '') out[key] = val;
        });
        return out;
    };

    HSync.renderMappingRows = function () {
        const region = $('[data-region="mapping-rows"]');
        const probe = HSync.state.mappingProbe || {};
        // Two path groups:
        // - rawPaths: native upstream fields (e.g. GS API: presented_price,
        //   offer_price, available_summary_quantity, sizes.size_eu).
        //   This is what the user wants to map FROM.
        // - dataPaths: post-bridge payload (sometimes partially Woo-shaped).
        //   Shown as a fallback for sources that don't expose raw fields.
        const rawPaths  = probe.pathsRaw  || [];
        const dataPaths = probe.pathsData || [];
        const datalistId = 'hsync-paths-datalist';
        // Datalist combines both so autocomplete works regardless. Raw
        // entries come first (what we recommend).
        const allPaths = Array.from(new Set([...rawPaths, ...dataPaths]));
        const datalist = '<datalist id="' + datalistId + '">'
            + allPaths.map(p => '<option value="' + esc(p) + '">').join('')
            + '</datalist>';

        const buildChips = (list) => list.map(p =>
            '<button type="button" class="hsync-chip" data-action="mapping-insert-token" data-token="{' + esc(p) + '}">{' + esc(p) + '}</button>',
        ).join('');

        const snippetOpts = HSync.MAPPING_SNIPPETS.map((s, i) =>
            '<option value="' + i + '">' + esc(s.label) + '</option>',
        ).join('');

        let paletteRows;
        if (!rawPaths.length && !dataPaths.length) {
            paletteRows = '<div class="hsync-palette-row">'
                + '<span class="hsync-muted">Premi <em>Anteprima sorgente</em> qui sopra per scoprire quali campi puoi inserire.</span>'
                + '</div>';
        } else {
            const rawSection = rawPaths.length
                ? '<div class="hsync-palette-row">'
                +   '<strong>Campi del feed esterno:</strong> ' + buildChips(rawPaths)
                + '</div>'
                : '';
            const dataSection = dataPaths.length
                ? '<div class="hsync-palette-row hsync-palette-row-secondary">'
                +   '<strong>Campi normalizzati' + (rawPaths.length ? ' (alternativa)' : '') + ':</strong> '
                +   buildChips(dataPaths)
                +   (!rawPaths.length
                          ? ' <small class="hsync-muted">Questa sorgente non espone i campi grezzi: i nomi qui sotto possono assomigliare a quelli di WooCommerce.</small>'
                          : '')
                + '</div>'
                : '';
            paletteRows = rawSection + dataSection;
        }

        const palette = ''
            + '<div class="hsync-mapping-palette is-hidden" data-region="mapping-palette">'
            +   paletteRows
            +   '<div class="hsync-palette-row">'
            +     '<strong>Oppure parti da un esempio:</strong> '
            +     '<select data-action="mapping-insert-snippet">'
            +       '<option value="">— scegli un esempio —</option>'
            +       snippetOpts
            +     '</select>'
            +     '<span class="hsync-muted">Le parentesi <code>{ }</code> contengono il campo del feed. Puoi mescolare testo libero e HTML.</span>'
            +   '</div>'
            + '</div>';

        // Spine row: target label is FIXED, only the source side is editable.
        // Required + empty = visual warning so the user can spot gaps.
        const renderSpineRow = (f) => {
            const value = HSync.state.mappingValues[f.key] || '';
            const isEmpty = String(value).trim() === '';
            const warnClass = f.required && isEmpty ? ' is-required-missing' : '';
            const filledClass = !isEmpty ? ' is-filled' : '';
            return ''
                + '<div class="hsync-mapping-row-builder' + warnClass + filledClass + '" data-key="' + esc(f.key) + '">'
                +   '<div class="hsync-mapping-cell hsync-mapping-target">'
                +     '<div class="hsync-mapping-label">'
                +       (f.required ? '<span class="hsync-required-marker" title="Obbligatorio">*</span>' : '')
                +       esc(f.label)
                +     '</div>'
                +     '<code class="hsync-mapping-key">' + esc(f.key) + '</code>'
                +     (f.hint ? '<small class="hsync-muted">' + esc(f.hint) + '</small>' : '')
                +   '</div>'
                +   '<div class="hsync-mapping-cell hsync-mapping-arrow" title="campo Woo ← campo sorgente">←</div>'
                +   '<div class="hsync-mapping-cell hsync-mapping-source">'
                +     '<input type="text" data-mapping-key="' + esc(f.key) + '"'
                +       ' value="' + esc(value) + '"'
                +       ' list="' + datalistId + '"'
                +       ' placeholder="es. presented_price, sizes.size_eu, oppure {brand_name} {name}">'
                +     (!allPaths.length
                          ? '<small class="hsync-muted">Premi <em>Anteprima sorgente</em> per vedere i campi disponibili.</small>'
                          : '')
                +   '</div>'
                +   (isEmpty
                          ? ''
                          : '<button type="button" class="button button-small" data-action="mapping-clear" data-key="' + esc(f.key) + '" title="Pulisci">✕</button>')
                + '</div>';
        };

        const renderCustomRow = (key, idx) => {
            const value = HSync.state.mappingValues[key] || '';
            return ''
                + '<div class="hsync-mapping-row-builder hsync-mapping-row-custom" data-custom-idx="' + idx + '">'
                +   '<div class="hsync-mapping-cell hsync-mapping-target">'
                +     '<input type="text" data-custom-key="' + idx + '"'
                +       ' value="' + esc(key) + '"'
                +       ' placeholder="es. meta_seo_focus_keyword">'
                +     '<small class="hsync-muted">campo personalizzato</small>'
                +   '</div>'
                +   '<div class="hsync-mapping-cell hsync-mapping-arrow">←</div>'
                +   '<div class="hsync-mapping-cell hsync-mapping-source">'
                +     '<input type="text" data-custom-value="' + idx + '"'
                +       ' value="' + esc(value) + '"'
                +       ' list="' + datalistId + '"'
                +       ' placeholder="campo della sorgente o template">'
                +   '</div>'
                +   '<button type="button" class="button button-small" data-action="mapping-custom-delete" data-idx="' + idx + '" title="Elimina">✕</button>'
                + '</div>';
        };

        const minimalRows  = HSync.WOO_SCHEMA.filter(f => f.group === 'minimal').map(renderSpineRow).join('');
        const advancedRows = HSync.WOO_SCHEMA.filter(f => f.group === 'advanced').map(renderSpineRow).join('');
        const customRows   = HSync.state.mappingCustomKeys.map(renderCustomRow).join('');
        const showAdv      = !!HSync.state.mappingShowAdvanced;
        const advCount     = HSync.WOO_SCHEMA.filter(f =>
            f.group === 'advanced' && String(HSync.state.mappingValues[f.key] || '').trim() !== '',
        ).length;
        const customCount  = HSync.state.mappingCustomKeys.length;

        // Required-fields summary banner — counts what's still missing
        // out of the Woo-mandatory triplet (sku/name/regular_price).
        const missingRequired = HSync.WOO_SCHEMA.filter(f =>
            f.required && String(HSync.state.mappingValues[f.key] || '').trim() === '',
        );
        const requiredBanner = missingRequired.length
            ? '<div class="hsync-warning">'
              + '<strong>Manca ancora qualcosa.</strong> Per creare un prodotto servono: '
              + missingRequired.map(f => '<code>' + esc(f.label || f.key) + '</code>').join(', ')
              + '. Compilali per poter salvare.</div>'
            : '<div class="hsync-summary-foot">Tutto pronto — i campi essenziali sono compilati ✓</div>';

        region.innerHTML = ''
            + datalist + palette
            + requiredBanner
            + '<section class="hsync-mapping-section">'
            +   '<h3 class="hsync-mapping-section-h">'
            +     '<span class="hsync-section-badge">Essenziali</span>'
            +     'Campi base del prodotto'
            +   '</h3>'
            +   '<p class="hsync-muted">Quelli con <span class="hsync-required-marker">*</span> sono indispensabili per creare un prodotto.</p>'
            +   minimalRows
            + '</section>'
            + '<section class="hsync-mapping-section">'
            +   '<button class="hsync-mapping-toggle" data-action="mapping-toggle-advanced">'
            +     (showAdv ? '▼' : '▶') + ' Campi avanzati'
            +     ' <small class="hsync-muted">(SEO, gallery, sconti — ' + advCount + ' compilati)</small>'
            +   '</button>'
            +   (showAdv
                ? '<p class="hsync-muted">Compilali solo se ti servono. Altrimenti lasciali vuoti.</p>' + advancedRows
                : '')
            + '</section>'
            + '<section class="hsync-mapping-section">'
            +   '<h3 class="hsync-mapping-section-h">'
            +     '<span class="hsync-section-badge is-custom">Personalizzati</span>'
            +     'I tuoi campi extra'
            +     ' <small class="hsync-muted">(' + customCount + ')</small>'
            +   '</h3>'
            +   '<p class="hsync-muted">Per chiavi che non rientrano nello standard — utili per meta SEO, attributi specifici del tuo store.</p>'
            +   customRows
            +   '<div class="hsync-actions"><button type="button" class="button" data-action="mapping-add-custom">+ Aggiungi campo</button></div>'
            + '</section>';
    };

    HSync.toggleAdvancedMapping = function () {
        HSync.state.mappingShowAdvanced = !HSync.state.mappingShowAdvanced;
        HSync.renderMappingRows();
    };

    HSync.clearMappingValue = function (key) {
        if (HSync.state.mappingValues[key] != null) {
            HSync.state.mappingValues[key] = '';
            HSync.renderMappingRows();
        }
    };

    HSync.addCustomMappingRow = function () {
        // Reserve a placeholder key. The user fills it in via the
        // editable left input. Using a unique placeholder key keeps
        // mappingValues coherent until the user types something.
        let n = 1;
        while (HSync.state.mappingValues['custom_' + n] !== undefined) n++;
        const key = 'custom_' + n;
        HSync.state.mappingValues[key] = '';
        HSync.state.mappingCustomKeys.push(key);
        HSync.renderMappingRows();
        // Focus the new key field so the user can rename immediately.
        setTimeout(() => {
            const idx = HSync.state.mappingCustomKeys.length - 1;
            const input = document.querySelector('[data-custom-key="' + idx + '"]');
            if (input) input.focus();
        }, 0);
    };

    HSync.deleteCustomMappingRow = function (idx) {
        const key = HSync.state.mappingCustomKeys[idx];
        if (!key) return;
        delete HSync.state.mappingValues[key];
        HSync.state.mappingCustomKeys.splice(idx, 1);
        HSync.renderMappingRows();
    };

    HSync.renameCustomMappingKey = function (idx, newKey) {
        const oldKey = HSync.state.mappingCustomKeys[idx];
        const trimmed = (newKey || '').trim();
        if (!oldKey || trimmed === '' || trimmed === oldKey) return;
        // Avoid clobbering a schema key or another custom key.
        const schemaKeys = new Set(HSync.WOO_SCHEMA.map(f => f.key));
        if (schemaKeys.has(trimmed) || (HSync.state.mappingValues[trimmed] !== undefined && trimmed !== oldKey)) {
            alert('La chiave "' + trimmed + '" è già usata. Scegline un\'altra.');
            HSync.renderMappingRows();
            return;
        }
        HSync.state.mappingValues[trimmed] = HSync.state.mappingValues[oldKey] || '';
        delete HSync.state.mappingValues[oldKey];
        HSync.state.mappingCustomKeys[idx] = trimmed;
    };

    /**
     * Track which value input currently has focus. Stored as the Woo
     * key for spine rows or as the "custom:N" string for custom rows
     * — the palette uses this to know where to insert.
     */
    HSync.onMappingValueFocus = function (input) {
        if (input.dataset.mappingKey) {
            HSync.state.mappingFocusedKey = input.dataset.mappingKey;
        } else if (input.dataset.customValue) {
            HSync.state.mappingFocusedKey = 'custom:' + input.dataset.customValue;
        } else {
            return;
        }
        const palette = $('[data-region="mapping-palette"]');
        if (palette) palette.classList.remove('is-hidden');
    };

    function focusedMappingInput() {
        const fk = HSync.state.mappingFocusedKey;
        if (!fk) return null;
        if (fk.startsWith('custom:')) {
            const idx = fk.slice(7);
            return document.querySelector('[data-custom-value="' + idx + '"]');
        }
        return document.querySelector('[data-mapping-key="' + fk + '"]');
    }

    HSync.insertMappingToken = function (token) {
        const input = focusedMappingInput();
        if (!input) {
            alert('Clicca prima dentro un campo per scegliere dove inserire il valore.');
            return;
        }
        const start = input.selectionStart ?? input.value.length;
        const end   = input.selectionEnd   ?? input.value.length;
        const next  = input.value.slice(0, start) + token + input.value.slice(end);
        input.value = next;
        // Sync into state.
        if (input.dataset.mappingKey) {
            HSync.state.mappingValues[input.dataset.mappingKey] = next;
        } else if (input.dataset.customValue) {
            const key = HSync.state.mappingCustomKeys[parseInt(input.dataset.customValue, 10)];
            if (key) HSync.state.mappingValues[key] = next;
        }
        input.focus();
        const caret = start + token.length;
        try { input.setSelectionRange(caret, caret); } catch {}
    };

    HSync.insertMappingSnippet = function (snippetIdx) {
        const snippet = HSync.MAPPING_SNIPPETS[snippetIdx];
        if (!snippet) return;
        const input = focusedMappingInput();
        if (!input) {
            alert('Clicca prima dentro un campo per scegliere dove applicare l\'esempio.');
            return;
        }
        if (input.value && !confirm('Questo campo ha già un valore. Vuoi sostituirlo con l\'esempio?')) return;
        input.value = snippet.value;
        if (input.dataset.mappingKey) {
            HSync.state.mappingValues[input.dataset.mappingKey] = snippet.value;
        } else if (input.dataset.customValue) {
            const key = HSync.state.mappingCustomKeys[parseInt(input.dataset.customValue, 10)];
            if (key) HSync.state.mappingValues[key] = snippet.value;
        }
        HSync.renderMappingRows();
    };

    HSync.toggleMappingJson = function (checked) {
        const view = $('[data-region="mapping-json-view"]');
        view.classList.toggle('is-hidden', !checked);
        if (checked) {
            $('[data-field="map-config"]').value = JSON.stringify(
                HSync.mappingValuesToConfig(), null, 2,
            );
        }
    };

    HSync.applyMappingJson = function () {
        let cfg;
        try {
            cfg = JSON.parse($('[data-field="map-config"]').value || '{}');
        } catch (e) {
            alert('JSON invalido: ' + e.message);
            return;
        }
        HSync.state.mappingValues = {};
        HSync.state.mappingCustomKeys = [];
        const schemaKeys = new Set(HSync.WOO_SCHEMA.map(f => f.key));
        Object.entries(cfg).forEach(([k, v]) => {
            HSync.state.mappingValues[k] = String(v == null ? '' : v);
            if (!schemaKeys.has(k)) HSync.state.mappingCustomKeys.push(k);
        });
        HSync.renderMappingRows();
        alert('JSON applicato — controlla i campi qui sotto.');
    };

    HSync.probeMappingSource = async function () {
        const sourceId = $('[data-field="map-source"]').value;
        if (!sourceId) { alert('Scegli prima la sorgente.'); return; }
        if (!HSync.state.sourceConfigs.length) {
            try {
                const data = await HSync.ajax('source_configs_list', {});
                HSync.state.sourceConfigs = data.configs || [];
            } catch {}
        }
        const cfg = HSync.state.sourceConfigs.find(c => c.source_kind === sourceId);
        if (!cfg) {
            alert('Per fare l\'anteprima ti serve una configurazione salvata per "' + sourceId + '". Vai su Connetti per aggiungerla.');
            return;
        }
        const out = $('[data-region="mapping-probe-output"]');
        out.classList.remove('is-hidden');
        out.innerHTML = '<p class="hsync-loading">Sto leggendo un esempio dalla sorgente…</p>';
        try {
            const data = await HSync.ajax('mapping_probe', {
                source_id: sourceId, config_slug: cfg.slug,
            });
            const rawPaths  = data.paths_raw  || [];
            const dataPaths = data.paths_data || [];
            HSync.state.mappingProbe = {
                pathsRaw:   rawPaths,
                pathsData:  dataPaths,
                sampleRaw:  data.sample_raw,
                sampleData: data.sample_data,
            };
            const warns = (data.warnings || []).map(w =>
                '<div class="hsync-warning">' + esc(w) + '</div>').join('');

            const renderSampleBlock = (label, payload) => payload
                ? '<details><summary>' + esc(label) + '</summary>'
                  + '<pre class="hsync-pre">' + esc(JSON.stringify(payload, null, 2)) + '</pre>'
                  + '</details>'
                : '';

            const sampleSummary = (data.sample_raw || data.sample_data)
                ? renderSampleBlock(
                    'Vedi il prodotto grezzo dal feed (' + data.count + ' totali nella sorgente)',
                    data.sample_raw,
                  )
                  + renderSampleBlock(
                    'Vedi il prodotto dopo la normalizzazione (alternativa)',
                    data.sample_data,
                  )
                : '<div class="hsync-empty">La sorgente non ha restituito alcun prodotto.</div>';

            const tally = rawPaths.length
                ? '<strong>' + rawPaths.length + '</strong> campi del feed disponibili'
                  + (dataPaths.length ? ' (+ ' + dataPaths.length + ' normalizzati come fallback)' : '')
                : (dataPaths.length
                    ? '<strong>' + dataPaths.length + '</strong> campi disponibili '
                      + '<em>(la sorgente non espone i campi grezzi separatamente)</em>'
                    : 'Nessun campo rilevato');

            out.innerHTML = ''
                + '<div class="hsync-summary-label">Anteprima da ' + esc(cfg.name) + '</div>'
                + '<p class="hsync-muted">' + tally + '. Clicca su un campo qui sotto per inserirlo.</p>'
                + warns
                + sampleSummary;
            HSync.renderMappingRows();
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.saveMapping = async function () {
        const jsonOpen = !$('[data-region="mapping-json-view"]').classList.contains('is-hidden');
        let cfg;
        if (jsonOpen) {
            try {
                cfg = JSON.parse($('[data-field="map-config"]').value || '{}');
            } catch (e) {
                alert('Il JSON contiene un errore: ' + e.message);
                return;
            }
        } else {
            cfg = HSync.mappingValuesToConfig();
        }
        // Block save when required fields are unmapped — those are
        // genuine fail-fast cases (no SKU = no upsert key).
        const missing = HSync.WOO_SCHEMA.filter(f =>
            f.required && (!cfg[f.key] || String(cfg[f.key]).trim() === ''),
        );
        if (missing.length) {
            alert('Mancano alcuni campi obbligatori: ' + missing.map(f => f.label || f.key).join(', '));
            return;
        }
        if (!Object.keys(cfg).length) {
            if (!confirm('La mappatura è vuota — vuoi salvarla lo stesso?')) return;
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

    HSync.installDefaults = async function (force) {
        if (force && !confirm('Sovrascrive le mappature e i flussi default con la versione del codice. Eventuali modifiche fatte a "gs-default" / "import-default" andranno perse. Procedere?')) return;
        try {
            const data = await HSync.ajax('install_defaults', force ? { force: '1' } : {});
            const m = data.mappings || 0, p = data.pipelines || 0;
            if (m === 0 && p === 0) {
                alert('Default già installati — nessuna modifica.');
            } else {
                alert((force ? 'Aggiornati ' : 'Installati ') + m + ' mappature + ' + p + ' flussi default.');
            }
            HSync.loadMappings();
            if (HSync.state.pipelines.length || HSync.state.currentTab === 'pipelines') HSync.loadPipelines();
        } catch (e) { alert('Errore: ' + e.message); }
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
        HSync.populateRunPipelinePicker();
    };

    HSync.populateRunPipelinePicker = async function () {
        const sel = $('[data-field="run-pipeline"]');
        if (!sel) return;
        if (!HSync.state.pipelines.length) {
            try { await HSync.loadPipelines(); } catch {}
        }
        const previous = sel.value;
        sel.innerHTML = '<option value="">— nessuna (fetch → diff → materialize) —</option>'
            + HSync.state.pipelines.map(p =>
                '<option value="' + esc(p.slug) + '"' + (p.slug === 'import-default' && !previous ? ' selected' : '') + '>'
                + esc(p.name) + '</option>'
            ).join('');
        if (previous) sel.value = previous;
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

    /**
     * Cron presets the user picks from when saving a Run as a scheduled
     * Job. Free-form input is also accepted — the server validates via
     * CronExpr::parse.
     */
    HSync.cronPresets = [
        { label: 'Ogni 15 minuti',     expr: '*/15 * * * *' },
        { label: 'Ogni 30 minuti',     expr: '*/30 * * * *' },
        { label: 'Ogni ora',           expr: '0 * * * *' },
        { label: 'Ogni 6 ore',         expr: '0 */6 * * *' },
        { label: 'Giornaliero (02:00)',expr: '0 2 * * *' },
        { label: 'Lun-Ven 02:00',      expr: '0 2 * * 1-5' },
    ];

    HSync.saveCurrentAsJob = async function () {
        const sourceId = $('[data-field="run-source"]').value;
        if (!sourceId) { alert('Scegli una sorgente.'); return; }

        const formEl = $('[data-context="run"]');
        const config = formEl ? HSync.collectConfig(formEl) : {};
        const configSlug  = $('[data-field="run-config-slug"]').value;
        const mappingSlug = $('[data-field="run-mapping"]').value;
        const mapping     = mappingSlug ? HSync.state.mappings.find(m => m.slug === mappingSlug) : null;
        const options     = mapping ? { mapping: mapping.config } : {};

        // Build a tiny picker as a prompt — keeps the dependency surface
        // small (no modal library). User can paste a custom cron too.
        const presetList = HSync.cronPresets
            .map((p, i) => '  ' + (i + 1) + ') ' + p.label + '   →  ' + p.expr)
            .join('\n');
        const choice = prompt(
            'Inserisci una cron expression (5 campi)\n\n' +
            'Presets:\n' + presetList + '\n\n' +
            'Digita il numero di un preset OPPURE l\'espressione cron completa:',
            '0 * * * *',
        );
        if (!choice) return;

        let cron = choice.trim();
        const idx = parseInt(cron, 10);
        if (!isNaN(idx) && cron === String(idx) && idx >= 1 && idx <= HSync.cronPresets.length) {
            cron = HSync.cronPresets[idx - 1].expr;
        }

        // Job ref: 'sourceId' for inline config, 'sourceId/configSlug' for stored.
        const ref = configSlug ? (sourceId + '/' + configSlug) : sourceId;

        // Job config: when using a saved config, only options travel; the
        // dispatcher hydrates the rest from SourceConfigRepository.
        // When inline, ship the full form payload so the job is self-contained.
        const jobConfig = configSlug
            ? { options: options }
            : { inline_config: config, options: options };

        try {
            const data = await HSync.ajax('job_save', {
                id:            '0',
                runnable_type: 'source.import',
                runnable_ref:  ref,
                cron_expr:     cron,
                enabled:       '1',
                config:        jobConfig,
            });
            const next = data.next_run_at || '—';
            if (confirm('Automazione creata.\nProssima esecuzione: ' + next + '\n\nApri il tab Automatizza?')) {
                HSync.switchTab('jobs');
            }
        } catch (e) {
            alert('Errore creazione job: ' + e.message);
        }
    };

    /**
     * Run the configured source, auto-resuming whenever the server
     * yields with status='continue' (cooperative deadline). Each tick
     * is capped server-side at ~25s so Apache workers don't stall;
     * the JS loops fresh AJAX calls until the run finishes or fails.
     *
     * Accumulates rows across ticks so the result table grows
     * incrementally — the user sees progress live instead of staring
     * at a spinner for the full duration.
     */
    HSync.runNow = async function () {
        const sourceId = $('[data-field="run-source"]').value;
        if (!sourceId) { alert('Scegli una sorgente.'); return; }
        const formEl = $('[data-context="run"]');
        const config = formEl ? HSync.collectConfig(formEl) : {};
        const configSlug = $('[data-field="run-config-slug"]').value;
        const dryRun = $('[data-field="run-dry-run"]').checked;
        const mappingSlug = $('[data-field="run-mapping"]').value;
        const mapping = mappingSlug ? HSync.state.mappings.find(m => m.slug === mappingSlug) : null;
        const pipelineSlug = ($('[data-field="run-pipeline"]') || {}).value || '';
        const options = {};
        if (mapping)        options.mapping = mapping.config;
        if (pipelineSlug)   options.pipeline_slug = pipelineSlug;

        const out = $('[data-region="run-output"]');
        out.innerHTML = '<p class="hsync-loading">Tick 1: starting…</p>';

        const accumulated = { rows: [], summary: null, warnings: [], runId: null };
        let cursor = null;
        let tick = 0;
        const maxTicks = 200;  // hard cap — refuse to loop forever on a misbehaving source

        try {
            while (tick < maxTicks) {
                tick++;
                const data = await HSync.ajax('run_now', {
                    source_id:   sourceId,
                    config_slug: configSlug,
                    config:      config,
                    options:     options,
                    dry_run:     dryRun ? '1' : '0',
                    cursor:      cursor || {},
                });

                accumulated.runId    = data.run_id || accumulated.runId;
                accumulated.summary  = data.summary || accumulated.summary;
                accumulated.warnings = data.warnings || accumulated.warnings;
                if (Array.isArray(data.rows)) {
                    // Server returns rows from THIS tick only; concat to grow live.
                    accumulated.rows = accumulated.rows.concat(data.rows).slice(0, 1000);
                }

                HSync.renderRunResult({
                    status:   data.status,
                    run_id:   accumulated.runId,
                    summary:  accumulated.summary,
                    warnings: accumulated.warnings,
                    rows:     accumulated.rows,
                    progress: data.progress,
                    tick:     tick,
                });

                if (data.status === 'continue') {
                    cursor = data.cursor || null;
                    if (!cursor || cursor.index === undefined) break;  // server forgot a cursor — bail
                    continue;
                }
                break;  // 'done' or 'failed'
            }
            if (tick >= maxTicks) {
                out.insertAdjacentHTML('beforeend',
                    '<div class="hsync-warning">Auto-resume cap raggiunto (' + maxTicks + ' tick). Run interrotto. Apri Runs per riprendere se necessario.</div>');
            }
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">Tick ' + tick + ' fallito: ' + esc(e.message) + '</div>';
        }
    };

    HSync.renderRunResult = function (data) {
        const out = $('[data-region="run-output"]');
        const s   = data.summary || {};
        const p   = data.progress || {};
        const stat = (label, num, kind) =>
            '<div class="hsync-stat ' + (kind || '') + '"><div class="hsync-stat-num">' + (num || 0) + '</div><div class="hsync-stat-label">' + label + '</div></div>';

        // Status banner: continue→spinner, done→green, failed→red.
        let status = data.status || '';
        let statusBadge;
        if (status === 'continue') {
            const pct = p.total ? Math.round((p.done || 0) * 100 / p.total) : 0;
            statusBadge = '<span class="hsync-action-pill is-updated">running</span> '
                + 'tick ' + (data.tick || 1)
                + ' · ' + (p.done || 0) + '/' + (p.total || '?')
                + ' (' + pct + '%)';
        } else if (status === 'done') {
            statusBadge = '<span class="hsync-action-pill is-created">done</span>';
        } else if (status === 'failed') {
            statusBadge = '<span class="hsync-action-pill is-failed">failed</span>';
        } else {
            statusBadge = '<span class="hsync-action-pill is-skipped">' + esc(status) + '</span>';
        }

        // `unchanged` items were never processed (diff said they're identical
        // to the existing product). The processing pool is `new + update`,
        // and the result must reconcile to that count. We surface every
        // bucket — including the four blocking/failure paths — so users can
        // see exactly where each item went.
        const processingPool = (s.new || 0) + (s.update || 0);
        const accounted = (s.created || 0) + (s.updated || 0) + (s.skipped || 0)
                        + (s.failed || 0) + (s.pre_blocked || 0) + (s.post_blocked || 0);
        const inFlight = Math.max(0, processingPool - accounted);
        const reconcileBad = (status === 'done' && inFlight > 0) ? 'is-bad' : '';

        const summary = ''
            + '<div class="hsync-summary-section">'
            +   '<div class="hsync-summary-label">Source diff</div>'
            +   '<div class="hsync-summary">'
            +     stat('Fetched',   s.fetched)
            +     stat('New',       s.new)
            +     stat('Update',    s.update)
            +     stat('Unchanged (skipped by diff)', s.unchanged, 'is-dim')
            +   '</div>'
            + '</div>'
            + '<div class="hsync-summary-section">'
            +   '<div class="hsync-summary-label">Processing pool: ' + processingPool + ' items (new + update)</div>'
            +   '<div class="hsync-summary">'
            +     stat('Created',          s.created,      (s.created || 0) > 0 ? 'is-good' : '')
            +     stat('Updated',          s.updated,      (s.updated || 0) > 0 ? 'is-good' : '')
            +     stat('Skipped (dry/no-op)', s.skipped,   (s.skipped || 0) > 0 ? 'is-dim'  : '')
            +     stat('Failed',           s.failed,       (s.failed  || 0) > 0 ? 'is-bad'  : '')
            +     stat('Pre-check blocked',  s.pre_blocked,  (s.pre_blocked  || 0) > 0 ? 'is-bad' : '')
            +     stat('Post-check blocked', s.post_blocked, (s.post_blocked || 0) > 0 ? 'is-bad' : '')
            +   '</div>'
            +   (status === 'done' && processingPool > 0
                ? '<div class="hsync-summary-foot ' + reconcileBad + '">'
                +   'Reconciled ' + accounted + '/' + processingPool
                +   (inFlight > 0
                    ? ' — <strong>' + inFlight + ' unaccounted</strong> (likely a runtime error mid-loop; check the run log)'
                    : ' ✓')
                + '</div>'
                : '')
            + '</div>';

        const warns = (data.warnings || []).map(w =>
            '<div class="hsync-warning">' + esc(w) + '</div>'
        ).join('');

        const rows = (data.rows || []).map((r, i) => {
            const klass  = r.action === 'failed' ? 'is-error' : '';
            const pillKl = 'is-' + (r.action || 'skipped');
            // `error` wins over `reason` — both feed the same column.
            // Skip reasons explain non-failure decisions (dry_run,
            // conflict_block, no_change, etc.) so the user knows whether
            // a "skipped" line is benign or worth investigating.
            const detail = r.error
                ? esc(r.error)
                : (r.reason ? '<span class="hsync-row-reason">' + esc(r.reason) + '</span>' : '');
            return '<tr class="' + klass + '"><td>' + (i + 1) + '</td>'
                + '<td>' + (r.pid || '—') + '</td>'
                + '<td><code>' + esc(r.sku) + '</code></td>'
                + '<td><span class="hsync-action-pill ' + pillKl + '">' + esc(r.action) + '</span></td>'
                + '<td>' + detail + '</td></tr>';
        }).join('');

        out.innerHTML = ''
            + '<h3>Run #' + (data.run_id || '?') + ' — ' + statusBadge + '</h3>'
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
        if (t.matches('[data-action="src-config-save"]'))   return HSync.saveSourceConfig(t.dataset.source);
        if (t.matches('[data-action="src-config-reset"]'))  return HSync.resetSourceConfigEditor(t.dataset.source);
        if (t.matches('[data-action="src-config-load"]'))   return HSync.loadSourceConfigEditor(t.dataset.source, t.dataset.slug);
        if (t.matches('[data-action="src-config-delete"]')) return HSync.deleteSourceConfig(t.dataset.slug);
        if (t.matches('[data-action="mapping-new"]'))   return HSync.openMappingEditor(null);
        if (t.matches('[data-action="mapping-edit"]')) {
            const m = HSync.state.mappings.find(x => x.slug === t.dataset.slug);
            return HSync.openMappingEditor(m);
        }
        if (t.matches('[data-action="mapping-delete"]')) return HSync.deleteMapping(t.dataset.slug);
        if (t.matches('[data-action="mapping-save"]'))   return HSync.saveMapping();
        if (t.matches('[data-action="mapping-cancel"]')) return HSync.closeMappingEditor();
        if (t.matches('[data-action="mapping-probe"]'))          return HSync.probeMappingSource();
        if (t.matches('[data-action="mapping-toggle-advanced"]')) return HSync.toggleAdvancedMapping();
        if (t.matches('[data-action="mapping-clear"]'))          return HSync.clearMappingValue(t.dataset.key);
        if (t.matches('[data-action="mapping-add-custom"]'))     return HSync.addCustomMappingRow();
        if (t.matches('[data-action="mapping-custom-delete"]'))  return HSync.deleteCustomMappingRow(parseInt(t.dataset.idx, 10));
        if (t.matches('[data-action="mapping-json-apply"]'))     return HSync.applyMappingJson();
        if (t.matches('[data-action="mapping-insert-token"]'))   return HSync.insertMappingToken(t.dataset.token);
        if (t.matches('[data-action="install-defaults"]'))       return HSync.installDefaults(false);
        if (t.matches('[data-action="install-defaults-force"]')) return HSync.installDefaults(true);
        if (t.matches('[data-action="run-now"]'))        return HSync.runNow();
        if (t.matches('[data-action="run-test-fetch"]')) return HSync.testFetchFromRun();
        if (t.matches('[data-action="run-save-config"]'))return HSync.saveCurrentConfig();
        if (t.matches('[data-action="run-save-job"]'))   return HSync.saveCurrentAsJob();
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
        if (t.matches('[data-action="pipeline-add-op"]'))   return HSync.addPipelineStep('operation');
        if (t.matches('[data-action="pipeline-add-chk"]'))  return HSync.addPipelineStep('check');
        if (t.matches('[data-action="pipeline-add-pre"]'))  return HSync.addPipelineStep('pre_check');
        if (t.matches('[data-action="pipeline-add-rule"]')) return HSync.addPipelineStep('import_rule');
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

        // Tools
        if (t.matches('[data-action="tools-preview"]'))       return HSync.toolsPreview();
        if (t.matches('[data-action="tools-execute"]'))       return HSync.toolsExecute();
        if (t.matches('[data-action="tools-source-count"]'))  return HSync.toolsSourceCount();
        if (t.matches('[data-action="tools-source-delete"]')) return HSync.toolsSourceDelete();

        // Media
        if (t.matches('[data-action="media-search"]'))             return HSync.loadMedia(1);
        if (t.matches('[data-action="media-rebuild-index"]'))      return HSync.rebuildMediaIndex();
        if (t.matches('[data-action="media-cleanup-preview"]'))    return HSync.previewMediaCleanup();
        if (t.matches('[data-action="media-cleanup-confirm"]'))    return HSync.confirmMediaCleanup(t.dataset.ids || '[]');
        if (t.matches('[data-action="media-page"]'))               return HSync.loadMedia(parseInt(t.dataset.page, 10));
        if (t.matches('[data-action="media-whitelist-toggle"]'))   return HSync.toggleMediaWhitelist(parseInt(t.dataset.id, 10));
        if (t.matches('[data-action="media-delete-one"]'))         return HSync.deleteMediaOne(parseInt(t.dataset.id, 10));
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-control="mappings-filter"]')) HSync.loadMappings();
        if (e.target.matches('[data-field="run-source"]'))        HSync.populateRunMappings();
        if (e.target.matches('[data-field="job-type"]'))          { HSync.state.editingJob.runnable_type = e.target.value; HSync.renderJobEditor(); }
        if (e.target.matches('[data-field="job-ref"]'))           { HSync.state.editingJob.runnable_ref  = e.target.value; if (HSync.state.editingJob.runnable_type === 'source.import') HSync.loadSourceConfigs(e.target.value).then(() => HSync.renderJobEditor()); }
        if (e.target.matches('[data-action="media-toggle"]'))     HSync.toggleMediaSelection(parseInt(e.target.dataset.id, 10));
        if (e.target.matches('[data-action="mapping-toggle-json"]')) HSync.toggleMappingJson(e.target.checked);
        if (e.target.matches('[data-action="mapping-insert-snippet"]')) {
            const sel = e.target;
            const idx = parseInt(sel.value, 10);
            sel.value = '';
            if (!Number.isNaN(idx)) HSync.insertMappingSnippet(idx);
        }

        // Custom row key rename fires on commit (blur), not on every keystroke
        if (e.target.matches('[data-custom-key]')) {
            const idx = parseInt(e.target.dataset.customKey, 10);
            HSync.renameCustomMappingKey(idx, e.target.value);
        }
    });

    document.addEventListener('input', function (e) {
        // Live-sync spine value inputs into state.
        if (e.target.matches('[data-mapping-key]')) {
            HSync.state.mappingValues[e.target.dataset.mappingKey] = e.target.value;
        }
        // Live-sync custom row VALUE inputs (key inputs sync on change).
        if (e.target.matches('[data-custom-value]')) {
            const idx = parseInt(e.target.dataset.customValue, 10);
            const key = HSync.state.mappingCustomKeys[idx];
            if (key) HSync.state.mappingValues[key] = e.target.value;
        }
    });

    document.addEventListener('focusin', function (e) {
        if (e.target.matches('[data-mapping-key]') || e.target.matches('[data-custom-value]')) {
            HSync.onMappingValueFocus(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        // Enter inside the media filename input triggers a search.
        if (e.target.matches('[data-field="media-filename"]') && e.key === 'Enter') {
            e.preventDefault();
            HSync.loadMedia(1);
        }
    });

    // ─── Media ────────────────────────────────────────────────────

    HSync.state.media = { items: [], page: 1, perPage: 60, total: 0, totalPages: 1, selected: new Set() };

    HSync.loadMedia = function (page) {
        const region = $('[data-region="media-list"]');
        const filename  = ($('[data-field="media-filename"]')  || {}).value || '';
        const usage     = ($('[data-field="media-usage"]')     || {}).value || 'all';
        const whitelist = ($('[data-field="media-whitelist"]') || {}).value || 'all';
        const target = page || HSync.state.media.page || 1;
        region.innerHTML = '<p class="hsync-loading">Caricamento media…</p>';
        return HSync.ajax('media_query', {
            filename: filename, usage: usage, whitelist: whitelist,
            page: target, per_page: HSync.state.media.perPage,
        }).then(data => {
            HSync.state.media.items       = data.items || [];
            HSync.state.media.page        = data.page || 1;
            HSync.state.media.perPage     = data.per_page || 60;
            HSync.state.media.total       = data.total || 0;
            HSync.state.media.totalPages  = data.total_pages || 1;
            HSync.renderMedia();
            HSync.renderMediaPager();
        }).catch(e => {
            region.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        });
    };

    HSync.renderMedia = function () {
        const region = $('[data-region="media-list"]');
        if (!HSync.state.media.items.length) {
            region.innerHTML = '<div class="hsync-empty">Nessun media trovato con i filtri correnti.</div>';
            return;
        }
        const sel = HSync.state.media.selected;
        const cards = HSync.state.media.items.map(it => {
            const usage = it.usage || [];
            const isMapped  = usage.length > 0;
            const isWl      = !!it.is_whitelisted;
            const usageStr  = usage.length
                ? usage.slice(0, 2).map(u => esc(u.role) + ' #' + u.pid + (u.sku ? ' (' + esc(u.sku) + ')' : '')).join(' · ')
                  + (usage.length > 2 ? ' +' + (usage.length - 2) : '')
                : 'orfano';
            const badges = ''
                + (isMapped ? '<span class="hsync-media-badge is-mapped">used</span>' : '<span class="hsync-media-badge is-orphan">orphan</span>')
                + (isWl     ? '<span class="hsync-media-badge is-whitelist">WL</span>' : '');
            const checked = sel.has(it.id) ? ' checked' : '';
            return ''
                + '<div class="hsync-media-card' + (sel.has(it.id) ? ' is-selected' : '') + '" data-id="' + it.id + '">'
                +   '<label class="hsync-media-checkbox"><input type="checkbox" data-action="media-toggle" data-id="' + it.id + '"' + checked + '></label>'
                +   '<div class="hsync-media-badges">' + badges + '</div>'
                +   '<div class="hsync-media-thumb" style="background-image:url(\'' + esc(it.thumbnail_url || it.url) + '\')"></div>'
                +   '<div class="hsync-media-meta">'
                +     '<div class="hsync-media-name" title="' + esc(it.filename) + '">' + esc(it.filename || ('#' + it.id)) + '</div>'
                +     '<div class="hsync-media-size">' + esc(it.filesize_human || '—') + '</div>'
                +     '<div class="hsync-media-size">' + esc(usageStr) + '</div>'
                +     '<div class="hsync-actions" style="margin-top:6px;">'
                +       '<button class="button button-small" data-action="media-whitelist-toggle" data-id="' + it.id + '">'
                +         (isWl ? 'Rimuovi WL' : 'Aggiungi WL')
                +       '</button>'
                +       (isMapped
                          ? ''
                          : '<button class="button button-small" data-action="media-delete-one" data-id="' + it.id + '">Elimina</button>')
                +     '</div>'
                +   '</div>'
                + '</div>';
        }).join('');
        region.innerHTML = '<div class="hsync-media-grid">' + cards + '</div>';
    };

    HSync.renderMediaPager = function () {
        const pager = $('[data-region="media-pager"]');
        const m = HSync.state.media;
        if (m.totalPages <= 1) {
            pager.innerHTML = '<span class="hsync-muted">' + m.total + ' risultati</span>';
            return;
        }
        const prev = m.page > 1
            ? '<button class="button" data-action="media-page" data-page="' + (m.page - 1) + '">←</button>'
            : '';
        const next = m.page < m.totalPages
            ? '<button class="button" data-action="media-page" data-page="' + (m.page + 1) + '">→</button>'
            : '';
        pager.innerHTML = prev
            + ' <span class="hsync-muted">Pagina ' + m.page + ' di ' + m.totalPages + ' · ' + m.total + ' risultati</span> '
            + next;
    };

    HSync.toggleMediaSelection = function (id) {
        const sel = HSync.state.media.selected;
        if (sel.has(id)) sel.delete(id);
        else sel.add(id);
        const card = document.querySelector('.hsync-media-card[data-id="' + id + '"]');
        if (card) card.classList.toggle('is-selected', sel.has(id));
    };

    HSync.toggleMediaWhitelist = async function (id) {
        const item = HSync.state.media.items.find(x => x.id === id);
        if (!item) return;
        try {
            if (item.is_whitelisted) {
                await HSync.ajax('media_whitelist_remove', { attachment_id: id });
            } else {
                const reason = prompt('Motivo della whitelist (opzionale):', '') || '';
                await HSync.ajax('media_whitelist_add', { attachment_id: id, reason: reason });
            }
            await HSync.loadMedia(HSync.state.media.page);
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.deleteMediaOne = async function (id) {
        if (!confirm('Eliminare definitivamente l\'attachment #' + id + ' dalla media library?\n\nFile + thumbnail saranno rimossi dal disco.')) return;
        try {
            const data = await HSync.ajax('media_cleanup_apply', { ids: [id] });
            if ((data.errors || {})[id]) {
                alert('Eliminazione bloccata: ' + data.errors[id]);
            } else if (data.skipped_whitelist?.includes(id)) {
                alert('Skipped: attachment è in whitelist.');
            } else {
                alert('Eliminato. Liberati ' + data.freed_human + '.');
            }
            HSync.loadMedia(HSync.state.media.page);
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.rebuildMediaIndex = async function () {
        try {
            const data = await HSync.ajax('media_index_rebuild', {});
            alert('Indice ricostruito. ' + data.attachments_indexed + ' attachment con utilizzo registrato.');
            HSync.loadMedia(HSync.state.media.page);
        } catch (e) { alert('Errore: ' + e.message); }
    };

    HSync.previewMediaCleanup = async function () {
        const out = $('[data-region="media-cleanup-output"]');
        out.innerHTML = '<p class="hsync-loading">Calcolo orfani…</p>';
        try {
            const data = await HSync.ajax('media_cleanup_preview', {});
            const idsJson = JSON.stringify(data.to_delete_ids || []);
            const wlRows = (data.whitelist_details || []).slice(0, 50).map(w =>
                '<tr><td>#' + w.id + '</td><td>' + esc(w.reason || '—') + '</td><td><a href="' + esc(w.url) + '" target="_blank">link</a></td></tr>'
            ).join('');
            out.innerHTML = ''
                + '<div class="hsync-summary" style="grid-template-columns:repeat(3,1fr);">'
                +   '<div class="hsync-stat"><div class="hsync-stat-num">' + data.total_matched + '</div><div class="hsync-stat-label">Orfani totali</div></div>'
                +   '<div class="hsync-stat is-bad"><div class="hsync-stat-num">' + data.to_delete_count + '</div><div class="hsync-stat-label">Da eliminare</div></div>'
                +   '<div class="hsync-stat"><div class="hsync-stat-num">' + data.whitelisted_count + '</div><div class="hsync-stat-label">Protetti (WL)</div></div>'
                + '</div>'
                + (wlRows ? '<details><summary>Whitelist esclusi (' + data.whitelisted_count + ')</summary>'
                    + '<table class="hsync-table"><thead><tr><th>ID</th><th>Reason</th><th>URL</th></tr></thead><tbody>'
                    + wlRows + '</tbody></table></details>' : '')
                + '<div class="hsync-actions">'
                +   '<button class="button button-primary" data-action="media-cleanup-confirm" data-ids=\'' + idsJson + '\'>'
                +     'Elimina ' + data.to_delete_count + ' orfani'
                +   '</button>'
                + '</div>';
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.confirmMediaCleanup = async function (idsJson) {
        let ids;
        try { ids = JSON.parse(idsJson); } catch (e) { alert('Payload corrotto.'); return; }
        if (!Array.isArray(ids) || !ids.length) { alert('Nessun ID da eliminare.'); return; }
        if (!confirm('Eliminare definitivamente ' + ids.length + ' attachment? L\'operazione non è reversibile.')) return;
        const out = $('[data-region="media-cleanup-output"]');
        out.innerHTML = '<p class="hsync-loading">Eliminazione in corso…</p>';
        try {
            const data = await HSync.ajax('media_cleanup_apply', { ids: ids });
            const errCount = Object.keys(data.errors || {}).length;
            out.innerHTML = ''
                + '<div class="hsync-summary" style="grid-template-columns:repeat(3,1fr);">'
                +   '<div class="hsync-stat is-good"><div class="hsync-stat-num">' + (data.deleted || []).length + '</div><div class="hsync-stat-label">Eliminati</div></div>'
                +   '<div class="hsync-stat"><div class="hsync-stat-num">' + (data.skipped_whitelist || []).length + '</div><div class="hsync-stat-label">Saltati WL</div></div>'
                +   '<div class="hsync-stat ' + (errCount > 0 ? 'is-bad' : '') + '"><div class="hsync-stat-num">' + errCount + '</div><div class="hsync-stat-label">Errori</div></div>'
                + '</div>'
                + '<p>Liberati ' + esc(data.freed_human || '0 B') + ' dal disco.</p>';
            HSync.loadMedia(1);
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    // ─── Tools / Nuclear Cleanup ─────────────────────────────────

    HSync.collectToolsTargets = function () {
        const targets = {};
        $$('[data-tools-target]').forEach(el => {
            if (el.checked) targets[el.dataset.toolsTarget] = true;
        });
        return targets;
    };

    HSync.toolsPreview = async function () {
        const targets = HSync.collectToolsTargets();
        const out = $('[data-region="tools-output"]');
        const exec = $('[data-action="tools-execute"]');
        if (!Object.keys(targets).length) {
            out.innerHTML = '<div class="hsync-warning">Seleziona almeno un target.</div>';
            exec.disabled = true;
            return;
        }
        out.innerHTML = '<p class="hsync-loading">Conteggio in corso…</p>';
        try {
            const data = await HSync.ajax('nuclear_preview', { targets: targets });
            const rows = Object.entries(data.preview || {}).map(([k, v]) => ''
                + '<tr>'
                +   '<td><strong>' + esc(k) + '</strong></td>'
                +   '<td>' + (v.count || 0) + '</td>'
                +   '<td>' + esc(v.label || '') + '</td>'
                + '</tr>'
            ).join('');
            out.innerHTML = rows
                ? '<table class="hsync-table"><thead><tr><th>Target</th><th>Count</th><th>Detail</th></tr></thead><tbody>' + rows + '</tbody></table>'
                : '<div class="hsync-empty">Niente da eliminare per i target scelti.</div>';
            exec.disabled = !rows;
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
            exec.disabled = true;
        }
    };

    HSync.toolsExecute = async function () {
        const targets = HSync.collectToolsTargets();
        if (!Object.keys(targets).length) return;
        const labels = Object.keys(targets).join(', ');
        const phrase = 'ELIMINA';
        const typed = prompt(
            'Stai per ELIMINARE definitivamente: ' + labels + '.\n\n'
            + 'L\'operazione NON è reversibile. Ho già fatto un backup del DB e dei file.\n\n'
            + 'Per confermare, digita ' + phrase + ':',
            '',
        );
        if (typed !== phrase) {
            alert('Annullato.');
            return;
        }
        const out = $('[data-region="tools-output"]');
        out.innerHTML = '<p class="hsync-loading">Cleanup in corso… (può richiedere parecchi minuti su store grandi)</p>';
        try {
            const data = await HSync.ajax('nuclear_execute', { targets: targets, confirm: '1' });
            const rows = Object.entries(data.results || {}).map(([k, v]) => ''
                + '<tr><td><strong>' + esc(k) + '</strong></td><td><pre class="hsync-pre">'
                + esc(typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v)) + '</pre></td></tr>'
            ).join('');
            out.innerHTML = ''
                + '<div class="hsync-summary-foot">Cleanup completato in ' + (data.duration_s || 0) + 's.</div>'
                + (rows
                    ? '<table class="hsync-table"><thead><tr><th>Target</th><th>Result</th></tr></thead><tbody>' + rows + '</tbody></table>'
                    : '');
            $('[data-action="tools-execute"]').disabled = true;
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.toolsSourceCount = async function () {
        const source = ($('[data-field="tools-source"]') || {}).value;
        const out = $('[data-region="tools-source-output"]');
        if (!source) { out.innerHTML = '<div class="hsync-warning">Scegli una sorgente.</div>'; return; }
        out.innerHTML = '<p class="hsync-loading">Conteggio…</p>';
        try {
            const data = await HSync.ajax('nuclear_count_by_source', { source: source });
            out.innerHTML = '<p><strong>' + data.count + '</strong> prodotti taggati come <code>'
                + esc(data.source) + '</code>.</p>';
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    HSync.toolsSourceDelete = async function () {
        const source = ($('[data-field="tools-source"]') || {}).value;
        if (!source) { alert('Scegli una sorgente.'); return; }
        const phrase = 'ELIMINA ' + source.toUpperCase();
        const typed = prompt(
            'Eliminerai TUTTI i prodotti importati da "' + source + '".\n\n'
            + 'Per confermare, digita ' + phrase + ':',
            '',
        );
        if (typed !== phrase) { alert('Annullato.'); return; }
        const out = $('[data-region="tools-source-output"]');
        out.innerHTML = '<p class="hsync-loading">Eliminazione in corso…</p>';
        try {
            const data = await HSync.ajax('nuclear_delete_by_source', { source: source, confirm: '1' });
            out.innerHTML = '<p>Eliminati <strong>' + data.deleted + '</strong> parent + <strong>'
                + data.variations + '</strong> varianti.</p>';
        } catch (e) {
            out.innerHTML = '<div class="hsync-error">' + esc(e.message) + '</div>';
        }
    };

    // ─── Boot ─────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        HSync.loadSources();
    });
})();
