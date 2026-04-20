# CLAUDE.md — Golden Hive

> Stai lavorando su **golden-hive**. La root del tuo lavoro è `/golden-hive/`.
>
> Ordine di lettura obbligatorio:
> 1. Questo file (CLAUDE.md)
> 2. `../CONVENTIONS.md` — convenzioni condivise tra tutti i plugin

---

## Contesto del Plugin

**Golden Hive** è una suite WooCommerce unificata che mergia le funzionalità di tutti i plugin standalone (`rp-product-manager`, `rp-media-cleaner`, `rp-rest-caller`, `rp-catalog-manager`, `rp-email-marketing`) in un'unica interfaccia admin con sidebar a tab.

---

## Stack Tecnico

| Layer | Tecnologia |
|---|---|
| CMS | WordPress 6.x |
| E-commerce | WooCommerce 8.x |
| SEO | Rank Math PRO |
| Email | wp_mail() → WP Mail SMTP → AWS SES |
| PHP | 8.0+ |
| Admin UI | Vanilla JS + CSS custom (dark theme) |
| Font stack | JetBrains Mono + DM Sans (Google Fonts) |

---

## Struttura del Plugin

```
golden-hive/
├── golden-hive.php              ← Entry point. Solo require_once.
├── CLAUDE.md                    ← Questo file.
└── includes/
    ├── product/                 ← Da rp-product-manager (prefix: rp_) + Inline Editor AJAX (prefix: gh_)
    │   ├── crud.php             ← rp_get_product, rp_create_product, rp_update_product, rp_delete_product
    │   ├── variations.php       ← rp_search_products, rp_get_product_variations, rp_update_variation, rp_bulk_update_variations
    │   └── ajax.php             ← gh_ajax_product_search, _load, _save, _variations_save
    ├── core/
    │   └── product-factory.php  ← gh_create_simple_product, gh_create_variable_product
    ├── catalog/                 ← Da rp-catalog-manager (prefix: rp_cm_)
    │   ├── reader.php           ← rp_cm_get_all_products, rp_cm_get_product_variants, rp_cm_get_product_categories, ...
    │   ├── aggregator.php       ← rp_cm_aggregate_product, rp_cm_extract_sizes, rp_cm_calculate_pricing, ...
    │   ├── tree-builder.php     ← rp_cm_build_tree, rp_cm_get_product_tree_path
    │   ├── exporter.php         ← rp_cm_export_catalog, rp_cm_export_roundtrip
    │   ├── importer.php         ← rp_cm_import_preview, rp_cm_import_apply
    │   ├── taxonomy-manager.php ← rp_cm_get_taxonomy_tree, rp_cm_create_category, rp_cm_assign_product_categories, ...
    │   ├── taxonomy-query.php   ← rp_cm_query_taxonomies (filter/sort/top-N), rp_cm_get_products_for_terms
    │   ├── bulk-creator.php     ← rp_cm_bulk_preview, rp_cm_bulk_apply
    │   └── ajax.php             ← AJAX bridge per catalogo/tassonomia
    ├── navigation/              ← NUOVO (prefix: gh_nav_)
    │   ├── manager.php          ← gh_nav_get_menus, _get_menu_items, _upsert_item, _populate_from_terms, _clear_managed_children
    │   └── ajax.php             ← gh_ajax_taxonomy_query, gh_ajax_nav_{menus,items,populate,clear_managed,delete_item}
    ├── media/                   ← Da rp-media-cleaner (prefix: rp_mc_)
    │   ├── scanner.php           ← rp_mm_build_usage_map, _get_orphan_attachments, _build_attachment_data_batch
    │   ├── browser.php          ← gh_media_build_usage_index (cached), gh_media_query, _safe_cleanup_preview
    │   ├── library.php          ← rp_mm_set_product_featured_image, _set_product_gallery, _get_attachment_usage
    │   ├── whitelist.php, cleaner.php
    │   └── ajax.php             ← gh_ajax_media_query, _query_ids, _safe_cleanup_preview, _bulk_whitelist, ...
    ├── feeds/                   ← Da rp-rest-caller (prefix: rp_rc_)
    │   ├── http-client.php, response-parser.php, saved-endpoints.php, feed-goldensneakers.php
    │   └── ajax.php
    ├── filter/                  ← NUOVO (prefix: gh_)
    │   ├── conditions.php       ← gh_get_condition_definitions, gh_evaluate_condition (18 tipi)
    │   ├── query-engine.php     ← gh_filter_products, gh_filter_product_ids, gh_get_filter_meta
    │   └── ajax.php             ← gh_ajax_filter_*, gh_ajax_inline_update, gh_ajax_product_detail
    ├── bulk/                    ← NUOVO (prefix: gh_)
    │   ├── actions.php          ← gh_execute_bulk_action (13 azioni: taxonomy, status, price, stock, SEO, order)
    │   ├── sorter.php           ← gh_sort_products, gh_sort_preview (11 regole di ordinamento)
    │   └── ajax.php             ← gh_ajax_bulk_*, gh_ajax_sort_*
    ├── email/                   ← Da rp-email-marketing (prefix: rp_em_)
    │   ├── contacts.php         ← rp_em_get_hustle_subscribers, rp_em_parse_csv_contacts, rp_em_merge_contacts
    │   ├── mailer.php           ← rp_em_send_test_email, rp_em_send_campaign, rp_em_personalize
    │   ├── campaigns.php        ← rp_em_get_campaigns, rp_em_save_campaign, rp_em_schedule_campaign, rp_em_execute_campaign
    │   └── ajax.php             ← rp_em_ajax_*
    ├── views/
    │   ├── css.php              ← Design system completo (dark theme)
    │   ├── panels.php           ← Pannelli: taxonomy, media library, whitelist, feeds, import, tools
    │   ├── panels-operations.php← Pannelli: filtra & agisci, inline editor, ordinamento
    │   ├── panels-navigation.php← Pannelli: tax-query, navigation (WP nav menus)
    │   ├── js.php               ← GH module IIFE (core functions, ajax, toast)
    │   ├── js2.php              ← GH module (whitelist, feeds, roundtrip, return public API)
    │   ├── js-operations.php    ← Filter/bulk JS (conditions builder, inline edit, selection, sorting)
    │   ├── js-inline.php        ← Inline Editor (search, form, JSON, variations, dirty save)
    │   ├── js-navigation.php    ← Tax Query + Navigation Manager (GH.tq*, GH.nav*)
    │   └── js-media.php         ← Media Library (query, filters, bulk ops, Safe Cleanup)
    └── admin-page.php           ← add_menu_page + render con sidebar tab
```

