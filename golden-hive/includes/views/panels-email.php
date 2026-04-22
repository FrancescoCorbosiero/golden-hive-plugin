<!-- ═══ EMAIL — BRAND ═══ -->
<div class="panel" id="panel-email-brand">
    <div class="toolbar">
        <span class="filter-label">Brand del sito</span>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" onclick="GH.emBrandSave()"><span class="spin" id="em-brand-save-spin" style="display:none"></span> Salva</button>
        <button class="btn btn-ghost" onclick="GH.emBrandLoad()">Ricarica</button>
        <button class="btn btn-ghost" onclick="GH.emBrandCopyJSON()" title="Copia la config brand come JSON">Copia JSON</button>
        <button class="btn btn-danger" onclick="GH.emBrandReset()" title="Ripristina ai valori di default">Reset defaults</button>
    </div>
    <div class="rpem-brand-form" id="em-brand-form">
        <div class="empty-state"><div class="empty-icon">&#9733;</div><div class="empty-text">Caricamento brand...</div></div>
    </div>
    <div class="em-hint" style="padding:8px 16px">
        Questi valori popolano i placeholder <code>{BRAND_*}</code> in tutti i template email.
        Il brand e globale: un solo set di impostazioni per il sito.
    </div>
</div>

<!-- ═══ EMAIL — TEMPLATES ═══ -->
<div class="panel" id="panel-email-templates">
    <!-- List view -->
    <div id="em-tpl-list-view">
        <div class="toolbar">
            <span class="filter-label">Template HTML</span>
            <div class="filter-sep"></div>
            <button class="btn btn-primary" onclick="GH.emTplNew()">+ Nuovo template</button>
            <button class="btn btn-ghost" onclick="GH.emTplLoad()">Ricarica</button>
        </div>
        <div class="rpem-tpl-list" id="em-tpl-list">
            <div class="empty-state"><div class="empty-icon">&#9881;</div><div class="empty-text">Nessun template. Crea il primo per iniziare.</div></div>
        </div>
    </div>

    <!-- Editor view -->
    <div id="em-tpl-editor-view" class="rpem-tpl-editor" style="display:none">
        <div class="toolbar">
            <button class="btn btn-ghost" onclick="GH.emTplBackToList()">&larr; Lista</button>
            <div class="filter-sep"></div>
            <span class="filter-label" id="em-tpl-editor-title">Nuovo template</span>
            <div style="flex:1"></div>
            <button class="btn btn-ghost" onclick="GH.emTplCopyJSON()" title="Copia il template come JSON (debug / backup)">Copia JSON</button>
            <button class="btn btn-ghost" onclick="GH.emTplDownloadRaw()" title="Scarica HTML grezzo con placeholder">Scarica grezzo</button>
            <button class="btn btn-ghost" onclick="GH.emTplDownloadDemo()" title="Scarica HTML renderizzato con dati demo"><span class="spin" id="em-tpl-demo-spin" style="display:none"></span> Scarica demo</button>
            <button class="btn btn-ghost" id="em-tpl-delete-btn" onclick="GH.emTplDelete()" style="color:var(--red);display:none">Elimina</button>
            <button class="btn btn-primary" onclick="GH.emTplSave()"><span class="spin" id="em-tpl-save-spin" style="display:none"></span> Salva</button>
        </div>
        <div class="rpem-tpl-editor-body">
            <div class="rpem-tpl-editor-left">
                <div class="cfg-row"><span class="cfg-label">Nome</span>
                    <input class="cfg-input" id="em-tpl-name" placeholder="Es: Weekend Coupon 2 prodotti" />
                </div>
                <div class="cfg-row"><span class="cfg-label">Descrizione</span>
                    <input class="cfg-input" id="em-tpl-desc" placeholder="Use case / quando usarlo" />
                </div>
                <div class="cfg-row em-row-stretch" style="flex:1">
                    <span class="cfg-label">HTML</span>
                    <textarea class="cfg-input rpem-code" id="em-tpl-html" spellcheck="false" placeholder="<!DOCTYPE html>&#10;<html>&#10;  <body>&#10;    <h1>Ciao {RECIPIENT_FIRST_NAME}</h1>&#10;    <p>{CAMPAIGN_HERO_SUBTITLE}</p>&#10;  </body>&#10;</html>" oninput="GH.emTplExtractPlaceholders()"></textarea>
                </div>
            </div>
            <aside class="rpem-tpl-ph-panel">
                <div class="rpem-tpl-ph-head">
                    <button class="rpem-tpl-tab is-active" id="em-tpl-tab-ph" onclick="GH.emTplSetAsideMode('ph')">Placeholder</button>
                    <button class="rpem-tpl-tab" id="em-tpl-tab-preview" onclick="GH.emTplSetAsideMode('preview')">Anteprima live</button>
                </div>
                <div class="rpem-tpl-ph-body" id="em-tpl-ph-body">
                    <div class="em-hint">Scrivi HTML con placeholder <code>{BRAND_*}</code>, <code>{CAMPAIGN_*}</code>, <code>{PRODUCT_N_*}</code>, <code>{ORDER_*}</code>, <code>{RECIPIENT_*}</code>, <code>{META_*}</code>.</div>
                </div>
                <iframe id="em-tpl-preview-iframe" class="rpem-tpl-preview-iframe" sandbox="allow-same-origin" title="Anteprima live" style="display:none"></iframe>
            </aside>
        </div>
    </div>
