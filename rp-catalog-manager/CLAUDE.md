# CLAUDE.md — RP Catalog Manager

> Stai lavorando su **rp-catalog-manager**. La root del tuo lavoro è `/rp-catalog-manager/`.
> Non toccare le altre cartelle del monorepo salvo indicazione esplicita.
>
> Ordine di lettura obbligatorio:
> 1. Questo file (CLAUDE.md)
> 2. `../CONVENTIONS.md` — convenzioni condivise tra tutti i plugin
> 3. `docs/ARCHITECTURE.md`
> 4. `docs/DATA_FORMATS.md`
> 5. `docs/ROADMAP.md`

---

## Contesto del Plugin

**RP Catalog Manager** è un plugin WordPress standalone per ResellPiacenza.

**Problema che risolve:** WooCommerce non ha una vista strutturata del catalogo come dato. La UI nativa mostra prodotti in lista piatta. Non esiste un modo rapido per vedere "quante taglie ho per questa scarpa", "qual è il prezzo medio di questa categoria", "cosa ho in stock tra i Jordan 4", o esportare lo stato del magazzino in un formato leggibile da un LLM o da un sistema esterno.

**Due modalità di output distinte e separate — questo è il cuore del plugin:**

### Modalità CATALOG
Output aggregato e sintetico. I valori delle singole varianti **non compaiono**. Ogni prodotto è rappresentato dai suoi metadati aggregati: range taglie, prezzi medi/min/max, conteggio stock, stato SEO. Utile per:
- Panoramica rapida dell'inventario
- Condivisione con il titolare ("cosa abbiamo?")
- Input per un LLM ("generami descrizioni per questi prodotti")
- Identificare gap nel catalogo

### Modalità FULL EXPORT
Snapshot completo dello stato WooCommerce. Include ogni variante con tutti i suoi valori. Utile per:
- Backup leggibile del catalogo
- Migrazione a un altro sistema
- Debug e analisi avanzata
- Storico dello stato del magazzino in un momento specifico

**Il plugin è read-only.** Non scrive mai su WooCommerce. Zero rischio di corruzione dati.

---

## Stack Tecnico

| Layer | Tecnologia |
|---|---|
| CMS | WordPress 6.x |
| E-commerce | WooCommerce 8.x |
| PHP | 8.0+ |
| Output | JSON (primary), CSV (future) |
| Admin UI | Vanilla JS + CSS custom — stesso stile di rp-product-manager |
| Font stack UI | JetBrains Mono + DM Sans (Google Fonts) |

---

## Struttura del Plugin

```
rp-catalog-manager/
├── rp-catalog-manager.php       ← Entry point. Solo require_once.
└── includes/
    ├── reader.php               ← Legge WooCommerce. Nessun side effect. Funzioni rp_cm_read_*.
    ├── aggregator.php           ← Aggrega dati varianti → metadati prodotto (modalità CATALOG).
    ├── exporter.php             ← Assembla i due formati JSON (CATALOG e FULL EXPORT).
    ├── tree-builder.php         ← Costruisce la struttura ad albero Sezione>Marca>Sottocategoria>Prodotti.
    ├── ajax.php                 ← Tutti i wp_ajax_rp_cm_* handler.
    └── admin-page.php           ← UI admin (menu + render + JSON viewer + download).
```

### Regola fondamentale dei layer

```
reader.php       →  "Leggi" (raw data da WooCommerce, nessuna trasformazione)
aggregator.php   →  "Aggrega" (calcola metadati da raw data varianti)
tree-builder.php →  "Struttura" (organizza in albero per categoria)
exporter.php     →  "Assembla" (combina i layer nel formato finale)
ajax.php         →  "Bridge"
admin-page.php   →  "Mostra"
```

**Nessuno di questi file scrive mai nel DB. Nessun `update_post_meta`, nessun `wp_insert_post`.**

---

## Struttura Dati — Formato CATALOG