---

## Layer Applicativi

```
product/crud.php, variations.php   → "Prodotti" (CRUD singolo, varianti)
catalog/reader.php, aggregator.php → "Catalogo" (lettura aggregata, albero)
filter/conditions.php, query-engine.php → "Filtra" (query composabile, 2 fasi DB+memoria)
bulk/actions.php, sorter.php       → "Agisci" (operazioni bulk, ordinamento)
email/contacts.php, mailer.php     → "Email" (contatti, campagne, wp_mail)
*/ajax.php                         → "Bridge" (sanitize → chiama funzione → json)
views/*.php, admin-page.php        → "UI" (zero logica business)
```

---

## Tab UI nella Sidebar

| Sezione | Tab | Pannello |
|---|---|---|
| OPERAZIONI | Filtra & Agisci | Query builder + tabella + inline edit + bulk actions (default) |
| | Inline Editor | Single-product: Form + JSON + Variations editing |
| | Ordinamento | Sort preview + apply menu_order |
| | Tassonomie | CRUD albero `product_cat` e `product_brand` |
| | Tax Query | Lista filtrabile (search, parent, depth, count range, top-N) con selezione &rarr; Navigazione |
| | Navigazione | Gestione WP nav menus + auto-populate di un item da un set di termini |
| MEDIA | Media Library | Browser unificato con filtri, bulk ops, Safe Cleanup |
| | Whitelist | Protezione immagini, inline add form |
| IMPORT | GS Feed | Golden Sneakers feed |
| | Bulk JSON | Import prodotti da JSON |
| | Roundtrip | Export/import snapshot |
| TOOLS | HTTP Client | Test API generiche |

> **Rimossi**: Overview (lenta), Catalog (JSON senza azioni), Browse (ricerca
> WP-like inutile), Mapping (assorbito in Media Library), Safe Cleanup
> (assorbito come shortcut in Media Library). La logica PHP sottostante
> (exporter, scanner) resta disponibile per Jobs/altri moduli.

---

## Inline Editor — Architettura

Complementa Filtra & Agisci: quello e per bulk, questo per lavoro
chirurgico su un singolo prodotto. Cross-linked: "Edit" nella tabella
dei risultati apre il prodotto nell'Inline Editor.

**Tre sub-tabs:**
- **Form** — campi validati (name, sku, status, prezzi, stock, SEO).
  Dirty tracking: solo i campi modificati vengono inviati al server.
- **JSON** — textarea editabile con il payload completo del prodotto
  (identico shape a `rp_get_product()`). Dev-first: copia, modifica,
  applica. I campi read-only (`id`, `type`, `price`, `dates`,
  `permalink`, `attributes`) vengono strippati silenziosamente lato
  server cosi incollare un JSON intero e sempre safe.
- **Variations** — tabella inline (solo per prodotti variable): taglia,
  sku, prezzi, stock, stato. Dirty tracking indipendente con batch save
  via `rp_bulk_update_variations()`.

