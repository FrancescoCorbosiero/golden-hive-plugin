<!-- ═══ FILTER & ACT ═══ -->
<div class="panel" id="panel-filter" style="position:relative">
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
                    <optgroup label="Ordine">
                        <option value="set_menu_order">Imposta ordine</option>
                    </optgroup>
                </select>

                <!-- Dynamic param inputs -->
                <div id="bulk-params" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"></div>

                <button class="btn btn-primary" id="btn-bulk-execute" onclick="GH.executeBulk()" disabled>Applica</button>
                <span id="bulk-result" style="font-size:11px;color:var(--grn);"></span>
            </div>
        </div>
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
