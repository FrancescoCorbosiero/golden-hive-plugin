# CLAUDE.md — Hive Sync

> Stai lavorando su **hive-sync**. Root: `/hive-sync/`.
>
> Ordine di lettura obbligatorio:
> 1. Questo file
> 2. `../CONVENTIONS.md` — convenzioni condivise
> 3. `../golden-hive/CLAUDE.md` — solo per capire il bridge legacy che
>    hive-sync chiama via `hive_sync/host/*` filter

---

## Contesto del Plugin

**Hive Sync** è il sostituto produzione-ready dell'import pipeline che
viveva in `golden-hive/includes/feeds/*`. Sostituisce GS / SF / CSV /
KicksDB feeds con una architettura a 3 buckets (new / update / patch)
che riduce drasticamente i tempi di sync. Standalone — funziona senza
Golden Hive — ma quando GH è attivo delega `materialize` al bridge
legacy che ha tutta la logica varianti + sideload media.

Stato attuale (branch `claude/migrate-hive-sync-features-dkJLJ`):

- ✅ Import GS end-to-end con varianti, stock per-size, media sideload
- ✅ 3 job di mantenimento default (add-new / refresh-stocks / re-update)
- ✅ Source generico `JsonSource` con `flavor` knob
- ✅ Mapping editor visuale spina-fissa Woo + sezione Avanzati + Custom
- ✅ Cockpit dashboard header con tile live-status
- ✅ Media management completo (browser, whitelist, safe cleanup)
- ✅ Strumenti / Nuclear Cleanup con typed-confirmation gate

---

## Stack

| Layer | Tecnologia |
|---|---|
| CMS / Commerce | WordPress 6.x + WooCommerce 8.x |
| PHP | 8.1+ (typed properties, `match`, readonly classes) |
| Autoload | Composer PSR-4 `HiveSync\` → `src/` |
| Storage | 8 tabelle dedicate `wp_hsync_*` (no CPT, no options-as-list) |
| Schedule | WP-Cron tick ogni 5 min + Action Scheduler fallback |
| UI | Vanilla JS + CSS scopati sotto `.hsync-wrap` (no React, no jQuery) |

---

## Struttura

```
hive-sync/
├── hive-sync.php             Entry. Activation seeds defaults, plugins_loaded boots.
├── composer.json             PSR-4 HiveSync\ → src/
├── includes/
│   ├── admin-page.php        Cockpit header + 10 tabs + panel HTML shells.
│   ├── ajax.php              ~30 wp_ajax_hsync_* handlers, all guarded.
│   ├── assets.php            Localize ajaxUrl + nonce + version.
│   ├── cron.php              wp_schedule_event('hsync_cron_tick') every 5 min.
│   ├── cron-fallback.php     admin_init throttled fallback (DISABLE_WP_CRON).
│   ├── host-adapter.php      Filter contract → bridge in golden-hive.
│   ├── migrate.php           dbDelta schema + GS→JSON one-shot data migration.
│   └── registrations.php     Self-register sources / operations / checks on boot.
├── src/
│   ├── Core/
│   │   ├── Bootstrap.php             Wires registries on hive_sync/core_booted.
│   │   ├── Source/                   Source contract + value objects.
│   │   ├── Operation/                Operation + ImportRule interfaces + registry.
│   │   ├── Check/                    Check + ImportCheck + registries.
│   │   ├── Pipeline/                 Pipeline + Step + Repository + Executor.
│   │   ├── Selection/                Selection mode (Ids / Filter / All).
│   │   └── Repo/                     DAOs over wp_hsync_* tables.
│   ├── Sources/
│   │   ├── JsonSource.php            ★ Generic JSON feed with `flavor` knob:
│   │   │                               'generic' = 1 row = 1 product
│   │   │                               'goldensneakers' = group by SKU + transform
│   │   ├── CsvSource.php             URL or local-file CSV w/ category_filter.
│   │   └── StockOnlyClassifier.php   Splits update bucket → updateFull/updateStock.
│   ├── Operations/
│   │   ├── Status/SetStatus.php
│   │   ├── Pricing/AdjustPrice.php  + MarkupPercent.php (ImportRule)
│   │   ├── Stock/SetStockStatus.php + SetStockQuantity.php
│   │   ├── Media/DownloadMedia.php           ImportRule, parallel sideload via host.
│   │   └── Taxonomy/AutoCategorize.php       ImportRule, sneakers/abbigliamento heuristic.
│   │       /ResolveTaxonomy.php              ImportRule, name → term_id w/ create.
│   ├── Checks/
│   │   ├── Import/HasRequiredFields.php  + HasMediaUrl.php   (pre-import, FeedItem)
│   │   ├── Media/HasImages.php           (post-import, productId)
│   │   └── Taxonomy/HasCategory.php      (post-import, productId)
│   ├── Media/                              Media management module
│   │   ├── Whitelist.php                   Protezione attachment dall'eliminazione.
│   │   ├── UsageIndex.php                  Reverse map attachment → [{pid,role}], 10m cache.
│   │   ├── Browser.php                     Paginated query w/ filters + Safe Cleanup preview.
│   │   ├── Cleaner.php                     Bulk delete con whitelist + log FIFO 500.
│   │   └── Library.php                     set featured / gallery + reverse "used by".
│   ├── Tools/
│   │   └── NuclearCleanup.php              SQL-direct cleanup (products, media, tax, transients, orphans).
│   └── Workflow/
│       ├── Run/ImportRunner.php            ★ Orchestratore con buckets[new/update/updateStock]
│       │                                     + fast-stock-patch path + cooperative deadline.
│       ├── Schedule/CronExpr.php           Parser 5-field cron, no shortcuts.
│       ├── Schedule/JobRunner.php          Dispatcher tick. Resolves mapping_slug → config.
│       ├── Mapping/PathResolver.php        Dot-path traversal ('sizes.size_eu').
│       ├── Mapping/Template.php            {placeholder} substitution.
│       ├── Migration/LegacyImporter.php    Dormant — Migrazione tab removed.
│       ├── Export/Exporter.php             Inventory CSV/JSON + catalog-by-taxonomy.
│       └── Seed/Defaults.php               ★ Seeds 2 mappings + 7 pipelines + 3 default jobs.
└── assets/
    ├── css/admin.css                       Cockpit styling, sticky tabs, HUD stats.
    └── js/admin.js                         Single HSync namespace, no jQuery, ~2700 LOC.