**AJAX endpoints (product/ajax.php):**
- `gh_ajax_product_search` — typeahead (auto-detect: ID → SKU esatto → fulltext → SKU LIKE)
- `gh_ajax_product_load` — payload completo + variations + brands + gallery
- `gh_ajax_product_save` — batch update via JSON (delega a `rp_update_product`)
- `gh_ajax_product_variations_save` — batch save varianti

---

## Media Library — Architettura

Browser unificato della media library con product awareness.
Sostituisce le vecchie tab Mapping + Safe Cleanup.

**Usage index inverso** (cached in transient 10 min):
`gh_media_build_usage_index()` → `attachment_id → [{pid, role}]`
dove role ∈ {featured, variation, post_featured, gallery, content}.
Invalidato da hook `add/delete_attachment` e `save_post_product`.

**Query paginaged** `gh_media_query($filters, $pagination)`:
- Filtri DB-level: filename LIKE (post_title + guid), mime
- Filtri memory-level: usage (mapped/unmapped), whitelist (yes/no)
- Hydration batch per pagina: metadata attachment + product name/sku/permalink

**Safe Cleanup** (shortcut button nel toolbar):
`gh_media_safe_cleanup_preview()` → split: `to_delete_ids` (unmapped
non-whitelisted) + `whitelist_details` (id + url + reason). L'UI mostra
un pannello di conferma in-panel con la lista dei whitelisted esclusi
prima di procedere.

---

## Media Safe Cleanup — Architettura

**Flusso a due fasi visibile all'utente:**

1. **Mapping phase** — `rp_mm_build_usage_map()` scansiona tutte le sorgenti
   che referenziano media e ritorna un breakdown ispezionabile:
   - `featured_products` — featured image di prodotti simple/variable
   - `featured_variations` — thumbnail di `product_variation`
   - `gallery_products` — ID in `_product_image_gallery` (CSV meta)
   - `featured_posts` — featured image di post/page
   - `inline_content` — URL in `<img src>` / `<a href>` nel content/excerpt
   - `all_used` — unione deduplicata delle precedenti
2. **Diff phase** — `rp_mm_get_orphan_attachments($usage_map)` sottrae
   `all_used` dall'elenco completo degli attachment mime=image. Gli orfani
   restituiti sono "100% sicuri" rispetto alle sorgenti coperte.

**Safety net:**
- La whitelist (`rp_mm_whitelist` option) blocca l'eliminazione anche se
  un attachment risulta orfano dal diff.
- `rp_mm_delete_attachment()` ricontrolla whitelist + `rp_mm_is_used()`
  puntuale prima di ogni cancellazione.
- Ogni cancellazione e loggata in `rp_mm_deletion_log` (FIFO max 500).

---

## Filter Engine — Architettura

**2 fasi:**
1. **Fase DB** — `WC_Product_Query` per status, tipo, categoria, tag (veloce, SQL)
2. **Fase memoria** — `gh_evaluate_condition()` per attributi, varianti, SEO, regex (flessibile, PHP)

**19 tipi di condizione:** category, brand, tag, attribute, status, type, price_range, has_sale, stock_status, stock_qty, sku_pattern, name_contains, date_created, date_modified, seo_field, has_image, gallery_count, variant_count, has_size, menu_order

> `brand` opera sulla tassonomia `product_brand` (WooCommerce Brands). Se la
> tassonomia non e registrata la condizione ritorna `true` (no-op) per evitare
> falsi negativi, e il selettore UI mostra "Nessun brand".

**Inline editing:** double-click su cella → input/select inline → AJAX save → aggiornamento in-place

---

## Bulk Actions

| Gruppo | Azioni |
|---|---|
| Taxonomy | assign_categories, remove_categories, set_categories, assign_brands, remove_brands, set_brands, assign_tags, remove_tags |
| Status | set_status |
| Price | set_sale_percent, remove_sale, adjust_price, markup_percent, discount_percent |
| Stock | set_stock_status, set_stock_quantity |
| SEO | set_seo_template (con placeholder {name}, {sku}, {price}, {brand}, {type}) |
| Media | remove_first_gallery_image, clear_gallery |
| Order | set_menu_order |

> Le azioni `assign_brands` / `remove_brands` / `set_brands` sono implementate
> riutilizzando `rp_cm_{assign,remove,set}_product_categories` col parametro
> `$taxonomy = 'product_brand'`. Stesso codice, diversa tassonomia.
>
> Il placeholder `{brand}` in `set_seo_template` risolve prima da
> `product_brand` (Woo Brands) e in fallback dal primo `product_cat`.

---

## Sorting — 11 Regole

name_asc/desc, price_asc/desc, date_newest/oldest, stock_first/last, sku_asc, variant_count_desc, sale_first

