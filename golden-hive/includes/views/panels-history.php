<!-- ═══ CATALOG HISTORY (DIFF VISUALIZATION) ═══════════════════════════════ -->
<!--
    Visualizza lo storico del catalogo: snapshot giornalieri di tutti i prodotti
    (con sources, taxonomy, meta tag) e diff visuale tra due date.

    Sorgente snapshot: cron daily (job kind 'catalog_snapshot') + manual
    "Cattura ora". Retention: 30 giorni — pruning automatico ad ogni capture.
-->
<div class="panel" id="panel-history" style="position:relative">

    <!-- Toolbar: pickers A vs B + actions -->
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <span class="filter-label">Confronta</span>
        <select class="filter-select" id="hist-snap-a" style="min-width:240px">
            <option value="">— Snapshot A (precedente) —</option>
        </select>
        <span style="color:var(--dim);font-family:var(--mono);font-size:11px">→</span>
        <select class="filter-select" id="hist-snap-b" style="min-width:240px">
            <option value="">— Snapshot B (successivo) —</option>
        </select>
        <button class="btn btn-primary" onclick="GH.histRunDiff()">
            <span class="spin" id="hist-diff-spin" style="display:none"></span> Calcola diff
        </button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.histRefreshList()">Ricarica lista</button>
        <button class="btn btn-ghost" onclick="GH.histCaptureNow()">
            <span class="spin" id="hist-cap-spin" style="display:none"></span> Cattura snapshot ora
        </button>
        <span style="margin-left:auto;color:var(--dim);font-family:var(--mono);font-size:11px" id="hist-retention-note">
            Retention 30 giorni
        </span>
    </div>

    <!-- Snapshot list (collapsible) -->
    <div class="stats-bar" id="hist-list-bar" style="display:none">
        <div class="stat"><span id="hist-snap-count">0</span> snapshot disponibili</div>
        <div class="stat" id="hist-newest-stat" style="display:none">
            Ultimo: <span id="hist-newest"></span>
        </div>
        <div class="stat" id="hist-oldest-stat" style="display:none">
            Primo: <span id="hist-oldest"></span>
        </div>
    </div>

    <!-- Diff result area -->
    <div id="hist-result-area" style="flex:1;overflow:auto;padding:0">
        <div class="empty-state" id="hist-empty">
            <div class="empty-icon" style="font-size:32px">&#8644;</div>
            <div class="empty-text">
                Seleziona due snapshot e premi <strong>Calcola diff</strong> per vedere le differenze del catalogo.
                <br><span style="color:var(--dim);font-size:11px">
                    Lo snapshot giornaliero gira automaticamente alle 03:15. Puoi anche catturarne uno manualmente.
                </span>
            </div>
        </div>

        <!-- Filled by GH.histRenderDiff() -->
        <div id="hist-diff-content" style="display:none">

            <!-- Summary cards -->
            <div id="hist-summary" style="display:flex;gap:12px;padding:16px 20px;flex-wrap:wrap;border-bottom:1px solid var(--b1)">
                <div class="gh-card gh-card--compact" style="min-width:140px">
                    <div style="font-family:var(--mono);font-size:10px;color:var(--dim);letter-spacing:.05em">AGGIUNTI</div>
                    <div style="font-family:var(--mono);font-size:24px;color:var(--grn);font-weight:600" id="hist-sum-added">0</div>
                </div>
                <div class="gh-card gh-card--compact" style="min-width:140px">
                    <div style="font-family:var(--mono);font-size:10px;color:var(--dim);letter-spacing:.05em">RIMOSSI</div>
                    <div style="font-family:var(--mono);font-size:24px;color:var(--red);font-weight:600" id="hist-sum-removed">0</div>
                </div>
                <div class="gh-card gh-card--compact" style="min-width:140px">
                    <div style="font-family:var(--mono);font-size:10px;color:var(--dim);letter-spacing:.05em">MODIFICATI</div>
                    <div style="font-family:var(--mono);font-size:24px;color:var(--amb);font-weight:600" id="hist-sum-changed">0</div>
                </div>
                <div class="gh-card gh-card--compact" style="min-width:140px">
                    <div style="font-family:var(--mono);font-size:10px;color:var(--dim);letter-spacing:.05em">INVARIATI</div>
                    <div style="font-family:var(--mono);font-size:24px;color:var(--dim);font-weight:600" id="hist-sum-unchanged">0</div>
                </div>
                <div class="gh-card gh-card--compact" style="min-width:140px">
                    <div style="font-family:var(--mono);font-size:10px;color:var(--dim);letter-spacing:.05em">CAMBI TOTALI</div>
                    <div style="font-family:var(--mono);font-size:24px;color:var(--acc);font-weight:600" id="hist-sum-total">0</div>
                </div>
                <div class="gh-card gh-card--compact" style="flex:1;min-width:240px">
                    <div style="font-family:var(--mono);font-size:10px;color:var(--dim);letter-spacing:.05em">RANGE</div>
                    <div style="font-family:var(--mono);font-size:13px;color:var(--txt)" id="hist-sum-range">—</div>
                </div>
            </div>

            <!-- Filter bar for diff results -->
            <div class="toolbar" style="flex-wrap:wrap">
                <span class="filter-label">Mostra</span>
                <select class="filter-select" id="hist-filter-bucket" onchange="GH.histRenderDiffTable()">
                    <option value="all">Tutti (aggiunti, rimossi, modificati)</option>
                    <option value="added">Solo aggiunti</option>
                    <option value="removed">Solo rimossi</option>
                    <option value="changed">Solo modificati</option>
                </select>
                <span class="filter-label">Source</span>
                <select class="filter-select" id="hist-filter-source" onchange="GH.histRenderDiffTable()">
                    <option value="">Tutte</option>
                </select>
                <span class="filter-label">Gruppo campi</span>
                <select class="filter-select" id="hist-filter-group" onchange="GH.histRenderDiffTable()">
                    <option value="">Tutti</option>
                    <option value="product">Product data</option>
                    <option value="taxonomy">Taxonomy</option>
                    <option value="seo">SEO meta</option>
                    <option value="provenance">Provenance</option>
                    <option value="variations">Variations</option>
                </select>
                <input class="filter-select" id="hist-filter-search" placeholder="Cerca nome/SKU..."
                       oninput="GH.histRenderDiffTable()" style="min-width:200px;flex:1;max-width:280px">
            </div>

            <!-- Results table -->
            <div id="hist-diff-table-wrap" style="padding:0 20px 20px"></div>
        </div>
    </div>
</div>
