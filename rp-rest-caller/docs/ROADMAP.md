# ROADMAP — RP REST Caller / Feed Importer

## Stato Attuale: v0.1 (da costruire)

Plugin non ancora iniziato. Visione in due fasi: client HTTP manuale → feed importer automatico.

---

## 🔴 P0 — MVP: Client HTTP Visuale

### HTTP Client Core
- [ ] `rp_rc_request()` — wrapper su `wp_remote_request()` con auth helper
- [ ] Supporto metodi: GET, POST, PUT, PATCH, DELETE
- [ ] Auth types: None, Basic, Bearer token, API Key header
- [ ] `rp_rc_detect_content_type()` — auto-detect formato response

### Response Parser
- [ ] `rp_rc_parse_response()` — JSON, XML, CSV, testo
- [ ] `rp_rc_flatten_response()` — estrazione lista da response annidata con dot notation
- [ ] Cast XML → array ricorsivo
- [ ] Auto-detect separator CSV

### Endpoint Salvati
- [ ] `rp_rc_get/save/delete/get_saved_endpoints()`
- [ ] Persistenza in `wp_options` come JSON
- [ ] Redazione secrets prima di inviare al client

### AJAX Layer
- [ ] `rp_rc_ajax_execute` — esegue request
- [ ] `rp_rc_ajax_get/save/delete_endpoint` — gestione endpoint salvati

### Admin UI — MVP
- [ ] Menu WP Admin "RP REST Caller"
- [ ] **Tab Request:** URL input, method selector, headers editor (key-value pairs), body textarea, auth configurator, bottone "Esegui"
- [ ] **Tab Response:** status badge colorato, duration pill, headers collassabili, body raw/formatted con syntax highlight, tab "Items" se response è array
- [ ] **Tab Endpoints:** lista endpoint salvati con last_used, click per ricaricare config
- [ ] Toast notifications
- [ ] Dark theme, coerente con gli altri plugin RP

---

## 🟡 P1 — Import da Response

### Import Mapper
- [ ] `rp_rc_extract_fields()` — applica field map con dot notation
- [ ] `rp_rc_validate_mapped_item()` — valida campi obbligatori
- [ ] `rp_rc_import_item()` — singolo import (upsert per SKU)
- [ ] `rp_rc_bulk_import()` — import N items con report

### AJAX Import
- [ ] `rp_rc_ajax_import_items` — bulk import con field_map

### Admin UI — Import
- [ ] **Tab Import** (visibile solo se rp-product-manager attivo):
  - Selezione items da importare (checkbox)
  - Field mapper: a sinistra campi source, a destra select con campi WooCommerce
  - Preview mapping (mostra primi 3 items come verranno importati)
  - Bottone "Importa selezionati" con doppio confirm
  - Report risultati: importati / aggiornati / errori / skippati

### Root Key Selector
- [ ] UI per specificare la chiave radice della response (es. `products`, `data.items`)
- [ ] Auto-suggest: rileva automaticamente chiavi che contengono array

---

## 🟡 P1 — Feature Client Avanzate

### History delle chiamate
- [ ] Ultime 20 chiamate salvate automaticamente (transient o wp_options)
- [ ] UI per rieseguire una chiamata storica o salvarla come endpoint

### Environment Variables
- [ ] Variabili globali (es. `{{BASE_URL}}`, `{{API_KEY}}`) sostituibili nelle request
- [ ] Gestite in un tab "Variabili" — valore visibile solo all'admin

### Response diff
- [ ] Esegui la stessa request due volte e mostra le differenze nella response (utile per monitorare cambi nei feed)

---

## 🟢 P2 — Feed Importer Automatico

Qui il plugin diventa "Feed Importer" a tutti gli effetti.

- [ ] **Feed schedulati** — esecuzione automatica con WP Cron (oraria / giornaliera / settimanale)
- [ ] **Mapping persistente** — salva field map insieme all'endpoint
- [ ] **Import automatico** — dopo ogni chiamata schedulata, importa automaticamente i risultati
- [ ] **Notifiche** — email all'admin con report import (N creati, N aggiornati, N errori)
- [ ] **Conflict resolution** — se un prodotto esiste con SKU diverso ma stesso nome: crea nuovo / skip / aggiorna?
- [ ] **Price sync mode** — modalità speciale: aggiorna solo `regular_price` e `sale_price` su prodotti esistenti, non tocca altro
- [ ] **Stock sync mode** — aggiorna solo `stock_quantity` delle varianti
- [ ] **Dashboard feed** — panoramica di tutti i feed attivi: ultimo run, stato, errori
- [ ] **WP-CLI** — `wp rp-feed run <endpoint-id>`, `wp rp-feed list`, `wp rp-feed status`

---

## Decisioni da Prendere prima di Iniziare

| Domanda | Opzioni | Raccomandazione |
|---|---|---|
| Secrets storage | Plaintext in wp_options / base64 / cifrato con AUTH_KEY | base64 + AUTH_KEY per MVP |
| History calls | transient (auto-expire) / wp_options / custom table | transient per semplicità |
| XML support depth | Basic / Full (con attributi, namespaces) | Basic per MVP |
| Rate limiting | Nessuno / throttle lato client | Nessuno per MVP (manuale) |
| Max response size | Nessuno / 10MB / configurabile | 10MB hard limit in http-client |