```

★ = files where most of the architecture lives.

---

## Pipeline lifecycle

Ogni Run passa per:

```
Source::fetch
  → Source::diff             { new, update, updateStock, unchanged }
  → ImportRunner loop:
      per ciascun item nel processing pool (filtrato da options.buckets):
      ├─ if bucket === 'updateStock':
      │   └─ ImportRunner::fastStockPatch (no pipeline, no materialize)
      │       set_regular_price + set_sale_price + set_stock_quantity + save
      └─ else:
          ├─ pipeline.preCheckSteps  (block-severity skips item)
          ├─ pipeline.importRuleSteps (mutate the FeedItem.data draft)
          │   - markup_percent (con override per-job)
          │   - media.download
          │   - taxonomy.auto_categorize
          │   - taxonomy.resolve
          ├─ Source::materialize  (delegated to host bridge for GS)
          └─ pipeline.checkSteps     (productId-scoped, post-create)
```

`options.buckets = ['new'|'update'|'updateStock']` filtra cosa entra
nel pool. Così un job "refresh-stocks" processa SOLO updateStock con
il fast-patch path. È la chiave perf-critica del sistema.

---

## Buckets — la chiave architetturale

Il `Diff` ha 4 buckets:

| Bucket | Significato | Path |
|---|---|---|
| `new` | SKU non esiste in Woo | Pipeline completa + create |
| `update` | SKU esiste, campi non-stock cambiati | Pipeline completa + materialize |
| `updateStock` | SKU esiste, SOLO prezzo/stock cambiati | Fast-patch (no pipeline, no media) |
| `unchanged` | Nessuna differenza | Skip totale |

`StockOnlyClassifier::split()` decide tra `update` e `updateStock`
confrontando ogni campo non-stock incoming vs il prodotto Woo
esistente. Se TUTTI i campi non-stock combaciano → `updateStock`.

Un import "rinfresca tutto" tipico su un catalog di 5K prodotti:
- ~20 `new` → pipeline completa, ~2-4s ciascuno
- ~30 `update` → pipeline completa
- ~4900 `updateStock` → fast-patch, ~10-30ms ciascuno

Tempo totale: 2-3 minuti invece di 4-5 ore.

---

## JsonSource — `flavor` knob

```php
configSchema():
  - url             (required)
  - token           (Bearer, optional)
  - cookie          (optional)
  - flavor          enum: 'generic' | 'goldensneakers'
                    DEFAULT: 'generic'
