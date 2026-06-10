# Hive Sync

WooCommerce import + sync engine. Standalone WordPress plugin —
installable independently of the rest of the Hive Commerce suite. When
Hive Commerce is active, Hive Sync delegates `materialize` to the legacy
bridge (`hive_sync/host/*` filters) for variant + media sideload
logic; without it falls back to a generic Woo upsert.

## Quickstart for the operator

1. Install + activate the plugin. Defaults are seeded automatically.
2. **Connetti** → "JSON" card → compila URL + token, salva con un nome
   (es. `gs-prod`), premi **Test fetch** per verificare le credenziali.
3. **Mappa** → premi **Aggiorna default** se hai installato prima del
   commit `46dbc00` (porta in-line le ultime modifiche al codice).
4. **Importa** → scegli sorgente / mapping / pipeline → tieni acceso
   *Solo prova* per la prima run, controlla lo Storico, poi togli e
   lancia per davvero.
5. **Automatizza** → quando il primo import a mano è andato bene, accendi
   i 3 job default (`gs-add-new`, `gs-refresh-stocks`, `gs-re-update`).

## Cosa fa, in due righe

Mantiene il catalogo Woo in sync con uno o più feed esterni (JSON o CSV)
con tre stagioni di update:

- **Add new** — crea i nuovi SKU, scarica le foto, assegna varianti
- **Refresh stocks** — aggiorna prezzo + stock per i prodotti già in Woo
  (sub-second per prodotto via fast-patch, no media, no taxonomy)
- **Re-update** — re-import completo per i prodotti dove sono cambiati
  campi non-stock (descrizione, brand, ecc.)

## Architettura — 3 buckets

`Source::diff()` divide ogni fetch in 4 buckets:

| Bucket | Significato | Path |
|---|---|---|
| `new` | SKU non in Woo | Pipeline completa + create |
| `update` | SKU in Woo, campi non-stock cambiati | Pipeline + materialize |
| `updateStock` | SKU in Woo, solo prezzo/stock | Fast-patch direct setters |
| `unchanged` | Nessuna differenza | Skip |

Su un catalog di 5K prodotti con 50 nuovi e 4950 da rinfrescare:
- 50 prodotti × ~3s = 2.5 minuti
- 4950 stock-patches × ~20ms = 100 secondi

Tempo totale ~4 minuti vs ~5 ore per un re-import completo.

## Tab UI (10 tabs)

| # | Tab | Cosa contiene |
|---|---|---|
| 1 | 🔌 Connetti | Saved source-configs (URL + auth) per sorgente |
| 2 | 🗺 Mappa | Mapping editor visuale: spina-Woo + Avanzati + Custom |
| 3 | ⚙ Componi | CRUD pipeline (pre-check / import-rule / post-check) |
| 4 | 🎯 Regole | Operazioni scoped su prodotti esistenti |
| 5 | ▶ Importa | Esecuzione ad-hoc con dry-run |
| 6 | ⏱ Automatizza | Schedule cron + Action Scheduler health |
| — | 🖼 Media | Browser + whitelist + Safe Cleanup orfani |
| — | ⬇ Esporta | Inventario CSV/JSON + catalog by taxonomy |
| — | 📜 Storico | Audit log delle ultime esecuzioni |
| — | ⚠ Strumenti | Nuclear Cleanup (richiede `manage_options`) |

Sopra i tab: header dashboard ("cockpit") con tile live-status:
automazioni attive / ultimo import / catalogo Woo / quick-tick button.
Refresh automatico ogni 30s.

## Sources

Solo **due classi**, ognuna con config flessibile:

- **`JsonSource`** — feed JSON da URL con auth Bearer / Cookie. Knob
  `flavor`:
  - `generic` (default) — 1 row response = 1 product, pass-through alla
    mapping
  - `goldensneakers` — gruppa flat rows per SKU + applica
    `transformToWoo` per la shape varianti del bridge legacy
- **`CsvSource`** — CSV da URL o file locale, supporta `category_filter`
  per i sub-import per subset

## Mappings

Editor visuale con la **schema Woo come spina fissa** (non si tocca).
L'utente sceglie solo cosa farci entrare a destra:

- Sezione **Essenziali** — sku / name / regular_price (richiesti) +
  description / image / categories / brand / stock_quantity
- Sezione **Avanzati** (collapsible) — sale_price / SEO meta /
  gallery_urls / tags / status / manage_stock / short_description
- Sezione **Personalizzati** — chiavi non-standard (meta extra)

Bottoni **Anteprima sorgente** + **Inserisci placeholder** + snippets
predefiniti per template HTML / SEO.

## Pipelines (default seeded)

- **`import-default`** — la pipeline standard: HasRequiredFields +
  HasMediaUrl → DownloadMedia + AutoCategorize + ResolveTaxonomy →
  HasImages + HasCategory
- **`import-sf-with-markup`** — pipeline con MarkupPercent override
  per-job (subset SF / generic CSV con margini diversi)
- **Plus** — set-draft, set-publish, mark-out-of-stock, validate-basics,
  audit-with-blocking

## Operations (registered)

Post-import operations (su productId):
- `status.set`, `pricing.adjust_price`, `stock.set_status`, `stock.set_quantity`

Import-rules (mutate FeedItem.data during import):
- `media.download` — parallel sideload
- `taxonomy.auto_categorize` — Sneakers / Abbigliamento heuristic
- `taxonomy.resolve` — name → term_id with create-if-missing
- `pricing.markup_percent` — % markup with floor + rounding modes