</div>

<!-- ═══ EMAIL — CAMPAIGNS ═══ -->
<div class="panel" id="panel-email-campaigns">
    <!-- List view -->
    <div id="em-camp-list-view">
        <div class="toolbar">
            <span class="filter-label">Campagne</span>
            <div class="filter-sep"></div>
            <button class="btn btn-primary" onclick="GH.emCampaignNew()">+ Nuova campagna</button>
            <button class="btn btn-ghost" onclick="GH.emCampaignsLoad()"><span class="spin" id="em-camp-spin" style="display:none"></span> Ricarica</button>
        </div>
        <div class="rpem-camp-list" id="em-camp-list">
            <div class="empty-state"><div class="empty-icon">&#9758;</div><div class="empty-text">Nessuna campagna. Crea la prima per iniziare.</div></div>
        </div>
    </div>

    <!-- Wizard view -->
    <div id="em-camp-wizard-view" class="rpem-wizard" style="display:none">
        <div class="toolbar">
            <button class="btn btn-ghost" onclick="GH.emCampaignBackToList()">&larr; Lista</button>
            <div class="filter-sep"></div>
            <span class="filter-label" id="em-camp-wizard-title">Nuova campagna</span>
            <div style="flex:1"></div>
            <button class="btn btn-ghost" onclick="GH.emCampaignCopyJSON()" title="Copia la campagna come JSON (debug / backup)">Copia JSON</button>
            <button class="btn btn-ghost" id="em-camp-delete-btn" onclick="GH.emCampaignDelete()" style="color:var(--red);display:none">Elimina</button>
            <button class="btn btn-ghost" onclick="GH.emCampaignValidate()"><span class="spin" id="em-camp-validate-spin" style="display:none"></span> Valida</button>
            <button class="btn btn-primary" onclick="GH.emCampaignSave()"><span class="spin" id="em-camp-save-spin" style="display:none"></span> Salva</button>
        </div>

        <div class="rpem-wizard-body">
            <div class="rpem-wizard-left">
                <!-- Step 1 — template + name -->
                <div class="rpem-step">
                    <div class="rpem-step-head"><span class="rpem-step-num">1</span> Base</div>
                    <div class="cfg-row"><span class="cfg-label">Nome campagna</span>
                        <input class="cfg-input" id="em-camp-name" placeholder="Nome interno" />
                    </div>
                    <div class="cfg-row"><span class="cfg-label">Template</span>
                        <select class="cfg-select" id="em-camp-template" onchange="GH.emCampaignOnTemplateChange()">
                            <option value="">— seleziona —</option>
                        </select>
                    </div>
                </div>

                <!-- Step 2 — subject + preheader -->
                <div class="rpem-step">
                    <div class="rpem-step-head"><span class="rpem-step-num">2</span> Oggetto</div>
                    <div class="cfg-row"><span class="cfg-label">Subject</span>
                        <input class="cfg-input" id="em-camp-subject" placeholder="Oggetto email" maxlength="120" />
                    </div>
                    <div class="cfg-row"><span class="cfg-label">Preheader</span>
                        <input class="cfg-input" id="em-camp-preheader" placeholder="Prima riga di preview nel client" maxlength="200" />
                    </div>
                </div>

                <!-- Step 3 — payload CAMPAIGN_* -->
                <div class="rpem-step">
                    <div class="rpem-step-head"><span class="rpem-step-num">3</span> Payload campagna</div>
                    <div id="em-camp-payload" class="rpem-payload-form">
                        <div class="em-hint">Seleziona un template per vedere i campi da compilare.</div>
                    </div>
                </div>

                <!-- Step 4 — product picker -->
                <div class="rpem-step">
                    <div class="rpem-step-head"><span class="rpem-step-num">4</span> Prodotti
                        <span class="em-hint-inline">ordinati &rarr; PRODUCT_1_*, PRODUCT_2_*, ...</span>
                    </div>
                    <div class="rpem-product-slots" id="em-camp-products">
                        <div class="em-hint">Nessun prodotto selezionato.</div>
                    </div>
                    <div class="rpem-product-search">
                        <input class="cfg-input" id="em-camp-product-query" placeholder="Cerca per nome, SKU o ID" onkeydown="if(event.key==='Enter'){event.preventDefault();GH.emCampaignProductSearch();}" />
                        <button class="btn btn-ghost" onclick="GH.emCampaignProductSearch()">Cerca</button>
                    </div>
                    <div class="rpem-product-results" id="em-camp-product-results"></div>
                </div>

                <!-- Step 5 — sorgente contatti -->
                <div class="rpem-step">
                    <div class="rpem-step-head"><span class="rpem-step-num">5</span> Destinatari</div>
                    <div class="cfg-row">
                        <span class="cfg-label">Sorgente</span>
                        <select class="cfg-select" id="em-camp-source" onchange="GH.emCampaignOnSourceChange()">
                            <option value="hustle">Hustle</option>
                            <option value="csv">CSV raw</option>
                            <option value="mixed">Mixed</option>
                        </select>
                        <span class="cfg-label">Rate</span>
                        <select class="cfg-select" id="em-camp-rate">
                            <option value="200000">Normale (~5/s)</option>
                            <option value="50000">Veloce (~20/s)</option>
                            <option value="1000000">Lento (1/s)</option>
                        </select>
                    </div>
                    <div class="cfg-row em-row-stretch" id="em-camp-csv-row" style="display:none">
                        <span class="cfg-label">CSV</span>
                        <textarea class="cfg-input em-textarea-sm" id="em-camp-csv" placeholder="email,display_name&#10;john@x.com,John"></textarea>
                    </div>
                </div>

                <!-- Step 6 — validate + send -->
                <div class="rpem-step">
                    <div class="rpem-step-head"><span class="rpem-step-num">6</span> Invio</div>
                    <div id="em-camp-validation" class="rpem-validation"></div>
                    <div class="cfg-row">
                        <span class="cfg-label">Schedule</span>
                        <input class="cfg-input" id="em-camp-scheduled" type="datetime-local" />
                        <button class="btn btn-ghost" onclick="GH.emCampaignSchedule()">Schedula</button>
                        <button class="btn btn-warn" onclick="GH.emCampaignSend()"><span class="spin" id="em-camp-send-spin" style="display:none"></span> Invia ora</button>
                    </div>
                    <div class="cfg-row">
                        <span class="cfg-label">Test a</span>
                        <input class="cfg-input" id="em-camp-test-to" type="email" placeholder="me@example.com" />
                        <button class="btn btn-ghost" onclick="GH.emCampaignSendTest()">Invia test</button>
                    </div>
                </div>
            </div>

            <!-- Preview iframe -->
            <aside class="rpem-wizard-preview">
                <div class="rpem-preview-head">
                    <span>Anteprima</span>
                    <span id="em-camp-preview-subject" class="rpem-preview-subject"></span>
                    <div style="flex:1"></div>
                    <button class="btn btn-ghost" onclick="GH.emCampaignSendPreviewAsTest()" title="Porta l'HTML renderizzato nella tab Test Email per inviartelo">&#9993; Test da qui</button>
                    <button class="btn btn-ghost" onclick="GH.emCampaignPreview()"><span class="spin" id="em-camp-preview-spin" style="display:none"></span> Refresh</button>
                </div>
                <iframe id="em-camp-preview-frame" class="rpem-preview-frame" sandbox="allow-same-origin" title="Preview campagna"></iframe>
            </aside>
        </div>
    </div>
