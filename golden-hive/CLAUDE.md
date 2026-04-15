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
    ├── product/                 ← Da rp-product-manager (prefix: rp_)
    │   ├── crud.php             ← rp_get_product, rp_create_product, rp_update_product, rp_delete_product
    │   └── variations.php       ← rp_search_products, rp_get_product_variations, rp_update_variation, rp_bulk_update_variations
    ├── core/
    │   └── product-factory.php  ← gh_create_simple_product, gh_create_variable_product
    ├── catalog/                 ← Da rp-catalog-manager (prefix: rp_cm_)
    │   ├── reader.php           ← rp_cm_get_all_products, rp_cm_get_product_variants, rp_cm_get_product_categories, ...
    │   ├── aggregator.php       ← rp_cm_aggregate_product, rp_cm_extract_sizes, rp_cm_calculate_pricing, ...
    │   ├── tree-builder.php     ← rp_cm_build_tree, rp_cm_get_product_tree_path
    │   ├── exporter.php         ← rp_cm_export_catalog, rp_cm_export_roundtrip
    │   ├── importer.php         ← rp_cm_import_preview, rp_cm_import_apply
    │   ├── taxonomy-manager.php ← rp_cm_get_taxonomy_tree, rp_cm_create_category, rp_cm_assign_product_categories, ...
    │   ├── bulk-creator.php     ← rp_cm_bulk_preview, rp_cm_bulk_apply
    │   └── ajax.php             ← AJAX bridge per catalogo/tassonomia
    ├── media/                   ← Da rp-media-cleaner (prefix: rp_mc_)
    │   ├── scanner.php, library.php, whitelist.php, cleaner.php
    │   └── ajax.php
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
    │   ├── panels.php           ← Pannelli: overview, catalog, taxonomy, media, feeds, import, tools
    │   ├── panels-operations.php← Pannelli: filtra & agisci, ordinamento
    │   ├── js.php               ← GH module IIFE (core functions, ajax, toast)
    │   ├── js2.php              ← GH module (tab handlers, return public API)
    │   └── js-operations.php    ← Filter/bulk JS (conditions builder, inline edit, selection, sorting)
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
| | Ordinamento | Sort preview + apply menu_order |
| | Tassonomie | CRUD albero `product_cat` e `product_brand` |
| MEDIA | Safe Cleanup | Mapping-based diff → orfani 100% sicuri (ex-"Orphans") |
| | Mapping | Prodotto-immagini + inline "×" per rimuovere da gallery |
| | Whitelist | Protezione immagini dall'eliminazione |
| IMPORT | GS Feed | Golden Sneakers feed |
| | Bulk JSON | Import prodotti da JSON |
| | Roundtrip | Export/import snapshot |
| TOOLS | HTTP Client | Test API generiche |

> **CATALOGO** rimosso: Overview era lenta e non informativa, Catalog
> costruiva un JSON aggregato senza operazioni collegate. La logica PHP
> `rp_cm_export_catalog` / summary builder resta disponibile per il modulo
> Jobs ma non e piu esposta via AJAX/UI.
>
> **Media Browse** rimosso: una ricerca wordpress-like della media library
> senza operazioni utili. Rimpiazzato dalle operazioni bulk media in
> Filtra & Agisci (vedi sotto).
>
> **Whitelist** spostata da TOOLS a MEDIA: e un supporto del Safe Cleanup,
> non uno strumento a se.

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

## Regole di Sviluppo per Claude Code

1. **Prefix corretto:** `gh_` per moduli nuovi (filter, bulk), prefix originale per moduli mergiati.
2. **Nonce:** `gh_nonce` per tutti gli AJAX di golden-hive.
3. **CSS scopato sotto `#gh`** — mai stili globali.
4. **JS estende GH** — i moduli aggiuntivi (js-operations.php) aggiungono metodi a `GH` dall'esterno.
5. **Mobile responsive** — il titolare usa lo strumento da telefono.
6. **Double-load guard** obbligatoria su ogni file condiviso con plugin standalone.
