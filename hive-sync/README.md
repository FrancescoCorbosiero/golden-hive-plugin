# Hive Sync

Stock-sync plugin for WooCommerce. Standalone — installable independently of the rest of the Golden Hive suite. When Golden Hive is also active, Hive Sync delegates a few operations to it via a small filter contract; without Golden Hive it falls back to vanilla WC public API.

## Scope

Import / export of WooCommerce products with a Shopify-Importer-style UI:

- **Sources** — pluggable (GoldenSneakers feed, generic CSV from URL or local file). Each source declares its config schema; the UI renders the form automatically.
- **Mappings** — saved column→Woo-field maps with template + dot-path syntax (`sizes.size_eu` fans out a nested array; `<p>{brand_name} {name}</p>` substitutes placeholders).
- **Pipelines** — composable lifecycle: `pre-check → import-rule → materialize → post-check`. Steps are reorderable and individually parameterized.
- **Rules** — scoped pipelines (selection filter + operation stack) — runnable independently as scheduled jobs.
- **Jobs** — cron-scheduled or ad-hoc runs of sources / rules. WP-Cron tick every 5 minutes with transient-based serialization.
- **Exports** — full inventory CSV/JSON or catalog grouped by `product_cat` → `product_brand`.
- **Runs** — per-execution audit history with cursor-based resume across ticks.
- **Migration** — idempotent one-shot import of legacy `gh_pipelines` / `gh_mapper_rules` / `gh_jobs`.

## Architecture

```
hive-sync/
├── hive-sync.php              Entry point. Activation registers schema + seeds defaults.
├── composer.json              PSR-4 HiveSync\ → src/
├── includes/
│   ├── migrate.php            8 dedicated wp_hsync_* tables, dbDelta-driven.
│   ├── host-adapter.php       Versioned filter contract — see below.
│   ├── admin-page.php         9-tab UI: Sources / Mappings / Pipelines / Rules / Run / Jobs / Exports / Runs / Migra.
│   ├── ajax.php               ~25 wp_ajax_hsync_* handlers, all guarded by hsync_nonce + manage_woocommerce.
│   ├── assets.php             Localizes ajaxUrl + nonce + version.
│   ├── cron.php               WP-Cron event registration.
│   ├── cron-fallback.php      admin_init throttled fallback for environments with DISABLE_WP_CRON.
│   ├── registrations.php      Concrete sources/operations/checks self-register on hive_sync/core_booted.
│   └── seeder logic           Default mappings + pipelines (idempotent).
├── src/
│   ├── Core/
│   │   ├── Source/            Source contract + AbstractSource + value objects.
│   │   ├── Operation/         Operation + ImportRule interfaces + registry + result types.
│   │   ├── Check/             Check + ImportCheck (pre-import) interfaces + registries.
│   │   ├── Selection/         Selection mode (Ids / Filter / All).
│   │   ├── Pipeline/          Pipeline + PipelineStep + Repository + Executor.
│   │   ├── Repo/              DAOs over the 8 tables.
│   │   └── Bootstrap.php      Wires registries on the hive_sync/core_booted action.
│   ├── Sources/
│   │   ├── GoldenSneakersSource.php   Delegates to legacy rp_rc_gs_* via host filters.
│   │   └── CsvSource.php              Native PHP parser, URL or local file.
│   ├── Operations/
│   │   ├── Status/SetStatus.php
│   │   ├── Pricing/AdjustPrice.php
│   │   ├── Stock/SetStockStatus.php  + SetStockQuantity.php
│   │   ├── Media/DownloadMedia.php           ImportRule — parallel sideload via host adapter.
│   │   └── Taxonomy/ResolveTaxonomy.php      ImportRule — auto-create missing terms.
│   ├── Checks/
│   │   ├── Media/HasImages.php               Post-check.
│   │   ├── Taxonomy/HasCategory.php          Post-check.
│   │   └── Import/HasRequiredFields.php  + HasMediaUrl.php   Pre-checks.
│   └── Workflow/
│       ├── Run/ImportRunner.php          Lifecycle orchestrator with cursor resume.
│       ├── Schedule/CronExpr.php  + JobRunner.php   5-field cron parser + dispatch.
│       ├── Mapping/PathResolver.php  + Template.php    Dot-paths + placeholders.
│       ├── Migration/LegacyImporter.php  Best-effort copy from wp_options to wp_hsync_*.
│       ├── Export/Exporter.php           Inventory + catalog-by-taxonomy.
│       └── Seed/Defaults.php             Idempotent default mappings + pipelines.
└── assets/
    ├── css/admin.css
    └── js/admin.js          Vanilla JS, single HSync namespace, no jQuery.
```

