# LLM Context — Calling the Hive Commerce `gh/v1` REST API from JavaScript

You are integrating a JavaScript/TypeScript app (e.g. Next.js) with a WordPress
plugin that exposes a REST API under `gh/v1`. This document is the complete,
authoritative contract. Follow it exactly. Do not invent endpoints, params, or
fields beyond what is listed here.

---

## 0. Architecture you MUST follow

```
Browser (client JS)
   │  fetch('/api/gh/...')         ← same-origin, NO secret in the browser
   ▼
Next.js route handler (server)     ← holds the WordPress Application Password
   │  fetch('https://WP/wp-json/gh/v1/...', Basic auth)
   ▼
WordPress + plugin (PHP)           ← the gh/v1 endpoints live here
```

Two hard rules:

1. **Never call the WordPress origin directly from the browser.** WordPress does
   not send CORS headers for cross-origin requests, and the credential is
   admin-level. Always go through a same-origin server route (the proxy).
2. **Never expose the Application Password to the client.** Keep it in
   server-only env vars (not `NEXT_PUBLIC_*`).

---

## 1. Authentication

HTTP Basic auth using a WordPress **Application Password** (WP ≥ 5.6).

```
Authorization: Basic base64("<wp_username>:<application password>")
```

- The user must have the `manage_woocommerce` capability (Administrator or Shop
  Manager).
- HTTPS is required (WP rejects Application Passwords over plain HTTP).
- The app-password value contains spaces — keep them; they are part of it.

Server-only env vars:

```
WP_BASE_URL=https://your-wp-site        # no trailing slash, no /wp-json
WP_USER=admin
WP_APP_PASS=xxxx xxxx xxxx xxxx xxxx xxxx
```

---

## 2. Endpoint reference (base: `${WP_BASE_URL}/wp-json/gh/v1`)

All requests/responses are `application/json`. POST bodies are raw JSON.

### 2.1 `GET /roundtrip/export`
Returns the full `rp_cm_roundtrip` snapshot envelope.

Query params (all optional):

| Param | Type | Notes |
|---|---|---|
| `filters` | JSON string | `{"status":"publish","category":12,"brand":"Nike","in_stock":true,"per_page":-1}` |
| `include_ids` | CSV | Subset by product ID, e.g. `10,42,7`. Overrides set filters. |
| `status` | string | `publish` \| `draft` \| `any` … (overrides `filters.status`) |
| `category` | int | product category term ID |
| `brand` | string | brand name match (legacy `product_cat`) |
| `in_stock` | bool | `true` keeps only in-stock |
| `per_page` | int | `-1` = all (default) |

Response `200`:
```json
{
  "format": "rp_cm_roundtrip",
  "version": 1,
  "generated_at": "2026-06-20T10:00:00+00:00",
  "site_url": "https://your-wp-site",
  "product_count": 3,
  "products": [
    {
      "id": 101, "name": "...", "slug": "...", "sku": "ABC-1",
      "type": "variable", "status": "publish",
      "regular_price": "", "sale_price": "",
      "manage_stock": false, "stock_quantity": null, "stock_status": "instock",
      "weight": "",
      "category_ids": [12], "category_names": ["..."],
      "tag_ids": [], "tag_names": [],
      "attributes": { "...": ["..."] },
      "featured_image_url": "https://...", "gallery_image_urls": ["https://..."],
      "meta_title": null, "meta_description": null, "focus_keyword": null,
      "date_created": "...", "date_modified": "...",
      "variations": [
        { "id": 201, "sku": "ABC-1-42", "status": "publish",
          "regular_price": "120", "sale_price": "",
          "manage_stock": false, "stock_quantity": null, "stock_status": "instock",
          "weight": "", "attributes": { "...": "..." } }
      ]
    }
  ]
}
```

### 2.2 `GET /roundtrip/ids`
Just the product ID list for a filter set — use it to **chunk the export**.
Same filter params as `/roundtrip/export` minus `include_ids`/`per_page`.

Response `200`: `{ "ids": [101,102, ...], "total": 612 }`

