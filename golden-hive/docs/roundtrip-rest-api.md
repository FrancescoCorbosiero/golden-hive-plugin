# Roundtrip REST API (`gh/v1`)

Programmatic access to the Hive Commerce **Roundtrip** feature: export your
catalog as a JSON file, mutate it with any external tool, and apply it back —
all over plain HTTP. The JSON file is the contract; these endpoints are a thin
wrapper over the exact same functions the admin UI uses.

- Base URL: `https://YOUR-SITE/wp-json/gh/v1`
- Format: `application/json` in and out
- Capability required: `manage_woocommerce` (same as the UI)

---

## Authentication — Application Passwords

Use a WordPress **Application Password** with HTTP Basic auth. No cookies, no
nonces, no expiry surprises.

1. WP Admin → **Users → Profile → Application Passwords**.
2. Enter a name (e.g. `roundtrip-bot`) → **Add New Application Password**.
3. Copy the generated password (spaces are fine; they're part of it).
4. The account must have the `manage_woocommerce` capability (Administrator or
   Shop Manager).

Send it as Basic auth on every request:

```
Authorization: Basic base64( "username:application password" )
```

> Application Passwords require HTTPS (WordPress enforces this outside of
> local/dev environments). Treat the password like an API key.

---

## Endpoints

### 1. Export — `GET /roundtrip/export`

Returns the `rp_cm_roundtrip` envelope. With no params it exports the whole
catalog (simple + variable products).

Query parameters (all optional):

| Param | Type | Notes |
|---|---|---|
| `filters` | JSON string | `{"status":"publish","category":12,"brand":"Nike","in_stock":true,"per_page":-1}` |
| `include_ids` | CSV or repeated | Subset export by product ID. Overrides the set filters. `?include_ids=10,42,7` or `?include_ids[]=10&include_ids[]=42` |
| `status` | string | Override / shortcut for `filters.status` (`publish`, `draft`, `any`, …) |
| `category` | int | Product category term ID |
| `brand` | string | `product_cat` brand name match (legacy behavior) |
| `in_stock` | bool | `true` keeps only products with stock |
| `per_page` | int | `-1` = all (default) |

Individual params override the same key inside `filters`.

```bash
# Whole catalog → file
curl -s -u "$WP_USER:$WP_APP_PASS" \
  "https://your-site/wp-json/gh/v1/roundtrip/export" \
  -o roundtrip.json

# Only specific products
curl -s -u "$WP_USER:$WP_APP_PASS" \
  "https://your-site/wp-json/gh/v1/roundtrip/export?include_ids=101,102,103" \
  -o subset.json

# Published, in stock, one category
curl -s -u "$WP_USER:$WP_APP_PASS" \
  --get "https://your-site/wp-json/gh/v1/roundtrip/export" \
  --data-urlencode 'filters={"status":"publish","in_stock":true,"category":12}' \
  -o filtered.json
```

Response body (`200`):

```json
{
  "format": "rp_cm_roundtrip",
  "version": 1,
  "generated_at": "2026-06-19T10:00:00+00:00",
  "site_url": "https://your-site",
  "product_count": 3,
  "products": [
    {
      "id": 101, "name": "...", "slug": "...", "sku": "ABC-1",
      "type": "variable", "status": "publish",
      "regular_price": "", "sale_price": "",
      "manage_stock": false, "stock_quantity": null, "stock_status": "instock",
      "category_ids": [12], "tag_ids": [],
      "meta_title": null, "meta_description": null, "focus_keyword": null,
      "variations": [
        { "id": 201, "sku": "ABC-1-42", "regular_price": "120",
          "sale_price": "", "stock_status": "instock", "stock_quantity": 5 }
      ]
    }
  ]
}
```

### 2. Preview — `POST /roundtrip/preview` (dry-run, no writes)

Send a roundtrip file as the request body. Returns the diff that **would** be
applied. Use it as a safety gate before `apply`.

| Param | Where | Default | Values |
|---|---|---|---|
| `mode` | query string | `update_only` | `update_only`, `create_if_missing` |

```bash
curl -s -u "$WP_USER:$WP_APP_PASS" \
  -H "Content-Type: application/json" \
  "https://your-site/wp-json/gh/v1/roundtrip/preview?mode=update_only" \
  --data-binary @roundtrip.json
```

Response (`200`):

```json
{
  "summary": {
    "total_in_file": 3, "matched": 3, "with_changes": 1,
    "skipped": 0, "would_create": 0,
    "variations_total": 9, "variations_with_changes": 4, "variations_skipped": 0
  },
  "details": [
    { "id": 101, "sku": "ABC-1", "name": "...", "status": "matched",
      "changes": [ { "field": "regular_price", "old": "120", "new": "110" } ],
      "variation_results": [ /* ... */ ] }
  ]
}
```

### 3. Apply — `POST /roundtrip/apply` (writes to WooCommerce)

Same body and `mode` as preview. Commits the changes.

```bash
curl -s -u "$WP_USER:$WP_APP_PASS" \
  -H "Content-Type: application/json" \
  "https://your-site/wp-json/gh/v1/roundtrip/apply?mode=update_only" \
  --data-binary @roundtrip.json
```

Response (`200`):

```json
{
  "summary": {
    "total_in_file": 3, "updated": 1, "created": 0, "skipped": 2, "errors": 0,
    "variations_updated": 4, "variations_skipped": 0, "variations_errors": 0
  },
  "details": [ /* per-product result */ ]
}
```

Each apply run is recorded in a capped audit log (`gh_roundtrip_apply_log`
option, last 50 runs: time, user, mode, summary).

---

## The automation loop

```
1. GET  /roundtrip/export            → roundtrip.json   (the core file)
2. mutate roundtrip.json             with your external tool
3. POST /roundtrip/preview           → assert the diff is what you expect
4. POST /roundtrip/apply             → commit
```

### Large catalogs — chunk the apply

The file format tolerates a **partial** `products[]` array (the validator only
needs `format`, `version`, and each item having `id` or `sku`). For big
catalogs, split your edited file into slices of ~50 products and POST each as
its own apply call — this mirrors what the browser UI does and avoids PHP/
FastCGI timeouts. Each slice keeps the same envelope header:

```json
{ "format": "rp_cm_roundtrip", "version": 1, "products": [ /* ≤50 items */ ] }
```

Sum the per-slice `summary` counters client-side for a total.

---

## Field reference

**Matching** — products are matched by `id` first, then `sku`. Variations are
matched by `id` then `sku` within the parent. For cross-site moves
(staging → prod) where IDs differ, **rely on `sku`** and drop `id`.

**Writable on apply** (anything else in the file is ignored):

- Product: `name`, `slug`, `sku`, `status`, `description`, `short_description`,
  `regular_price`, `sale_price`, `manage_stock`, `stock_quantity`,
  `stock_status`, `weight`, `category_ids`, `tag_ids`,
  `meta_title`, `meta_description`, `focus_keyword` (Rank Math).
- Variation: `sku`, `status`, `regular_price`, `sale_price`, `manage_stock`,
  `stock_quantity`, `stock_status`, `weight`.

**Modes:**
- `update_only` — only touch products that already exist. Default. Safe.
- `create_if_missing` — also create missing **simple** products. Variable
  product creation is **not** supported (they can only be updated).

---

## Known limits (by design)

- **Images are export-only.** `featured_image_url` / `gallery_image_urls` are
  emitted but never written back. You cannot change images via roundtrip.
- **Attributes are export-only.** `attributes` (incl. variation attributes)
  are emitted but never applied. You cannot add/restructure variations.
- **Conflict rules are bypassed.** Roundtrip apply writes directly; it does
  **not** consult the cross-feed provenance / "manual is sacred" rules that
  the KicksDB/GS/SF feeds respect. Automated apply can overwrite manually
  curated fields — preview first.
- **Preview and apply are independent calls** (not transactional). Treat
  preview as a gate, not a guarantee that nothing changed in between.

---

## Errors

| HTTP | When |
|---|---|
| `401` | Missing/invalid Basic auth |
| `403` | Authenticated but lacks `manage_woocommerce` |
| `400` | Body not JSON, or invalid envelope (`invalid_format`, `invalid_version`, `no_products`, `no_identifier`) |
| `500` | Export/preview/apply threw server-side |

Error body shape (standard WP REST):

```json
{ "code": "gh_bad_body", "message": "Body mancante o non-JSON...", "data": { "status": 400 } }
```
