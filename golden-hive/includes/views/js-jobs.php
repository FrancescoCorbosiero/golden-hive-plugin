// ═══ JOBS ═══════════════════════════════════════════════════════════════════

(function(){

    const ajax  = GH.ajax;
    const toast = GH.toast;
    const esc   = GH.esc;
    const hl    = (window.GH && GH.hl) || function(s){return s;};

    // state
    let jobs = [];
    let kinds = {};
    let editing = null;      // the job record being edited (null when creating)
    let editMode = 'form';   // 'form' | 'code'
    let currentView = 'list';// 'list' | 'log'

    // ── PUBLIC: enter/refresh the jobs tab
    async function jobsReload() {
        const r = await ajax('gh_ajax_jobs_list');
        if (!r.success) { toast('Errore nel caricamento jobs', 'err'); return; }
        jobs  = r.data.jobs || [];
        kinds = r.data.kinds || {};
        populateKindSelect();
        if (currentView === 'list') renderList();
        else jobsLoadLog();
    }

    function jobsShow(view) {
        currentView = view;
        document.getElementById('jobs-list-view').style.display = view === 'list' ? '' : 'none';
        document.getElementById('jobs-log-view').style.display  = view === 'log'  ? '' : 'none';
        document.getElementById('btn-jobs-view-list').style.borderColor = view === 'list' ? 'var(--acc)' : 'var(--b1)';
        document.getElementById('btn-jobs-view-log').style.borderColor  = view === 'log'  ? 'var(--acc)' : 'var(--b1)';
        document.getElementById('btn-jobs-clear-log').style.display     = view === 'log'  ? '' : 'none';
        if (view === 'log') jobsLoadLog();
    }

    function renderList() {
        const area = document.getElementById('jobs-list-area');
        if (!jobs.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9202;</div><div class="empty-text">Nessun job schedulato.<br>Crea un job per eseguire operazioni periodiche via wp-cron.</div></div>';
            return;
        }
        let h = '<table class="ptable"><thead><tr>';
        h += '<th>Stato</th><th>Label</th><th>Kind</th><th>Cron</th><th>Prossimo</th><th>Ultimo</th><th>Runs</th><th>Azioni</th>';
        h += '</tr></thead><tbody>';
        for (const j of jobs) {
            const enabled = j.enabled;
            const lastCls = j.last_status === 'done' ? 'green' : j.last_status === 'error' || j.last_status === 'crashed' ? 'red' : j.last_status === 'continue' ? 'amber' : 'dim';
            const nextIso = j.next_run_at ? new Date(j.next_run_at * 1000).toLocaleString('it-IT') : '–';
            const lastIso = j.last_run_at ? new Date(j.last_run_at).toLocaleString('it-IT') : '–';
            const kindLbl = kinds[j.kind] ? kinds[j.kind].label : j.kind;
            h += '<tr>';
            h += '<td><span class="' + (enabled ? 'green' : 'dim') + '" style="font-family:var(--mono);font-size:10px">' + (enabled ? '● ON' : '○ OFF') + '</span></td>';
            h += '<td>' + esc(j.label || '') + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px;color:var(--dim)">' + esc(kindLbl) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + esc(j.cron || '') + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + nextIso + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px"><span class="' + lastCls + '">' + (j.last_status || '–') + '</span> ' + lastIso + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + (j.run_count || 0) + '</td>';
            h += '<td><button class="btn btn-ghost" onclick="GH.jobsRunNow(\'' + j.id + '\')">Run</button>';
            h += ' <button class="btn btn-ghost" onclick="GH.jobsToggle(\'' + j.id + '\')">' + (enabled ? 'Pausa' : 'Attiva') + '</button>';
            h += ' <button class="btn btn-ghost" onclick="GH.jobsEdit(\'' + j.id + '\')">Edit</button>';
            h += ' <button class="btn btn-ghost" style="color:var(--red)" onclick="GH.jobsDelete(\'' + j.id + '\')">Del</button>';
            h += '</td></tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    function populateKindSelect() {
        const sel = document.getElementById('jobs-f-kind');
        if (!sel) return;
        sel.innerHTML = '';
        for (const slug in kinds) {
            const o = document.createElement('option');
            o.value = slug;
            o.textContent = kinds[slug].label + '  (' + slug + ')';
            sel.appendChild(o);
        }
    }

    // ── EDITOR
    function jobsNew() {
        editing = null;
        document.getElementById('jobs-editor-title').textContent = 'Nuovo job';
        document.getElementById('jobs-f-label').value = '';
        document.getElementById('jobs-f-cron').value  = '0 * * * *';
        document.getElementById('jobs-f-every').value = 1;
        document.getElementById('jobs-f-unit').value  = 'hour';
        document.getElementById('jobs-f-max-runtime').value = 3600;
        document.getElementById('jobs-f-tick-budget').value = 25;
        document.getElementById('jobs-f-enabled').checked = true;
        if (Object.keys(kinds).length) {
            document.getElementById('jobs-f-kind').value = Object.keys(kinds)[0];
        }
        renderParamsForm({});
        jobsPreviewCron();
        document.getElementById('jobs-editor').style.display = '';
        jobsSetEditMode('form');
    }

    async function jobsEdit(id) {
        const job = jobs.find(j => j.id === id);
        if (!job) return;
        editing = JSON.parse(JSON.stringify(job));
        document.getElementById('jobs-editor-title').textContent = 'Edit: ' + (job.label || id);
        document.getElementById('jobs-f-label').value = job.label || '';
        document.getElementById('jobs-f-kind').value  = job.kind || '';
        document.getElementById('jobs-f-cron').value  = job.cron || '';
        document.getElementById('jobs-f-max-runtime').value = job.max_runtime || 3600;
        document.getElementById('jobs-f-tick-budget').value = job.tick_budget || 25;
        document.getElementById('jobs-f-enabled').checked   = !!job.enabled;
        renderParamsForm(job.params || {});
        jobsPreviewCron();
        document.getElementById('jobs-editor').style.display = '';
        jobsSetEditMode('form');
    }

    function jobsCancelEdit() {
        editing = null;
        document.getElementById('jobs-editor').style.display = 'none';
    }

    function jobsSetEditMode(mode) {
        editMode = mode;
        document.getElementById('jobs-edit-form').style.display = mode === 'form' ? '' : 'none';
        document.getElementById('jobs-edit-code').style.display = mode === 'code' ? '' : 'none';
        document.getElementById('jobs-edit-mode-form').style.borderColor = mode === 'form' ? 'var(--acc)' : 'var(--b1)';
        document.getElementById('jobs-edit-mode-code').style.borderColor = mode === 'code' ? 'var(--acc)' : 'var(--b1)';
        if (mode === 'code') {
            // serialize current form into JSON
            const payload = buildRecordFromForm();
            document.getElementById('jobs-f-json').value = JSON.stringify(payload, null, 2);
        }
    }

    function buildRecordFromForm() {
        return {
            id:          editing ? editing.id : '',
            label:       document.getElementById('jobs-f-label').value,
            kind:        document.getElementById('jobs-f-kind').value,
            cron:        document.getElementById('jobs-f-cron').value,
            enabled:     document.getElementById('jobs-f-enabled').checked,
            max_runtime: parseInt(document.getElementById('jobs-f-max-runtime').value, 10) || 3600,
            tick_budget: parseInt(document.getElementById('jobs-f-tick-budget').value, 10) || 25,
            params:      collectParamsFromForm(),
        };
    }

    function jobsOnKindChange() {
        const kind = document.getElementById('jobs-f-kind').value;
        const def  = kinds[kind];
        const current = editing && editing.kind === kind ? (editing.params || {}) : {};
        renderParamsForm(current);
        if (!def) return;
    }

    function renderParamsForm(values) {
        const wrap = document.getElementById('jobs-f-params');
        const kind = document.getElementById('jobs-f-kind').value;
        const def  = kinds[kind];
        wrap.innerHTML = '';
        if (!def || !def.params) {
            wrap.innerHTML = '<div style="font-family:var(--mono);font-size:10px;color:var(--dim)">Nessun parametro</div>';
            return;
        }
        for (const key in def.params) {
            const spec = def.params[key];
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center';
            const lab = document.createElement('span');
            lab.textContent = (spec.label || key) + (spec.required ? ' *' : '');
            lab.style.cssText = 'font-family:var(--mono);font-size:11px;color:var(--dim);min-width:150px';
            row.appendChild(lab);
            const val = values[key] !== undefined ? values[key] : (spec.default !== undefined ? spec.default : '');
            let inp;
            if (spec.type === 'bool') {
                inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.checked = !!val;
            } else if (spec.type === 'enum') {
                inp = document.createElement('select');
                inp.className = 'cfg-select';
                (spec.options || []).forEach(op => {
                    const o = document.createElement('option');
                    o.value = op; o.textContent = op;
                    if (op === val) o.selected = true;
                    inp.appendChild(o);
                });
            } else {
                inp = document.createElement('input');
                inp.className = 'cfg-input';
                inp.type = 'text';
                inp.value = val == null ? '' : String(val);
                inp.style.flex = '1';
            }
            inp.dataset.paramKey = key;
            inp.dataset.paramType = spec.type || 'string';
            row.appendChild(inp);
            wrap.appendChild(row);
        }
    }

    function collectParamsFromForm() {
        const wrap = document.getElementById('jobs-f-params');
        const out  = {};
        wrap.querySelectorAll('[data-param-key]').forEach(el => {
            const key = el.dataset.paramKey;
            const t   = el.dataset.paramType;
            if (t === 'bool') out[key] = el.checked;
            else out[key] = el.value;
        });
        return out;
    }

    async function jobsApplySimple() {
        const every = parseInt(document.getElementById('jobs-f-every').value, 10) || 1;
        const unit  = document.getElementById('jobs-f-unit').value;
        const r = await ajax('gh_ajax_jobs_cron_simple', { every, unit });
        if (!r.success) { toast(r.data, 'err'); return; }
        document.getElementById('jobs-f-cron').value = r.data.cron;
        jobsPreviewCron();
    }

    let previewTimer;
    function jobsPreviewCron() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            const expr = document.getElementById('jobs-f-cron').value;
            if (!expr) return;
            const r = await ajax('gh_ajax_jobs_cron_preview', { cron: expr });
            const area = document.getElementById('jobs-f-preview');
            if (!r.success) { area.innerHTML = '<span style="color:var(--red)">' + esc(r.data) + '</span>'; return; }
            const runs = (r.data.next_runs || []).map(x => x.iso).join(' · ');
            area.innerHTML = '<span style="color:var(--acc)">' + esc(r.data.description) + '</span> — ' + esc(runs);
        }, 300);
    }

    async function jobsSave() {
        const sp = document.getElementById('jobs-save-spin');
        sp.style.display = '';
        try {
            let r;
            if (editMode === 'code') {
                r = await ajax('gh_ajax_jobs_save', { job_json: document.getElementById('jobs-f-json').value });
            } else {
                const rec = buildRecordFromForm();
                r = await ajax('gh_ajax_jobs_save', {
                    id:          rec.id || '',
                    label:       rec.label,
                    kind:        rec.kind,
                    cron:        rec.cron,
                    enabled:     rec.enabled ? '1' : '',
                    max_runtime: String(rec.max_runtime),
                    tick_budget: String(rec.tick_budget),
                    params_json: JSON.stringify(rec.params),
                });
            }
            if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
            toast('Job salvato', 'ok');
            jobsCancelEdit();
            jobsReload();
        } finally {
            sp.style.display = 'none';
        }
    }

    async function jobsDelete(id) {
        if (!confirm('Eliminare questo job?')) return;
        const r = await ajax('gh_ajax_jobs_delete', { job_id: id });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Job eliminato', 'ok');
        jobsReload();
    }

    async function jobsToggle(id) {
        const r = await ajax('gh_ajax_jobs_toggle', { job_id: id });
        if (!r.success) { toast('Errore', 'err'); return; }
        jobsReload();
    }

    async function jobsRunNow(id) {
        if (!confirm('Eseguire subito questo job? (Rispetta il lock — se in corso salterà)')) return;
        toast('Avviato...', 'inf');
        const r = await ajax('gh_ajax_jobs_run_now', { job_id: id });
        if (!r.success) { toast('Errore: ' + r.data, 'err'); return; }
        toast('Run: ' + (r.data.status || 'done'), r.data.status === 'error' ? 'err' : 'ok');
        jobsReload();
    }

    // ── LOG
    async function jobsLoadLog() {
        const r = await ajax('gh_ajax_jobs_log', { limit: 100 });
        const area = document.getElementById('jobs-log-area');
        if (!r.success || !r.data || !r.data.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9776;</div><div class="empty-text">Nessun run registrato</div></div>';
            return;
        }
        let h = '<table class="ptable"><thead><tr>';
        h += '<th>Inizio</th><th>Job</th><th>Kind</th><th>Stato</th><th>Durata</th><th>Ticks</th><th>Trigger</th><th>Dettaglio</th>';
        h += '</tr></thead><tbody>';
        for (const e of r.data) {
            const cls = e.status === 'done' ? 'green' : e.status === 'error' || e.status === 'crashed' ? 'red' : e.status === 'continue' ? 'amber' : 'dim';
            const detail = e.error
                ? '<span style="color:var(--red)">' + esc(e.error) + '</span>'
                : e.summary ? esc(JSON.stringify(e.summary)) : '';
            h += '<tr>';
            h += '<td style="font-size:10px">' + new Date(e.started_at).toLocaleString('it-IT') + '</td>';
            h += '<td>' + esc(e.job_label) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px;color:var(--dim)">' + esc(e.kind) + '</td>';
            h += '<td class="' + cls + '" style="font-family:var(--mono);font-size:10px">' + esc(e.status) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + ((e.duration_ms || 0) / 1000).toFixed(1) + 's</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + (e.ticks || 1) + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + esc(e.trigger || 'cron') + '</td>';
            h += '<td style="font-family:var(--mono);font-size:10px">' + detail + '</td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    async function jobsClearLog() {
        if (!confirm('Svuotare il run log?')) return;
        await ajax('gh_ajax_jobs_log_clear');
        toast('Log svuotato', 'ok');
        jobsLoadLog();
    }

    // ── expose
    GH.jobsReload      = jobsReload;
    GH.jobsShow        = jobsShow;
    GH.jobsNew         = jobsNew;
    GH.jobsEdit        = jobsEdit;
    GH.jobsCancelEdit  = jobsCancelEdit;
    GH.jobsSetEditMode = jobsSetEditMode;
    GH.jobsOnKindChange= jobsOnKindChange;
    GH.jobsApplySimple = jobsApplySimple;
    GH.jobsPreviewCron = jobsPreviewCron;
    GH.jobsSave        = jobsSave;
    GH.jobsDelete      = jobsDelete;
    GH.jobsToggle      = jobsToggle;
    GH.jobsRunNow      = jobsRunNow;
    GH.jobsLoadLog     = jobsLoadLog;
    GH.jobsClearLog    = jobsClearLog;

    // auto-load when the jobs tab is activated
    const origSwitch = GH.switchTab;
    GH.switchTab = function(tab, el) { origSwitch(tab, el); if (tab === 'jobs') jobsReload(); };

})();