</div>

<!-- ═══ EMAIL — TRANSAZIONALI ═══ -->
<div class="panel" id="panel-email-transactional">
    <div class="toolbar">
        <span class="filter-label">Email transazionali</span>
        <div class="filter-sep"></div>
        <button class="btn btn-ghost" onclick="GH.emTrxLoad()"><span class="spin" id="em-trx-spin" style="display:none"></span> Ricarica</button>
    </div>
    <div class="em-hint" style="padding:8px 16px">
        Le email transazionali scattano in risposta a eventi ordine WooCommerce.
        Per ciascun evento abilitato, il template selezionato viene renderizzato
        con i placeholder <code>{ORDER_*}</code> risolti dall'ordine reale e
        inviato all'email del cliente. Non richiedono contatti: 1 evento = 1 email.
    </div>
    <div class="em-hint" style="padding:4px 16px 16px 16px;background:#fff4e5;border-left:3px solid #c68500;color:#6b4a00;margin:8px 16px;">
        <strong>Come funziona l'evento <code>order_shipped</code>:</strong>
        apri un ordine in WooCommerce &rarr; nella sidebar trovi il box
        <em>Spedizione &amp; Notifica</em>. Compila corriere, codice e URL di
        tracking, poi clicca <em>Salva &amp; invia notifica</em>.
    </div>
    <div class="rpem-trx-list" id="em-trx-list">
        <div class="empty-state"><div class="empty-icon">&#9993;</div><div class="empty-text">Caricamento...</div></div>
    </div>

    <!-- Test fire — invia un evento transazionale su un ordine reale per QA -->
    <div class="toolbar" style="margin-top:24px">
        <span class="filter-label">Test transazionale</span>
        <div class="filter-sep"></div>
    </div>
    <div class="em-form">
        <div class="cfg-row">
            <span class="cfg-label">Evento</span>
            <select class="cfg-select" id="em-trx-test-event">
                <option value="">&mdash; seleziona &mdash;</option>
            </select>
            <span class="cfg-label">Order ID</span>
            <input class="cfg-input" type="number" id="em-trx-test-order" placeholder="Es: 12345" min="1" />
            <button class="btn btn-warn" onclick="GH.emTrxTestFire()"><span class="spin" id="em-trx-test-spin" style="display:none"></span> Rendi &amp; invia</button>
        </div>
        <div class="em-hint">
            Renderizza l'evento selezionato per l'ordine reale indicato e invia
            l'email alla stessa email del cliente dell'ordine. Usa per QA prima
            di attivare un binding in produzione.
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
        <button class="btn btn-ghost" onclick="GH.emContactsLoad()"><span class="spin" id="em-ct-spin" style="display:none"></span> Ricarica</button>
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