### 2.3 `POST /roundtrip/preview`  (dry-run, no writes)
Body = a roundtrip envelope (`{ format, version, products:[...] }`). Partial
`products[]` is allowed (this is how you chunk).
Query param `mode`: `update_only` (default) | `create_if_missing`.

Response `200`:
```json
{
  "summary": { "total_in_file": 3, "matched": 3, "with_changes": 1,
    "skipped": 0, "would_create": 0,
    "variations_total": 9, "variations_with_changes": 4, "variations_skipped": 0 },
  "details": [ { "id":101, "sku":"ABC-1", "name":"...", "status":"matched",
    "changes":[{"field":"regular_price","old":"120","new":"110"}],
    "variation_results":[ ... ] } ]
}
```

### 2.4 `POST /roundtrip/apply`  (writes)
Same body and `mode` as preview. Commits.
Response `200`:
```json
{ "summary": { "total_in_file":3, "updated":1, "created":0, "skipped":2, "errors":0,
  "variations_updated":4, "variations_skipped":0, "variations_errors":0 },
  "details": [ ... ] }
```

### 2.5 `POST /bulk/preview`  (dry-run, no writes)
**Different engine** (bulk creator). Body = `{ "products": [...] }` (bare array
also accepted). Query param `mode`: `create` (default) | `create_or_update`.
Response `200`: `{ "summary": { "total", "to_create", "to_update", ... }, "details": [...] }`

### 2.6 `POST /bulk/apply`  (writes)
Same body/`mode` as bulk preview. Response `200`:
`{ "summary": { "total", "created", "updated", "errors" }, "details": [...] }`

### 2.7 `POST /bulk/dispatch`  (background job — preferred for large sets)
Body = `{ "products": [...] }`. Query param `mode`: `create` | `create_or_update`.
Returns **immediately** (`202`); the import runs server-side in WP-Cron ticks.
Response `202`: `{ "job_id": "job_xxx", "total": 1460 }`

### 2.8 `GET /bulk/job?id=<job_id>`  (poll)
Response `200`:
```json
{ "job_id":"job_xxx", "status":"continue", "done":false,
  "summary": { "processed":320, "total":1460, "created":0, "updated":320, "errors":0 } }
```
`status` ∈ `continue` | `done` | `error` | `skipped`. `done` is `true` when
`status` is `done` or `error`.

---

## 3. Semantics you must respect

**Roundtrip importer (`/roundtrip/*`)** — field-level updater:
- Matches each product by `id` first, then `sku`. Cross-site (IDs differ) ⇒ omit
  `id`, rely on `sku`.
- Writable: `name, slug, sku, status, description, short_description,
  regular_price, sale_price, manage_stock, stock_quantity, stock_status, weight,
  category_ids, tag_ids, meta_title, meta_description, focus_keyword`; per
  variation: `sku, status, regular_price, sale_price, manage_stock,
  stock_quantity, stock_status, weight`. Anything else in the file is ignored.
- `mode=update_only` touches existing only; `create_if_missing` also creates
  **simple** products (variable creation NOT supported).
- **Images and attributes are export-only** — present in the export, never
  written back. You cannot change images/attributes through `/roundtrip/*`.

**Bulk creator (`/bulk/*`, `/bulk/dispatch`)** — create / upsert:
- Each product requires `name`; simple needs `regular_price`; variable needs
  `variations`.
- `mode=create` always creates; `create_or_update` matches by `sku` (updates if
  found, else creates). Builds attributes/variations on create.

A roundtrip export file is accepted as bulk input (shared `products[]`), but it
runs through the create/upsert engine, not the field-level updater.

---

## 4. The Cloudflare constraint (why you chunk)

The origin is behind Cloudflare, whose proxy kills any request that runs past
**~100 s** with a **524**. This is not configurable (except Enterprise). So:

- **Export:** never rely on one `GET /roundtrip/export` for a big catalog. Call
  `/roundtrip/ids`, then page `/roundtrip/export?include_ids=…` ~50 IDs at a
  time and merge `products[]`.
