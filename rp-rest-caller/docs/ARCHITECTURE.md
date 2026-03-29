# ARCHITECTURE.md — RP REST Caller

## Flusso Principale: Esecuzione Request

```
┌─────────────────────────────────────────────────────────┐
│                     Admin UI                            │
│  URL + Method + Headers + Body → "Esegui" button        │
└────────────────────────┬────────────────────────────────┘
                         │ AJAX: rp_rc_ajax_execute
                         │ { config: { url, method, headers, body, auth } }
                         ▼
┌─────────────────────────────────────────────────────────┐
│                    ajax.php                             │
│  check_ajax_referer + manage_woocommerce                │
│  json_decode($config) → rp_rc_request($config)          │
│  rp_rc_redact_sensitive_headers() prima di rispondere   │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│                   http-client.php                       │
│                                                         │
│  rp_rc_request($config)                                 │
│    ├─ Costruisce args per wp_remote_request()           │
│    │   ├─ method, timeout                               │
│    │   ├─ headers (+ auth header se auth.type presente) │
│    │   └─ body (stringa raw o json_encode se array)     │
│    ├─ wp_remote_request($url, $args)                    │
│    ├─ SE WP_Error: ritorna {error: message}             │
│    └─ SE OK:                                            │
│        ├─ wp_remote_retrieve_response_code()            │
│        ├─ wp_remote_retrieve_headers()                  │
│        ├─ wp_remote_retrieve_body()                     │
│        └─ rp_rc_parse_response(body, auto-detect)       │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│  Response al client:                                    │
│  { status, status_text, headers (redacted),             │
│    body (raw), parsed (strutturato), duration_ms }      │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│  UI: mostra response                                    │
│    ├─ Status badge (verde 2xx, giallo 3xx, rosso 4xx/5xx)│
│    ├─ Duration pill (es. "342ms")                       │
│    ├─ Headers (collassabile)                            │
│    ├─ Body: tab Raw / tab Formatted (pretty print)      │
│    └─ Se array di oggetti: tab "Items" con tabella      │
│        └─ Se rp-product-manager attivo: tab "Import"    │
└─────────────────────────────────────────────────────────┘
```

---

## Flusso: Import da Response

```
Utente vede tabella "Items" dalla response
    │
    ├─ Seleziona quali items importare (checkbox)
    ├─ Configura field map (UI mapper: source_field → woo_field)
    └─ Clicca "Importa selezionati"
                    │
            AJAX: rp_rc_ajax_import_items
            { items: [...], mode: 'upsert', field_map: {...} }
                    │
            import-mapper.php
                    │
            rp_rc_extract_fields($items, $field_map)
            → array di $mapped_items
                    │
            rp_rc_bulk_import($mapped_items, $mode)
                    │
            Per ogni item:
              rp_rc_validate_mapped_item($item)    ← verifica name + regular_price
              SE mode='upsert': cerca per SKU con wc_get_product_id_by_sku()
                └─ trovato → rp_update_product()  (da rp-product-manager)
                └─ non trovato → rp_create_product()
                    │
            Response: { imported: [ids], updated: [ids], errors: {...}, skipped: [...] }
                    │
            UI: toast riepilogo + aggiorna tabella items con stato (✓ / ✗ / skip)
```

---

## Struttura Dati

### Endpoint salvato (wp_options: rp_rc_endpoints)
```json
{
  "id": "6a3f8b",
  "name": "StockX API - Prezzi Nike",
  "url": "https://api.stockx.com/v2/products?brand=nike",
  "method": "GET",
  "headers": {
    "x-api-key": "••••••••xxxx"
  },
  "body": "",
  "auth": {
    "type": "bearer",
    "token_encrypted": "dGVzdA=="
  },
  "last_used": "2025-03-10 14:22:00",
  "created_at": "2025-01-05 09:00:00",
  "notes": "Aggiorna i prezzi di mercato Nike settimanalmente"
}
```

### Field map (passato dall'UI per l'import)
```json
{
  "name": "product_name",
  "sku": "style_code",
  "regular_price": "market_price.retail",
  "meta_title": "seo.title",
  "focus_keyword": "search_query"
}
```

Dot notation per campi annidati: `"market_price.retail"` → `$item['market_price']['retail']`

---

## Stato UI

```javascript
let state = {
    // Request corrente
    request: {
        url:     '',
        method:  'GET',
        headers: {},    // { key: value }
        body:    '',
        auth:    { type: 'none' }
    },

    // Response corrente
    response: null,    // null | { status, headers, body, parsed, duration_ms, error }

    // Items estratti dalla response (per import)
    items:       [],        // array flat di oggetti
    rootKey:     '',        // chiave usata per flatten (es. "products")
    fieldMap:    {},        // { woo_field: source_field }
    selectedItems: new Set(),

    // Endpoints salvati
    savedEndpoints: [],

    // UI state
    loading:    false,
    activeTab:  'request',  // 'request' | 'response' | 'endpoints' | 'import'
    responseView: 'formatted', // 'raw' | 'formatted' | 'items'
};
```

---

## Parsing XML → Array

`simplexml_load_string()` ritorna un oggetto SimpleXML che non si comporta come un array normale. Serve un cast ricorsivo:

```php
function rp_rc_xml_to_array(\SimpleXMLElement $xml): array {
    $result = [];
    foreach ($xml->children() as $key => $child) {
        $value = count($child->children()) > 0
            ? rp_rc_xml_to_array($child)
            : (string) $child;
        if (isset($result[$key])) {
            // Gestisce elementi multipli con stesso tag → array
            if (!is_array($result[$key]) || !isset($result[$key][0])) {
                $result[$key] = [$result[$key]];
            }
            $result[$key][] = $value;
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}
```

---

## Auto-detect Separator CSV

```php
function rp_rc_detect_csv_separator(string $sample): string {
    $counts = [
        ','  => substr_count($sample, ','),
        ';'  => substr_count($sample, ';'),
        "\t" => substr_count($sample, "\t"),
        '|'  => substr_count($sample, '|'),
    ];
    arsort($counts);
    return array_key_first($counts);
}
// Usa le prime 3 righe come sample.
```

---

## Dipendenza da rp-product-manager

`import-mapper.php` controlla la disponibilità prima di usare le funzioni:

```php
function rp_rc_check_product_manager(): bool {
    return function_exists('rp_create_product') && function_exists('rp_update_product');
}
```

Se il check fallisce, il tab "Import" in UI mostra:
> "Per usare la funzione import, attiva il plugin RP Product Manager."

Non viene lanciato nessun errore fatale — il REST Caller funziona autonomamente come client HTTP puro.
