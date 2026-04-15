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
