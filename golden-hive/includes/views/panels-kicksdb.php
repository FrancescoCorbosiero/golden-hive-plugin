<?php
/**
 * Panels — KicksDB.
 *
 * Un singolo top-level tab "KicksDB" suddiviso in 5 sub-section interne via
 * data-kdb-tab toggle (stesso pattern del Mapper). Phase 3 ships con UI per:
 *
 * 1. Settings        — API key, market, pricing formula, concurrency
 * 2. Lookup          — paste SKU(s), preview diff, apply
 * 3. Refresh Pricing — paste SKU(s), batch-only refresh (gated da tracked=1)
 * 4. Provenance      — stato della migration + lookup per product_id
 * 5. Rules           — lista conflict rules (CRUD basico, no editor avanzato)
 *
 * NOTA: Discover (search browser) e l'editor visuale delle Rules con tree-view
 * KicksDB sono Phase 5 — non in questo file.
 */

defined( 'ABSPATH' ) || exit;
?>

<style>
#gh .kdb-d-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 10px;
    padding: 10px;
}
#gh .kdb-d-card {
    background: var(--bg2, #15161a);
    border: 1px solid var(--brd, #26272c);
    border-radius: 6px;
    overflow: hidden;
    cursor: pointer;
    transition: transform .12s ease, border-color .12s ease, background .12s ease;
    display: flex;
    flex-direction: column;
}
#gh .kdb-d-card:hover {
    border-color: var(--acc, #6ca0ff);
    transform: translateY(-1px);
}
#gh .kdb-d-card.is-selected {
    border-color: var(--acc, #6ca0ff);
    background: var(--acc-10, rgba(108,160,255,.10));
    box-shadow: 0 0 0 1px var(--acc-30, rgba(108,160,255,.30));
}
#gh .kdb-d-card-img {
    position: relative;
    aspect-ratio: 1 / 1;
    background: var(--bg, #0c0d10);
    display: flex;
    align-items: center;
    justify-content: center;
}
#gh .kdb-d-card-img img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
#gh .kdb-d-card-noimg {
    font-size: 32px;
    color: var(--dim, #6b6d73);
}
#gh .kdb-d-card-check {
    position: absolute;
    top: 6px; left: 6px;
    width: 18px; height: 18px;
    accent-color: var(--acc, #6ca0ff);
    cursor: pointer;
}
#gh .kdb-d-card-badge {
    position: absolute;
    top: 6px; right: 6px;
    font-size: 9px;
}
#gh .kdb-d-card-body {
    padding: 8px 10px 10px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
}
#gh .kdb-d-card-title {
    font-family: var(--sans, 'DM Sans', sans-serif);
    font-size: 12px;
    font-weight: 500;
    line-height: 1.3;
    color: var(--fg, #e8e9ec);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
#gh .kdb-d-card-meta {
    font-family: var(--mono, 'JetBrains Mono', monospace);
    font-size: 10px;
    color: var(--dim, #6b6d73);
}
#gh .kdb-d-card-brand {
    color: var(--acc, #6ca0ff);
    font-weight: 500;
}
#gh .kdb-d-card-sku {
    font-family: var(--mono, 'JetBrains Mono', monospace);
    font-size: 10px;
    color: var(--dim, #6b6d73);
    margin-top: auto;
}
#gh .kdb-d-card-sku .dim { opacity: .7; }

#gh .kdb-grid-wrap {
    min-height: 200px;
    max-height: 70vh;
    overflow-y: auto;
}

@media (max-width: 768px) {
    #gh .kdb-d-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 8px;
        padding: 8px;
    }
}
</style>