## Default jobs (3, all DISABLED on seed)

| Slug | Cron | Buckets |
|---|---|---|
| `gs-add-new` | `*/30 * * * *` | `[new]` |
| `gs-refresh-stocks` | `*/15 * * * *` | `[updateStock]` |
| `gs-re-update` | `0 */6 * * *` | `[update]` |

Tutti puntano a `runnable_ref = 'json/gs-prod'`. Per altri feed
clona uno di questi e cambia `runnable_ref` + opzioni.

Cron expression vengono tradotte in italiano live nel job editor:
`*/15 * * * *` → "Ogni 15 minuti", `0 2 * * 1-5` → "Da lunedì a
venerdì alle 02:00", ecc.

## Tabelle (8)

```
wp_hsync_mappings        external→Woo field maps
wp_hsync_pipelines       lifecycle compositions
wp_hsync_rules           scoped operations + selection
wp_hsync_jobs            scheduled or ad-hoc Runnable refs
wp_hsync_runs            execution audit (per Runnable invocation)
wp_hsync_checks          saved Check definitions
wp_hsync_source_configs  per-source credential bundles, autoload=false
                         secrets stored cleartext, redacted in UI on read
```

## Host adapter contract

Filter contract that the host (Hive Commerce bridge) can bind. Standalone
fallbacks exist for every filter so the plugin works without GH.

| Filter | Caller | Purpose |
|---|---|---|
| `hive_sync/host/taxonomy/resolve` | ResolveTaxonomy | name → term_id, create when missing |
| `hive_sync/host/media/preimport_batch` | DownloadMedia | parallel curl_multi sideload |
| `hive_sync/host/product/upsert` | JsonSource generic mat. | create/update Woo product |
| `hive_sync/host/source/gs/materialize` | JsonSource gs flavor | bridge to rp_rc_gs_create/update |

`HSYNC_HOST_CONTRACT_VERSION = 1`.

## Standalone vs with Hive Commerce

| Capability | Standalone | + Hive Commerce |
|---|---|---|
| Import JSON / CSV | ✓ | ✓ |
| Variant explosion (GS flavor) | ✓ (built-in transformToWoo) | ✓ (bridge) |
| Parallel media sideload | sequential single-URL fallback | ✓ curl_multi sliding-window |
| Taxonomy auto-create | `wp_insert_term` | ✓ same path via filter |
| Conflict resolution | default-allow | ✓ rule-based |

## Smoke test

1. Mappa → "Aggiorna default"
2. Connetti → JSON card → URL+token → save as `gs-prod` → Test fetch
3. Importa → `json` source / `gs-prod` config / `gs-default` mapping /
   `import-default` pipeline → Solo prova OFF → Importa adesso
4. Verify a few imported products: should be `variable` with
   `pa_taglia` attribute, per-EU-size variants, real stock per variant,
   featured image sideloaded.
5. Storico → run row should show `created: N / stock_patched: M`
6. Automatizza → toggle on i 3 jobs default

## Coexistence with Hive Commerce

Hive Sync is **not** a partial-coexistence module. It's the production
replacement for `golden-hive/includes/feeds/`. The Migrazione tab was
removed in commit `c9398e7` because fresh installs don't need to
import legacy `gh_pipelines` / `gh_jobs` / `gh_mapper_rules` — Hive
Sync starts clean.

The legacy `golden-hive` plugin is still referenced for the
materialize bridge (variant + sideload logic). Eventually that gets
absorbed into hive-sync directly; until then the bridge contract
keeps the dependency one-way and explicit.

## WP-Cron in produzione

Il plugin schedule un evento `hive_sync_jobs_tick` ogni 5 minuti che
fa il dispatch dei job. Il default WordPress fa scattare gli eventi
sul page-load di un visitatore — fragile su siti a basso traffico.

**Setup raccomandato in produzione:**

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```sh
# crontab -e
* * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

Il cockpit header mostra un banner rosso quando l'evento è in
ritardo di oltre 10 minuti — diagnosi rapida quando WP-Cron è broken.

## No junk

Il plugin non scrive file su disk fuori dalla media library WP
standard. Su uninstall (delete dalla schermata Plugin di WP):
`uninstall.php` droppa le 8 tabelle `wp_hsync_*`, cancella le 4
options + 2 transients, e clear-a tutti gli scheduled cron events.

Lo storico run è auto-prunato ogni 24h (default: > 30 giorni o > 5000
record). Il log eliminazioni media è FIFO capped a 500. Tutti i
bottoni di pulizia manuale stanno nei tab Storico / Media /
Strumenti.

## Tech

- PHP 8.1+ — strict types, readonly value objects, `match` expressions
- WordPress 6.0+
- WooCommerce 8.x
- Composer PSR-4 autoload (`HiveSync\` → `src/`)
- WP-Cron 5-minute tick + Action Scheduler fallback
- Vanilla JS (no React, no jQuery, no build step) + CSS scopato a
  `.hsync-wrap`

## Branch

Production work happens on `claude/migrate-hive-sync-features-dkJLJ`.
See `git log --oneline` for the full history; key checkpoints:

- `932cb79` — cockpit dashboard header
- `a77470b` — generic JsonSource + cron-IT translator
- `03d09cb` — JS sums result counters across ticks
- `632e9e4` — GS produces bridge-ready Woo shape
- `2d164ab` — GS aggregates flat rows by SKU
- `300c265` — three-bucket sync architecture

## License

Proprietary.
