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
            currentTab: 'sources',
            editingMapping: null,
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
        if (name === 'mappings') HSync.loadMappings();
        if (name === 'runs')     HSync.loadRuns();
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
            return;
        }
        const src = HSync.state.sources.find(s => s.id === sourceId);
        if (!src) return;
        region.innerHTML = '<form class="hsync-config-form" data-source="' + esc(src.id) + '" data-context="run">'
            + HSync.renderSchema(src.config_schema, 'run-' + src.id)
            + '</form>';
        // Refresh mapping picker scoped to this source
        if (HSync.state.mappings.length === 0) {
            HSync.loadMappings();
        } else {
            HSync.populateRunMappings();
        }
    };

    HSync.runNow = async function () {
        const sourceId = $('[data-field="run-source"]').value;
        if (!sourceId) { alert('Scegli una sorgente.'); return; }
        const formEl = $('[data-context="run"]');
        const config = formEl ? HSync.collectConfig(formEl) : {};
        const dryRun = $('[data-field="run-dry-run"]').checked;
        const mappingSlug = $('[data-field="run-mapping"]').value;
        const mapping = mappingSlug ? HSync.state.mappings.find(m => m.slug === mappingSlug) : null;
        const options = mapping ? { mapping: mapping.config } : {};

        const out = $('[data-region="run-output"]');
        out.innerHTML = '<p class="hsync-loading">Run in corso…</p>';
        try {
            const data = await HSync.ajax('run_now', {
                source_id: sourceId,
                config:    config,
                options:   options,
                dry_run:   dryRun ? '1' : '0',
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
        if (t.matches('[data-action="runs-refresh"]'))   return HSync.loadRuns();
        if (t.matches('[data-action="legacy-audit"]'))   return HSync.legacyAudit();
        if (t.matches('[data-action="legacy-import"]'))  return HSync.legacyImport();
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-control="mappings-filter"]')) HSync.loadMappings();
        if (e.target.matches('[data-field="run-source"]'))        HSync.populateRunMappings();
    });

    // ─── Boot ─────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        HSync.loadSources();
    });
})();
