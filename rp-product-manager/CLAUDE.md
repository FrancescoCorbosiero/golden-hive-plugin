# CLAUDE.md — ResellPiacenza Project

> Stai lavorando su **rp-product-manager**. La root del tuo lavoro è `/rp-product-manager/`.
> Non toccare le altre cartelle del monorepo salvo indicazione esplicita.
>
> **NOTA:** I moduli core di questo plugin (`crud.php`, `variations.php`) sono stati
> mergiati in **golden-hive** (`/golden-hive/includes/product/`). Le due copie sono
> tenute in sync con guard `function_exists()` per la co-esistenza.
> Per nuove funzionalità product-related, valutare se sviluppare direttamente in golden-hive.
>
> Ordine di lettura obbligatorio:
> 1. Questo file (CLAUDE.md)
> 2. `../CONVENTIONS.md` — convenzioni condivise tra tutti i plugin
> 3. `docs/ARCHITECTURE.md`
> 4. `docs/ROADMAP.md`

---

## Contesto del Progetto

**ResellPiacenza** è un e-commerce WooCommerce italiano specializzato in sneakers resell (Nike, Jordan, Adidas, Corteiz, ecc.).

Il progetto ha due anime:
1. **Plugin WordPress** (`rp-product-manager`) — CRUD layer + Admin UI per gestire i prodotti WooCommerce programmaticamente, senza passare dall'interfaccia nativa di WP che è lenta per operazioni intensive.
2. **SEO layer** — schema markup avanzato, Rank Math PRO integration, contenuto ottimizzato per Traditional Search + Answer Engines + LLMs.

Il developer (utente) gestisce il negozio di un cliente (il "titolare") e ha bisogno di strumenti veloci, flessibili, usabili anche da mobile durante chiamate.

---

## Stack Tecnico

| Layer | Tecnologia |
|---|---|
| CMS | WordPress 6.x |
| E-commerce | WooCommerce 8.x |
| SEO | Rank Math PRO |
| PHP | 8.0+ (usa named args, union types, null safe operator) |
| Admin UI | Vanilla JS + CSS custom (no framework) |
| Font stack UI | JetBrains Mono + DM Sans (Google Fonts) |
| Hosting | VPS (vedi `docs/VPS_NOTES.md`) |

---

## Struttura del Plugin

```
rp-product-manager/
├── rp-product-manager.php   ← Entry point. Solo require_once dei moduli.
└── includes/
    ├── crud.php             ← Funzioni rp_*. Nessun hook qui, solo logica pura.
    ├── variations.php       ← Search + varianti. Stesso principio.
    ├── ajax.php             ← TUTTI i wp_ajax_rp_* handler. Solo glue code.
    └── admin-page.php       ← add_menu_page() + render HTML/CSS/JS.
```

### Regola fondamentale dei layer

```
crud.php / variations.php   →  "What" (logica, nessun side effect HTTP)
ajax.php                    →  "Bridge" (sanitize → chiama funzione → json response)
admin-page.php              →  "How it looks" (UI pura, zero logica business)
```

**Non mescolare mai i layer.** Se aggiungi una funzione di business logic, va in `crud.php` o `variations.php`. Se aggiungi un endpoint, va in `ajax.php`. L'UI non sa come funziona WooCommerce.

---

## Funzioni PHP Disponibili

### `crud.php`

```php
rp_get_product(int $product_id): array
// Ritorna tutti i dati di un prodotto inclusi meta Rank Math.
// Se non trovato: ['error' => '...']

rp_create_product(array $data): int|WP_Error
// Campi obbligatori: name, regular_price
// Opzionali: sku, sale_price, description, short_description,
//            status, weight, slug, manage_stock, stock_quantity,
//            stock_status, category_ids[], tag_ids[],
//            meta_title, meta_description, focus_keyword

rp_update_product(int $product_id, array $data): true|WP_Error
// Aggiorna SOLO i campi presenti in $data (usa array_key_exists, non isset).
// Questo permette di passare stringa vuota per cancellare un campo.
// Stessi campi di create, tutti opzionali.

rp_delete_product(int $product_id, bool $force_delete = false): true|WP_Error
// force=false → cestino WP (recuperabile)
// force=true  → eliminazione definitiva dal DB
```

### `variations.php`

```php
rp_search_products(string $query, int $limit = 8): array
// Auto-detection: numerico→ID, poi SKU esatto, poi fulltext titolo, poi SKU LIKE.
// Ogni risultato: [id, name, sku, type, price, status]

rp_get_product_variations(int $product_id): array
// Richiede prodotto di tipo 'variable'. Ordinate per taglia (numerica se possibile).
// Ogni variante: [variation_id, size, sku, regular_price, sale_price,
//                 price, manage_stock, stock_quantity, stock_status, status, attributes]

rp_update_variation(int $variation_id, array $data): true|WP_Error
// Campi: regular_price, sale_price, sku, status, stock_quantity, stock_status
// Chiama WC_Product_Variable::sync() dopo il save (aggiorna prezzo padre).

rp_bulk_update_variations(array $updates): array
// Input: [{variation_id: 101, regular_price: "180.00"}, ...]
// Output: {101: "ok", 102: "errore..."} — processa tutti, non si ferma agli errori.
```

### AJAX Endpoints (tutti richiedono nonce `rp_crud_nonce` + `manage_woocommerce`)

