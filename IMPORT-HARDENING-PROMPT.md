# Claude Code Prompt — Golden Hive: Import Pipeline Hardening

## Context

You're working on **golden-hive**, a WooCommerce admin suite plugin at `/golden-hive/`. Read `CLAUDE.md` and `../CONVENTIONS.md` first.

The import pipeline (`includes/feeds/`) handles product imports from external feeds (StockFirmati CSV, Golden Sneakers API, generic CSV feeds). It currently works but lacks robustness at scale (2000+ products, 17k+ media). We need to port proven patterns from an external reference implementation.

## Reference Implementation

**Repository:** https://github.com/FrancescoCorbosiero/woo-importer

This is a CLI-based WooCommerce importer built by the same team. It handles thousands of products reliably. The key source files to study (fetch them via WebFetch on raw.githubusercontent.com):

| File | What to learn |
|------|---------------|
| `src/Import/WooCommerceImporter.php` | Retry with binary split, batch product creation, duplicate SKU recovery, parallel variation processing |
| `src/Media/MediaUploader.php` | Sliding-window curl_multi (already ported), image-map validation, gallery sampling |
| `src/Import/WcProductBuilder.php` | Taxonomy pre-resolution (slug→ID map before import), catalog provenance meta |
| `src/Import/GsCatalogSync.php` | Delta sync: GS feed → diff vs WC → KicksDB enrich (new only) → upsert. Lightweight variation-only patches |
| `src/Import/FeedMerger.php` | Variation-level merge from multiple feed sources |
| `src/Taxonomy/TaxonomyManager.php` | Bulk taxonomy pre-creation with cached slug→ID map |
| `src/Pricing/PriceCalculator.php` | Tiered margin + floor price + rounding per brand/category |
| `CLAUDE.md` | Full architecture docs, pipeline descriptions, catalog JSON structure |

**IMPORTANT:** woo-importer is a standalone CLI tool using WC REST API externally. Golden Hive is a WordPress plugin running inside WP via AJAX. You cannot use the REST API pattern directly — adapt the logic to use WC PHP functions (`wc_get_product`, `$product->save()`, `wp_set_object_terms`, etc.) which are faster from inside WP anyway.

## What to implement

### 1. Retry with Binary Split (HIGH PRIORITY)

**Reference:** `WooCommerceImporter::executeBatchWithRetry()`

**Problem:** When a batch of 25 products times out in `gh_ajax_fc_apply`, the entire batch is lost. The JS sends the next 25, but the failed ones are gone.

**Solution:** When a batch fails (timeout/fatal), split it in half and retry each half with exponential backoff. Max 3 retry levels (25 → 12+13 → 6+7+6+7). If a single product still fails, log it and continue.

**Where to implement:**
- `includes/feeds/ajax.php` — the `gh_ajax_fc_apply` handler (lines ~232-320)
- The try/catch block around the create/update loop should catch timeouts and split
- Also apply to `rp_rc_ajax_gs_apply` handler
- JS side (`views/js2.php` `sfApply()`): if a batch returns `partial: true` or errors, log but continue to next batch

**Key pattern from woo-importer:**
```php
// On timeout: split batch in half, retry with backoff
if ($is_timeout && $retry_depth < 3 && count($chunk) > 1) {
    $half = (int) ceil(count($chunk) / 2);
    sleep(pow(2, $retry_depth + 1)); // 2s, 4s, 8s
    $this->executeBatchWithRetry($op, array_slice($chunk, 0, $half), $map, $label.'.1', $retry_depth + 1);
    $this->executeBatchWithRetry($op, array_slice($chunk, $half), $map, $label.'.2', $retry_depth + 1);
}
```

Adapt this for the PHP AJAX handler — the retry happens server-side within the single request, not across AJAX calls.

---

### 2. Taxonomy Pre-Creation with Cached Map (HIGH PRIORITY)

**Reference:** `TaxonomyManager.php`, `WcProductBuilder` taxonomy resolution

**Problem:** During import of 2000 products, `gh_fc_post_process()` calls `term_exists()` + `wp_insert_term()` for every product's brand, category, subcategory, and tags. If 2000 products share 5 brands, that's 10000 redundant `term_exists()` queries.