```

Comportamento per flavor:

| Flavor | Fetch | Materialize |
|---|---|---|
| `generic` | 1 row response = 1 product, pass-through to mapping | `hsync_upsert_product` host adapter |
| `goldensneakers` | Group flat rows by SKU, run `transformToWoo()` | Bridge `hsync_gs_materialize` (varianti + sideload) |

GS-specific code (transformToWoo, _gs_brand, EU size pattern) gira
SOLO quando `flavor=goldensneakers`. Per altri JSON feed → `flavor=generic`
e l'utente mappa i campi liberamente.

Migration `hsync_migrate_gs_to_json()` ha già spostato tutti i
`source_kind='goldensneakers'` esistenti a `'json'` con
`config.flavor='goldensneakers'`. Idempotente, runs once.

---

## Default jobs (3 jobs)

Tutti seeded DISABLED. L'operatore configura `json/gs-prod`
nel tab Connetti, poi accende i job in Automatizza.

| Slug | Cron | Buckets | Scopo |
|---|---|---|---|
| `gs-add-new` | `*/30 * * * *` | `[new]` | Crea i nuovi SKU. Pipeline completa. |
| `gs-refresh-stocks` | `*/15 * * * *` | `[updateStock]` | Fast-patch prezzo+stock. ~sub-second per prodotto. |
| `gs-re-update` | `0 */6 * * *` | `[update]` | Re-import quando cambiano descrizioni / brand / etc. |

`runnable_ref = 'json/<config_slug>'`. JobRunner risolve
`options.mapping_slug` → mapping config a dispatch time.

Per altri feed (SF, custom JSON), l'operatore clona uno di questi
in UI e cambia `runnable_ref` + `options.buckets`. Stesso modello.

---

## Tab UI (10 tabs)

Numerati 1-6 per il workflow primario, poi 4 utility:

| # | Tab | data-tab | Scopo |
|---|---|---|---|
| 1 | 🔌 Connetti | `sources` | Saved source-configs (URL + auth) per sorgente |
| 2 | 🗺 Mappa | `mappings` | Visual mapping spina-Woo + Avanzati + Custom |
| 3 | ⚙ Componi | `pipelines` | CRUD pipeline (pre-check / import-rule / post-check) |
| 4 | 🎯 Regole | `rules` | Scoped operations su prodotti esistenti |
| 5 | ▶ Importa | `run` | Esecuzione ad-hoc con dry-run |
| 6 | ⏱ Automatizza | `jobs` | Schedule cron + Action Scheduler health |
| — | 🖼 Media | `media` | Browser + Safe Cleanup orfani |
| — | ⬇ Esporta | `exports` | Inventario CSV/JSON + catalog by taxonomy |
| — | 📜 Storico | `runs` | Audit log delle ultime esecuzioni |
| — | ⚠ Strumenti | `tools` | Nuclear Cleanup (manage_options gate) |

---

## Cockpit header

Live-status strip sopra i tab. AJAX `hsync_ajax_cockpit_status`
ritorna in una call:

- Jobs: `enabled / total`
- Last run: `created + updated + patched` con timestamp e `failed` callout
- Catalog: `count(post_type=product, status NOT IN trash/auto-draft)`

Refresh automatico ogni 30s. Bottone "Esegui ciclo automatizzazioni"
forza un dispatch immediato senza attendere il cron.

---

## Tabelle (8)

```
wp_hsync_mappings        external→Woo field maps
wp_hsync_pipelines       lifecycle compositions
wp_hsync_rules           scoped operations + selection
wp_hsync_jobs            scheduled or ad-hoc Runnable refs
wp_hsync_runs            execution audit (per Runnable invocation)
wp_hsync_checks          saved Check definitions
wp_hsync_source_configs  per-source credential bundles, secrets cleartext
                         (autoload=false), redacted in UI on read
