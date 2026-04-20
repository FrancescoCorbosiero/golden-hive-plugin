<!-- ═══ TAXONOMY QUERY (advanced list for Tassonomie tab) ═══ -->
<!--
    Pannello riutilizzabile come "query mode" alternativo alla tree view.
    Si appoggia a gh_ajax_taxonomy_query e permette ricerca, top-N,
    range conteggio, filtro per parent/depth. Il risultato e una lista
    piatta con checkbox per selezione (sorgente per il Navigation populator).
-->
<div class="panel" id="panel-tax-query" style="position:relative">
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <span class="filter-label">Sorgente</span>
        <select class="filter-select" id="tq-taxonomy">
            <option value="product_cat">Categorie (product_cat)</option>
            <option value="product_brand">Brand (product_brand)</option>
        </select>
        <input class="filter-select" id="tq-search" placeholder="Cerca nome/slug"
               onkeydown="if(event.key==='Enter')GH.tqRun()" style="min-width:180px" />
        <span class="filter-label">Parent</span>
        <input class="filter-select" id="tq-parent" type="number" placeholder="any ( -1 = root )" style="width:120px" />
        <span class="filter-label">Depth</span>
        <input class="filter-select" id="tq-depth-min" type="number" placeholder="min" style="width:60px" />
        <input class="filter-select" id="tq-depth-max" type="number" placeholder="max" style="width:60px" />
        <span class="filter-label">Prodotti</span>
        <input class="filter-select" id="tq-min-count" type="number" placeholder="min" style="width:70px" />
        <input class="filter-select" id="tq-max-count" type="number" placeholder="max" style="width:70px" />
        <div class="filter-sep"></div>
        <span class="filter-label">Ordina</span>
        <select class="filter-select" id="tq-orderby">
            <option value="name">Nome</option>
            <option value="count">Conteggio</option>
            <option value="depth">Profondita</option>
            <option value="path">Path</option>
            <option value="id">ID</option>
        </select>
        <select class="filter-select" id="tq-order">
            <option value="asc">ASC</option>
            <option value="desc">DESC</option>
        </select>
        <span class="filter-label">Limit</span>
        <input class="filter-select" id="tq-limit" type="number" value="50" style="width:70px" />
        <div class="filter-sep"></div>
        <button class="btn btn-primary" onclick="GH.tqRun()"><span class="spin" id="tq-spin" style="display:none"></span> Esegui</button>
        <button class="btn btn-ghost" onclick="GH.tqPreset('top15')" title="Top 15 per numero prodotti">Top 15</button>
    </div>

    <!--
        Cross-taxonomy filter: restringi il risultato ai termini che hanno
        almeno un prodotto anche in una delle categorie / brand indicati.
        Esempio d'uso: target=product_brand + in_cat=[Abbigliamento] →
        "brand dei prodotti sotto Abbigliamento" con conteggio filtrato.
        Accetta term_id separati da virgola o spazio.
    -->
    <div class="toolbar" style="background:var(--s1);border-top:1px solid var(--b1);flex-wrap:wrap;gap:8px">
        <span class="filter-label" title="Cross-filter: tieni solo i termini i cui prodotti sono anche in queste categorie/brand">Cross-filter</span>
        <span class="filter-label">in product_cat</span>
        <input class="filter-select" id="tq-in-cat" placeholder="term_id, term_id..." style="min-width:180px" />
        <button class="btn btn-ghost" onclick="GH.tqPickTerms('in-cat','product_cat')" title="Pick da albero">pick...</button>
        <div class="filter-sep"></div>
        <span class="filter-label">in product_brand</span>
        <input class="filter-select" id="tq-in-brand" placeholder="term_id, term_id..." style="min-width:180px" />
        <button class="btn btn-ghost" onclick="GH.tqPickTerms('in-brand','product_brand')" title="Pick da albero">pick...</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.tqPreset('brands-in-cat')" title="Brand dei prodotti nella categoria indicata (imposta target=product_brand)">Brand di &rarr; cat</button>
        <button class="btn btn-ghost" onclick="GH.tqPreset('cats-in-brand')" title="Categorie dei prodotti del brand indicato (imposta target=product_cat)">Cat di &rarr; brand</button>
    </div>

    <div class="stats-bar" id="tq-stats" style="display:none">
        <div class="stat"><span id="tq-total">0</span> risultati</div>
        <div class="stat" id="tq-sel-stat" style="display:none"><span class="green" id="tq-sel-n">0</span> selezionati</div>
        <div class="stat" style="margin-left:auto">
            <button class="btn btn-ghost" onclick="GH.tqSelectAll()">Seleziona tutti</button>
            <button class="btn btn-ghost" onclick="GH.tqSelectNone()">Deseleziona</button>
            <button class="btn btn-primary" onclick="GH.tqSendToNav()" title="Apri Navigazione con i termini selezionati">Invia a Navigazione &rarr;</button>
        </div>
    </div>

    <div id="tq-results" style="flex:1;overflow-y:auto">
        <div class="empty-state"><div class="empty-icon">&#9698;</div><div class="empty-text">Imposta i filtri e clicca Esegui</div></div>
    </div>
