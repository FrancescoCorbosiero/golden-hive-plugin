// ═══ EMAIL MARKETING ════════════════════════════════════════════════════════
// Tab handlers per: Test, Campagne, Contatti, Storico.
// Si appoggia a GH.ajax / GH.toast / GH.esc gia esposti dal core.

(function(){
    const ajax  = GH.ajax;
    const toast = GH.toast;
    const esc   = GH.esc;

    // ── STATE ───────────────────────────────────────────────────
    let campaigns = [];
    let editingCampaign = null;       // null = nuova, altrimenti id
    let contacts = [];
    let csvRaw = '';                  // CSV crudo per upload manuale
    let historyDebounceTimer = null;

    // ────────────────────────────────────────────────────────────
    // TEST EMAIL
    // ────────────────────────────────────────────────────────────
    GH.emSendTest = async function() {
        const to      = (document.getElementById('em-test-to').value || '').trim();
        const subject = (document.getElementById('em-test-subject').value || '').trim();
        const body    = (document.getElementById('em-test-body').value || '').trim();

        if (!to) { toast('Inserisci un destinatario', 'err'); return; }

        const btn = document.getElementById('btn-em-send-test');
        const sp  = document.getElementById('em-test-spin');
        btn.disabled = true; sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_send_test', { to, subject, body });
            if (r.success) {
                toast(r.data.message || 'Email inviata', 'ok');
            } else {
                toast('Errore: ' + (r.data || 'invio fallito'), 'err');
            }
        } catch (e) {
            toast('Errore di rete', 'err');
        } finally {
            btn.disabled = false; sp.style.display = 'none';
        }
    };

    // ────────────────────────────────────────────────────────────
    // CAMPAIGNS — list + editor
    // ────────────────────────────────────────────────────────────
    GH.emCampaignsLoad = async function() {
        const sp = document.getElementById('em-camp-spin');
        if (sp) sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_get_campaigns');
            if (!r.success) { toast('Errore caricamento campagne', 'err'); return; }
            campaigns = Array.isArray(r.data) ? r.data : [];
            renderCampaignsList();
        } finally {
            if (sp) sp.style.display = 'none';
        }
    };

    function renderCampaignsList() {
        document.getElementById('em-camp-list').style.display = '';
        document.getElementById('em-camp-editor').style.display = 'none';
        const a = document.getElementById('em-camp-list');
        if (!campaigns.length) {
            a.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9758;</div><div class="empty-text">Nessuna campagna salvata</div></div>';
            return;
        }
        let h = '';
        for (const c of campaigns) {
            const st = c.status || 'draft';
            const stats = c.stats || { sent:0, failed:0 };
            h += '<div class="em-camp-card" onclick="GH.emCampaignEdit(\'' + esc(c.id) + '\')">';
            h += '  <div class="em-camp-card-head"><span class="em-camp-card-name">' + esc(c.name || '(senza nome)') + '</span><span class="em-st em-st-' + esc(st) + '">' + esc(st) + '</span></div>';
            h += '  <div class="em-camp-card-subj">' + esc(c.subject || '') + '</div>';
            h += '  <div class="em-camp-card-meta">';
            h += '    <span>ID ' + esc(c.id || '') + '</span>';
            h += '    <span>Sorgente: ' + esc(c.source_type || 'hustle') + '</span>';
            h += '    <span>Inviate: ' + (stats.sent || 0) + '</span>';
            if (stats.failed) h += '    <span style="color:var(--red)">Fallite: ' + stats.failed + '</span>';
            if (c.scheduled_at) h += '    <span>Schedulata: ' + esc(c.scheduled_at) + '</span>';
            h += '  </div>';
            h += '</div>';
        }
        a.innerHTML = h;
    }

    GH.emCampaignNew = function() {
        editingCampaign = null;
        document.getElementById('em-c-name').value = '';
        document.getElementById('em-c-subject').value = '';
        document.getElementById('em-c-body').value = '';
        document.getElementById('em-c-source').value = 'hustle';
        document.getElementById('em-c-rate').value = '200000';
        document.getElementById('em-c-csv').value = '';
        document.getElementById('em-c-sched').value = '';
        document.getElementById('em-c-status').textContent = 'Nuova bozza';
        GH.emToggleSource();
        showEditor();
    };

    GH.emCampaignEdit = function(id) {
        const c = campaigns.find(x => x.id === id);
        if (!c) return;
        editingCampaign = id;
        document.getElementById('em-c-name').value = c.name || '';
        document.getElementById('em-c-subject').value = c.subject || '';
        document.getElementById('em-c-body').value = c.body || '';
        document.getElementById('em-c-source').value = c.source_type || 'hustle';
        document.getElementById('em-c-rate').value = String(c.rate_limit || 200000);
        document.getElementById('em-c-csv').value = c.csv_contacts || '';
        document.getElementById('em-c-sched').value = (c.scheduled_at || '').replace(' ', 'T').slice(0,16);
        document.getElementById('em-c-status').textContent = 'Stato: ' + (c.status || 'draft');
        GH.emToggleSource();
        showEditor();
    };

    function showEditor() {
        document.getElementById('em-camp-list').style.display = 'none';
        document.getElementById('em-camp-editor').style.display = 'flex';
    }

    GH.emCampaignBackToList = function() {
        renderCampaignsList();
    };

    GH.emToggleSource = function() {
        const v = document.getElementById('em-c-source').value;
        document.getElementById('em-c-csv-row').style.display = (v === 'csv' || v === 'mixed') ? 'flex' : 'none';
    };

    function collectCampaignPayload() {
        const payload = {
            name:         document.getElementById('em-c-name').value.trim(),
            subject:      document.getElementById('em-c-subject').value.trim(),
            body:         document.getElementById('em-c-body').value,
            source_type:  document.getElementById('em-c-source').value,
            module_ids:   [],
            csv_contacts: document.getElementById('em-c-csv').value,
            rate_limit:   parseInt(document.getElementById('em-c-rate').value, 10) || 200000,
            scheduled_at: document.getElementById('em-c-sched').value.replace('T', ' '),
        };
        if (editingCampaign) payload.id = editingCampaign;
        return payload;
    }

    GH.emCampaignSave = async function() {
        const payload = collectCampaignPayload();
        if (!payload.name || !payload.subject || !payload.body) {
            toast('Nome, oggetto e corpo sono obbligatori', 'err');
            return;
        }
        const r = await ajax('rp_em_ajax_save_campaign', { campaign: JSON.stringify(payload) });
        if (!r.success) { toast('Errore: ' + (r.data || 'salvataggio fallito'), 'err'); return; }
        editingCampaign = r.data.id;
        toast('Campagna salvata', 'ok');
        await GH.emCampaignsLoad();
        // Torna a editor sulla campagna salvata
        GH.emCampaignEdit(editingCampaign);
    };

    GH.emCampaignDelete = async function() {
        if (!editingCampaign) { toast('Nessuna campagna selezionata', 'err'); return; }
        if (!confirm('Eliminare definitivamente questa campagna?')) return;
        const r = await ajax('rp_em_ajax_delete_campaign', { campaign_id: editingCampaign });
        if (!r.success) { toast('Errore eliminazione', 'err'); return; }
        toast('Campagna eliminata', 'ok');
        editingCampaign = null;
        GH.emCampaignsLoad();
    };

    GH.emCampaignSend = async function() {
        if (!editingCampaign) {
            toast('Salva prima la campagna', 'err'); return;
        }
        if (!confirm('Inviare la campagna ORA a tutti i contatti?')) return;
        const r = await ajax('rp_em_ajax_send_campaign', { campaign_id: editingCampaign });
        if (!r.success) { toast('Errore: ' + (r.data || 'invio fallito'), 'err'); return; }
        const d = r.data || {};
        toast('Inviate: ' + (d.sent || 0) + ' • Fallite: ' + (d.failed || 0), d.failed ? 'inf' : 'ok');
        GH.emCampaignsLoad();
    };

    GH.emCampaignSchedule = async function() {
        if (!editingCampaign) { toast('Salva prima la campagna', 'err'); return; }
        const dt = document.getElementById('em-c-sched').value.replace('T', ' ');
        if (!dt) { toast('Imposta data/ora di schedulazione', 'err'); return; }
        const r = await ajax('rp_em_ajax_schedule_campaign', { campaign_id: editingCampaign, scheduled_at: dt });
        if (!r.success) { toast('Errore: ' + (r.data || 'schedulazione fallita'), 'err'); return; }
        toast(r.data.message || 'Campagna schedulata', 'ok');
        GH.emCampaignsLoad();
    };

    // ────────────────────────────────────────────────────────────
    // CONTACTS
    // ────────────────────────────────────────────────────────────
    GH.emContactsInit = function() {
        // Carica una volta all'apertura del tab.
        if (!contacts.length) GH.emContactsLoad();
    };

    GH.emContactsLoad = async function() {
        const source = document.getElementById('em-ct-source').value;
        document.getElementById('em-ct-upload').style.display = (source === 'csv') ? 'flex' : 'none';

        const sp = document.getElementById('em-ct-spin');
        sp.style.display = '';
        try {
            const body = { source_type: source };
            if (source === 'csv' && csvRaw) body.csv_raw = csvRaw;
            const r = await ajax('rp_em_ajax_get_contacts', body);
            if (!r.success) { toast('Errore caricamento contatti', 'err'); return; }
            contacts = r.data.contacts || [];
            const counts = r.data.counts || { total:0, hustle:0, csv:0 };
            document.getElementById('em-ct-stats').style.display = 'flex';
            document.getElementById('em-ct-total').textContent = counts.total || 0;
            document.getElementById('em-ct-hustle').textContent = counts.hustle || 0;
            document.getElementById('em-ct-csv').textContent = counts.csv || 0;
            renderContactsList();
        } finally {
            sp.style.display = 'none';
        }
    };

    GH.emContactsUploadFile = async function(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('action', 'rp_em_ajax_upload_csv');
        fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
        fd.append('csv_file', file);
        try {
            const res = await fetch('<?php echo esc_js( $ajax ); ?>', { method:'POST', body: fd });
            const r = await res.json();
            if (!r.success) { toast('Errore upload: ' + (r.data || ''), 'err'); return; }
            // r.data.contacts è già parsato lato server. Salviamo come "csv raw"
            // ricostruito per riusare la pipeline get_contacts.
            csvRaw = (r.data.contacts || []).map(c => (c.email || '') + ',' + (c.display_name || '')).join('\n');
            csvRaw = 'email,display_name\n' + csvRaw;
            toast(file.name + ': ' + (r.data.count || 0) + ' contatti', 'ok');
            GH.emContactsLoad();
        } catch (e) {
            toast('Errore di rete', 'err');
        }
    };

    function renderContactsList() {
        const a = document.getElementById('em-ct-list');
        if (!contacts.length) {
            a.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9786;</div><div class="empty-text">Nessun contatto trovato</div></div>';
            return;
        }
        let h = '';
        for (const c of contacts.slice(0, 500)) {
            h += '<div class="em-row">';
            h += '  <span class="em-time">' + esc(c.source || c.module_id || '') + '</span>';
            h += '  <span class="em-to">' + esc(c.email || '') + '</span>';
            h += '  <span class="em-subj">' + esc(c.display_name || '') + '</span>';
            h += '  <span class="em-type"></span>';
            h += '  <span class="em-status"></span>';
            h += '</div>';
        }
        if (contacts.length > 500) {
            h += '<div class="em-row"><span class="em-subj" style="grid-column:1/-1;text-align:center;color:var(--dim)">... ' + (contacts.length - 500) + ' altri (mostriamo solo i primi 500)</span></div>';
        }
        a.innerHTML = h;
    }

    // ────────────────────────────────────────────────────────────
    // HISTORY (Storico email — lightweight)
    // ────────────────────────────────────────────────────────────
    GH.emHistoryDebounce = function() {
        clearTimeout(historyDebounceTimer);
        historyDebounceTimer = setTimeout(GH.emHistoryLoad, 300);
    };

    GH.emHistoryLoad = async function() {
        const sp = document.getElementById('em-h-spin');
        sp.style.display = '';
        try {
            const body = {
                limit:  200,
                type:   document.getElementById('em-h-type').value,
                status: document.getElementById('em-h-status').value,
                search: document.getElementById('em-h-search').value,
            };
            const r = await ajax('rp_em_ajax_get_log', body);
            if (!r.success) { toast('Errore caricamento storico', 'err'); return; }
            renderHistory(r.data.entries || [], r.data.stats || {});
        } finally {
            sp.style.display = 'none';
        }
    };

    function renderHistory(entries, stats) {
        const sb = document.getElementById('em-h-stats');
        sb.style.display = 'flex';
        document.getElementById('em-h-total').textContent  = stats.total  || 0;
        document.getElementById('em-h-sent').textContent   = stats.sent   || 0;
        document.getElementById('em-h-failed').textContent = stats.failed || 0;

        const a = document.getElementById('em-h-list');
        if (!entries.length) {
            a.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9202;</div><div class="empty-text">Nessuna email nello storico</div></div>';
            return;
        }
        let h = '';
        for (const e of entries) {
            const ok = e.status === 'sent';
            const subjLine = e.type === 'campaign' && e.campaign_name
                ? esc(e.subject || '') + ' \u2014 ' + esc(e.campaign_name)
                : esc(e.subject || '');
            h += '<div class="em-row">';
            h += '  <span class="em-time">' + esc(e.sent_at || '') + '</span>';
            h += '  <span class="em-to">' + esc(e.to || '') + '</span>';
            h += '  <span class="em-subj">' + subjLine + '</span>';
            h += '  <span class="em-type">' + esc(e.type || '') + '</span>';
            h += '  <span class="em-status ' + (ok ? 'ok' : 'err') + '">' + esc(e.status || '') + '</span>';
            if (!ok && e.error) {
                h += '  <span class="em-err-detail">' + esc(e.error) + '</span>';
            }
            h += '</div>';
        }
        a.innerHTML = h;
    }

    GH.emHistoryClear = async function() {
        if (!confirm('Svuotare tutto lo storico email? Operazione non reversibile.')) return;
        const r = await ajax('rp_em_ajax_clear_log');
        if (!r.success) { toast('Errore svuotamento', 'err'); return; }
        toast('Storico svuotato', 'ok');
        GH.emHistoryLoad();
    };

    // ═══ TEMPLATES ═══════════════════════════════════════════════

    let tplList = [];
    let tplEditing = null;
    let tplCtx = {};

    GH.emTplLoad = async function() {
        const r = await ajax('rp_em_ajax_get_templates');
        if (!r.success) { toast('Errore', 'err'); return; }
        tplList = r.data || [];
        tplRenderList();
    };

    function tplRenderList() {
        const area = document.getElementById('em-tpl-list');
        if (!tplList.length) {
            area.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Nessun template. Crea il primo per iniziare.</div></div>';
            return;
        }
        const catLabels = { general: 'Generale', order: 'Ordine', marketing: 'Marketing', support: 'Supporto' };
        let h = '<table class="ptable"><thead><tr><th>Nome</th><th>Categoria</th><th>Oggetto</th><th>Modificato</th></tr></thead><tbody>';
        for (const t of tplList) {
            h += '<tr style="cursor:pointer" onclick="GH.emTplEdit(\'' + esc(t.id) + '\')">';
            h += '<td><strong>' + esc(t.name) + '</strong></td>';
            h += '<td style="font-size:10px">' + esc(catLabels[t.category] || t.category || '') + '</td>';
            h += '<td style="font-size:10px;color:var(--dim);max-width:300px;overflow:hidden;text-overflow:ellipsis">' + esc(t.subject || '') + '</td>';
            h += '<td style="font-size:10px;color:var(--dim)">' + esc((t.updated_at || '').substring(0, 10)) + '</td>';
            h += '</tr>';
        }
        h += '</tbody></table>';
        area.innerHTML = h;
    }

    GH.emTplNew = function() {
        tplEditing = null; tplCtx = {};
        document.getElementById('em-tpl-editor-title').textContent = 'Nuovo Template';
        document.getElementById('em-tpl-name').value = '';
        document.getElementById('em-tpl-subject').value = '';
        document.getElementById('em-tpl-body').value = '';
        document.getElementById('em-tpl-category').value = 'general';
        document.getElementById('em-tpl-send-to').value = '';
        document.getElementById('em-tpl-ctx-order').value = '';
        document.getElementById('em-tpl-ctx-customer').value = '';
        document.getElementById('em-tpl-search-results').innerHTML = '';
        document.getElementById('em-tpl-preview-area').style.display = 'none';
        document.getElementById('btn-em-tpl-delete').style.display = 'none';
        document.getElementById('em-tpl-list-view').style.display = 'none';
        document.getElementById('em-tpl-editor-view').style.display = 'flex';
        tplLoadPlaceholders();
    };

    GH.emTplEdit = function(id) {
        const t = tplList.find(x => x.id === id);
        if (!t) return;
        tplEditing = id; tplCtx = {};
        document.getElementById('em-tpl-editor-title').textContent = t.name;
        document.getElementById('em-tpl-name').value = t.name || '';
        document.getElementById('em-tpl-subject').value = t.subject || '';
        document.getElementById('em-tpl-body').value = t.body || '';
        document.getElementById('em-tpl-category').value = t.category || 'general';
        document.getElementById('em-tpl-send-to').value = '';
        document.getElementById('em-tpl-ctx-order').value = '';
        document.getElementById('em-tpl-ctx-customer').value = '';
        document.getElementById('em-tpl-search-results').innerHTML = '';
        document.getElementById('em-tpl-preview-area').style.display = 'none';
        document.getElementById('btn-em-tpl-delete').style.display = '';
        document.getElementById('em-tpl-list-view').style.display = 'none';
        document.getElementById('em-tpl-editor-view').style.display = 'flex';
        tplLoadPlaceholders();
    };

    GH.emTplBackToList = function() {
        document.getElementById('em-tpl-editor-view').style.display = 'none';
        document.getElementById('em-tpl-list-view').style.display = '';
        GH.emTplLoad();
    };

    GH.emTplSave = async function() {
        const data = {
            id:       tplEditing || '',
            name:     document.getElementById('em-tpl-name').value,
            subject:  document.getElementById('em-tpl-subject').value,
            body:     document.getElementById('em-tpl-body').value,
            category: document.getElementById('em-tpl-category').value,
        };
        if (!data.name) { toast('Nome obbligatorio', 'err'); return; }
        const sp = document.getElementById('em-tpl-save-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_save_template', { template: JSON.stringify(data) });
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            tplEditing = r.data.id;
            document.getElementById('em-tpl-editor-title').textContent = data.name;
            document.getElementById('btn-em-tpl-delete').style.display = '';
            toast('Template salvato', 'ok');
        } catch (e) { toast('Errore', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    GH.emTplDelete = async function() {
        if (!tplEditing || !confirm('Eliminare questo template?')) return;
        const r = await ajax('rp_em_ajax_delete_template', { template_id: tplEditing });
        if (!r.success) { toast('Errore', 'err'); return; }
        toast('Eliminato', 'ok');
        GH.emTplBackToList();
    };

    async function tplLoadPlaceholders() {
        const r = await ajax('rp_em_ajax_get_placeholders');
        if (!r.success) return;
        const area = document.getElementById('em-tpl-placeholders');
        let h = '';
        for (const [group, info] of Object.entries(r.data)) {
            h += '<div style="width:100%;margin-top:4px;color:var(--dim);font-size:9px;text-transform:uppercase;letter-spacing:.1em">' + esc(info.label) + '</div>';
            for (const [key, desc] of Object.entries(info.placeholders)) {
                h += '<button class="btn btn-ghost" style="font-size:10px;padding:2px 6px;border:1px solid var(--b1);border-radius:3px" onclick="GH.emTplInsertPlaceholder(\'' + key + '\')" title="' + esc(desc) + '">{' + key + '}</button>';
            }
        }
        area.innerHTML = h;
    }

    GH.emTplInsertPlaceholder = function(key) {
        const ta = document.getElementById('em-tpl-body');
        const start = ta.selectionStart, end = ta.selectionEnd;
        const text = '{' + key + '}';
        ta.value = ta.value.substring(0, start) + text + ta.value.substring(end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + text.length;
    };

    function tplBuildContext() {
        const ctx = { ...tplCtx };
        ctx.email = document.getElementById('em-tpl-send-to').value || 'test@example.com';
        ctx.first_name = ctx.customer_name || 'Test';
        const orderVal = document.getElementById('em-tpl-ctx-order').value;
        if (orderVal && /^\d+$/.test(orderVal)) ctx.order_id = parseInt(orderVal);
        const custVal = document.getElementById('em-tpl-ctx-customer').value;
        if (custVal && /^\d+$/.test(custVal)) ctx.customer_id = parseInt(custVal);
        return ctx;
    }

    GH.emTplPreview = async function() {
        if (!tplEditing) { await GH.emTplSave(); if (!tplEditing) return; }
        const sp = document.getElementById('em-tpl-preview-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_render_template', {
                template_id: tplEditing,
                context: JSON.stringify(tplBuildContext()),
            });
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            document.getElementById('em-tpl-preview-subject').textContent = 'Oggetto: ' + r.data.subject;
            document.getElementById('em-tpl-preview-body').innerHTML = r.data.body;
            document.getElementById('em-tpl-preview-area').style.display = '';
        } catch (e) { toast('Errore', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    GH.emTplSend = async function() {
        const to = document.getElementById('em-tpl-send-to').value;
        if (!to) { toast('Inserisci email destinatario', 'err'); return; }
        if (!tplEditing) { await GH.emTplSave(); if (!tplEditing) return; }
        if (!confirm('Inviare email a ' + to + '?')) return;
        const sp = document.getElementById('em-tpl-send-spin'); sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_send_template', {
                template_id: tplEditing,
                to: to,
                context: JSON.stringify(tplBuildContext()),
            });
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            toast(r.data.success ? 'Email inviata!' : 'Invio fallito: ' + (r.data.message || ''), r.data.success ? 'ok' : 'err', 5000);
        } catch (e) { toast('Errore', 'err'); }
        finally { sp.style.display = 'none'; }
    };

    GH.emTplSearchOrder = async function() {
        const q = document.getElementById('em-tpl-ctx-order').value;
        if (!q) return;
        const r = await ajax('rp_em_ajax_search_orders', { query: q });
        if (!r.success || !r.data.length) { document.getElementById('em-tpl-search-results').innerHTML = '<span style="color:var(--red)">Nessun ordine trovato</span>'; return; }
        let h = '';
        for (const o of r.data) {
            h += '<a href="#" onclick="GH.emTplSelectOrder(' + o.id + ');return false" style="color:var(--acc);margin-right:8px">#' + o.number + '</a> ' +
                 '<span style="color:var(--dim)">' + esc(o.customer) + ' — ' + esc(o.total) + ' — ' + esc(o.date) + '</span><br>';
        }
        document.getElementById('em-tpl-search-results').innerHTML = h;
    };

    GH.emTplSelectOrder = function(id) {
        document.getElementById('em-tpl-ctx-order').value = id;
        tplCtx.order_id = id;
        document.getElementById('em-tpl-search-results').innerHTML = '<span style="color:var(--grn)">Ordine #' + id + ' selezionato</span>';
    };

    GH.emTplSearchCustomer = async function() {
        const q = document.getElementById('em-tpl-ctx-customer').value;
        if (!q) return;
        const r = await ajax('rp_em_ajax_search_customers', { query: q });
        if (!r.success || !r.data.length) { document.getElementById('em-tpl-search-results').innerHTML = '<span style="color:var(--red)">Nessun cliente trovato</span>'; return; }
        let h = '';
        for (const c of r.data) {
            h += '<a href="#" onclick="GH.emTplSelectCustomer(' + c.id + ',\'' + esc(c.name) + '\');return false" style="color:var(--acc);margin-right:8px">' + esc(c.name) + '</a> ' +
                 '<span style="color:var(--dim)">' + esc(c.email) + ' — ' + c.orders + ' ordini — ' + esc(c.spent) + '</span><br>';
        }
        document.getElementById('em-tpl-search-results').innerHTML = h;
    };

    GH.emTplSelectCustomer = function(id, name) {
        document.getElementById('em-tpl-ctx-customer').value = id;
        tplCtx.customer_id = id;
        tplCtx.customer_name = name;
        document.getElementById('em-tpl-search-results').innerHTML = '<span style="color:var(--grn)">Cliente "' + esc(name) + '" selezionato</span>';
    };

    GH.emTplUseInCampaign = function() {
        const subject = document.getElementById('em-tpl-subject').value;
        const body = document.getElementById('em-tpl-body').value;
        if (!body) { toast('Template vuoto', 'err'); return; }
        GH.switchTab('email-campaigns', document.querySelector('[onclick*="email-campaigns"]'));
        GH.emCampaignsLoad();
        setTimeout(function() {
            GH.emCampaignNew();
            document.getElementById('em-c-subject').value = subject;
            document.getElementById('em-c-body').value = body;
            toast('Template caricato nella campagna', 'ok');
        }, 300);
    };

})();