**Solution:** Before the product creation loop, scan all products for unique taxonomy terms, create them all at once, and build a `slug → term_id` map. During product creation, use the map for assignment (just `wp_set_object_terms` with known IDs).

**Where to implement:**
- New function `gh_fc_prepare_taxonomies(array $woo_products, array $config): array` in `feed-config-engine.php`
- Returns `['brands' => [slug => id], 'categories' => [slug => id], 'tags' => [slug => id]]`
- Called once in `gh_ajax_fc_apply` before the create/update loop
- `gh_fc_post_process()` receives the map and does direct `wp_set_object_terms($pid, [$cached_id], $taxonomy)` instead of `term_exists()` + conditional `wp_insert_term()`
- Same for `gh_sf_assign_brand()` and `gh_sf_assign_category()` in `feed-stockfirmati.php`

**Key pattern:**
```php
function gh_fc_prepare_taxonomies(array $products): array {
    $map = ['brands' => [], 'categories' => [], 'tags' => []];
    
    // Collect unique terms
    $needed_brands = [];
    foreach ($products as $p) {
        if (!empty($p['_fc_brand'])) $needed_brands[$p['_fc_brand']] = true;
        // ... categories, tags
    }
    
    // Bulk resolve: get_terms once, create missing
    foreach (array_keys($needed_brands) as $brand_name) {
        $term = get_term_by('name', $brand_name, 'product_brand');
        if ($term) {
            $map['brands'][$brand_name] = $term->term_id;
        } else {
            $result = wp_insert_term($brand_name, 'product_brand');
            if (!is_wp_error($result)) $map['brands'][$brand_name] = $result['term_id'];
        }
    }
    
    return $map;
}
```

---

### 3. Lightweight Variation-Only Patches (HIGH PRIORITY)

**Reference:** `gs-variation-update`, `GsCatalogSync` variation patching

**Problem:** The GS feed runs every 2-4 hours. Currently, it re-imports everything (full product create/update cycle). For price+stock changes on existing products, this is overkill — we only need to patch variations.

**Solution:** New "quick patch" mode that:
1. Fetches the feed
2. Diffs only price + stock fields against current WC values
3. Patches only changed variations via `rp_update_variation()`
4. Skips product-level processing entirely (no taxonomy, no images, no SEO)

**Where to implement:**
- New function `gh_fc_quick_patch(array $products, array $config): array` in `feed-config-engine.php`
- New AJAX endpoint `gh_ajax_fc_quick_patch` in `feeds/ajax.php`
- JS: add a "Quick Update (solo prezzi/stock)" button in the SF and GS feed panels, next to the existing import button
- This should be FAST — for 2000 products with 10 variations each, patching prices takes seconds not minutes

**Key logic:**
```php
function gh_fc_quick_patch(array $woo_products): array {
    $patched = 0;
    $skipped = 0;
    
    foreach ($woo_products as $p) {
        $existing = wc_get_product(wc_get_product_id_by_sku($p['sku']));
        if (!$existing || !$existing->is_type('variable')) { $skipped++; continue; }
        
        foreach ($existing->get_children() as $var_id) {
            $variation = wc_get_product($var_id);
            // ... compare prices/stock, patch if different
        }
    }
    return ['patched' => $patched, 'skipped' => $skipped];
}
```

---

### 4. Catalog Provenance Meta (MEDIUM PRIORITY)

**Reference:** woo-importer catalog provenance tagging

**Problem:** After importing from multiple feeds, you can't tell which feed a product came from.

**Solution:** Tag every imported product with source metadata:

```php
update_post_meta($product_id, '_gh_import_source', 'stockfirmati');
update_post_meta($product_id, '_gh_import_date', current_time('mysql'));
update_post_meta($product_id, '_gh_import_feed_id', $config_id);
```

**Where to implement:**
- `gh_fc_post_process()` — after all other processing
- `gh_sf_create_product()` — after creation
- Add `import_source` as a filter condition in the filter engine (`includes/filter/conditions.php`) so you can filter by source in Filtra & Agisci

---

### 5. Image Map Validation (MEDIUM PRIORITY)

