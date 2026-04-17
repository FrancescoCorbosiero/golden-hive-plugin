<!-- ═══ EMAIL — TEMPLATES ═══ -->
<div class="panel" id="panel-email-templates">
    <!-- Template List View -->
    <div id="em-tpl-list-view">
        <div class="toolbar">
            <span class="filter-label">Email Templates</span>
            <div class="filter-sep"></div>
            <button class="btn btn-primary" onclick="GH.emTplNew()">+ Nuovo Template</button>
            <button class="btn btn-ghost" onclick="GH.emTplLoad()">Aggiorna</button>
        </div>
        <div class="preview-wrap" id="em-tpl-list">
            <div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Nessun template. Crea il primo per iniziare.</div></div>
        </div>
    </div>

    <!-- Template Editor View -->
    <div id="em-tpl-editor-view" style="display:none;flex:1;display:none;flex-direction:column;overflow:hidden">
        <div class="toolbar">
            <button class="btn btn-ghost" onclick="GH.emTplBackToList()">&larr; Lista</button>
            <div class="filter-sep"></div>
            <span class="filter-label" id="em-tpl-editor-title">Nuovo Template</span>
            <div style="flex:1"></div>
            <button class="btn btn-ghost" id="btn-em-tpl-delete" onclick="GH.emTplDelete()" style="color:var(--red);display:none">Elimina</button>
            <button class="btn btn-primary" id="btn-em-tpl-save" onclick="GH.emTplSave()"><span class="spin" id="em-tpl-save-spin" style="display:none"></span> Salva</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px">
            <div class="cfg-row"><span class="cfg-label">Nome</span><input class="cfg-input" id="em-tpl-name" placeholder="Es: Conferma spedizione" /></div>
            <div class="cfg-row"><span class="cfg-label">Categoria</span>
                <select class="cfg-select" id="em-tpl-category">
                    <option value="general">Generale</option>
                    <option value="order">Ordine</option>
                    <option value="marketing">Marketing</option>
                    <option value="support">Supporto</option>
                </select>
            </div>
            <div class="cfg-row"><span class="cfg-label">Oggetto</span><input class="cfg-input" id="em-tpl-subject" placeholder="Es: Il tuo ordine #{order_id} è stato spedito" /></div>
            <div class="cfg-row em-row-stretch" style="flex:1">
                <span class="cfg-label">HTML Body</span>
                <textarea class="cfg-input em-textarea" id="em-tpl-body" style="min-height:250px" placeholder="<h1>Ciao {first_name}!</h1>&#10;&#10;<p>Il tuo ordine #{order_id} è stato spedito.</p>"></textarea>
            </div>

            <!-- Placeholder Picker -->
            <div style="border:1px solid var(--b1);border-radius:6px;padding:12px;background:var(--s2)">
                <div style="font-family:var(--mono);font-size:11px;color:var(--acc);margin-bottom:8px">Placeholder disponibili — clicca per inserire</div>
                <div id="em-tpl-placeholders" style="display:flex;flex-wrap:wrap;gap:4px;font-family:var(--mono);font-size:10px"></div>
            </div>

            <!-- Send / Preview Section -->
            <div style="border:1px solid var(--b1);border-radius:6px;padding:12px;background:var(--s2)">
                <div style="font-family:var(--mono);font-size:11px;color:var(--acc);margin-bottom:8px">Invia con dati reali</div>
                <div class="cfg-row" style="flex-wrap:wrap;gap:6px">
                    <span class="cfg-label" style="min-width:50px">A:</span>
                    <input class="cfg-input" id="em-tpl-send-to" type="email" placeholder="email@destinatario.com" style="max-width:220px" />
                    <div class="filter-sep"></div>
                    <span class="cfg-label" style="min-width:50px">Ordine:</span>
                    <input class="cfg-input" id="em-tpl-ctx-order" type="text" placeholder="ID o email" style="max-width:140px" />
                    <button class="btn btn-ghost" onclick="GH.emTplSearchOrder()" style="font-size:10px">Cerca</button>
                    <div class="filter-sep"></div>
                    <span class="cfg-label" style="min-width:50px">Cliente:</span>
                    <input class="cfg-input" id="em-tpl-ctx-customer" type="text" placeholder="ID o email" style="max-width:140px" />
                    <button class="btn btn-ghost" onclick="GH.emTplSearchCustomer()" style="font-size:10px">Cerca</button>
                </div>
                <div id="em-tpl-search-results" style="font-family:var(--mono);font-size:10px;color:var(--dim);padding:4px 0"></div>
                <div class="cfg-row" style="margin-top:8px;gap:6px">
                    <button class="btn btn-ghost" onclick="GH.emTplPreview()"><span class="spin" id="em-tpl-preview-spin" style="display:none"></span> Anteprima</button>
                    <button class="btn btn-warn" onclick="GH.emTplSend()"><span class="spin" id="em-tpl-send-spin" style="display:none"></span> Invia email</button>
                    <button class="btn btn-ghost" onclick="GH.emTplUseInCampaign()" style="font-size:10px;color:var(--pur)" title="Usa questo template come corpo campagna">Usa in campagna</button>
                </div>
            </div>

            <!-- Preview Output -->
            <div id="em-tpl-preview-area" style="display:none;border:1px solid var(--b1);border-radius:6px;overflow:hidden">
                <div style="padding:8px 12px;background:var(--s1);font-family:var(--mono);font-size:10px;color:var(--dim)" id="em-tpl-preview-subject"></div>
                <div id="em-tpl-preview-body" style="padding:16px;background:#fff;color:#333;font-size:13px"></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ EMAIL — TEST ═══ -->
