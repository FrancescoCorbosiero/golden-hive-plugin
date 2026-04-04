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

<!-- ═══ SORTING / REPOSITIONING ═══ -->
<div class="panel" id="panel-sorting" style="position:relative">
    <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <span class="filter-label">Categoria</span>
        <select class="filter-select" id="sort-category" style="min-width:200px;">
            <option value="">— Seleziona categoria —</option>
        </select>
        <button class="btn btn-primary" id="btn-sort-load" onclick="GH.loadCategoryOrder()"><span class="spin" id="sort-spin" style="display:none"></span> Carica</button>
        <div class="filter-sep"></div>
        <span class="filter-label" id="sort-count" style="color:var(--grn);font-weight:600;"></span>
    </div>

    <!-- Move action bar -->
    <div id="sort-action-bar" style="display:none;padding:8px 16px;background:var(--s2);border-bottom:1px solid var(--b1);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span id="sort-sel-count" style="font-size:11px;color:var(--acc);font-weight:600;"></span>
        <div class="filter-sep" style="height:16px;"></div>
        <button class="btn btn-ghost btn-sm" onclick="GH.repoMove('to_top')">&#9650; In cima</button>
        <button class="btn btn-ghost btn-sm" onclick="GH.repoMove('to_bottom')">&#9660; In fondo</button>
        <div class="filter-sep" style="height:16px;"></div>
        <span class="filter-label">Posizione</span>
        <input type="number" class="filter-select" id="sort-pos" min="1" style="width:60px;" placeholder="N">
        <button class="btn btn-ghost btn-sm" onclick="GH.repoMove('to_position')">Sposta</button>
        <div class="filter-sep" style="height:16px;"></div>
        <span class="filter-label">Dopo prodotto</span>
        <select class="filter-select" id="sort-after-product" style="min-width:180px;">
            <option value="">— Seleziona —</option>
        </select>
        <button class="btn btn-ghost btn-sm" onclick="GH.repoMove('after')">Dopo</button>
        <div class="filter-sep" style="height:16px;"></div>
        <button class="btn btn-primary btn-sm" id="btn-repo-apply" onclick="GH.repoApply()" disabled>Applica</button>
        <span id="sort-result" style="font-size:11px;color:var(--grn);"></span>
    </div>

    <!-- Auto-sort section (collapsible) -->
    <div style="padding:4px 16px;border-bottom:1px solid var(--b1);">
        <details>
            <summary style="cursor:pointer;font-size:10px;color:var(--dim);font-family:var(--mono);letter-spacing:.05em;">ORDINAMENTO AUTOMATICO (regole)</summary>
            <div style="padding:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select class="filter-select" id="sort-rule" style="min-width:180px;">
                    <option value="name_asc">Nome A-Z</option><option value="name_desc">Nome Z-A</option>
                    <option value="price_asc">Prezzo cresc.</option><option value="price_desc">Prezzo decresc.</option>
                    <option value="date_newest">Recenti prima</option><option value="date_oldest">Vecchi prima</option>
                    <option value="stock_first">In stock prima</option><option value="stock_last">Esauriti prima</option>
                    <option value="sku_asc">SKU A-Z</option><option value="variant_count_desc">Piu taglie prima</option>
                    <option value="sale_first">In saldo prima</option>
                </select>
                <button class="btn btn-ghost btn-sm" onclick="GH.sortPreview()">Anteprima</button>
                <button class="btn btn-primary btn-sm" id="btn-sort-apply" onclick="GH.sortApply()" disabled>Applica regola</button>
            </div>
        </details>
    </div>

    <div id="sort-results" style="flex:1;overflow-y:auto;padding:16px;">
        <div class="empty-state"><div class="empty-icon">&#8693;</div><div class="empty-text">Seleziona una categoria e carica l'ordine corrente</div></div>
    </div>
</div>
