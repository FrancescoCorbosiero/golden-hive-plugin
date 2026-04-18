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
    let activeFilter = 'all';// 'all' | 'on' | 'off' | 'error'

    // ── Kind category/color map — keeps cards grouped by intent
    //    (feed/email/maintenance/ops/export/generic), so the panel
    //    doesn't read as "one giant control grid".
    const KIND_CATEGORIES = {
        goldensneakers_feed: { group: 'feed',     color: 'blu',  groupLabel: 'Feed prodotti' },
        email_campaign:      { group: 'email',    color: 'grn',  groupLabel: 'Email' },
        media_cleanup:       { group: 'maint',    color: 'amb',  groupLabel: 'Manutenzione' },
        bulk_action:         { group: 'ops',      color: 'pur',  groupLabel: 'Operazioni bulk' },
        catalog_export:      { group: 'export',   color: 'cya',  groupLabel: 'Export catalogo' },
        rest_call:           { group: 'generic',  color: 'dim',  groupLabel: 'Integrazioni' },
    };
    const DEFAULT_KIND_CATEGORY = { group: 'generic', color: 'dim', groupLabel: 'Altri' };
    const GROUP_ORDER = ['feed','email','maint','ops','export','generic'];

    function kindMeta(kind) {
        return KIND_CATEGORIES[kind] || DEFAULT_KIND_CATEGORY;
    }

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
        document.getElementById('btn-jobs-view-list').classList.toggle('is-active', view === 'list');
        document.getElementById('btn-jobs-view-log').classList.toggle('is-active', view === 'log');
        document.getElementById('btn-jobs-clear-log').style.display = view === 'log'  ? '' : 'none';
        if (view === 'log') jobsLoadLog();
    }

    // ── Helpers: human-friendly cron + relative times ───────────

    function cronHuman(expr) {
        if (!expr) return '';
        const parts = expr.trim().split(/\s+/);
        if (parts.length !== 5) return expr;
        const [m, h, dom, mon, dow] = parts;
        const allStar = dom === '*' && mon === '*' && dow === '*';
        const dayNames = ['domenica','lunedì','martedì','mercoledì','giovedì','venerdì','sabato'];
        const pad = n => String(n).padStart(2, '0');

        if (m === '*' && h === '*' && allStar) return 'Ogni minuto';

        let x = m.match(/^\*\/(\d+)$/);
        if (x && h === '*' && allStar) return 'Ogni ' + x[1] + ' minuti';

        if (/^\d+$/.test(m) && h === '*' && allStar) {
            return m === '0' ? 'Ogni ora' : 'Ogni ora al minuto ' + m;
        }

        x = h.match(/^\*\/(\d+)$/);
        if (x && /^\d+$/.test(m) && allStar) {
            return 'Ogni ' + x[1] + ' ore' + (m !== '0' ? ' al minuto ' + m : '');
        }

        if (/^\d+$/.test(m) && /^\d+$/.test(h) && dom === '*' && mon === '*') {
            const time = pad(h) + ':' + pad(m);
            if (dow === '*') return 'Ogni giorno alle ' + time;
            if (/^\d+$/.test(dow)) return 'Ogni ' + dayNames[parseInt(dow, 10) % 7] + ' alle ' + time;
        }

        if (/^\d+$/.test(m) && /^\d+$/.test(h) && /^\d+$/.test(dom) && mon === '*' && dow === '*') {
            return 'Il giorno ' + dom + ' di ogni mese alle ' + pad(h) + ':' + pad(m);
        }

        return expr; // fallback: raw cron
    }

    function relTime(ts) {
        if (!ts) return null;
        const date = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
        if (isNaN(date.getTime())) return null;
        const diff = (Date.now() - date.getTime()) / 1000;
        const future = diff < 0;
        const abs = Math.abs(diff);
        let label;
        if (abs < 45)        label = 'adesso';
        else if (abs < 3600) label = Math.round(abs / 60) + 'm';
        else if (abs < 86400) label = Math.round(abs / 3600) + 'h';
        else if (abs < 2592000) label = Math.round(abs / 86400) + 'g';
        else if (abs < 31104000) label = Math.round(abs / 2592000) + ' mesi';
        else label = Math.round(abs / 31104000) + ' anni';
        if (label === 'adesso') return future ? 'a momenti' : 'adesso';
        return future ? 'tra ' + label : label + ' fa';
    }

    function absDate(ts) {
        if (!ts) return '';
        const d = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleString('it-IT');
    }

    // ── Filters + counts ────────────────────────────────────────

    function jobMatchesFilter(j, filter) {
        switch (filter) {
            case 'on':    return !!j.enabled;
            case 'off':   return !j.enabled;
            case 'error': return j.last_status === 'error' || j.last_status === 'crashed';
            default:      return true;
        }
    }

    function renderChips() {
        const box = document.getElementById('jobs-list-chips');
        if (!box) return;
        if (!jobs.length) { box.style.display = 'none'; return; }
        const counts = {
            all:   jobs.length,
            on:    jobs.filter(j => jobMatchesFilter(j, 'on')).length,
            off:   jobs.filter(j => jobMatchesFilter(j, 'off')).length,
            error: jobs.filter(j => jobMatchesFilter(j, 'error')).length,
        };
        const chips = [
            ['all',   'Tutti'],
            ['on',    'Attivi'],
            ['off',   'In pausa'],
            ['error', 'In errore'],
        ];
        let h = '';
        for (const [key, label] of chips) {
            const cls = 'gh-job-chip' + (activeFilter === key ? ' is-active' : '') + (key === 'error' && counts.error ? ' gh-job-chip-warn' : '');
            h += '<button type="button" class="' + cls + '" onclick="GH.jobsSetFilter(\'' + key + '\')">'
              +    '<span class="gh-job-chip-label">' + label + '</span>'
              +    '<span class="gh-job-chip-count">' + counts[key] + '</span>'
              +  '</button>';
        }
        box.innerHTML = h;
        box.style.display = 'flex';
    }

    function jobsSetFilter(filter) {
        activeFilter = filter;
        renderList();
    }

    function renderList() {
        const area = document.getElementById('jobs-list-area');

        renderChips();

        if (!jobs.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9202;</div>'
              + '<div class="empty-text">Nessun job schedulato.<br>Crea un job per eseguire operazioni periodiche via wp-cron.</div></div>';
            return;
        }

        const filtered = jobs.filter(j => jobMatchesFilter(j, activeFilter));
        if (!filtered.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9788;</div>'
              + '<div class="empty-text">Nessun job corrisponde al filtro selezionato.</div></div>';
            return;
        }

        // Group by category, then sort each group by label
        const groups = {};
        for (const j of filtered) {
            const meta = kindMeta(j.kind);
            (groups[meta.group] = groups[meta.group] || { label: meta.groupLabel, jobs: [] }).jobs.push(j);
        }
        for (const g of Object.values(groups)) g.jobs.sort((a, b) => (a.label || '').localeCompare(b.label || ''));

        let h = '';
        for (const groupKey of GROUP_ORDER) {
            if (!groups[groupKey]) continue;
            const g = groups[groupKey];
            h += '<div class="gh-job-group">'
              +    '<div class="gh-job-group-head">'
              +      '<span class="gh-job-group-name">' + esc(g.label) + '</span>'
              +      '<span class="gh-job-group-count">' + g.jobs.length + '</span>'
              +    '</div>'
              +    '<div class="gh-job-group-body">';
            for (const j of g.jobs) h += jobCardHTML(j);
            h += '</div></div>';
        }
        area.innerHTML = h;
    }

    function jobCardHTML(j) {
        const meta     = kindMeta(j.kind);
        const kindLbl  = kinds[j.kind] ? kinds[j.kind].label : j.kind;
        const schedule = cronHuman(j.cron);
        const scheduleRaw = j.cron || '';
        const nextRel  = j.next_run_at ? relTime(j.next_run_at) : null;
        const nextAbs  = absDate(j.next_run_at);
        const lastRel  = j.last_run_at ? relTime(j.last_run_at) : null;
        const lastAbs  = absDate(j.last_run_at);

        let lastText = '', lastDotCls = 'gh-dot-idle', lastLabel = 'Mai eseguito';
        if (j.last_status) {
            lastLabel = {
                done:     'Completato',
                error:    'Errore',
                crashed:  'Crash',
                continue: 'In corso…',
                skipped:  'Saltato',
            }[j.last_status] || j.last_status;
            lastDotCls = ({
                done: 'gh-dot-ok',
                error: 'gh-dot-err',
                crashed: 'gh-dot-err',
                continue: 'gh-dot-warn',
            })[j.last_status] || 'gh-dot-idle';
        }
        if (lastRel) lastText = lastLabel + ' · ' + lastRel;
        else lastText = lastLabel;

        const stateCls = j.enabled ? 'gh-job-state-on' : 'gh-job-state-off';
        const stateLbl = j.enabled ? 'Attivo' : 'In pausa';
        const toggleLbl = j.enabled ? 'Pausa' : 'Attiva';
        const runCount = j.run_count || 0;
        const id = esc(j.id);

        return '<article class="gh-job-card gh-job-color-' + meta.color + '" data-kind="' + esc(j.kind) + '">'
            +    '<div class="gh-job-card-stripe"></div>'
            +    '<div class="gh-job-card-body">'
            +      '<div class="gh-job-card-row1">'
            +        '<span class="gh-job-kind-tag">' + esc(kindLbl) + '</span>'
            +        '<span class="gh-job-title">' + esc(j.label || '(senza nome)') + '</span>'
            +        '<span class="gh-job-state-pill ' + stateCls + '">' + stateLbl + '</span>'
            +      '</div>'
            +      '<div class="gh-job-card-row2">'
            +        '<span class="gh-job-meta" title="' + esc(scheduleRaw) + '">'
            +          '<span class="gh-job-meta-k">Schedulazione</span>'
            +          '<span class="gh-job-meta-v">' + esc(schedule) + '</span>'
            +        '</span>'
            +        '<span class="gh-job-meta">'
            +          '<span class="gh-job-meta-k">Ultimo</span>'
            +          '<span class="gh-job-meta-v"><span class="gh-dot ' + lastDotCls + '"></span>' + esc(lastText) + (lastAbs ? ' <span class="gh-job-abs">(' + esc(lastAbs) + ')</span>' : '') + '</span>'
            +        '</span>'
            +        '<span class="gh-job-meta">'
            +          '<span class="gh-job-meta-k">Prossimo</span>'
            +          '<span class="gh-job-meta-v">' + (j.enabled ? (esc(nextRel || '—') + (nextAbs ? ' <span class="gh-job-abs">(' + esc(nextAbs) + ')</span>' : '')) : '—') + '</span>'
            +        '</span>'
            +        '<span class="gh-job-meta">'
            +          '<span class="gh-job-meta-k">Runs</span>'
            +          '<span class="gh-job-meta-v">' + runCount + '</span>'
            +        '</span>'
            +      '</div>'
            +    '</div>'
            +    '<div class="gh-job-card-actions">'
            +      '<button class="gh-job-act gh-job-act-run" onclick="GH.jobsRunNow(\'' + id + '\')" title="Esegui ora">Esegui</button>'
            +      '<button class="gh-job-act" onclick="GH.jobsToggle(\'' + id + '\')" title="' + toggleLbl + '">' + toggleLbl + '</button>'
            +      '<button class="gh-job-act" onclick="GH.jobsEdit(\'' + id + '\')" title="Modifica">Modifica</button>'
            +      '<button class="gh-job-act gh-job-act-danger" onclick="GH.jobsDelete(\'' + id + '\')" title="Elimina">Elimina</button>'
            +    '</div>'
            +  '</article>';
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

    // ── LOG — timeline view
    async function jobsLoadLog() {
        const r = await ajax('gh_ajax_jobs_log', { limit: 100 });
        const area = document.getElementById('jobs-log-area');
        if (!r.success || !r.data || !r.data.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9776;</div><div class="empty-text">Nessun run registrato</div></div>';
            return;
        }
        let h = '<div class="gh-joblog-list">';
        for (const e of r.data) {
            const dotCls =
                e.status === 'done'     ? 'gh-dot-ok'  :
                e.status === 'continue' ? 'gh-dot-warn':
                (e.status === 'error' || e.status === 'crashed') ? 'gh-dot-err' : 'gh-dot-idle';
            const statusLbl = {
                done: 'completato',
                error: 'errore',
                crashed: 'crash',
                continue: 'in corso',
                skipped: 'saltato',
            }[e.status] || (e.status || '—');
            const meta = kindMeta(e.kind);
            const dur  = ((e.duration_ms || 0) / 1000).toFixed(1) + 's';
            const rel  = relTime(e.started_at) || '';
            const abs  = absDate(e.started_at);
            const hasDetail = !!(e.error || (e.summary && Object.keys(e.summary || {}).length));
            const detailBody = e.error
                ? '<pre class="gh-joblog-err">' + esc(e.error) + '</pre>'
                : (e.summary ? '<pre class="gh-joblog-sum">' + esc(JSON.stringify(e.summary, null, 2)) + '</pre>' : '');

            const rowTag = hasDetail ? 'details' : 'div';
            const headTag = hasDetail ? 'summary' : 'div';
            h += '<' + rowTag + ' class="gh-joblog-row' + (hasDetail ? ' gh-joblog-row-expandable' : '') + '" data-status="' + esc(e.status || '') + '">'
              +    '<' + headTag + ' class="gh-joblog-head">'
              +      '<span class="gh-dot ' + dotCls + '"></span>'
              +      '<span class="gh-joblog-time" title="' + esc(abs) + '">' + esc(rel) + '</span>'
              +      '<span class="gh-joblog-label">' + esc(e.job_label || '') + '</span>'
              +      '<span class="gh-joblog-kind gh-job-color-' + meta.color + '">' + esc(e.kind || '') + '</span>'
              +      '<span class="gh-joblog-status gh-joblog-status-' + (dotCls.replace('gh-dot-','')) + '">' + esc(statusLbl) + '</span>'
              +      '<span class="gh-joblog-dur">' + dur + '</span>'
              +      '<span class="gh-joblog-trigger">' + esc(e.trigger || 'cron') + (e.ticks > 1 ? ' · ' + e.ticks + ' tick' : '') + '</span>'
              +    '</' + headTag + '>'
              +    (hasDetail ? '<div class="gh-joblog-body">' + detailBody + '</div>' : '')
              +  '</' + rowTag + '>';
        }
        h += '</div>';
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
    GH.jobsSetFilter   = jobsSetFilter;

    // auto-load when the jobs tab is activated
    const origSwitch = GH.switchTab;
    GH.switchTab = function(tab, el) { origSwitch(tab, el); if (tab === 'jobs') jobsReload(); };

})();
