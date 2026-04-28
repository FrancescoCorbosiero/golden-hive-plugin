<?php
/**
 * v2 Workflow tab — unified pipeline UI.
 *
 * Batch 5a scope: source picker + capabilities chips + config form
 * rendered from configSchema(). Selection table + pipeline builder +
 * Run button land in subsequent sub-batches.
 *
 * No legacy tab is removed. This sits next to the existing GS/SF/CSV
 * panels until full parity is reached and then the legacy panels go.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="panel" id="panel-workflow">
    <div class="panel-header">
        <h2 style="display:flex;align-items:center;gap:.5rem;margin:0 0 .5rem">
            Workflow
            <span class="gh-status gh-status--info" style="font-size:.75rem">v2 beta</span>
        </h2>
        <p class="panel-desc" style="opacity:.75;margin:0 0 1rem">
            Pipeline unificate: scegli una sorgente, definisci la pipeline, esegui come job.
            Ogni sorgente espone lo stesso flusso — nessuna UI da imparare per feed.
        </p>
    </div>

    <div class="panel-section">
        <label class="form-label" for="wf-source-select" style="display:block;margin-bottom:.25rem">Sorgente</label>
        <select id="wf-source-select" class="form-input" style="min-width:280px">
            <option value="">— Caricamento…</option>
        </select>
    </div>

    <div class="panel-section" id="wf-source-info" hidden style="margin-top:1rem">
        <div style="font-size:.8rem;opacity:.75;margin-bottom:.25rem">Capabilities</div>
        <div id="wf-source-caps" style="display:flex;gap:.4rem;flex-wrap:wrap"></div>
    </div>

    <div class="panel-section" id="wf-source-config" hidden style="margin-top:1.25rem">
        <h3 style="margin:0 0 .75rem;font-size:1rem">Configurazione</h3>
        <div id="wf-config-form" style="display:flex;flex-direction:column;gap:.75rem;max-width:560px"></div>
        <div style="margin-top:.5rem;font-size:.75rem;opacity:.6">
            Le credenziali sono caricate dallo storage centralizzato — i token redatti
            (•••) significano "valore conservato, non re-incollare per salvare".
        </div>
    </div>

    <div class="panel-section" id="wf-preview-block" hidden style="margin-top:1.5rem">
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem">
            <h3 style="margin:0;font-size:1rem">Selezione</h3>
            <input type="search" id="wf-search" class="form-input" placeholder="Cerca per nome o SKU…" style="flex:1;min-width:200px;max-width:340px">
            <button type="button" id="wf-load-btn" class="button">Carica preview</button>
            <button type="button" id="wf-refresh-btn" class="button" hidden title="Bypassa la cache (15 min)">Refresh</button>
            <span id="wf-fetched-at" style="font-size:.75rem;opacity:.6"></span>
        </div>

        <div id="wf-preview-warnings" hidden style="padding:.5rem .75rem;margin-bottom:.5rem;border-left:3px solid var(--amb,#e8a33d);background:var(--amb-15,rgba(232,163,61,.1));font-size:.8rem"></div>

        <div id="wf-preview-table-wrap" style="border:1px solid var(--bd,#2a2d33);border-radius:6px;overflow:hidden">
            <table id="wf-preview-table" style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead style="background:var(--surface-2,#16181d);text-align:left">
                    <tr>
                        <th style="width:36px;padding:.5rem"><input type="checkbox" id="wf-check-all"></th>
                        <th style="width:48px;padding:.5rem"></th>
                        <th style="padding:.5rem">SKU</th>
                        <th style="padding:.5rem">Nome</th>
                        <th style="padding:.5rem">Prezzo</th>
                        <th style="padding:.5rem">Stato</th>
                        <th style="padding:.5rem">Tipo</th>
                    </tr>
                </thead>
                <tbody id="wf-preview-tbody">
                    <tr><td colspan="7" style="padding:1.5rem;text-align:center;opacity:.6">— Nessun caricamento —</td></tr>
                </tbody>
            </table>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-top:.5rem;font-size:.8rem">
            <div id="wf-selection-count" style="opacity:.75">0 selezionati</div>
            <div id="wf-pagination" style="display:flex;align-items:center;gap:.5rem">
                <button type="button" id="wf-prev" class="button" disabled>&lsaquo;</button>
                <span id="wf-page-info" style="opacity:.75">—</span>
                <button type="button" id="wf-next" class="button" disabled>&rsaquo;</button>
            </div>
        </div>
    </div>

    <div class="panel-section" id="wf-next-steps" hidden style="margin-top:1.5rem;padding:1rem;border:1px dashed var(--bd, #2a2d33);border-radius:6px;opacity:.7">
        <div style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">Prossimo</div>
        <div style="font-size:.85rem;line-height:1.5">
            Pipeline builder (operations / import rules / checks) → Run come job. In arrivo (Batch 5c / 5d).
        </div>
    </div>
</div>
