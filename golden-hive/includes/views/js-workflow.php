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
        if (!info || !cfg || !next) return;

        if (!id) {
            info.hidden = true; cfg.hidden = true; next.hidden = true;
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
})();