<div class="panel" id="panel-email-test">
    <div class="toolbar">
        <span class="filter-label">Test Email</span>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="btn-em-send-test" onclick="GH.emSendTest()"><span class="spin" id="em-test-spin" style="display:none"></span> Invia test</button>
    </div>
    <div class="em-form">
        <div class="cfg-row"><span class="cfg-label">A</span><input class="cfg-input" id="em-test-to" type="email" placeholder="destinatario@example.com" /></div>
        <div class="cfg-row"><span class="cfg-label">Oggetto</span><input class="cfg-input" id="em-test-subject" placeholder="(opzionale: usa template di default)" /></div>
        <div class="cfg-row em-row-stretch"><span class="cfg-label">HTML</span><textarea class="cfg-input em-textarea" id="em-test-body" placeholder="(opzionale: usa template di default)"></textarea></div>
        <div class="em-hint">Questo invia tramite <strong>wp_mail()</strong> instradato su WP Mail SMTP / AWS SES. Lascia vuoti oggetto e corpo per usare il template di default.</div>
    </div>
</div>

<!-- ═══ EMAIL — CAMPAIGNS ═══ -->
<div class="panel" id="panel-email-campaigns">
    <div class="toolbar">
        <span class="filter-label">Campagne</span>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" onclick="GH.emCampaignNew()">+ Nuova campagna</button>
        <button class="btn btn-ghost" onclick="GH.emCampaignsLoad()"><span class="spin" id="em-camp-spin" style="display:none"></span> Aggiorna</button>
    </div>
    <!-- list view -->
    <div class="em-camp-list" id="em-camp-list">
        <div class="empty-state"><div class="empty-icon">&#9758;</div><div class="empty-text">Carica per visualizzare le campagne</div></div>
    </div>
    <!-- editor view (hidden by default) -->
    <div class="em-camp-editor" id="em-camp-editor" style="display:none">
        <div class="em-form">
            <div class="cfg-row"><span class="cfg-label">Nome</span><input class="cfg-input" id="em-c-name" placeholder="Nome interno della campagna" /></div>
            <div class="cfg-row"><span class="cfg-label">Oggetto</span><input class="cfg-input" id="em-c-subject" placeholder="Oggetto email" /></div>
            <div class="cfg-row em-row-stretch"><span class="cfg-label">HTML</span><textarea class="cfg-input em-textarea" id="em-c-body" placeholder="Corpo HTML. Placeholder: {{first_name}}, {{email}}, {{site_name}}"></textarea></div>
            <div class="cfg-row">
                <span class="cfg-label">Sorgente</span>
                <select class="cfg-select" id="em-c-source" onchange="GH.emToggleSource()">
                    <option value="hustle">Hustle</option>
                    <option value="csv">CSV raw</option>
                    <option value="mixed">Mixed</option>
                </select>
                <span class="cfg-label">Rate</span>
                <select class="cfg-select" id="em-c-rate">
                    <option value="200000">Normale (~5/s)</option>
                    <option value="50000">Veloce (~20/s)</option>
                    <option value="1000000">Lento (1/s)</option>
                </select>
            </div>
            <div class="cfg-row em-row-csv" id="em-c-csv-row" style="display:none">
                <span class="cfg-label">CSV</span>
                <textarea class="cfg-input em-textarea-sm" id="em-c-csv" placeholder="email,display_name&#10;john@x.com,John"></textarea>
            </div>
            <div class="cfg-row"><span class="cfg-label">Schedule</span><input class="cfg-input" id="em-c-sched" type="datetime-local" /><span class="em-hint-inline">(vuoto = invio immediato manuale)</span></div>
        </div>
        <div class="confirm-bar">
            <span class="summary-text" id="em-c-status">Bozza</span>
            <button class="btn btn-ghost" onclick="GH.emCampaignBackToList()">Annulla</button>
            <button class="btn btn-ghost" onclick="GH.emCampaignSave()">Salva</button>
            <button class="btn btn-ghost" onclick="GH.emCampaignSchedule()">Schedula</button>
            <button class="btn btn-warn" onclick="GH.emCampaignSend()">Invia ora</button>
            <button class="btn btn-danger" onclick="GH.emCampaignDelete()">Elimina</button>
        </div>
    </div>
