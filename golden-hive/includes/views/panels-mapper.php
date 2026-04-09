<!-- ═══ MAPPER — REGOLE ═══ -->
<div class="panel" id="panel-mapper-rules">
    <div class="toolbar">
        <button class="btn btn-primary" onclick="GH.mapperLoadRules()"><span class="spin" id="mpr-spin" style="display:none"></span> Aggiorna</button>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.mapperNewRule()">+ Nuova Regola</button>
    </div>
    <div class="mp-rules-list" id="mp-rules-list">
        <div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Nessuna regola di mapping salvata.<br>Crea la prima regola per iniziare.</div></div>
    </div>
</div>

<!-- ═══ MAPPER — EDITOR ═══ -->
<div class="panel" id="panel-mapper-editor" style="position:relative">
    <!-- Step bar -->
    <div class="mp-steps">
        <div class="mp-step active" data-step="1" onclick="GH.mapperGoStep(1)"><span class="mp-step-n">1</span> Sorgente</div>
        <div class="mp-step" data-step="2" onclick="GH.mapperGoStep(2)"><span class="mp-step-n">2</span> Mapping</div>
        <div class="mp-step" data-step="3" onclick="GH.mapperGoStep(3)"><span class="mp-step-n">3</span> Preview</div>
    </div>

    <!-- STEP 1: Source JSON -->
    <div class="mp-stage active" id="mp-stage-1">
        <div class="mp-form-row">
            <span class="cfg-label">Nome</span>
            <input class="cfg-input" id="mp-rule-name" placeholder="Es: GoldenSneakers → WC" />
        </div>
        <div class="mp-form-row">
            <span class="cfg-label">Descrizione</span>
            <input class="cfg-input" id="mp-rule-desc" placeholder="Descrizione opzionale" />
        </div>
        <div class="mp-form-row">
            <span class="cfg-label">Items Path</span>
            <input class="cfg-input" id="mp-items-path" placeholder="Es: products, data.items (percorso all'array prodotti)" />
        </div>
        <div class="section-title">JSON sorgente di esempio</div>
        <div class="mp-source-area">
            <div class="drop-area" id="mp-source-drop" onclick="document.getElementById('mp-source-file').click()">
                <input type="file" id="mp-source-file" accept=".json" style="display:none" />
                <div class="drop-area-text">Trascina un file .json o clicca per selezionare</div>
                <div class="drop-area-file" id="mp-source-filename"></div>
            </div>
            <div class="mp-or-label">oppure incolla:</div>
            <textarea class="mp-source-textarea" id="mp-source-textarea" placeholder='{"products":[{"name":"...","price":99}]}'></textarea>
        </div>
        <div class="mp-stage-actions">
            <button class="btn btn-primary" onclick="GH.mapperParseSource()"><span class="spin" id="mp-parse-spin" style="display:none"></span> Analizza campi</button>
        </div>
    </div>

    <!-- STEP 2: Visual Mapping -->
    <div class="mp-stage" id="mp-stage-2">
        <div class="mp-mapper-layout">
            <!-- Left: Source fields -->
            <div class="mp-col mp-col-source">
                <div class="mp-col-head">
                    <span class="mp-col-title">Sorgente</span>
                    <span class="mp-col-count" id="mp-src-count">0 campi</span>
                </div>
                <div class="mp-field-list" id="mp-source-fields"></div>
            </div>

            <!-- Center: Mapping connections -->
            <div class="mp-col mp-col-mappings">
                <div class="mp-col-head">
                    <span class="mp-col-title">Regole di mapping</span>
                    <button class="btn btn-ghost" style="padding:3px 8px;font-size:9px" onclick="GH.mapperAddRow()">+ Aggiungi</button>
                </div>
                <div class="mp-mapping-rows" id="mp-mapping-rows">
                    <div class="empty-state" style="padding:40px 0"><div class="empty-text">Clicca "Aggiungi" per creare<br>la prima regola di mapping</div></div>
                </div>
            </div>

            <!-- Right: WC Target fields -->
            <div class="mp-col mp-col-target">
                <div class="mp-col-head">
                    <span class="mp-col-title">WooCommerce</span>
                    <span class="mp-col-count" id="mp-tgt-count">0 campi</span>
                </div>
                <div class="mp-field-list" id="mp-target-fields"></div>
            </div>
        </div>
        <div class="mp-stage-actions">
            <button class="btn btn-ghost" onclick="GH.mapperGoStep(1)">&larr; Sorgente</button>
            <button class="btn btn-primary" onclick="GH.mapperPreview()"><span class="spin" id="mp-preview-spin" style="display:none"></span> Preview &rarr;</button>
        </div>
    </div>

    <!-- STEP 3: Preview & Save/Apply -->
    <div class="mp-stage" id="mp-stage-3">
        <div class="mp-preview-toolbar">
            <span class="mp-preview-summary" id="mp-preview-summary"></span>
            <div class="filter-sep"></div>
            <button class="btn btn-ghost" onclick="GH.mapperGoStep(2)">&larr; Modifica</button>
            <button class="btn btn-primary" onclick="GH.mapperSaveRule()"><span class="spin" id="mp-save-spin" style="display:none"></span> Salva regola</button>
        </div>
        <div class="preview-wrap" id="mp-preview-area"></div>
        <div class="confirm-bar" id="mp-apply-bar" style="display:none">
            <div class="mp-apply-form">
                <span class="cfg-label">Modalit&agrave;</span>
                <select class="cfg-select" id="mp-apply-mode">
                    <option value="create">Crea sempre</option>
                    <option value="create_or_update">Crea o aggiorna (SKU)</option>
                    <option value="update_by_sku">Solo aggiorna (SKU)</option>
                </select>
            </div>
            <button class="btn btn-warn" onclick="GH.mapperApply()"><span class="spin" id="mp-apply-spin" style="display:none"></span> Applica a WooCommerce</button>
        </div>
    </div>

    <div class="gen-overlay" id="mp-overlay"><div class="gen-spinner"></div><div class="gen-text" id="mp-overlay-text">Elaborazione...</div></div>
</div>

<!-- ═══ MAPPER — Transform modal (reused) ═══ -->
<div class="mp-modal-overlay" id="mp-transform-modal" style="display:none">
    <div class="mp-modal">
        <div class="mp-modal-head">
            <span class="mp-modal-title">Trasformazioni</span>
            <button class="btn btn-ghost" style="padding:3px 8px" onclick="GH.mapperCloseTransforms()">&#10005;</button>
        </div>
        <div class="mp-modal-body" id="mp-transform-list"></div>
        <div class="mp-modal-foot">
            <select class="cfg-select" id="mp-add-transform-type">
                <option value="">+ Aggiungi trasformazione...</option>
            </select>
            <div style="flex:1"></div>
            <button class="btn btn-primary" onclick="GH.mapperSaveTransforms()">Conferma</button>
        </div>
    </div>
</div>
