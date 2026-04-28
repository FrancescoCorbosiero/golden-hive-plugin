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

    <div class="panel-section" id="wf-next-steps" hidden style="margin-top:1.5rem;padding:1rem;border:1px dashed var(--bd, #2a2d33);border-radius:6px;opacity:.7">
        <div style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">Prossimo</div>
        <div style="font-size:.85rem;line-height:1.5">
            Selezione granulare prodotti → pipeline builder (operations / import rules / checks)
            → Run come job. In arrivo (Batch 5b → 5d).
        </div>
    </div>
</div>