</div>

<!-- ═══ NAVIGATION MANAGER ═══ -->
<!--
    Pannello per gestire i menu di navigazione WordPress. Flusso:
      1. Scegli un menu esistente (lista menus registrati).
      2. Scegli l'item target (es. "Abbigliamento") che verra popolato con figli.
      3. Scegli la tassonomia e (opzionale) imposta criteri veloci.
      4. Preview dei term che verranno inseriti.
      5. Populate now (non distruttivo: sostituisce solo i managed).

    I term_id possono anche arrivare dalla tab "Tax Query" via GH.tqSendToNav().
-->
<div class="panel" id="panel-navigation" style="position:relative">
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <span class="filter-label">Menu</span>
        <select class="filter-select" id="nav-menu" onchange="GH.navLoadItems()"></select>
        <span class="filter-label">Target</span>
        <select class="filter-select" id="nav-parent" style="min-width:220px">
            <option value="0">(root del menu)</option>
        </select>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.navLoadMenus()"><span class="spin" id="nav-spin" style="display:none"></span> Ricarica</button>
    </div>

    <div class="config-form" style="border-top:1px solid var(--b1)">
        <div class="cfg-row">
            <span class="cfg-label">Tassonomia</span>
            <select class="cfg-select filter-select" id="nav-taxonomy">
                <option value="product_cat">product_cat</option>
                <option value="product_brand">product_brand</option>
            </select>
            <span class="cfg-label" style="min-width:90px">Ordina per</span>
            <select class="filter-select" id="nav-orderby">
                <option value="count">Conteggio</option>
                <option value="name">Nome</option>
            </select>
            <select class="filter-select" id="nav-order">
                <option value="desc">DESC</option>
                <option value="asc">ASC</option>
            </select>
            <span class="cfg-label" style="min-width:70px">Limite</span>
            <input class="filter-select" id="nav-limit" type="number" value="15" style="width:80px" />
        </div>
        <div class="cfg-row">
            <span class="cfg-label">Sotto parent</span>
            <input class="filter-select" id="nav-ancestor" type="number" placeholder="term_id (vuoto = tutti)" style="width:200px" />
            <span class="cfg-label" style="min-width:70px">Min. prodotti</span>
            <input class="filter-select" id="nav-min-count" type="number" placeholder="0" style="width:80px" />
            <div style="flex:1"></div>
            <label style="font-family:var(--mono);font-size:10px;color:var(--dim);display:flex;align-items:center;gap:6px">
                <input type="checkbox" id="nav-replace-managed" checked />
                sostituisci figli managed esistenti
            </label>
        </div>
        <div class="cfg-row">
            <button class="btn btn-primary" onclick="GH.navPreview()"><span class="spin" id="nav-prev-spin" style="display:none"></span> Preview</button>
            <button class="btn btn-warn" onclick="GH.navPopulate()" id="btn-nav-populate" disabled><span class="spin" id="nav-pop-spin" style="display:none"></span> Populate now</button>
            <div style="flex:1"></div>
            <button class="btn btn-danger" onclick="GH.navClearManaged()" title="Elimina SOLO i figli managed dell'item target">&times; Clear managed children</button>
        </div>
    </div>

    <div style="flex:1;display:flex;overflow:hidden">
        <div style="flex:1;overflow-y:auto;padding:12px 20px;border-right:1px solid var(--b1)">
            <div class="filter-label" style="margin-bottom:6px">Item del menu selezionato</div>
            <div id="nav-items-area">
                <div class="empty-state"><div class="empty-text">Seleziona un menu</div></div>
            </div>
        </div>
        <div style="flex:1;overflow-y:auto;padding:12px 20px">
            <div class="filter-label" style="margin-bottom:6px">Anteprima termini (verranno aggiunti come figli)</div>
            <div id="nav-preview-area">
                <div class="empty-state"><div class="empty-text">Configura criteri e clicca Preview</div></div>
            </div>
        </div>
    </div>
</div>