**Reference:** `MediaUploader::validateMap()`

**Problem:** The pre-import map (`GH_MEDIA_PREIMPORT_MAP_KEY`) can have stale entries (media deleted from library but still in the map).

**Solution:** Add a "Validate Map" button/function that:
1. Loads the entire map
2. Checks each attachment_id via `wp_get_attachment_url()`
3. Removes stale entries
4. Reports: N valid, M pruned

**Where to implement:**
- New function `gh_preimport_validate_map(): array` in `feeds/media-preimport.php`
- New AJAX endpoint `gh_ajax_preimport_validate`
- Add a "Valida mappa" button in the SF feed panel near the "Scarica Immagini" button

---

### 6. Duplicate SKU Recovery (MEDIUM PRIORITY)

**Reference:** `WooCommerceImporter` duplicate_retry_queue

**Problem:** If a product create fails because the SKU already exists (race condition, or the diff missed it), the product is lost.

**Solution:** When `gh_fc_create_product()` or `gh_create_variable_product()` fails with a duplicate SKU error, automatically look up the existing product by SKU and update it instead.

**Where to implement:**
- `gh_fc_create_product()` in `feed-config-engine.php` — catch the WP_Error, check if it's a duplicate SKU, find existing product, update instead
- Same pattern in `gh_sf_create_product()`

---

### 7. PriceCalculator — Tiered Margins (LOW PRIORITY)

**Reference:** `src/Pricing/PriceCalculator.php`

**Problem:** The current markup is a flat multiplier (e.g., 3.5×). Different brands/categories might need different margins.

**Solution:** A pricing engine with rules:
```php
$rules = [
    ['brand' => 'Nike', 'min_cost' => 0, 'max_cost' => 100, 'margin' => 2.5, 'floor' => 89],
    ['brand' => 'Nike', 'min_cost' => 100, 'max_cost' => 300, 'margin' => 2.0, 'floor' => 199],
    ['brand' => '*', 'min_cost' => 0, 'max_cost' => 999, 'margin' => 3.0, 'floor' => 49],
];
```

**Where to implement:**
- New file `includes/feeds/price-calculator.php`
- Called from `gh_fc_transform_one()` and `gh_sf_transform_to_woo()` when computing regular_price from cost
- UI: configurable rules in a new section of the feed config, or a dedicated tab

---

## Constraints

- **PHP 8.0+**, no Composer, vanilla JS, prefix `gh_` for new functions
- **Nonce:** `gh_nonce` for all AJAX
- **Security:** `check_ajax_referer` + `current_user_can('manage_woocommerce')` on every handler
- **No direct DB writes for products** — use WC CRUD (`$product->save()`, `wp_set_object_terms`)
- **All JS inside IIFEs** extending the `GH` module pattern
- **Mobile responsive** — the owner uses this from their phone
- **Error handling:** wrap every AJAX handler in `try/catch \Throwable`, return JSON errors not 500s
- **Bump limits:** `@set_time_limit(300)` + `wp_raise_memory_limit('admin')` on any handler that processes batches

## Current state of the feeds module

The branch `claude/reorganize-tabs-sections-8IxSI` has these recent improvements already in place:
- **Chunked imports** — JS sends products in batches of 25, with live progress
- **Import as Draft** — checkbox in SF/GS panels, propagated through transform → create
- **Pre-import media** — separate "Scarica Immagini" step downloads images before product creation
- **Parallel downloads** — curl_multi with sliding window + thumbnail skip during bulk
- **Stop button** — abort flag between batches, resumable via the persistent image map

Start from this base. Don't re-implement what's already done — build on top.

## Order of work

1. Retry with binary split (#1) — most impactful for reliability
2. Taxonomy pre-creation (#2) — most impactful for speed
3. Lightweight variation patches (#3) — enables fast frequent syncs
4. Provenance meta (#4) — quick win, adds traceability
5. Image map validation (#5) — quick win, adds safety
6. Duplicate SKU recovery (#6) — edge case but prevents data loss
7. PriceCalculator (#7) — nice to have, implement if time allows

Commit each feature separately with clear commit messages. Push to the same branch.
