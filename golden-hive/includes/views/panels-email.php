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

            <!-- Placeholder Picker (collapsible) -->
            <div class="em-tpl-box em-tpl-ph-box" id="em-tpl-ph-box">
                <button type="button" class="em-tpl-box-head" onclick="GH.emTplTogglePlaceholders()" aria-expanded="false">
                    <span class="em-tpl-caret" id="em-tpl-ph-caret">&#9656;</span>
                    <span class="em-tpl-box-title">Placeholder disponibili</span>
                    <span class="em-tpl-box-hint">clicca un tag per inserirlo nel body</span>
                </button>
                <div id="em-tpl-placeholders" class="em-tpl-ph-body" style="display:none"></div>
            </div>

            <!-- Send Section -->
            <div class="em-tpl-box em-tpl-send-box">
                <div class="em-tpl-box-title-strong">Invia con dati reali</div>

                <!-- Step 1: Context -->
                <div class="em-tpl-step">
                    <div class="em-tpl-step-label">
                        <span class="em-tpl-step-num">1</span>
                        Dati contesto
                        <span class="em-tpl-step-hint">popola i placeholder (es. <code>{order_id}</code>, <code>{first_name}</code>)</span>
                    </div>
                    <div id="em-tpl-ctx-chips" class="em-tpl-chips"></div>
                    <div class="em-tpl-ctx-pickers">
                        <div class="em-tpl-picker">
                            <span class="em-tpl-picker-label">Ordine</span>
                            <input class="cfg-input" id="em-tpl-ctx-order" type="text" placeholder="ID o email" onkeydown="if(event.key==='Enter'){event.preventDefault();GH.emTplSearchOrder();}" />
                            <button class="btn btn-ghost em-tpl-picker-btn" onclick="GH.emTplSearchOrder()">Cerca</button>
                        </div>
                        <div class="em-tpl-picker">
                            <span class="em-tpl-picker-label">Cliente</span>
                            <input class="cfg-input" id="em-tpl-ctx-customer" type="text" placeholder="ID o email" onkeydown="if(event.key==='Enter'){event.preventDefault();GH.emTplSearchCustomer();}" />
                            <button class="btn btn-ghost em-tpl-picker-btn" onclick="GH.emTplSearchCustomer()">Cerca</button>
                        </div>
                    </div>
                    <div id="em-tpl-search-results" class="em-tpl-search-results"></div>
                </div>

                <!-- Step 2: Recipient -->
                <div class="em-tpl-step">
                    <div class="em-tpl-step-label">
                        <span class="em-tpl-step-num">2</span>
                        Destinatario
                        <span class="em-tpl-step-hint">dove arriverà realmente l'email</span>
                    </div>
                    <div class="em-tpl-rmode" id="em-tpl-rmode-custom">
                        <label class="em-tpl-rmode-head">
                            <input type="radio" name="em-tpl-rmode" value="custom" checked onchange="GH.emTplSetRecipientMode('custom')" />
                            <span class="em-tpl-rmode-title">Email di test</span>
                            <span class="em-tpl-rmode-desc">invia a un indirizzo qualsiasi (i dati di contesto popolano comunque i placeholder)</span>
                        </label>
                        <input class="cfg-input em-tpl-rmode-input" id="em-tpl-send-to" type="email" placeholder="me@example.com" />
                    </div>
                    <div class="em-tpl-rmode em-tpl-rmode-disabled" id="em-tpl-rmode-customer">
                        <label class="em-tpl-rmode-head">
                            <input type="radio" name="em-tpl-rmode" value="customer" onchange="GH.emTplSetRecipientMode('customer')" disabled />
                            <span class="em-tpl-rmode-title">Cliente reale</span>
                            <span class="em-tpl-rmode-desc">invia direttamente al cliente associato a ordine/cliente selezionato</span>
                        </label>
                        <div class="em-tpl-rmode-resolved" id="em-tpl-rmode-resolved">seleziona prima un ordine o un cliente al punto 1</div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="em-tpl-actions">
                    <button class="btn btn-warn em-tpl-send-btn" onclick="GH.emTplSend()">
                        <span class="spin" id="em-tpl-send-spin" style="display:none"></span>
                        <span id="em-tpl-send-label">Invia email</span>
                    </button>
                    <button class="btn btn-ghost" onclick="GH.emTplUseInCampaign()" title="Usa questo template come corpo campagna">Usa in campagna</button>
                </div>
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