## Host adapter contract

Hive Sync calls into the host plugin via filters (`hive_sync/host/*`). Default behavior when nothing is bound = no-op or fallback. Golden Hive ships a bridge (`golden-hive/includes/integrations/hive-sync-bridge.php`) that wires every filter to existing `gh_*` / `rp_*` functions.

| Filter / action | Purpose | Default fallback |
|---|---|---|
| `hive_sync/host/taxonomy/resolve` | name → term_id, create when missing | `wp_insert_term` |
| `hive_sync/host/media/preimport` | single-URL sideload | `media_sideload_image` |
| `hive_sync/host/media/preimport_batch` | parallel curl_multi sideload | sequential single-URL fallback |
| `hive_sync/host/product/upsert` | create/update Woo product | `WC_Product_Simple` minimal create |
| `hive_sync/host/conflict/record` | provenance write | no-op |
| `hive_sync/host/conflict/resolve` | per-slice write veto | default-allow all slices |
| `hive_sync/host/selection/resolve` | filter → product_ids[] | empty array (Ids mode still works) |
| `hive_sync/host/source/gs/{fetch,diff,materialize}` | GS legacy delegation | source returns warning |

Contract version is exposed as `HSYNC_HOST_CONTRACT_VERSION = 1`. Bumping this requires updating bound filters in the host bridge.

## Tables

```
wp_hsync_mappings        external→Woo field maps (gs-default ships seeded)
wp_hsync_pipelines       lifecycle compositions (import-default ships seeded)
wp_hsync_rules           scoped pipelines (selection + ops + checks)
wp_hsync_jobs            scheduled or ad-hoc Runnable references
wp_hsync_runs            execution audit (per Runnable invocation)
wp_hsync_checks          saved Check definitions
wp_hsync_source_configs  per-source credential bundles, secret-redacted in UI
```

## Lifecycle

```
Source::fetch
  → Source::diff → buckets (new, update, unchanged)
  → for each item in (new ∪ update):
      → ImportCheck::evaluate(FeedItem)         pre-import gates (block-severity skips item)
      → ImportRule::applyDuringImport()         mutate the draft (media.download, taxonomy.resolve)
      → Source::materialize(draft)              host product/upsert filter
      → Check::evaluate(productId)              post-import gates
```

Cooperative deadline: each tick caps at ~25s. When the deadline is reached, the runner yields with `status: continue` + cursor `{index, run_id}`. The AJAX layer auto-resumes via the JS loop; the WP-Cron tick resumes on the next firing. No mid-item interruptions.

## Security posture

- Every AJAX handler: `check_ajax_referer( 'hsync_nonce', 'nonce' )` + `current_user_can( 'manage_woocommerce' )`.
- All SQL parameterized via `$wpdb->prepare`.
- All user output `esc()`-escaped on the JS side.
- Secrets stored cleartext but redacted (`••••XXXX`) on every read path; a redacted placeholder posted back to save is rejected and the stored value is preserved.
- No `wp_ajax_nopriv_*` — admin only.

## Production checklist

- [x] Activation creates 8 tables + seeds defaults
- [x] Deactivation clears WP-Cron event
- [x] WP-Cron + Action Scheduler fallback for `DISABLE_WP_CRON` environments
- [x] Cooperative deadline + cursor resume on long imports
- [x] Idempotent migration from legacy
- [x] Host filter contract is versioned and standalone-fallback-safe
- [x] All 9 admin tabs functional
- [x] Composer UI handles all 4 step kinds (pre_check / import_rule / check / operation)
- [x] Action Scheduler health utility (purge past-due, run queue)

## Versioning

`HSYNC_VERSION` is the single source of truth (entry point + plugin header). Schema migrations re-run on every version bump via `plugins_loaded` priority 20 → `hsync_migrate_schema()`; the function is `dbDelta`-based so adding columns is a no-op when they already exist.

Contract version (`HSYNC_HOST_CONTRACT_VERSION`) bumps independently when filter signatures change.
