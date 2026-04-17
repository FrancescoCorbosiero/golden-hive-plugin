<!-- ═══ FILTER & ACT ═══ -->
<div class="panel active" id="panel-filter" style="position:relative">
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <button class="btn btn-primary" id="btn-run-filter" onclick="GH.runFilter()"><span class="spin" id="filter-spin" style="display:none"></span> Filtra</button>
        <button class="btn btn-ghost" onclick="GH.addCondition()">+ Condizione</button>
        <button class="btn btn-ghost" onclick="GH.clearConditions()">&#10005; Reset</button>
        <div class="filter-sep"></div>
        <span class="filter-label" id="filter-count" style="color:var(--grn);font-weight:600;"></span>
    </div>

    <!-- Condition builder -->
    <div id="filter-conditions" style="padding:0 16px;display:flex;flex-direction:column;gap:6px;"></div>

    <!-- Results area -->
    <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
        <div id="filter-results-area" style="flex:1;overflow-y:auto;padding:0 16px 16px;">
            <div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Aggiungi condizioni e premi "Filtra"</div></div>
        </div>

        <!-- Bulk action bar -->
        <div id="filter-action-bar" style="display:none;padding:12px 16px;background:var(--s1);border-top:1px solid var(--b1);flex-shrink:0;">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:11px;color:var(--dim);font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Azione bulk</span>
                <select id="bulk-action-select" class="filter-select" style="min-width:180px;">
                    <option value="">— Seleziona azione —</option>
                    <optgroup label="Tassonomia">
                        <option value="assign_categories">Aggiungi categorie</option>
                        <option value="remove_categories">Rimuovi categorie</option>
                        <option value="set_categories">Imposta categorie</option>
                        <option value="assign_brands">Aggiungi brand</option>
                        <option value="remove_brands">Rimuovi brand</option>
                        <option value="set_brands">Imposta brand</option>
                        <option value="assign_tags">Aggiungi tag</option>
                        <option value="remove_tags">Rimuovi tag</option>
                    </optgroup>
                    <optgroup label="Stato">
                        <option value="set_status">Cambia stato</option>
                    </optgroup>
                    <optgroup label="Prezzo">
                        <option value="set_sale_percent">Imposta sconto %</option>
                        <option value="remove_sale">Rimuovi saldo</option>
                        <option value="adjust_price">Modifica prezzo</option>
                        <option value="markup_percent">Aumento prezzo %</option>
                        <option value="discount_percent">Sconto prezzo %</option>
                    </optgroup>
                    <optgroup label="Stock">
                        <option value="set_stock_status">Imposta stato stock</option>
                        <option value="set_stock_quantity">Imposta quantita</option>
                    </optgroup>
                    <optgroup label="SEO">
                        <option value="set_seo_template">Template SEO</option>
                    </optgroup>
                    <optgroup label="Media">
                        <option value="remove_first_gallery_image">Rimuovi prima img galleria</option>
                        <option value="clear_gallery">Svuota galleria</option>
                    </optgroup>
                    <optgroup label="Ordine">
                        <option value="set_menu_order">Imposta ordine</option>
                    </optgroup>
                </select>

                <!-- Dynamic param inputs -->
                <div id="bulk-params" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"></div>

                <button class="btn btn-primary" id="btn-bulk-execute" onclick="GH.executeBulk()" disabled>Applica</button>
                <div class="filter-sep"></div>
                <button class="btn btn-ghost" id="btn-bulk-json" onclick="GH.openBulkJson()" style="color:var(--pur)" disabled>{ } JSON Editor</button>
                <span id="bulk-result" style="font-size:11px;color:var(--grn);"></span>
            </div>
        </div>
    </div>
</div>

    <!-- Bulk JSON Editor overlay (inside panel-filter) -->
    <div id="bulk-json-overlay" style="display:none;position:absolute;inset:0;z-index:50;background:var(--bg);flex-direction:column;overflow:hidden;">
        <div class="toolbar" style="flex-shrink:0;">
            <button class="btn btn-ghost" onclick="GH.closeBulkJson()">&larr; Torna ai risultati</button>
            <div class="filter-sep"></div>
            <span style="font-family:var(--mono);font-size:11px;color:var(--pur);font-weight:500" id="bjson-title">Bulk JSON Editor</span>
            <div style="flex:1"></div>
            <span style="font-family:var(--mono);font-size:10px;color:var(--dim)" id="bjson-status"></span>
            <button class="btn btn-ghost" onclick="GH.bulkJsonCopy()" style="font-size:10px">Copia</button>
            <button class="btn btn-ghost" onclick="GH.bulkJsonPaste()" style="font-size:10px">Incolla</button>
            <button class="btn btn-ghost" onclick="GH.bulkJsonFormat()" style="font-size:10px">Formatta</button>
            <button class="btn btn-warn" id="btn-bjson-apply" onclick="GH.bulkJsonApply()"><span class="spin" id="bjson-apply-spin" style="display:none"></span> Applica</button>
        </div>
        <textarea id="bjson-editor" style="flex:1;width:100%;resize:none;background:var(--s2);color:var(--txt);border:none;padding:16px;font-family:var(--mono);font-size:11px;line-height:1.5;tab-size:2;outline:none;" spellcheck="false" placeholder="[ { ... }, { ... } ]"></textarea>
        <div id="bjson-result" style="display:none;padding:12px 16px;background:var(--s1);border-top:1px solid var(--b1);flex-shrink:0;max-height:200px;overflow-y:auto;font-family:var(--mono);font-size:11px"></div>
    </div>