<!-- ═══ EMAIL — TEST (mailer smoke + seeder) ═══ -->
<div class="panel" id="panel-email-test">
    <div class="toolbar">
        <span class="filter-label">Test mailer</span>
        <div class="filter-sep"></div>
        <button class="btn btn-primary" id="em-test-btn" onclick="GH.emSendTest()"><span class="spin" id="em-test-spin" style="display:none"></span> Invia test</button>
    </div>
    <div class="em-form">
        <div class="cfg-row">
            <span class="cfg-label">Template</span>
            <select class="cfg-select" id="em-test-template" onchange="GH.emTestOnTemplateChange()">
                <option value="">&mdash; HTML libero &mdash;</option>
            </select>
            <button class="btn btn-ghost" onclick="GH.emTestLoadTemplate()" id="em-test-load-btn" disabled><span class="spin" id="em-test-load-spin" style="display:none"></span> Carica con dati demo</button>
        </div>
        <div class="cfg-row"><span class="cfg-label">A</span><input class="cfg-input" id="em-test-to" type="email" placeholder="destinatario@example.com" /></div>
        <div class="cfg-row"><span class="cfg-label">Oggetto</span><input class="cfg-input" id="em-test-subject" placeholder="(opzionale)" /></div>
        <div class="cfg-row em-row-stretch"><span class="cfg-label">HTML</span><textarea class="cfg-input em-textarea" id="em-test-body" placeholder="(opzionale: usa template di default)"></textarea></div>
        <div class="em-hint">
            Invio diretto via <strong>wp_mail()</strong> &rarr; WP Mail SMTP &rarr; AWS SES.
            Se selezioni un template, <em>Carica con dati demo</em> renderizza
            BRAND + META + valori euristici per CAMPAIGN/PRODUCT/ORDER (ultimo
            ordine reale) e popola il body qui sotto. Da li puoi modificare e inviare.
        </div>
        <div id="em-test-unresolved" class="em-hint" style="display:none"></div>
    </div>

    <div class="toolbar" style="margin-top:24px">
        <span class="filter-label">Seed demo data</span>
        <div class="filter-sep"></div>
        <label class="em-hint-inline"><input type="checkbox" id="em-seed-reset-brand" /> Reset anche il brand ai defaults</label>
        <button class="btn btn-ghost" onclick="GH.emSeedDemo()"><span class="spin" id="em-seed-spin" style="display:none"></span> Popola demo</button>
    </div>
    <div id="em-seed-result" class="rpem-seed-result"></div>
    <div class="em-hint">
        Crea (o aggiorna) un template &laquo;Demo Weekend Coupon&raquo; + una campagna &laquo;Weekend Coupon Demo&raquo; con 2 prodotti WooCommerce reali.
        Idempotente.
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
        <button class="btn btn-ghost" onclick="GH.emHistoryLoad()"><span class="spin" id="em-h-spin" style="display:none"></span> Ricarica</button>
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