L'albero segue la gerarchia del catalogo ResellPiacenza:
`Sezione → Marca → Sottocategoria → [Prodotti]`

```json
{
  "generated_at": "2025-03-29T14:30:00+01:00",
  "mode": "catalog",
  "summary": {
    "total_products": 217,
    "total_in_stock": 143,
    "total_variants": 1840,
    "categories": 12,
    "brands": 8
  },
  "tree": {
    "Sneakers": {
      "Nike": {
        "Nike Dunk Low": [
          {
            "id": 123,
            "name": "Nike Dunk Low Black Lime Glow",
            "sku": "NK-DL-BLK-LIME",
            "slug": "nike-dunk-low-black-lime-glow",
            "status": "publish",
            "permalink": "https://resellpiacenza.it/prodotto/...",

            "sizes": {
              "range": "40 – 44",
              "available": ["40", "40.5", "41", "42", "42.5", "43", "44"],
              "in_stock": ["41", "42", "43"],
              "out_of_stock": ["40", "40.5", "42.5", "44"]
            },

            "pricing": {
              "regular_min": 179.00,
              "regular_max": 179.00,
              "regular_avg": 179.00,
              "has_sale": false,
              "sale_min": null,
              "sale_max": null,
              "currency": "EUR"
            },

            "stock": {
              "variant_count": 7,
              "in_stock_count": 3,
              "out_of_stock_count": 4,
              "stock_status": "partial"
            },

            "seo": {
              "focus_keyword": "nike dunk low black lime glow",
              "meta_title": "Nike Dunk Low Black Lime Glow | Shop Online",
              "has_description": true,
              "has_short_description": true
            },

            "dates": {
              "created": "2024-11-10",
              "modified": "2025-03-01"
            }
          }
        ]
      }
    }
  }
}
```

**Nota stock_status:** campo calcolato.
- `"full"` → tutte le varianti in stock
- `"partial"` → almeno una variante in stock, ma non tutte
- `"out"` → nessuna variante in stock
- `"unmanaged"` → stock non gestito (manage_stock = false)

---

## Struttura Dati — Formato FULL EXPORT

Stessa struttura ad albero, ma ogni prodotto contiene l'array completo delle varianti con tutti i valori.

```json
{
  "generated_at": "2025-03-29T14:30:00+01:00",
  "mode": "full_export",
  "summary": { "...": "..." },
  "tree": {
    "Sneakers": {
      "Nike": {
        "Nike Dunk Low": [
          {
            "id": 123,
            "name": "Nike Dunk Low Black Lime Glow",
            "sku": "NK-DL-BLK-LIME",
            "status": "publish",
            "type": "variable",

            "pricing": { "regular": "179.00", "sale": "" },
            "stock": { "manage_stock": false, "stock_status": "instock" },

            "content": {
              "description": "<p>...</p>",
              "short_description": "<p>...</p>"
            },

            "seo": {
              "focus_keyword": "nike dunk low black lime glow",
              "meta_title": "...",
              "meta_description": "...",
              "slug": "nike-dunk-low-black-lime-glow"
            },

            "media": {
              "featured_image_url": "https://...",
              "gallery_urls": ["https://...", "https://..."]
            },

            "attributes": {
              "pa_taglia": ["40", "40.5", "41", "42", "42.5", "43", "44"]
            },

            "variants": [
              {
                "variation_id": 456,
                "size": "40",
                "sku": "NK-DL-BLK-LIME-T40",
                "regular_price": "179.00",
                "sale_price": "",
                "stock_quantity": 0,
                "stock_status": "outofstock",
                "status": "publish"
              },
              {
                "variation_id": 457,
                "size": "40.5",
                "sku": "NK-DL-BLK-LIME-T40.5",
                "regular_price": "179.00",
                "sale_price": "",
                "stock_quantity": 1,
                "stock_status": "instock",
                "status": "publish"
              }
            ],

            "dates": {
              "created": "2024-11-10T09:00:00",
              "modified": "2025-03-01T16:22:00"
            }
          }
        ]
      }
    }
  }
}
```

