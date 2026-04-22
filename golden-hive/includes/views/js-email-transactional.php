// ═══ EMAIL — TRANSACTIONAL (event → template bindings + test fire) ════════
// IIFE che attacca a GH. Gestisce la tab "Transazionali".

(function(){
    const ajax = GH.ajax, toast = GH.toast, esc = GH.esc;
    const $ = id => document.getElementById(id);

    let events = [], bindings = {}, templates = [];

    GH.emTrxLoad = async function() {
        const sp = $('em-trx-spin'); if (sp) sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_trx_list');
            if (!r.success) { toast('Errore transazionali: ' + (r.data || ''), 'err'); return; }
            events    = r.data.events    || [];
            bindings  = r.data.bindings  || {};
            templates = r.data.templates || [];
            renderList();
            renderTestEventOptions();
        } finally { if (sp) sp.style.display = 'none'; }
    };

    function renderList() {
        const c = $('em-trx-list');
        if (!c) return;
        if (!events.length) {
            c.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9993;</div><div class="empty-text">Nessun evento registrato.</div></div>';
            return;
        }
        c.innerHTML = events.map(ev => {
            const b = bindings[ev.slug] || { enabled: false, template_id: '', subject: '', preheader: '' };
            const tplOpts = '<option value="">&mdash; nessun template &mdash;</option>' +
                templates.map(t => '<option value="' + esc(t.id) + '"' + (b.template_id === t.id ? ' selected' : '') + '>' + esc(t.name || t.id) + '</option>').join('');
            return '' +
            '<div class="rpem-trx-card" data-slug="' + esc(ev.slug) + '">' +
                '<div class="rpem-trx-head">' +
                    '<div>' +
                        '<div class="rpem-trx-title">' + esc(ev.label) + '</div>' +
                        '<div class="rpem-trx-desc">' + esc(ev.desc || '') + '</div>' +
                        '<div class="rpem-trx-hook">hook: <code>' + esc(ev.hook) + '</code></div>' +
                    '</div>' +
                    '<label class="rpem-trx-toggle">' +
                        '<input type="checkbox" data-field="enabled"' + (b.enabled ? ' checked' : '') + ' />' +
                        '<span>Attivo</span>' +
                    '</label>' +
                '</div>' +
                '<div class="cfg-row"><span class="cfg-label">Template</span>' +
                    '<select class="cfg-select" data-field="template_id">' + tplOpts + '</select>' +
                '</div>' +
                '<div class="cfg-row"><span class="cfg-label">Subject</span>' +
                    '<input class="cfg-input" type="text" data-field="subject" value="' + esc(b.subject || '') + '" placeholder="Es: Il tuo ordine {ORDER_NUMBER} e in viaggio" />' +
                '</div>' +
                '<div class="cfg-row"><span class="cfg-label">Preheader</span>' +
                    '<input class="cfg-input" type="text" data-field="preheader" value="' + esc(b.preheader || '') + '" placeholder="Prima riga di preview nel client" />' +
                '</div>' +
                '<div class="cfg-row">' +
                    '<div style="flex:1"></div>' +
                    '<button class="btn btn-primary" onclick="GH.emTrxSave(\'' + esc(ev.slug) + '\', this)">Salva</button>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    function renderTestEventOptions() {
        const sel = $('em-trx-test-event');
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = '<option value="">&mdash; seleziona &mdash;</option>' +
            events.map(ev => '<option value="' + esc(ev.slug) + '"' + (cur === ev.slug ? ' selected' : '') + '>' + esc(ev.label) + '</option>').join('');
    }

    GH.emTrxSave = async function(slug, btn) {
        const card = document.querySelector('.rpem-trx-card[data-slug="' + slug + '"]');
        if (!card) return;
        const data = {
            enabled:     card.querySelector('[data-field="enabled"]').checked,
            template_id: card.querySelector('[data-field="template_id"]').value,
            subject:     card.querySelector('[data-field="subject"]').value,
            preheader:   card.querySelector('[data-field="preheader"]').value,
        };
        const orig = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Salvataggio...'; }
        try {
            const r = await ajax('rp_em_ajax_trx_save', { slug, binding: JSON.stringify(data) });
            if (!r.success) { toast('Errore: ' + (r.data || ''), 'err'); return; }
            bindings[slug] = r.data.binding || data;
            toast('Binding salvato', 'ok');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = orig; }
        }
    };

    GH.emTrxTestFire = async function() {
        const event    = $('em-trx-test-event').value;
        const order_id = $('em-trx-test-order').value;
        if (!event) { toast('Seleziona un evento', 'err'); return; }
        if (!order_id || +order_id <= 0) { toast('Inserisci un Order ID valido', 'err'); return; }
        if (!confirm('Inviare l\'email reale all\'indirizzo del cliente dell\'ordine #' + order_id + '?')) return;

        const sp = $('em-trx-test-spin'); if (sp) sp.style.display = '';
        try {
            const r = await ajax('rp_em_ajax_trx_test_fire', { event, order_id });
            if (!r.success) { toast('Errore: ' + (r.data || 'invio fallito'), 'err'); return; }
            const d = r.data || {};
            if (d.success) {
                toast(d.message || ('Inviata a ' + d.recipient), 'ok');
            } else if (d.skipped_reason) {
                toast('Skipped: ' + d.skipped_reason, 'err');
            } else {
                toast('Invio fallito: ' + (d.message || 'unknown'), 'err');
            }
        } finally { if (sp) sp.style.display = 'none'; }
    };
})();
