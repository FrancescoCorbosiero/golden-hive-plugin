# CLAUDE.md — RP REST Caller (Feed Importer)

> Stai lavorando su **rp-rest-caller**. La root del tuo lavoro è `/rp-rest-caller/`.
> Non toccare le altre cartelle del monorepo salvo indicazione esplicita.
>
> Ordine di lettura obbligatorio:
> 1. Questo file (CLAUDE.md)
> 2. `../CONVENTIONS.md` — convenzioni condivise tra tutti i plugin
> 3. `docs/ARCHITECTURE.md`
> 4. `docs/ROADMAP.md`

---

## Contesto del Plugin

**RP REST Caller** è un plugin WordPress standalone per ResellPiacenza.

**Problema che risolve:** ResellPiacenza ha bisogno di interrogare endpoint HTTP esterni — feed di fornitori sneaker, API prezzi di mercato (StockX, GOAT, ecc.), feed CSV/JSON con disponibilità stock — e importare i risultati come prodotti o aggiornamenti di prezzi WooCommerce.

**In questa versione (v1):** il plugin è un **client HTTP visuale** integrato in WP Admin. L'utente configura una request (URL, metodo, headers, body), la esegue, vede la risposta formattata, e può ispezionare/mappare i campi manualmente.

**Visione futura (v2+):** il layer di UI manuale diventa la base per automazioni — feed salvati, esecuzione schedulata, mapping automatico campi→prodotti WooCommerce, import one-click.

**Nome evolutivo:** il plugin nasce come "REST Caller" ma diventerà "Feed Importer" con l'arrivo delle feature di import automatico.

---

## Stack Tecnico

| Layer | Tecnologia |
|---|---|
| CMS | WordPress 6.x |
| HTTP Client | `wp_remote_request()` — wrapper WP su cURL, rispetta proxy e SSL del server |
| Response parsing | PHP nativo: `json_decode`, `simplexml_load_string`, `str_getcsv` |
| E-commerce | WooCommerce (per import prodotti) |
| PHP | 8.0+ |
| Admin UI | Vanilla JS + CSS custom — stesso stile di rp-product-manager |
| Font stack UI | JetBrains Mono + DM Sans (Google Fonts) |

---

## Struttura del Plugin

```
rp-rest-caller/
├── rp-rest-caller.php           ← Entry point. Solo require_once.
└── includes/
    ├── http-client.php          ← Esegue le chiamate HTTP. Nessun hook.
    ├── response-parser.php      ← Parsing JSON / XML / CSV. Nessun side effect.
    ├── saved-endpoints.php      ← CRUD endpoint salvati (wp_options).
    ├── import-mapper.php        ← Mappa campi response → prodotto WooCommerce.
    ├── ajax.php                 ← Tutti i wp_ajax_rp_rc_* handler.
    └── admin-page.php           ← UI admin (menu + render).
```

### Regola fondamentale dei layer

```
http-client.php      →  "Chiama" (fa la request, ritorna response grezza)
response-parser.php  →  "Interpreta" (trasforma response in struttura navigabile)
saved-endpoints.php  →  "Ricorda" (persistenza configurazioni endpoint)
import-mapper.php    →  "Importa" (usa rp_* da rp-product-manager se disponibile)
ajax.php             →  "Bridge"
admin-page.php       →  "Mostra"
```

**Dipendenza opzionale:** se `rp-product-manager` è attivo, `import-mapper.php` usa le sue funzioni `rp_create_product()` e `rp_update_product()`. Se non è attivo, l'import è disabilitato con un messaggio in UI.

---

## Funzioni PHP Disponibili

### `http-client.php`

```php
rp_rc_request(array $config): array
// Esegue una chiamata HTTP tramite wp_remote_request().
// $config: {
//   url:     string   (obbligatorio)
//   method:  string   GET | POST | PUT | PATCH | DELETE (default: GET)
//   headers: array    ['Authorization' => 'Bearer xxx', ...]
//   body:    string   body raw (JSON string, form data, ecc.)
//   timeout: int      secondi (default: 30)
//   auth:    array    ['type' => 'basic', 'user' => '...', 'pass' => '...']
//                     oppure ['type' => 'bearer', 'token' => '...']
// }
// Ritorna: {
//   status:        int     (es. 200, 404, 500)
//   status_text:   string  (es. "OK", "Not Found")
//   headers:       array   response headers
//   body:          string  response body raw
//   parsed:        mixed   body già parsato (JSON→array, XML→array, CSV→array)
//   content_type:  string  (es. "application/json")
//   duration_ms:   int     tempo impiegato
//   error:         string|null  se WP_Error
// }

rp_rc_detect_content_type(string $content_type_header, string $body): string
// Auto-detect del formato: 'json' | 'xml' | 'csv' | 'html' | 'text'
// Prima guarda il header Content-Type, poi tenta parsing empirico del body.
```

### `response-parser.php`