---

## Funzioni PHP Disponibili

### `reader.php`

```php
rp_cm_get_all_products(array $filters = []): array
// Ritorna tutti i prodotti WooCommerce (tipo: any).
// $filters opzionali: [
//   'status'   => 'publish' | 'draft' | 'any'  (default: 'any')
//   'category' => int $term_id
//   'per_page' => int (default: -1, tutti)
// ]
// Usa WC_Product_Query per evitare get_posts() diretto (più stabile).
// Ritorna array di WC_Product objects.

rp_cm_get_product_variants(int $product_id): array
// Ritorna varianti raw di un prodotto variabile.
// Ogni variante: WC_Product_Variation object.
// Prodotto simple → ritorna array vuoto (non errore).

rp_cm_get_product_categories(): array
// Ritorna la gerarchia completa delle categorie prodotto.
// [ term_id => ['name', 'slug', 'parent_id', 'children' => [...]] ]

rp_cm_get_product_images(int $product_id): array
// Ritorna: ['featured_url' => '...', 'gallery_urls' => [...]]
// Usato solo in FULL EXPORT (il catalog non include immagini per dimensioni).
```

### `aggregator.php`

```php
rp_cm_aggregate_product(WC_Product $product, array $variants): array
// Calcola i metadati aggregati per la modalità CATALOG.
// Input: prodotto + sue varianti (già letti da reader.php).
// Output: l'oggetto "catalog entry" con sizes, pricing, stock, seo, dates.
// NON chiama il DB — lavora solo sui dati già passati.

rp_cm_calculate_stock_status(array $variants): string
// 'full' | 'partial' | 'out' | 'unmanaged'
// Se prodotto simple (variants vuoto): guarda $product->get_stock_status().

rp_cm_extract_sizes(array $variants): array
// Estrae le taglie dalle varianti, ordina numericamente.
// Ritorna: ['range' => '40 – 44', 'available' => [...], 'in_stock' => [...], 'out_of_stock' => [...]]
// La regex per trovare l'attributo taglia è la stessa di rp-product-manager:
// /(taglia|size|misura|eu|uk|us|fr|cm)/i

rp_cm_calculate_pricing(array $variants, WC_Product $product): array
// Calcola min/max/avg su regular_price e sale_price di tutte le varianti.
// Per prodotto simple: usa i prezzi del prodotto stesso.
// Ignora varianti con price vuoto nel calcolo avg.
// Ritorna: ['regular_min', 'regular_max', 'regular_avg', 'has_sale', 'sale_min', 'sale_max', 'currency']
```

### `tree-builder.php`

```php
rp_cm_build_tree(array $products_data): array
// Prende array flat di prodotti (già aggregati o full) e li organizza in albero.
// Struttura: Sezione → Marca → Sottocategoria → [prodotti]
// La gerarchia è derivata dalle categorie WooCommerce del prodotto.
// Logica di fallback:
//   - Se il prodotto ha categoria con parent → usa parent come Marca, categoria come Sottocategoria
//   - Se ha solo una categoria → usa come Sottocategoria, Marca = 'Uncategorized'
//   - Se non ha categorie → finisce in tree['_uncategorized']['_uncategorized']['_none']
// La Sezione è sempre "Sneakers" per questo store, ma il codice non la hardcoda.

rp_cm_get_product_tree_path(int $product_id): array
// Ritorna ['sezione', 'marca', 'sottocategoria'] per un prodotto.
// Usato da rp_cm_build_tree() internamente.
```

### `exporter.php`

