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

<!-- ═══ KICKSDB ═══════════════════════════════════════════════════════════ -->
<div class="panel" id="panel-kicksdb" style="position:relative">

    <!-- Sub-tabs -->
    <div class="config-form" style="padding-bottom:0">
        <div class="cfg-row" style="border-bottom:none;padding-bottom:6px">
            <div style="display:flex;gap:4px;flex-wrap:wrap">
                <button class="btn btn-ghost is-active" data-kdb-subtab="lookup"     onclick="GH.kdbSubtab('lookup',this)">Lookup</button>
                <button class="btn btn-ghost"           data-kdb-subtab="refresh"    onclick="GH.kdbSubtab('refresh',this)">Refresh Pricing</button>
                <button class="btn btn-ghost"           data-kdb-subtab="provenance" onclick="GH.kdbSubtab('provenance',this)">Provenance</button>
                <button class="btn btn-ghost"           data-kdb-subtab="rules"      onclick="GH.kdbSubtab('rules',this)">Conflict Rules</button>
                <div style="flex:1"></div>
                <button class="btn btn-ghost"           data-kdb-subtab="settings"   onclick="GH.kdbSubtab('settings',this)" title="Settings">&#9881; Settings</button>
            </div>
        </div>
    </div>

    <!-- ── Sub: SETTINGS ──────────────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="settings" style="display:none">
        <div class="config-form">
            <div class="cfg-row"><span class="cfg-label">API Key</span>
                <input class="cfg-input" id="kdb-s-api-key" type="password" placeholder="(redatta dopo save)" />
                <button class="btn btn-ghost" onclick="GH.kdbTestConnection()" title="Smoke test"><span class="spin" id="kdb-test-spin" style="display:none"></span> Test</button>
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
                <button class="btn btn-primary" onclick="GH.kdbSaveSettings()">Salva settings</button>
            </div>
        </div>
    </div>

    <!-- ── Sub: LOOKUP ────────────────────────────────────────────────── -->
    <div class="kdb-sub" data-kdb-section="lookup">
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