- **Import (synchronous):** POST ~50 products (or ~80 "write-weight" =
  Σ `1 + variations.length`) per call to `/roundtrip/apply` or `/bulk/apply`;
  sum the `summary` counters yourself.
- **Import (large / unattended):** prefer `/bulk/dispatch` + poll `/bulk/job`.
  It runs server-side in ticks, so the 100 s cap does not apply at all and it
  survives the client disconnecting. (Requires WP-Cron to be firing.)

Also add a small retry/backoff per chunk — a transient 524 on one batch
shouldn't abort the whole run.

---

## 5. Error contract

Standard WordPress REST shape:
```json
{ "code": "gh_bad_body", "message": "Body mancante o non-JSON...", "data": { "status": 400 } }
```

| HTTP | Meaning |
|---|---|
| 200 | OK |
| 202 | Job dispatched (`/bulk/dispatch`) |
| 400 | Bad/missing JSON body or invalid envelope (`invalid_format`, `invalid_version`, `no_products`, `no_identifier`, `missing_name`, `missing_price`, `missing_variations`) |
| 401 | Missing/invalid Basic auth |
| 403 | Authenticated but lacks `manage_woocommerce` |
| 404 | Job not found (`/bulk/job`) |
| 500 | Server-side failure during export/preview/apply/dispatch |

Always read `message` for display; branch on `data.status` / HTTP status.

---

## 6. Reference implementation (TypeScript / Next.js)

### 6.1 Proxy — one catch-all route (App Router)

`app/api/gh/[...path]/route.ts`:
```ts
import { NextRequest } from "next/server";

const WP_BASE = process.env.WP_BASE_URL!;
const AUTH =
  "Basic " +
  Buffer.from(`${process.env.WP_USER}:${process.env.WP_APP_PASS}`).toString("base64");

async function forward(req: NextRequest, path: string[]) {
  const url = new URL(req.url);
  const target = `${WP_BASE}/wp-json/gh/v1/${path.join("/")}${url.search}`;

  const init: RequestInit = {
    method: req.method,
    headers: { Authorization: AUTH, "Content-Type": "application/json" },
    // Big imports can take a while even per-chunk; don't let the platform
    // abort early. (On Vercel, also raise the route's maxDuration.)
  };
  if (req.method !== "GET" && req.method !== "HEAD") {
    init.body = await req.text(); // pass JSON through untouched
  }

  const res = await fetch(target, init);
  return new Response(await res.text(), {
    status: res.status,
    headers: { "Content-Type": res.headers.get("content-type") ?? "application/json" },
  });
}

// Next 15: params is a Promise — await it. Next 13/14: it's a plain object.
export async function GET(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path);
}
export async function POST(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path);
}
```

> The browser now talks only to `/api/gh/...` (same-origin → no CORS), and the
> secret stays on the server.

### 6.2 Tiny client (browser, same-origin)

```ts
async function gh<T = any>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`/api/gh/${path}`, init);
  const body = await res.json().catch(() => null);
  if (!res.ok) throw new Error(body?.message || `HTTP ${res.status}`);
  return body as T;
}

const qs = (o: Record<string, unknown>) => {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(o)) if (v !== undefined && v !== null && v !== "") p.set(k, String(v));
  return p.toString();
};
```

### 6.3 Chunked export (ids → include_ids → merge)

```ts
export async function exportRoundtrip(filters: Record<string, unknown> = {}) {
  const q = Object.keys(filters).length ? `?filters=${encodeURIComponent(JSON.stringify(filters))}` : "";
  const { ids } = await gh<{ ids: number[]; total: number }>(`roundtrip/ids${q}`);

  const BATCH = 50;
  let header: any = null;
  const products: any[] = [];
  for (let i = 0; i < ids.length; i += BATCH) {
    const slice = ids.slice(i, i + BATCH);
    const data = await withRetry(() => gh(`roundtrip/export?include_ids=${slice.join(",")}`));
    header ??= data;
    if (Array.isArray(data.products)) products.push(...data.products);
  }
  return {
    format: header?.format ?? "rp_cm_roundtrip",
    version: header?.version ?? 1,
    generated_at: header?.generated_at ?? new Date().toISOString(),
    site_url: header?.site_url ?? "",
    product_count: products.length,
    products,
  };
}

async function withRetry<T>(fn: () => Promise<T>, attempts = 3): Promise<T> {
  let last: unknown;
  for (let i = 0; i < attempts; i++) {
    try { return await fn(); }
    catch (e) { last = e; if (i < attempts - 1) await new Promise(r => setTimeout(r, 1000 * (i + 1))); }
  }
  throw last;
}
```