</div>

<!-- ═══ SORTING ═══ -->
<div class="panel" id="panel-sorting" style="position:relative">
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <span class="filter-label">Regola</span>
        <select class="filter-select" id="sort-rule" style="min-width:200px;">
            <option value="name_asc">Nome A → Z</option>
            <option value="name_desc">Nome Z → A</option>
            <option value="price_asc">Prezzo crescente</option>
            <option value="price_desc">Prezzo decrescente</option>
            <option value="date_newest">Piu recenti prima</option>
            <option value="date_oldest">Piu vecchi prima</option>
            <option value="stock_first">In stock prima</option>
            <option value="stock_last">Esauriti prima</option>
            <option value="sku_asc">SKU A → Z</option>
            <option value="variant_count_desc">Piu taglie prima</option>
            <option value="sale_first">In saldo prima</option>
        </select>
        <span class="filter-label">Sorgente</span>
        <select class="filter-select" id="sort-source" style="min-width:160px;">
            <option value="all">Tutti i prodotti</option>
            <option value="filtered">Solo filtrati (da tab Filtra)</option>
        </select>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.sortPreview()"><span class="spin" id="sort-spin" style="display:none"></span> Anteprima</button>
        <button class="btn btn-primary" id="btn-sort-apply" onclick="GH.sortApply()" disabled>Applica Ordinamento</button>
    </div>

    <div id="sort-results" style="flex:1;overflow-y:auto;padding:16px;">
        <div class="empty-state"><div class="empty-icon">&#8693;</div><div class="empty-text">Seleziona una regola e premi "Anteprima"</div></div>
    </div>
</div>

<!-- ═══ INLINE EDITOR ═══ -->
<!--
    Editor focalizzato per un singolo prodotto. Complementa Filtra & Agisci:
    quello e per bulk, questo per "lavora di precisione su un prodotto alla volta".

    Tre modi di editing, switchabili tramite sub-tabs:
    - Form        → campi validati per name/sku/prezzi/stock/SEO/stato
    - JSON        → payload editabile, dev-first, apply as-is via rp_update_product
    - Variations  → tabella inline editabile (per prodotti variable)

    Apertura:
    - Manuale: digita ID/SKU/nome nella search bar
    - Da Filtra & Agisci: bottone "Edit" per riga → GH.openInlineEditor(id)
-->
<div class="panel" id="panel-inline-editor" style="position:relative">
    <!-- Search / load bar -->
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <div class="search-wrap" style="flex:1;min-width:260px;position:relative">
            <input class="filter-select" id="ie-search" placeholder="Cerca prodotto: ID, SKU, o titolo..." autocomplete="off" style="width:100%"
                   oninput="GH.ieSearch()" onkeydown="GH.ieSearchKey(event)" />
            <div id="ie-search-drop" class="ie-search-drop"></div>
        </div>
        <button class="btn btn-ghost" onclick="GH.ieUnload()">&times; Chiudi</button>
    </div>

    <!-- Product header (hidden until load) -->
    <div id="ie-header" style="display:none;padding:10px 16px;background:var(--s1);border-bottom:1px solid var(--b1);flex-shrink:0">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <span class="mono" id="ie-h-id" style="color:var(--dim);font-size:10px"></span>
            <span id="ie-h-name" style="font-family:var(--sans);font-size:13px;font-weight:500;color:var(--txt)"></span>
            <span class="mono" id="ie-h-sku" style="color:var(--acc);font-size:11px"></span>
            <span id="ie-h-type" style="font-family:var(--mono);font-size:10px"></span>
            <span id="ie-h-status" style="font-family:var(--mono);font-size:10px"></span>
            <a id="ie-h-link" href="#" target="_blank" rel="noopener" style="color:var(--acc);text-decoration:none;font-size:13px" title="Apri prodotto">&#128065;</a>
            <span class="filter-sep"></span>
            <span id="ie-dirty-badge" class="ie-dirty-badge" style="display:none">&#9679; Non salvato</span>
            <button class="btn btn-primary" id="btn-ie-save" onclick="GH.ieSave()" disabled><span class="spin" id="ie-save-spin" style="display:none"></span> Salva</button>
            <button class="btn btn-ghost" onclick="GH.ieReload()">Ricarica</button>
        </div>
    </div>

    <!-- Sub-tabs: Form / JSON / Variations -->
    <div id="ie-tabs" style="display:none;background:var(--s1);border-bottom:1px solid var(--b1);flex-shrink:0">
        <div style="display:flex;gap:0">
            <button class="ie-subtab active" data-ie-tab="form" onclick="GH.ieSwitch('form')">Proprieta</button>
            <button class="ie-subtab" data-ie-tab="json" onclick="GH.ieSwitch('json')">JSON</button>
            <button class="ie-subtab" data-ie-tab="variations" id="ie-subtab-variations" onclick="GH.ieSwitch('variations')" style="display:none">Variazioni</button>
        </div>
    </div>

    <!-- Content area (scrollable) -->
    <div id="ie-content" style="flex:1;overflow-y:auto;padding:16px">
        <div class="empty-state"><div class="empty-icon">&#9783;</div><div class="empty-text">Cerca un prodotto per iniziare. Da Filtra & Agisci puoi usare "Edit" per aprirlo direttamente qui.</div></div>
    </div>

    <div class="gen-overlay" id="ie-overlay"><div class="gen-spinner"></div><div class="gen-text" id="ie-overlay-text">Caricamento...</div></div>
</div>