| Action | Parametri POST | Risposta |
|---|---|---|
| `rp_ajax_read` | `product_id` | prodotto completo |
| `rp_ajax_create` | `json_payload` (JSON string) | `{id, product}` |
| `rp_ajax_update` | `product_id`, `json_payload` | `{id, product}` |
| `rp_ajax_delete` | `product_id`, `force` (bool) | `{message}` |
| `rp_ajax_search` | `query` | array di prodotti |
| `rp_ajax_get_variations` | `product_id` | array di varianti |
| `rp_ajax_save_variations` | `updates` (JSON array), `product_id` | `{results, errors, variations}` |

---

## Convenzioni di Codice

### PHP
- Prefix **`rp_`** su tutte le funzioni pubbliche per evitare collisioni.
- `array_key_exists()` per update selettivi (non `isset` — passa anche null/false).
- Sempre `check_ajax_referer()` + `current_user_can('manage_woocommerce')` prima di qualsiasi AJAX.
- `wp_send_json_success()` / `wp_send_json_error()` — mai `echo` raw in AJAX handler.
- Compatibilità PHP 8.0+: usa null-safe operator `?->`, union types `int|WP_Error`, ecc.

### JavaScript (Admin UI)
- Tutto in un IIFE `(function(){ ... })()` o pattern module con `return` di API pubblica.
- Stato applicazione in oggetto `state = { product, variations, dirty, selected }`.
- Le funzioni pubbliche sono esposte tramite `return {}` e chiamate come `RPM.methodName()`.
- **Nessun framework** — vanilla JS puro, compatibile con l'ambiente WP Admin.
- AJAX sempre tramite `fetch` con `FormData` (non jQuery `$.ajax`).

### CSS
- Tutto scopato sotto `#rpm` per non interferire con WP Admin.
- CSS custom properties (`--acc`, `--grn`, `--red`, ecc.) per coerenza cromatica.
- Dark theme: `--bg: #0c0d10` come base.

---

## Catalogo Prodotti

Il catalogo è definito in `catalog.json` e `catalogo.csv` con questa struttura:

```
Sezione, Marca, Sottocategoria, SKU, Titolo, Query, Taglie, Prezzo, Prezzo Scontato
```

- **SKU format**: `BRAND-MODEL-DETAIL` (es. `NK-DL-BLK-LIME`, `AJ4-BLACKCAT-2025`)
- **Taglie**: numeri EU separati da `|` (es. `40|40.5|41|42|42.5|43|44`)
- Le taglie corrispondono a **varianti WooCommerce** (`product_type = variable`)
- Attributo variante cercato da `rp_get_product_variations()` con regex: `/(taglia|size|misura|eu|uk|us|fr|cm)/i`

---

## SEO Integration (Rank Math)

I meta field Rank Math sono salvati come post meta standard:

```php
'rank_math_title'         // Meta title SEO
'rank_math_description'   // Meta description
'rank_math_focus_keyword' // Focus keyword (supporta multipli separati da virgola)
```

`rp_get_product()` li include sempre nella response. `rp_update_product()` li aggiorna se presenti nel payload.

**Template prompt SEO** → vedi `docs/SEO_PROMPT_TEMPLATE.md`

---

## Roadmap e Prossimi Sviluppi

Vedi `docs/ROADMAP.md` per la lista completa. In sintesi, i prossimi task prioritari sono:

1. **Bulk import da CSV** — leggere `catalogo.csv` e creare prodotti variabili in batch
2. **Price sync** — aggiornamento prezzi da feed esterno
3. **Stock alerts** — notifica quando una variante va a zero
4. **Image upload helper** — associare immagini ai prodotti via path/URL

---

## File di Riferimento nel Progetto

| File | Contenuto |
|---|---|
| `docs/ARCHITECTURE.md` | Architettura dettagliata del plugin |
| `docs/API_REFERENCE.md` | Documentazione completa di ogni funzione |
| `docs/ROADMAP.md` | Feature built + backlog prioritizzato |
| `docs/SEO_PROMPT_TEMPLATE.md` | Template prompt per generare contenuto SEO prodotti |
| `catalogo.csv` | Catalogo prodotti ResellPiacenza |
| `catalog_corteiz.csv` | Sub-catalogo brand Corteiz |
| `catalog.json` | Versione JSON del catalogo |

---

## Regole di Sviluppo per Claude Code

1. **Prima di modificare qualsiasi file PHP**, verifica sempre i layer — la modifica appartiene a `crud.php`, `variations.php`, `ajax.php` o `admin-page.php`?
2. **Non aggiungere dipendenze esterne** (Composer, npm) senza discuterne. Il plugin deve restare zero-dependency oltre a WooCommerce.
3. **Ogni nuova funzione PHP pubblica** deve avere il prefix `rp_` e un commento DocBlock con: cosa fa, parametri, return type, esempio di chiamata.
4. **Ogni nuovo AJAX handler** deve avere: `check_ajax_referer`, `current_user_can`, sanitizzazione input, e ritornare sempre JSON via `wp_send_json_*`.
5. **Test manuale**: dopo ogni modifica al PHP, testa con il plugin attivo su un'installazione WP reale. Non ci sono unit test automatici (ancora).
6. **L'UI deve funzionare su mobile** — il titolare usa lo strumento durante chiamate, anche da telefono. Controlla sempre che i touch target siano adeguati e il layout regga su viewport stretto.