```php
rp_rc_parse_response(string $body, string $format): array|WP_Error
// Parsa il body in base al formato.
// format: 'json' | 'xml' | 'csv' | 'auto'
// 'auto' chiama rp_rc_detect_content_type() prima.
// Per JSON: json_decode($body, true)
// Per XML:  simplexml_load_string() → cast ricorsivo ad array
// Per CSV:  str_getcsv() con auto-detect separator (,  ;  \t)
// Ritorna WP_Error se il parsing fallisce.

rp_rc_flatten_response(array $data, string $root_key = ''): array
// Appiattisce una response annidata in una lista di oggetti.
// Utile per feed che wrappano i risultati in una chiave (es. {"products": [...]}).
// $root_key: chiave da cui estrarre la lista (es. "products", "data.items")
//            Supporta dot notation per accesso annidato.
// Se $root_key è vuoto, tenta auto-detection della prima chiave array.

rp_rc_extract_fields(array $items, array $field_map): array
// Applica un mapping campi a ogni item della lista.
// $field_map: ['woo_field' => 'source_field', ...]
// Supporta dot notation per source: 'price.retail' → $item['price']['retail']
// Ritorna array di oggetti mappati, pronti per rp_create/update_product().
```

### `saved-endpoints.php`

```php
rp_rc_get_saved_endpoints(): array
// Ritorna tutti gli endpoint salvati da wp_options.
// Ogni endpoint: [id, name, url, method, headers, body, auth, last_used, created_at]

rp_rc_save_endpoint(array $config): string
// Salva un endpoint. Ritorna l'ID generato (uniqid).
// Se $config['id'] è fornito, aggiorna quello esistente.

rp_rc_delete_endpoint(string $id): bool

rp_rc_get_endpoint(string $id): array|null

rp_rc_update_last_used(string $id): void
// Aggiorna il timestamp last_used. Chiamata dopo ogni esecuzione.
```

### `import-mapper.php`

```php
rp_rc_import_item(array $mapped_item, string $mode = 'create'): int|WP_Error
// Importa un singolo item come prodotto WooCommerce.
// $mapped_item: array con chiavi compatibili con rp_create_product() / rp_update_product()
// $mode: 'create' | 'update' | 'upsert' (crea se non esiste, aggiorna se esiste per SKU)
// Richiede rp-product-manager attivo. Ritorna WP_Error('dependency_missing') se assente.

rp_rc_bulk_import(array $items, string $mode = 'upsert'): array
// Importa N items. Non si ferma agli errori.
// Ritorna: { imported: [ids], updated: [ids], errors: {index: 'reason'}, skipped: [ids] }

rp_rc_validate_mapped_item(array $item): true|WP_Error
// Valida che un item mappato abbia i campi obbligatori (name, regular_price).
// Usato prima dell'import per preview errori nella UI.
```

---

## AJAX Endpoints

Tutti richiedono nonce `rp_rc_nonce` + capability `manage_woocommerce`.

| Action | Parametri POST | Risposta |
|---|---|---|
| `rp_rc_ajax_execute` | `config` (JSON) | `{status, headers, body, parsed, duration_ms}` |
| `rp_rc_ajax_get_endpoints` | — | array endpoint salvati |
| `rp_rc_ajax_save_endpoint` | `config` (JSON) | `{id, endpoint}` |
| `rp_rc_ajax_delete_endpoint` | `endpoint_id` | `{success}` |
| `rp_rc_ajax_import_items` | `items` (JSON), `mode`, `field_map` (JSON) | `{imported, updated, errors, skipped}` |

---

## Convenzioni di Codice

### PHP
- Prefix **`rp_rc_`** su tutte le funzioni pubbliche.
- Usare sempre `wp_remote_request()`, **mai** `curl_exec()` diretto — rispetta le impostazioni WordPress (proxy, SSL verify, timeout globale).
- Secrets (API key, password) non vengono mai loggati né inclusi in risposte AJAX. Usare `rp_rc_redact_sensitive_headers()` prima di inviare headers al client.
- Gli endpoint salvati in `wp_options` non salvano mai plaintext delle credenziali — usare `base64_encode()` + una chiave derivata da `AUTH_KEY` del wp-config per offrire minima protezione at rest (non è vera cifratura, ma evita esposizione accidentale nel DB).
- Timeout default: 30 secondi. Configurabile per endpoint ma mai oltre 120s.

### UI Behaviour
- L'editor request ha syntax highlighting per il body JSON (textarea con monospace, nessun editor complesso).
- La response viene mostrata formattata (JSON indentato, XML indentato) con syntax highlighting.
- Gli headers di request e response sono mostrati in una sezione collassabile.
- Il tab "Endpoints Salvati" mostra lista con last_used, permettendo di ricaricare una config in un click.
- Il mapper campi (per import) è una UI drag-and-drop mentale: a sinistra i campi del response, a destra i campi WooCommerce, con select per collegare. Da costruire quando si arriva all'import UI.

---

## Gestione Secrets

```php
// Redazione prima di inviare al client
function rp_rc_redact_sensitive_headers(array $headers): array {
    $sensitive = ['authorization', 'x-api-key', 'x-auth-token', 'api-key'];
    foreach ($headers as $key => $value) {
        if (in_array(strtolower($key), $sensitive)) {
            $headers[$key] = '••••••••' . substr($value, -4);
        }
    }
    return $headers;
}
```

---

## File di Riferimento

| File | Contenuto |
|---|---|
| `docs/ARCHITECTURE.md` | Flusso request, stato UI, field mapper |
| `docs/ROADMAP.md` | Feature built + backlog |
