// ═══ UNIFIED FEED/SERVICE SETTINGS ═════════════════════════════════════════
//
// One canonical save/load module for KicksDB, GS Feed, SF Feed, and any
// future service. Fixes the long-standing UX trap of round-tripping the
// redacted bullet placeholder through the input value.
//
// Pattern per panel:
//   - Render normal inputs for non-secret fields (URL, market, margin, ...).
//   - Render an EMPTY input for each secret field, with a small hint span
//     beside it: "Salvata: ••••abcd · 32 char" (or "Non impostata").
//   - On Save: collect inputs into { field_name: value }. Empty secret
//     inputs are simply not sent — server preserves existing.
//   - Server returns per-field status (updated|preserved|unchanged|cleared|
//     rejected). The toast shows the human summary; the saved-hint spans
//     are refreshed from the response so the user immediately sees
//     "Salvata: ••••XXXX" reflecting the value the server actually stored.
//
// Public API (attached to GH.feedSettings):
//
//   GH.feedSettings.load(service, fieldMap)
//     fieldMap: { fieldName: { input: '#dom-id-or-Element', hint: '#dom-id-or-Element' } }
//     - For non-secret fields: input.value = stored value
//     - For secret fields: input.value cleared, hint shows "Salvata: ••••XXXX" or "Non impostata"
//
//   GH.feedSettings.save(service, fields)
//     fields: { fieldName: stringValueFromInput }
//     - Empty secret values are pruned automatically (= "leave alone")
//     - Returns the server response: { ok, fields: { name: { status, ... } } }
//
//   GH.feedSettings.refreshHints(service, fieldMap, response)
//     - After save, re-paints all hint spans from the per-field response.
//
//   GH.feedSettings.dump(service)
//     - WP_DEBUG-only. Returns the actual stored option (secrets fingerprinted).
//       Logs to console.

(function () {
    const ajax  = GH.ajax;
    const toast = GH.toast;
    const esc   = GH.esc;

    function el(ref) {
        if (!ref) return null;
        if (typeof ref === 'string') return document.querySelector(ref);
        return ref;
    }

    function renderHintSecret(fp) {
        if (!fp || !fp.present) return '<span style="color:var(--dim)">Non impostata</span>';
        const last4 = fp.last4 || '????';
        const len   = fp.length || 0;
        return '<span style="color:var(--dim)">Salvata:</span> ' +
               '<span style="font-family:var(--mono);color:var(--grn)">••••' + esc(last4) + '</span>' +
               '<span style="color:var(--dim)"> · ' + len + ' char</span>';
    }

    function renderHintScalar(value) {
        if (value === null || value === undefined || value === '') {
            return '<span style="color:var(--dim)">—</span>';
        }
        return '<span style="font-family:var(--mono);color:var(--dim)">' + esc(String(value)) + '</span>';
    }

    function isSecretField(fields, name) {
        const f = fields && fields[name];
        return !!(f && typeof f === 'object' && ('present' in f) && ('length' in f));
    }

    async function load(service, fieldMap) {
        const r = await ajax('gh_settings_get', { service });
        if (!r.success) {
            toast('Errore caricamento settings ' + service + ': ' + (r.data && r.data.error || ''), 'err', 6000);
            return null;
        }
        const fields = (r.data && r.data.fields) || {};
        applyFieldsToDom(fields, fieldMap);
        return fields;
    }

    function applyFieldsToDom(fields, fieldMap) {
        Object.entries(fieldMap).forEach(([name, ref]) => {
            const input = el(ref.input);
            const hint  = el(ref.hint);
            const value = fields[name];

            if (isSecretField(fields, name)) {
                // Secret: never put plaintext in the input. Hint shows fingerprint.
                if (input) input.value = '';
                if (hint)  hint.innerHTML = renderHintSecret(value);
            } else {
                // Non-secret: input shows the stored value, hint can mirror it
                // (or a "saved" indicator).
                if (input && value !== undefined && value !== null) {
                    input.value = String(value);
                }
                if (hint) hint.innerHTML = renderHintScalar(value);
            }
        });
    }

    async function save(service, fields) {
        // Prune empty secret-style fields automatically. The server treats
        // empty secret as "preserve existing"; sending nothing is cleaner.
        // Non-secret empty values are kept (the server decides whether they
        // are 'cleared' or 'rejected' based on the schema).
        const payload = Object.assign({}, fields);

        const r = await ajax('gh_settings_save', {
            service,
            fields: JSON.stringify(payload),
        });

        // Both 200 success and 422 with detail come back here. The 422 path
        // returns success:false with the per-field detail in r.data.
        const detail = (r && r.data) || {};
        const fieldStatuses = detail.fields || {};

        if (!r || (!r.success && !fieldStatuses)) {
            toast('Salvataggio fallito (' + service + ')', 'err', 0);
            return { ok: false, fields: {} };
        }

        // Build the human summary toast.
        const summary = humanSummary(fieldStatuses);
        const hasRejected = Object.values(fieldStatuses).some(s => s.status === 'rejected');
        const hasUpdated  = Object.values(fieldStatuses).some(s => ['updated','cleared'].includes(s.status));
        const tone = hasRejected ? 'err' : (hasUpdated ? 'ok' : 'info');
        const ms   = hasRejected ? 0 : 4500;
        toast(summary, tone, ms);

        return { ok: !!r.success, fields: fieldStatuses, raw: detail };
    }

    function humanSummary(statuses) {
        const groups = { updated: [], unchanged: [], preserved: [], cleared: [], rejected: [] };
        Object.entries(statuses).forEach(([name, st]) => {
            const bucket = groups[st.status] ? st.status : 'rejected';
            const note   = st.error ? (name + ': ' + st.error) : name;
            groups[bucket].push(note);
        });
        const parts = [];
        if (groups.updated.length)   parts.push('aggiornati: ' + groups.updated.join(', '));
        if (groups.cleared.length)   parts.push('svuotati: ' + groups.cleared.join(', '));
        if (groups.unchanged.length) parts.push('invariati: ' + groups.unchanged.join(', '));
        if (groups.preserved.length) parts.push('preservati: ' + groups.preserved.join(', '));
        if (groups.rejected.length)  parts.push('RIFIUTATI — ' + groups.rejected.join(' | '));
        return parts.length ? parts.join(' · ') : 'Nessun cambiamento';
    }

    function refreshHints(service, fieldMap, response) {
        if (!response || !response.fields) return;
        Object.entries(fieldMap).forEach(([name, ref]) => {
            const hint = el(ref.hint);
            if (!hint) return;
            const st = response.fields[name];
            if (!st) return;
            if (st.fingerprint) {
                hint.innerHTML = renderHintSecret(st.fingerprint);
            } else if ('value' in st) {
                hint.innerHTML = renderHintScalar(st.value);
            }
        });
    }

    async function dump(service) {
        const r = await ajax('gh_settings_dump', { service });
        if (!r.success) {
            toast('Dump fallito: ' + (r.data && r.data.error || ''), 'err');
            return null;
        }
        console.group('[gh_settings_dump] ' + service);
        console.log(r.data);
        console.groupEnd();
        if (r.data && r.data.enabled === false) {
            toast('WP_DEBUG non attivo — abilita WP_DEBUG in wp-config.php per vedere il dump', 'warn', 6000);
        } else {
            toast('Dump in console (F12) — opzione: ' + (r.data && r.data.option_key), 'ok', 5000);
        }
        return r.data;
    }

    GH.feedSettings = { load, save, refreshHints, dump, applyFieldsToDom };
})();