```php
rp_cm_export_catalog(array $filters = []): array
// Assembla il JSON in modalità CATALOG.
// Flusso:
//   1. rp_cm_get_all_products($filters)
//   2. Per ogni prodotto: rp_cm_get_product_variants()
//   3. rp_cm_aggregate_product() per ogni prodotto
//   4. rp_cm_build_tree($aggregated)
//   5. Aggiunge summary e metadata
// Ritorna array (non stringa JSON — json_encode() viene fatto nell'AJAX handler).

rp_cm_export_full(array $filters = []): array
// Assembla il JSON in modalità FULL EXPORT.
// Stesso flusso ma include:
//   - rp_cm_get_product_images() per ogni prodotto
//   - variants array completo (non aggregato)
//   - content (description, short_description)
// Più lento di export_catalog: processa più dati per prodotto.

rp_cm_build_summary(array $tree): array
// Calcola il blocco summary dal tree già costruito.
// ['total_products', 'total_in_stock', 'total_variants', 'categories', 'brands']
```

---

## AJAX Endpoints

Tutti richiedono nonce `rp_cm_nonce` + capability `manage_woocommerce`.

| Action | Parametri POST | Risposta |
|---|---|---|
| `rp_cm_ajax_export_catalog` | `filters` (JSON, opz.) | JSON catalog completo |
| `rp_cm_ajax_export_full` | `filters` (JSON, opz.) | JSON full export completo |
| `rp_cm_ajax_get_summary` | — | solo blocco summary (veloce, senza tree) |
| `rp_cm_ajax_get_tree_paths` | — | lista sezione/marca/sottocategoria disponibili (per filtri UI) |

---

## Convenzioni di Codice

### PHP
- Prefix **`rp_cm_`** su tutte le funzioni pubbliche.
- **Read-only assoluto.** Nessun `update_*`, `insert_*`, `delete_*`, `wp_delete_*`.
- Usare `WC_Product_Query` invece di `get_posts()` per leggere i prodotti — più stabile tra versioni WooCommerce.
- `json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` per output leggibile.
- Per cataloghi grandi (>300 prodotti): la generazione può richiedere 5-15 secondi. Implementare un timeout check e ritornare un warning se si avvicina al PHP `max_execution_time`.

### Filtri
L'export accetta filtri opzionali che passano dall'UI:
```php
$filters = [
    'status'    => 'publish',          // default per catalog, 'any' per full export
    'category'  => 12,                 // term_id specifico
    'brand'     => 'Nike',             // nome del brand (match su categoria)
    'in_stock'  => true,               // solo prodotti con almeno una variante instock
];
```

### UI: JSON Viewer
La UI mostra il JSON in un viewer con:
- Syntax highlighting (stessa funzione `hl()` degli altri plugin RP)
- Collasso/espansione dei nodi (implementato in JS puro, nessuna libreria)
- Bottone "Copia JSON" → clipboard
- Bottone "Download .json" → `Blob` + `URL.createObjectURL()` → download diretto nel browser
- Dimensione file stimata mostrata prima del download

### UI: Filtri
Prima di generare, l'utente può impostare:
- Status: Pubblicati / Bozze / Tutti
- Brand: dropdown con brand disponibili
- Solo in stock: toggle
- Modalità: Catalog / Full Export

---

## Performance Notes

| Catalogo | Tempo stimato CATALOG | Tempo stimato FULL |
|---|---|---|
| 50 prodotti | ~1s | ~2s |
| 200 prodotti | ~4s | ~8s |
| 500 prodotti | ~10s | ~20s |

Per cataloghi >500 prodotti considerare:
- Export in background con WP Cron + polling dalla UI
- Oppure export paginato con stream JSON (chunked)

Per ResellPiacenza (~217 prodotti dal catalogo.csv) la chiamata sincrona è accettabile.

---

## File di Riferimento

| File | Contenuto |
|---|---|
| `docs/ARCHITECTURE.md` | Flusso assembly, tree-building logic, casi edge |
| `docs/DATA_FORMATS.md` | Esempi completi dei due formati JSON con annotazioni |
| `docs/ROADMAP.md` | Feature built + backlog |
| `catalogo.csv` (root progetto) | Catalogo attuale — riferimento per la struttura ad albero attesa |