Scrive `menu_order` incrementale (10, 20, 30...) rispettato da WooCommerce nel catalogo.

---

## Co-esistenza con Plugin Standalone

I file condivisi (product, email) hanno guard di double-loading:
```php
if ( function_exists( 'rp_get_product' ) ) return;
if ( defined( 'RP_EM_CAMPAIGNS_KEY' ) ) return;
```
Questo permette di avere golden-hive + rp-product-manager attivi insieme senza fatal error.

---

## Taxonomy Query + Navigation — Architettura

Due moduli strettamente accoppiati che condividono lo stesso query engine.

### Tax Query (catalog/taxonomy-query.php)

Una sola funzione parametrica:

```php
rp_cm_query_taxonomies([
    'taxonomy'     => 'product_cat',   // product_cat | product_brand
    'search'       => 'abbigl',         // substring su name/slug
    'parent'       => -1,               // -1=root only, int=parent diretto, null=any
    'ancestor'     => 123,              // tutti i discendenti di #123
    'depth_min'    => 1, 'depth_max' => 2,
    'min_count'    => 5, 'max_count' => 500,
    'has_products' => true,             // shortcut per min_count>=1
    'orderby'      => 'count',          // name|count|id|depth|path
    'order'        => 'desc',
    'limit'        => 15, 'offset' => 0,
]);
// => [ 'items' => Term[], 'total' => int ]
```

Ogni Term contiene `id, name, slug, parent, count, depth, path, permalink`.
Compute `depth` e `path` sono memoizzati per singola chiamata.

Helper complementare `rp_cm_get_products_for_terms($term_ids, $taxonomy, $extra)`
restituisce gli ID prodotto assegnati a un set di termini — utile per bulk ops
sui prodotti di una query tassonomica.

### Navigation Manager (navigation/manager.php)

Thin wrapper sulla Nav Menu API di WordPress con due garanzie in piu:

1. **Marker `_gh_nav_managed`** — ogni item creato da
   `gh_nav_populate_from_terms()` riceve post meta
   `_gh_nav_managed=1` (costante `GH_NAV_MANAGED_META`).
2. **Clear non distruttivo** — `gh_nav_clear_managed_children($menu_id,
   $parent_item_id)` elimina SOLO gli item marker'ati. Gli item aggiunti a
   mano dall'admin WP restano intatti anche se sono figli dello stesso parent.

Flusso "Populate now":
- L'UI mostra un preview dei termini (una query via `rp_cm_query_taxonomies`).
- L'utente clicca *Populate now* → l'AJAX riceve `term_ids` espliciti.
- Il layer PHP: (opz.) clear managed → insert con marker, `menu_order += 10`
  partendo dall'item sibling con ordine piu alto.
- Ritorna `{ created: int[], removed: int, skipped: int, errors: string[] }`.

> Non esiste persistenza di "regole" (niente tabella salvata). E una azione
> one-shot: esegui quando serve. Il preview mostra esattamente cosa verra
> scritto prima del commit.

### AJAX endpoints

Tutti sotto prefix `gh_ajax_` con nonce `gh_nonce`:

| Azione | Descrizione |
|---|---|
| `gh_ajax_taxonomy_query` | Query parametrica sulla tassonomia (ritorna items+total) |
| `gh_ajax_nav_menus` | Lista menu WP registrati |
| `gh_ajax_nav_items` | Item del menu (con flag `managed`) |
| `gh_ajax_nav_populate` | Popola sotto `parent_item_id` da `term_ids` espliciti |
| `gh_ajax_nav_clear_managed` | Elimina solo i figli managed di un parent |
| `gh_ajax_nav_delete_item` | Elimina un singolo item (manuale o managed) |

### UI

- **Tab Tax Query** (`panel-tax-query`) — toolbar di filtri + preset "Top 15"
  + tabella con checkbox. Bottone *Invia a Navigazione* cambia tab e
  pre-compila il preview del populator con gli ID selezionati.
- **Tab Navigazione** (`panel-navigation`) — selettore menu, selettore item
  target (flat con indentazione), criteri tassonomici, preview, *Populate
  now*, *Clear managed children*. Lato destro: lista completa item correnti
  con badge `GH` sugli item managed.

---

## Regole di Sviluppo per Claude Code

1. **Prefix corretto:** `gh_` per moduli nuovi (filter, bulk), prefix originale per moduli mergiati.
2. **Nonce:** `gh_nonce` per tutti gli AJAX di golden-hive.
3. **CSS scopato sotto `#gh`** — mai stili globali.
4. **JS estende GH** — i moduli aggiuntivi (js-operations.php) aggiungono metodi a `GH` dall'esterno.
5. **Mobile responsive** — il titolare usa lo strumento da telefono.
6. **Double-load guard** obbligatoria su ogni file condiviso con plugin standalone.