</div>

<!-- ═══ EMAIL — CONTACTS ═══ -->
<div class="panel" id="panel-email-contacts">
    <div class="toolbar">
        <span class="filter-label">Sorgente</span>
        <select class="filter-select" id="em-ct-source" onchange="GH.emContactsLoad()">
            <option value="hustle">Hustle</option>
            <option value="csv">CSV upload</option>
        </select>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.emContactsLoad()"><span class="spin" id="em-ct-spin" style="display:none"></span> Aggiorna</button>
    </div>
    <div class="stats-bar" id="em-ct-stats" style="display:none">
        <div class="stat">Totale: <span class="blue" id="em-ct-total">0</span></div>
        <div class="stat">Hustle: <span class="green" id="em-ct-hustle">0</span></div>
        <div class="stat">CSV: <span class="amber" id="em-ct-csv">0</span></div>
    </div>
    <div class="em-csv-upload" id="em-ct-upload" style="display:none">
        <input type="file" id="em-ct-file" accept=".csv,.txt" onchange="GH.emContactsUploadFile(this)" />
        <span class="em-hint-inline">Colonne richieste: email (obbligatoria), display_name (opzionale)</span>
    </div>
    <div class="em-list" id="em-ct-list">
        <div class="empty-state"><div class="empty-icon">&#9786;</div><div class="empty-text">Seleziona una sorgente</div></div>
    </div>
</div>

<!-- ═══ EMAIL — HISTORY ═══ -->
<div class="panel" id="panel-email-history">
    <div class="toolbar">
        <span class="filter-label">Tipo</span>
        <select class="filter-select" id="em-h-type" onchange="GH.emHistoryLoad()">
            <option value="">Tutti</option>
            <option value="test">Test</option>
            <option value="campaign">Campagna</option>
        </select>
        <span class="filter-label">Stato</span>
        <select class="filter-select" id="em-h-status" onchange="GH.emHistoryLoad()">
            <option value="">Tutti</option>
            <option value="sent">Inviati</option>
            <option value="failed">Falliti</option>
        </select>
        <input class="search-input" id="em-h-search" placeholder="Cerca email, oggetto, campagna..." oninput="GH.emHistoryDebounce()" />
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.emHistoryLoad()"><span class="spin" id="em-h-spin" style="display:none"></span> Aggiorna</button>
        <button class="btn btn-danger" onclick="GH.emHistoryClear()">Svuota log</button>
    </div>
    <div class="stats-bar" id="em-h-stats" style="display:none">
        <div class="stat">Totale: <span class="blue" id="em-h-total">0</span></div>
        <div class="stat">Inviate: <span class="green" id="em-h-sent">0</span></div>
        <div class="stat">Fallite: <span class="red" id="em-h-failed">0</span></div>
    </div>
    <div class="em-list" id="em-h-list">
        <div class="empty-state"><div class="empty-icon">&#9202;</div><div class="empty-text">Carica per visualizzare lo storico</div></div>
    </div>
</div>