```

Nessuna option-as-list. Tutto in tabelle dedicate con index +
slug-or-id addressing.

---

## Host adapter contract

Filtri che il bridge in golden-hive può binding-are. Default = stub /
no-op quando il bridge non è caricato (usabile standalone).

| Filter | Chi lo chiama | Cosa fa |
|---|---|---|
| `hive_sync/host/taxonomy/resolve` | ResolveTaxonomy | name → term_id, crea se manca |
| `hive_sync/host/media/preimport_batch` | DownloadMedia | parallel curl_multi sideload |
| `hive_sync/host/product/upsert` | JsonSource generic mat. | create/update Woo product |
| `hive_sync/host/source/gs/materialize` | JsonSource gs mat. | rp_rc_gs_create/update_product |
| `hive_sync/host/conflict/resolve` | (TODO wired) | per-slice write veto |

Contract version: `HSYNC_HOST_CONTRACT_VERSION = 1`.

---

## WP-Cron in produzione (CRITICAL)

L'intera catena di automazione (ogni job, refresh stocks, cleanup
periodico) gira su `wp_schedule_event` con hook `hive_sync_jobs_tick`
ogni 5 minuti. **Il default WordPress** fa scattare gli eventi cron
sul page-load di un visitatore — fragile su siti a basso traffico,
spesso la causa di "i job non partono".

**Setup raccomandato in produzione:**

1. In `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
2. Cron di sistema (Linux) — `crontab -e`:
   ```
   * * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
   ```
   (o `wp cron event run --due-now` via WP-CLI se presente.)

Senza questo setup, gli eventi possono restare "in coda" finché un
admin non visita una pagina del sito. Il cockpit header espone uno
status banner rosso quando `hive_sync_jobs_tick` è in ritardo di
oltre 10 minuti — cattura i casi in cui WP-Cron è broken.

L'AJAX `hsync_ajax_system_status` ritorna i dati grezzi
(`next_tick_at`, `disable_wp_cron`, `recommended_crontab`, `overdue`)
per debug rapido.

## Pulizia + lifecycle (no junk)

Il plugin è progettato per non lasciare tracce su disk/DB se si
disinstalla. Cosa scrive e come si pulisce:

| Cosa | Dove | Pulizia |
|---|---|---|
| 8 tabelle `wp_hsync_*` | DB | DROP in `uninstall.php` |
| 4 options (`hsync_db_version`, `hsync_migrated_gs_to_json`, `hsync_media_whitelist`, `hsync_media_deletion_log`) | `wp_options` | `delete_option` in `uninstall.php` |
| 2 transients (usage index, tick lock) | `wp_options` (transient_*) | `delete_transient` in `uninstall.php` + deactivation |
| `hsync_runs_pruned_today` | transient | Auto-expires DAY_IN_SECONDS |
| WP-Cron event `hive_sync_jobs_tick` | `cron` option | `wp_clear_scheduled_hook` in deactivation + uninstall |
| File su disk | nessuno | n/a — non scriviamo file standalone (le immagini sideloaded vivono nella WP media library standard) |

**Crescita controllata:**

- `wp_hsync_runs` viene auto-prunata ogni 24h dal cron tick:
  - `hsync_runs_retention_days` (default 30) — DELETE finished runs older than N days
  - `hsync_runs_keep_max` (default 5000) — safety cap, trim a tutti tranne i più recenti N
- `hsync_media_deletion_log` capped FIFO a 500 entries da `Cleaner::LOG_MAX`
- Action Scheduler (se Woo lo usa) ha la propria gestione retention separata — vedi tab Automatizza → Stato del motore

**Bottoni di pulizia manuale (UI):**