### 6.4 Synchronous chunked import (weight-based)

```ts
const weight = (p: any) => 1 + (Array.isArray(p?.variations) ? p.variations.length : 0);

function* weightedBatches(products: any[], budget = 80) {
  let batch: any[] = [], w = 0;
  for (const p of products) {
    const pw = weight(p);
    if (batch.length && w + pw > budget) { yield batch; batch = []; w = 0; }
    batch.push(p); w += pw;
  }
  if (batch.length) yield batch;
}

// kind: "roundtrip" → /roundtrip/apply (envelope body), "bulk" → /bulk/apply ({products})
export async function applyChunked(
  products: any[],
  kind: "roundtrip" | "bulk",
  mode: string,
  onProgress?: (done: number, total: number) => void,
) {
  const total = products.length;
  let done = 0;
  const summary: Record<string, number> = {};
  for (const batch of weightedBatches(products)) {
    const path = kind === "roundtrip" ? `roundtrip/apply?mode=${mode}` : `bulk/apply?mode=${mode}`;
    const body = kind === "roundtrip"
      ? JSON.stringify({ format: "rp_cm_roundtrip", version: 1, products: batch })
      : JSON.stringify({ products: batch });
    const res = await withRetry(() =>
      gh<{ summary: Record<string, number> }>(path, {
        method: "POST", headers: { "Content-Type": "application/json" }, body,
      }),
    );
    for (const [k, v] of Object.entries(res.summary ?? {})) if (typeof v === "number") summary[k] = (summary[k] ?? 0) + v;
    done += batch.length;
    onProgress?.(done, total);
  }
  return summary;
}
```

### 6.5 Background import (fire-and-poll) — best for large/unattended

```ts
export async function bulkImportBackground(
  products: any[],
  mode: "create" | "create_or_update" = "create_or_update",
  onProgress?: (s: any) => void,
) {
  const { job_id, total } = await gh<{ job_id: string; total: number }>(`bulk/dispatch?mode=${mode}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ products }),
  });

  for (;;) {
    await new Promise(r => setTimeout(r, 2500));
    const st = await gh<{ status: string; done: boolean; summary: any }>(
      `bulk/job?id=${encodeURIComponent(job_id)}`,
    );
    onProgress?.(st.summary ?? { total });
    if (st.done) {
      if (st.status === "error") throw new Error("Import job failed — check the WP Jobs log");
      return st.summary;
    }
  }
}
```

---

## 7. Decision guide (which import path)

- **Small set, want the diff first** → `POST /roundtrip/preview` then `apply`
  (field-level update of existing products).
- **Creating / upserting products by SKU, small-ish** → `POST /bulk/preview` then
  `apply` (chunked, §6.4).
- **Large (hundreds+) or unattended** → `POST /bulk/dispatch` + poll
  `GET /bulk/job` (§6.5). CDN-proof, survives disconnect.

---

## 8. Quick verification (no app code)

```bash
# Routes registered?
curl -s "$WP_BASE_URL/wp-json/" | grep -o 'gh/v1[^"]*'

# Auth + a tiny export
curl -s -u "$WP_USER:$WP_APP_PASS" \
  "$WP_BASE_URL/wp-json/gh/v1/roundtrip/ids?status=publish" | head
```

If `wp-json/` doesn't list `gh/v1`, the plugin build isn't active yet. If you get
`401` with correct credentials, the server is likely stripping the
`Authorization` header (Apache/FastCGI) — add `CGIPassAuth On` or
`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