<!-- ═══ KICKSDB ═══════════════════════════════════════════════════════════ -->
<div class="panel" id="panel-kicksdb" style="position:relative">

    <!-- Sub-tabs -->
    <div class="config-form" style="padding-bottom:0">
        <div class="cfg-row" style="border-bottom:none;padding-bottom:6px">
            <div style="display:flex;gap:4px;flex-wrap:wrap">
                <button class="btn btn-ghost is-active" data-kdb-subtab="discover"   onclick="GH.kdbSubtab('discover',this)" title="Cerca su KicksDB e importa">&#128269; Discover</button>
                <button class="btn btn-ghost"           data-kdb-subtab="lookup"     onclick="GH.kdbSubtab('lookup',this)">Lookup</button>
                <button class="btn btn-ghost"           data-kdb-subtab="refresh"    onclick="GH.kdbSubtab('refresh',this)">Refresh Pricing</button>
                <button class="btn btn-ghost"           data-kdb-subtab="provenance" onclick="GH.kdbSubtab('provenance',this)">Provenance</button>
                <button class="btn btn-ghost"           data-kdb-subtab="mapping"    onclick="GH.kdbSubtab('mapping',this)">Field Mapping</button>
                <button class="btn btn-ghost"           data-kdb-subtab="rules"      onclick="GH.kdbSubtab('rules',this)">Conflict Rules</button>
                <div style="flex:1"></div>
                <button class="btn btn-ghost"           data-kdb-subtab="settings"   onclick="GH.kdbSubtab('settings',this)" title="Settings">&#9881; Settings</button>
            </div>
        </div>
    </div>

    <!-- ── Sub: DISCOVER (search browser) ─────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="discover">
        <div class="config-form">
            <div class="cfg-row">
                <span class="cfg-label">Query</span>
                <input class="cfg-input" id="kdb-d-query" placeholder="es. 'Nike Dunk Low' / 'Jordan 1' / 'Yeezy'" onkeydown="if(event.key==='Enter'){GH.kdbDiscoverSearch(1)}" />
                <span class="cfg-label">Brand</span>
                <input class="cfg-input" id="kdb-d-brand" placeholder="Nike, Adidas, ..." style="max-width:140px" />
            </div>
            <div class="cfg-row">
                <span class="cfg-label">Sort</span>
                <select class="cfg-select" id="kdb-d-sort" style="max-width:160px">
                    <option value="">Default (relevance)</option>
                    <option value="rank">Rank</option>
                    <option value="release_date">Release date</option>
                </select>
                <select class="cfg-select" id="kdb-d-order" style="max-width:80px">
                    <option value="desc">desc</option>
                    <option value="asc">asc</option>
                </select>
                <span class="cfg-label">Limit</span>
                <input class="cfg-input" id="kdb-d-limit" type="number" min="10" max="100" value="50" style="max-width:80px" />
                <div style="flex:1"></div>
                <button class="btn btn-primary" onclick="GH.kdbDiscoverSearch(1)"><span class="spin" id="kdb-d-spin" style="display:none"></span> Cerca</button>
            </div>
        </div>

        <div class="stats-bar" id="kdb-d-stats" style="display:none">
            <div class="stat">Risultati: <span class="blue" id="kdb-d-count">0</span></div>
            <div class="stat">Selezionati: <span id="kdb-d-selcount">0</span></div>
            <div class="stat">Pagina: <span id="kdb-d-page">1</span></div>
            <div class="stat">Tempo: <span id="kdb-d-duration">0</span> ms</div>
        </div>

        <!-- Bulk action toolbar (shown when items selected) -->
        <div class="gs-sel-bar" id="kdb-d-selbar" style="display:none">
            <button class="btn btn-ghost" onclick="GH.kdbDiscoverSelectAll()">Tutti (nuovi)</button>
            <button class="btn btn-ghost" onclick="GH.kdbDiscoverSelectNone()">Nessuno</button>
            <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px;margin-left:8px">
                <input type="checkbox" id="kdb-d-include-existing" /> Includi anche prodotti gia in catalogo
            </label>
            <div style="flex:1"></div>
            <button class="btn btn-warn" onclick="GH.kdbDiscoverImport()"><span class="spin" id="kdb-d-import-spin" style="display:none"></span> Importa selezionati</button>
        </div>

        <!-- Results grid -->
        <div class="kdb-grid-wrap" id="kdb-d-results">
            <div class="empty-state"><div class="empty-icon">&#128269;</div><div class="empty-text">Cerca prodotti su KicksDB — seleziona e importa in bulk.</div></div>
        </div>

        <!-- Pagination -->
        <div id="kdb-d-pager" style="display:none;padding:8px;text-align:center;font-family:var(--mono);font-size:11px">
            <button class="btn btn-ghost" id="kdb-d-prev" onclick="GH.kdbDiscoverPage(-1)">&larr; Prec</button>
            <span style="padding:0 12px">Pagina <b id="kdb-d-pageinfo">1</b></span>
            <button class="btn btn-ghost" id="kdb-d-next" onclick="GH.kdbDiscoverPage(+1)">Succ &rarr;</button>
        </div>
    </div>

    <!-- ── Sub: SETTINGS ──────────────────────────────────────────────── -->
    <!--
        UI pattern unificato (vedi includes/views/js-settings.php + feeds/settings-store.php):
        - Secret fields: input SEMPRE vuoto. La hint sotto mostra "Salvata: ••••XXXX · N char".
          Lasciare vuoto = "non cambiare". Digitare = "salva questo nuovo valore".
        - Non-secret fields: input normale, popolato col valore corrente al load.
        - Save → toast con stato per-field (updated|preserved|unchanged|cleared|rejected).
        - "Verifica DB" (WP_DEBUG only) dumpa l'opzione realmente storata in console.
    -->
    <div class="kdb-sub" data-kdb-section="settings" style="display:none">
        <div class="config-form">
            <div class="cfg-row"><span class="cfg-label">API Key</span>
                <input class="cfg-input" id="kdb-s-api-key" type="password" autocomplete="new-password" spellcheck="false" placeholder="(lascia vuoto per non cambiare)" />
                <button class="btn btn-ghost" onclick="GH.kdbTestConnection()" title="Smoke test"><span class="spin" id="kdb-test-spin" style="display:none"></span> Test</button>
            </div>
            <div class="cfg-row" style="margin-top:-4px;padding-top:0">
                <span class="cfg-label"></span>
                <span id="kdb-s-api-key-hint" style="font-size:10px"></span>
            </div>

            <div class="cfg-row"><span class="cfg-label">Base URL</span>
                <input class="cfg-input" id="kdb-s-base-url" placeholder="https://api.kicks.dev/v3" />
                <span class="cfg-label">Market</span>
                <input class="cfg-input" id="kdb-s-market" style="max-width:80px" maxlength="3" />
            </div>
            <div class="cfg-row"><span class="cfg-label">Concorrenza</span>
                <input class="cfg-input" id="kdb-s-concurrency" type="number" min="1" max="16" style="max-width:80px" />
                <span class="cfg-label">Cache TTL</span>
                <input class="cfg-input" id="kdb-s-cache-ttl" type="number" min="60" style="max-width:120px" /> <span style="font-size:10px;color:var(--dim)">sec</span>
            </div>

            <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:10px;margin-top:6px">
                <span class="cfg-label" style="color:var(--acc)">Pricing</span>
                <span style="font-size:10px;color:var(--dim)">selling = round(max(market &times; (1 + margin%), floor))</span>
            </div>
            <div class="cfg-row"><span class="cfg-label">Margin %</span>
                <input class="cfg-input" id="kdb-s-margin" type="number" step="0.1" style="max-width:100px" />
                <span class="cfg-label">Floor</span>
                <input class="cfg-input" id="kdb-s-floor" type="number" step="0.01" min="0" style="max-width:100px" />
                <span class="cfg-label">Round</span>
                <select class="cfg-select" id="kdb-s-round-mode" style="max-width:90px">
                    <option value="ceil">ceil</option>
                    <option value="round">round</option>
                    <option value="floor">floor</option>
                </select>
                <input class="cfg-input" id="kdb-s-round-step" type="number" step="0.01" min="0.01" style="max-width:80px" title="Step" />
                <span class="cfg-label">Currency</span>
                <input class="cfg-input" id="kdb-s-currency" maxlength="3" style="max-width:70px" />
            </div>

            <div class="cfg-row"><span class="cfg-label"></span>
                <div style="flex:1"></div>
                <span id="kdb-s-test-result" style="font-size:10px;color:var(--dim)"></span>
                <button class="btn btn-ghost" onclick="GH.feedSettings.dump('kicksdb')" title="WP_DEBUG only — logga in console l'opzione realmente in DB">Verifica DB</button>
                <button class="btn btn-primary" onclick="GH.kdbSaveSettings()">Salva settings</button>
            </div>
        </div>
    </div>

    <!-- ── Sub: LOOKUP ────────────────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="lookup" style="display:none">
        <div class="config-form">
            <div class="cfg-row"><span class="cfg-label">SKU(s)</span>
                <textarea class="cfg-input" id="kdb-lookup-skus" rows="2" placeholder="Uno SKU per riga, o separati da virgola — es. DD1873-102, BQ6817-010"></textarea>
            </div>
            <div class="cfg-row"><span class="cfg-label"></span>
                <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px"><input type="checkbox" id="kdb-lookup-force" /> Force (bypassa cache)</label>
                <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px"><input type="checkbox" id="kdb-lookup-create" checked /> Crea nuovi</label>
                <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:4px"><input type="checkbox" id="kdb-lookup-update" checked /> Aggiorna esistenti</label>
                <div style="flex:1"></div>
                <button class="btn btn-primary" onclick="GH.kdbLookup()"><span class="spin" id="kdb-lookup-spin" style="display:none"></span> Lookup</button>
                <button class="btn btn-warn"    onclick="GH.kdbLookupApply()" id="btn-kdb-lookup-apply" style="display:none"><span class="spin" id="kdb-apply-spin" style="display:none"></span> Applica</button>
            </div>
        </div>
        <div class="stats-bar" id="kdb-lookup-stats" style="display:none">
            <div class="stat">SKU: <span class="blue" id="kdb-stat-total">0</span></div>
            <div class="stat">Cache: <span id="kdb-stat-cached">0</span></div>
            <div class="stat">Fetch: <span id="kdb-stat-fetched">0</span></div>
            <div class="stat">Errori: <span class="red" id="kdb-stat-failed">0</span></div>
            <div class="stat">Nuovi: <span class="green" id="kdb-stat-new">0</span></div>
            <div class="stat">Aggiorn.: <span class="amber" id="kdb-stat-update">0</span></div>
            <div class="stat">Invariati: <span id="kdb-stat-unchanged">0</span></div>
            <div class="stat">Tempo: <span id="kdb-stat-duration">0</span> ms</div>
        </div>
        <div class="preview-wrap" id="kdb-lookup-preview"><div class="empty-state"><div class="empty-icon">&#9883;</div><div class="empty-text">Incolla uno o piu SKU per fare lookup su KicksDB</div></div></div>
    </div>

    <!-- ── Sub: REFRESH PRICING ──────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="refresh" style="display:none">
        <div class="config-form">
            <div class="cfg-row"><span class="cfg-label">SKU(s)</span>
                <textarea class="cfg-input" id="kdb-refresh-skus" rows="2" placeholder="Uno per riga / virgola — solo prodotti tracked verranno aggiornati"></textarea>
            </div>
            <div class="cfg-row"><span class="cfg-label"></span>
                <span style="font-size:10px;color:var(--dim)">Usa il batch endpoint /stockx/prices (50 SKU per call). Solo prodotti con _gh_kicksdb_tracked=1 vengono aggiornati; gli altri sono skippati.</span>
                <div style="flex:1"></div>
                <button class="btn btn-warn" onclick="GH.kdbRefreshPricing()"><span class="spin" id="kdb-refresh-spin" style="display:none"></span> Refresh prezzi</button>
            </div>
        </div>
        <div class="preview-wrap" id="kdb-refresh-preview"><div class="empty-state"><div class="empty-icon">&#8635;</div><div class="empty-text">Incolla SKU di prodotti tracked KicksDB</div></div></div>
    </div>

    <!-- ── Sub: PROVENANCE ────────────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="provenance" style="display:none">
        <div class="config-form">
            <div class="cfg-row">
                <span class="cfg-label">Backfill provenance</span>
                <span style="font-size:10px;color:var(--dim)">tagga prodotti esistenti con la loro source canonica (legacy → manual / goldensneakers / stockfirmati / csv)</span>
                <div style="flex:1"></div>
                <button class="btn btn-ghost" onclick="GH.kdbMigrateStatus()">Aggiorna stato</button>
                <button class="btn btn-primary" onclick="GH.kdbMigrateTick()" id="btn-kdb-migrate"><span class="spin" id="kdb-migrate-spin" style="display:none"></span> Esegui batch</button>
            </div>
            <div class="cfg-row"><span class="cfg-label">Stato</span>
                <div id="kdb-migrate-status" style="font-family:var(--mono);font-size:11px;color:var(--dim);flex:1">—</div>
            </div>
            <div class="cfg-row" style="border-top:1px solid var(--brd);padding-top:10px;margin-top:6px">
                <span class="cfg-label">Lookup product</span>
                <input class="cfg-input" id="kdb-prov-pid" type="number" placeholder="product_id" style="max-width:140px" />
                <button class="btn btn-ghost" onclick="GH.kdbProvenanceLookup()">Mostra provenance</button>
            </div>
        </div>
        <div class="preview-wrap" id="kdb-prov-preview"><div class="empty-state"><div class="empty-icon">&#9740;</div><div class="empty-text">Esegui un batch di backfill o cerca un prodotto per ID</div></div></div>
    </div>

    <!-- ── Sub: FIELD MAPPING ─────────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="mapping" style="display:none">
        <div class="config-form">
            <div class="cfg-row">
                <span class="cfg-label">Mapping profiles</span>
                <span style="font-size:10px;color:var(--dim)">Solo una profile puo essere active; l'active viene applicata a ogni import/refresh (required fields + description template + gallery).</span>
                <div style="flex:1"></div>
                <button class="btn btn-ghost" onclick="GH.kdbMappingReload()">Ricarica</button>
                <button class="btn btn-primary" onclick="GH.kdbMappingNew()">+ Nuovo profile</button>
            </div>
        </div>
        <div class="preview-wrap" id="kdb-m-list"><div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Carica i profiles</div></div></div>
        <div id="kdb-m-editor" style="display:none"></div>
    </div>

    <!-- ── Sub: RULES ─────────────────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="rules" style="display:none">
        <div class="config-form">
            <div class="cfg-row">
                <span class="cfg-label">Conflict rules</span>
                <span style="font-size:10px;color:var(--dim)">Valutate in ordine di priority asc. Prima rule che matcha applica le sue direttive (allow / block / source-pin) per slice.</span>
                <div style="flex:1"></div>
                <button class="btn btn-ghost" onclick="GH.kdbRulesReload()">Ricarica</button>
                <button class="btn btn-warn"  onclick="GH.kdbRulesReset()" title="Reinstalla i default ('manual_sacred' + GS-vs-KicksDB)">Reset default</button>
                <button class="btn btn-primary" onclick="GH.kdbRuleNew()">+ Nuova rule</button>
            </div>
        </div>
        <div class="preview-wrap" id="kdb-rules-list"><div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Carica le rules</div></div></div>
        <div id="kdb-rule-editor" style="display:none"></div>
    </div>

    <!-- Overlay for long ops -->
    <div class="gen-overlay" id="kdb-overlay"><div class="gen-spinner"></div><div class="gen-text" id="kdb-overlay-text">…</div></div>
</div>