| Tab | Bottone | Effetto |
|---|---|---|
| Storico | Cancella più vecchi di 7/30 giorni | `runs_purge_older` AJAX |
| Storico | Cancella tutto lo storico | `runs_purge_all` AJAX |
| Media | Svuota log eliminazioni | `media_log_clear` AJAX (delete_option) |
| Automatizza | Stato del motore → Purge past-due | Action Scheduler retention |
| Strumenti | Nuclear Cleanup | Bulk delete per scelta operatore |

## Idempotency / migration markers

| Option | Scope | Reset condition |
|---|---|---|
| `hsync_db_version` | Schema migration tracker | `!== HSYNC_VERSION` runs `hsync_migrate_schema()` |
| `hsync_migrated_gs_to_json` | One-shot data migration done | Set to `'done'`, never re-runs |

Defaults seeder (`Defaults::install()`) is **additive idempotent** by
default — won't overwrite existing slugs. Pass `force=true` to
overwrite (UI: "Aggiorna default" button in Mappa tab).

---

## Convenzioni

1. **PSR-4** stretto: file path = class FQN. `src/Sources/JsonSource.php` → `HiveSync\Sources\JsonSource`.
2. **AJAX nonce**: `hsync_nonce`. `hsync_ajax_guard()` enforces it + `manage_woocommerce`. Strumenti tab usa `hsync_ajax_admin_guard()` con `manage_options`.
3. **Strict types** ovunque (`declare(strict_types=1);`).
4. **Readonly classes** per value objects (FeedItem, Diff, MaterializeResult, ...).
5. **ID enum** sulle Operation / Check classes (`public const ID = 'taxonomy.resolve'`) — registrate via `Bootstrap::$operations->register(new ...)`.
6. **CSS scopato sotto `.hsync-wrap`** — niente regole globali.
7. **JS estende `HSync.*`** — no jQuery, no global window pollution oltre `HSync` + `HSyncBoot`.

---

## Smoke test (after fresh install)

1. Mappa → "Aggiorna default" — pulls latest seeded mappings/pipelines/jobs.
2. Connetti → JSON card → compila URL+token, salva come `gs-prod`, premi "Test fetch".
3. Importa → seleziona `json` source, `gs-prod` config, `gs-default` mapping, `import-default` pipeline → uncheck Solo prova → "Importa adesso".
4. Verify: prodotti creati come `variable` con `pa_taglia` + variants per ogni size + stock per variant + media sideloaded.
5. Storico → vedi run con `created: N / stock_patched: M` reconciliation.
6. Automatizza → toggle on i 3 default jobs.

---

## Branch + commits

Lavoro produzione: branch `claude/migrate-hive-sync-features-dkJLJ`.
Commit atomici con messaggio che spiega *why* non solo *what*. Ogni
PR/merge passa per main attraverso review umano.

Storico commits significativi (più recenti in cima):

- `932cb79` — cockpit header + sticky tabs + HUD stats
- `a77470b` — generic JsonSource + cron-IT translator
- `03d09cb` — JS sums result counters across ticks
- `632e9e4` — GS produces bridge-ready Woo shape (variants + stock)
- `2d164ab` — GS aggregates flat rows by SKU
- `46dbc00` — Auto-categorize Sneakers / Abbigliamento
- `300c265` — three-bucket sync (new / refresh / re-update)
- `13d8bde` — credential save in Sources tab + visual mapping editor + nuclear cleanup
- `ab88bbf` — media management + tab intros + skip-reason fix

`git log --oneline claude/migrate-hive-sync-features-dkJLJ` per il
log completo.

---

## What NOT to add

- Migrazione tab UI (rimossa — il plugin sostituisce GH, non coesiste).
- Visual variant editor nel mapping (i variants sono flat-by-design,
  uscire dalla path `pa_taglia ← sizes.size_eu` rompe la semplicità).
- Per-variant pricing API custom (la materialize del bridge legacy lo
  copre già; aggiungerlo qui duplicherebbe logica).
- Multi-tenant / multi-site config (il plugin gira dentro UN sito
  brandizzato — same posture as golden-hive).
- React/Vue (vanilla JS è sufficiente, build step è zero).
