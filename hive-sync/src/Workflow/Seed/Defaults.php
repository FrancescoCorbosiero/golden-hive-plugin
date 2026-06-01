<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Seed;

use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Pipeline\PipelineStep;
use HiveSync\Core\Pipeline\PipelineStepKind;
use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\MappingRepository;
use HiveSync\Core\Repo\RuleRepository;

/**
 * Idempotent installer for default Mappings + Pipelines so a fresh
 * Hive Sync activation isn't a wall of empty tabs.
 *
 * Each entity is keyed by a stable slug; install() checks for an
 * existing row before inserting. Re-running install() never duplicates
 * and never overwrites user edits.
 *
 * Rules are intentionally NOT seeded — they touch existing products
 * (status changes, taxonomy assignments) and shipping defaults could
 * surprise the operator. The user composes them deliberately when
 * they're ready.
 */
final class Defaults
{
    public function __construct(
        private readonly MappingRepository $mappings,
        private readonly PipelineRepository $pipelines,
        private readonly ?JobRepository $jobs = null,
        private readonly ?RuleRepository $rules = null,
    ) {}

    /**
     * @param bool $force  When true, overwrite existing mappings/pipelines
     *                     with the same slug so code-level changes ship to
     *                     installs that already ran the activation seeder.
     *                     User-edited entities are clobbered — call only
     *                     when the operator explicitly asks for it.
     * @return array{mappings: int, pipelines: int}  counts of inserted+updated rows
     */
    public function install( bool $force = false ): array
    {
        return [
            'mappings'  => $this->seedMappings( $force ),
            'pipelines' => $this->seedPipelines( $force ),
            'jobs'      => $this->jobs ? $this->seedJobs( $force ) : 0,
            'rules'     => $this->rules ? $this->seedRules( $force ) : 0,
        ];
    }

    // ─── Mappings ─────────────────────────────────────────────────

    private function seedMappings( bool $force ): int
    {
        $touched = 0;
        foreach ( self::defaultMappings() as $m ) {
            $exists = $this->mappings->find( $m['slug'] ) !== null;
            if ( $exists && ! $force ) continue;
            $this->mappings->save( $m );
            $touched++;
        }
        if ( $force ) {
            foreach ( self::obsoleteSeedSlugs()['mappings'] as $slug ) {
                if ( $this->mappings->find( $slug ) !== null ) {
                    $this->mappings->delete( $slug );
                    $touched++;
                }
            }
        }
        return $touched;
    }

    /**
     * @return array<int, array{slug:string, name:string, source_kind:string, config:array}>
     */
    public static function defaultMappings(): array
    {
        return [
            [
                'slug'        => 'gs-default',
                'name'        => 'Golden Sneakers — default',
                'source_kind' => 'json',
                // Field names mirror the actual GS API payload — the
                // upstream ships ONE ROW PER (SKU + size) with these
                // exact keys. GoldenSneakersSource::aggregateFlatRows
                // groups rows by SKU into a single product, exposing
                // both the original product-level fields (product_name,
                // image_full_url, ...) and the synthesized ones
                // (sizes[], summary_qty, stock_status).
                'config'      => [
                    'sku'            => 'sku',
                    'name'           => 'product_name',
                    'regular_price'  => 'presented_price',
                    'sale_price'     => 'offer_price',
                    // summary_qty + stock_status are derived during
                    // aggregation (sum of available_quantity across
                    // all sizes; stock_status follows from > 0).
                    'stock_quantity' => 'summary_qty',
                    'stock_status'   => 'stock_status',
                    'manage_stock'   => 'manage_stock',
                    // Single product image — gallery isn't shipped
                    // separately by the GS API.
                    'image_url'      => 'image_full_url',
                    'featured_image' => 'image_full_url',
                    'brand'          => 'brand_name',
                    // Multi-value path: returns the list of EU sizes
                    // — the materialize step expands them into Woo
                    // variations automatically.
                    'pa_taglia'      => 'sizes.size_eu',
                    'size_mapper'    => 'size_mapper_name',
                    // Global attribute taxonomies — AttributeMerger
                    // promotes these into $woo['attributes'][pa_*],
                    // ResolveTaxonomy creates the term + the global
                    // attribute on first import. GS exposes brand /
                    // product name; gender / color / material are
                    // not in the GS payload, so we leave them blank
                    // for the operator to wire up if upstream adds
                    // them.
                    'pa_brand'       => 'brand_name',
                    'pa_model'       => 'product_name',

                    // ── SEO meta templates ──────────────────────
                    // GS upstream ships NO SEO content, so products
                    // landed with blank Rank Math meta_title/description.
                    // These templates give new GS products a baseline
                    // built from name + brand. Written ONLY on CREATE
                    // (gh_apply_product_meta runs on the factory create
                    // path; rp_rc_gs_update_product never touches SEO) so
                    // existing products are never clobbered.
                    //
                    // NOTE: description / short_description are
                    // deliberately NOT templated here. They are part of
                    // StockOnlyClassifier::COMPARABLE_FIELDS, so a GS
                    // template that mismatches an existing KicksDB-rich
                    // description would route that product into the slow
                    // full-update bucket on every sync. Rich descriptions
                    // are KicksDB's job (it owns the `catalog` slice);
                    // meta_title/description are not compared, so they're
                    // free to template.
                    'meta_title'        => '{name} | {brand_name}',
                    'meta_description'  => 'Acquista {name} di {brand_name}. Sneakers originali al 100%, spedizione rapida in tutta Italia e reso facile entro 14 giorni.',
                ],
            ],
            [
                'slug'        => 'sf-default',
                'name'        => 'StockFirmati — default (con template descrizioni)',
                'source_kind' => 'csv',
                // The SF flavor (CsvSource::sfTransformToWoo) already
                // populates sku / name / prices / stock / variations /
                // _sf_brand / _sf_category / _sf_images at fetch time.
                // This mapping carries those values through (so the
                // spine's required-fields validator passes) AND adds
                // template-driven enrichment for description + SEO
                // fields. Mirrors the field-by-field assignments the
                // legacy gh_sf_apply chain made via assign_brand /
                // assign_category / sideload_images.
                'config'      => [
                    // ── Spine pass-throughs ─────────────────────
                    'sku'              => 'sku',
                    'name'             => 'name',
                    'regular_price'    => 'regular_price',
                    'sale_price'       => 'sale_price',
                    'stock_quantity'   => 'stock_quantity',
                    'stock_status'     => 'stock_status',
                    'manage_stock'     => 'manage_stock',

                    // ── Taxonomy + media overlay ────────────────
                    // taxonomy.resolve reads `brand` / `categories`
                    // / `gallery_urls` from the draft and creates
                    // missing terms; media.download reads
                    // `gallery_urls` for sideload. We point those
                    // at the _sf_* meta the flavor produces.
                    'brand'            => '_sf_brand',
                    'categories'       => '_sf_category',
                    'gallery_urls'     => '_sf_images',

                    // ── Global product attributes (pa_*) ────────
                    // Promoted by AttributeMerger into
                    // $woo['attributes'][pa_*] post-mapping, then
                    // resolved into terms (with auto-create of the
                    // global attribute taxonomy itself) by
                    // taxonomy.resolve. SF exposes a richer set
                    // than GS — sex/color/material/subcategory all
                    // come through the PRODUCT row.
                    'pa_brand'         => '_sf_brand',
                    'pa_model'         => 'name',
                    'pa_gender'        => '_sf_sex',
                    'pa_color'         => '_sf_color',
                    'pa_material'      => '_sf_material',

                    // ── SEO / description templates ─────────────
                    // Tweak per-deployment in the mapping editor.
                    // {_sf_color} / {_sf_material} can be empty
                    // strings when SF doesn't ship them — the
                    // template engine substitutes '' silently.
                    'short_description' => '{_sf_brand} {name}',
                    'description'       => '<p><strong>{_sf_brand} {name}</strong> — colore {_sf_color}, materiale {_sf_material}.</p>',
                    'meta_title'        => '{name} | {_sf_brand}',
                    'meta_description'  => 'Acquista {name} di {_sf_brand}. Colore {_sf_color}, materiale {_sf_material}. Spedizione veloce.',
                ],
            ],
        ];
    }

    // ─── Pipelines ────────────────────────────────────────────────

    private function seedPipelines( bool $force ): int
    {
        $touched = 0;
        foreach ( self::defaultPipelines() as $def ) {
            $exists = $this->pipelines->find( $def['slug'] ) !== null;
            if ( $exists && ! $force ) continue;
            $steps = [];
            foreach ( $def['steps'] as $s ) {
                $kind = PipelineStepKind::tryFrom( (string) $s['kind'] ) ?? PipelineStepKind::Operation;
                $steps[] = new PipelineStep(
                    kind: $kind,
                    refId: (string) $s['ref_id'],
                    params: (array) ( $s['params'] ?? [] ),
                    note: isset( $s['note'] ) ? (string) $s['note'] : null,
                );
            }
            $this->pipelines->save( new Pipeline(
                id: (string) $def['slug'],
                name: (string) $def['name'],
                steps: $steps,
            ) );
            $touched++;
        }
        if ( $force ) {
            foreach ( self::obsoleteSeedSlugs()['pipelines'] as $slug ) {
                if ( $this->pipelines->find( $slug ) !== null ) {
                    $this->pipelines->delete( $slug );
                    $touched++;
                }
            }
        }
        return $touched;
    }

    /**
     * Hard-coded list of slugs that USED to ship as defaults but no
     * longer do. Pruned on force=true so an operator clicking
     * "Reinstalla (sovrascrivi)" gets a clean install. Slugs added
     * here must match the historical default slug exactly — they're
     * matched by literal find() lookup, so a user's mapping with the
     * same slug WOULD be deleted (which is the trade-off of using
     * slug-as-identity for the seeded set).
     *
     * Why not auto-derive: mappings/pipelines have no _seed_id marker
     * (unlike jobs) so the seeder can't distinguish a stale default
     * from a user clone. Explicit list is the safest knob.
     *
     * @return array{mappings: array<int, string>, pipelines: array<int, string>}
     */
    public static function obsoleteSeedSlugs(): array
    {
        return [
            'mappings'  => [
                'csv-minimal',           // collapsed: sf-default covers CSV needs
            ],
            'pipelines' => [
                'import-sf-with-markup', // double-applied markup; markup now lives on source-config
            ],
        ];
    }

    /**
     * Each entry is a complete pipeline composition. Slugs are stable
     * identifiers — changing them in code on a deployed install would
     * break references from saved Jobs. Treat as append-only.
     *
     * @return array<int, array{slug:string, name:string, steps: array<int, array>}>
     */
    public static function defaultPipelines(): array
    {
        return [
            [
                'slug'  => 'import-default',
                'name'  => 'Import — pre-checks + media download + taxonomy resolve',
                'steps' => [
                    [
                        'kind'   => 'pre_check',
                        'ref_id' => 'import.has_required_fields',
                        'params' => [ 'fields' => 'sku,name', 'severity' => 'block' ],
                        'note'   => 'Skip rows missing sku or name',
                    ],
                    [
                        'kind'   => 'pre_check',
                        'ref_id' => 'import.has_media_url',
                        'params' => [ 'severity' => 'warn' ],
                        'note'   => 'Flag rows without any media URL — does not block',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'media.download',
                        'params' => [ 'concurrency' => 24, 'skip_if_set' => true ],
                        'note'   => 'Parallel curl_multi sideload (host adapter). Bumped from 10→24 — media is the dominant per-item cost; concurrency is network-bound and most VPS configurations have headroom. Bridge caps at 32; raise via the pipeline editor if your network can sustain it.',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'taxonomy.auto_categorize',
                        'params' => [
                            'sneakers_label' => 'Sneakers',
                            'apparel_label'  => 'Abbigliamento',
                            'override'       => false,
                        ],
                        // No-op when the feed mapping already populates
                        // `categories` (SF maps _sf_category → categories,
                        // so this only fires for GS where the feed is
                        // category-less). Heuristic: alpha sizes → apparel,
                        // numeric sizes → sneakers, keyword fallback on
                        // product name. Drop this step from the pipeline
                        // entirely if you import only feeds that ship
                        // category data.
                        'note'   => 'Fallback GS: classifica Sneakers/Abbigliamento. Salta automaticamente se il feed ha già categories (es. SF).',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'taxonomy.resolve',
                        'params' => [ 'create_missing' => true ],
                        // Universal step: reads the draft's `categories`
                        // / `brand` / `tags` / `pa_*` and creates the
                        // missing terms (and global attribute
                        // taxonomies, when needed). Fires for every feed.
                        'note'   => 'Universale: risolve categorie / brand / pa_* in term IDs e crea i mancanti. Sempre attivo.',
                    ],
                    [
                        'kind'   => 'check',
                        'ref_id' => 'media.has_images',
                        'params' => [ 'min' => 1, 'severity' => 'warn' ],
                    ],
                    [
                        'kind'   => 'check',
                        'ref_id' => 'taxonomy.has_category',
                        'params' => [ 'taxonomy' => 'product_cat', 'min' => 1, 'severity' => 'warn' ],
                    ],
                ],
            ],
            [
                'slug'  => 'validate-basics',
                'name'  => 'Validate basics — has image + has category',
                'steps' => [
                    [
                        'kind'   => 'check',
                        'ref_id' => 'media.has_images',
                        'params' => [ 'min' => 1, 'severity' => 'warn' ],
                        'note'   => 'Flag products without a featured/gallery image',
                    ],
                    [
                        'kind'   => 'check',
                        'ref_id' => 'taxonomy.has_category',
                        'params' => [ 'taxonomy' => 'product_cat', 'min' => 1, 'severity' => 'warn' ],
                        'note'   => 'Flag products without a product_cat assignment',
                    ],
                ],
            ],
            [
                'slug'  => 'set-draft',
                'name'  => 'Set status: draft',
                'steps' => [
                    [
                        'kind'   => 'operation',
                        'ref_id' => 'status.set',
                        'params' => [ 'status' => 'draft' ],
                    ],
                ],
            ],
            [
                'slug'  => 'set-publish',
                'name'  => 'Set status: publish',
                'steps' => [
                    [
                        'kind'   => 'operation',
                        'ref_id' => 'status.set',
                        'params' => [ 'status' => 'publish' ],
                    ],
                ],
            ],
            [
                'slug'  => 'mark-out-of-stock',
                'name'  => 'Mark out of stock',
                'steps' => [
                    [
                        'kind'   => 'operation',
                        'ref_id' => 'stock.set_status',
                        'params' => [ 'stock_status' => 'outofstock' ],
                    ],
                ],
            ],
            [
                'slug'  => 'audit-with-blocking',
                'name'  => 'Audit (blocking) — image + category required',
                'steps' => [
                    [
                        'kind'   => 'check',
                        'ref_id' => 'media.has_images',
                        'params' => [ 'min' => 1, 'severity' => 'block' ],
                        'note'   => 'Halt downstream ops if no image',
                    ],
                    [
                        'kind'   => 'check',
                        'ref_id' => 'taxonomy.has_category',
                        'params' => [ 'taxonomy' => 'product_cat', 'min' => 1, 'severity' => 'block' ],
                    ],
                ],
            ],
        ];
    }

    // ─── Jobs ─────────────────────────────────────────────────────
    //
    // Jobs are addressed by integer id, not slug. To make seeding
    // idempotent we tag each seeded job with `config._seed_id` and
    // skip on re-seed when a row already carries that marker. With
    // force=true existing seeded jobs are updated in place AND any
    // seeded job whose _seed_id is no longer in the canonical
    // defaultJobs() lineup is DELETED — that's how the operator
    // transitions from an old lineup to a new one with one click on
    // "Reinstalla (sovrascrivi)". User jobs (no _seed_id marker) are
    // never touched.

    private function seedJobs( bool $force ): int
    {
        if ( ! $this->jobs ) return 0;
        $existing = $this->jobs->all();
        $existingBySeed = [];
        foreach ( $existing as $row ) {
            $sid = (string) ( ( $row['config']['_seed_id'] ?? '' ) );
            if ( $sid !== '' ) $existingBySeed[ $sid ] = (int) $row['id'];
        }

        $defaults = self::defaultJobs();
        $defaultSeedIds = array_map( static fn( array $d ): string => (string) $d['_seed_id'], $defaults );

        $touched = 0;
        foreach ( $defaults as $def ) {
            $seedId = (string) $def['_seed_id'];
            $isExisting = isset( $existingBySeed[ $seedId ] );
            if ( $isExisting && ! $force ) continue;

            $config = (array) $def['config'];
            $config['_seed_id'] = $seedId;
            $config['_seed_label'] = (string) $def['label'];

            $payload = [
                'runnable_type' => (string) $def['runnable_type'],
                'runnable_ref'  => (string) $def['runnable_ref'],
                // Empty cron → null (ad-hoc only, dispatcher won't tick).
                'cron_expr'     => isset( $def['cron'] ) ? (string) $def['cron'] : '',
                // Seeded jobs ship DISABLED — the operator wires up
                // credentials and turns them on intentionally.
                'enabled'       => false,
                'config'        => $config,
            ];
            if ( $isExisting ) {
                $payload['id'] = $existingBySeed[ $seedId ];
            }
            $this->jobs->save( $payload );
            $touched++;
        }

        // Force-mode prune: delete seeded jobs whose _seed_id is no
        // longer in the canonical lineup. Without this, switching from
        // a 6-job lineup to a 4-job lineup would leave 2 zombies in
        // the cockpit. User-created jobs (no _seed_id) stay intact.
        if ( $force ) {
            foreach ( $existingBySeed as $seedId => $jobId ) {
                if ( ! in_array( $seedId, $defaultSeedIds, true ) ) {
                    $this->jobs->delete( $jobId );
                    $touched++;
                }
            }
        }
        return $touched;
    }

    /**
     * Default job lineup — ONE per source. Each job is fully
     * idempotent and self-rebalancing:
     *
     *   - The source's bucket diff is computed fresh every tick.
     *     First run on an empty catalog → everything in `new`.
     *     Subsequent runs → mostly noop, some `updateStock` (price
     *     or stock moved), occasional `update` (image swap, brand
     *     rename, new attribute). Same job, same code path —
     *     different shape based on state.
     *
     *   - The pipeline (`import-default`) is idempotent by design:
     *     media.download has skip_if_set=true, taxonomy.auto_categorize
     *     has override=false, taxonomy.resolve uses create_missing.
     *     Re-running on a settled SKU is a near-noop.
     *
     *   - Markup is preserved automatically: JsonSource/CsvSource bake
     *     markup_percent + markup_rules in at materialize time, BEFORE
     *     the bucket diff. Neither the fast-stock-patch path nor the
     *     full pipeline strips or re-applies it downstream.
     *
     *   - Long first-runs are safe: ImportRunner's cooperative 25s
     *     deadline + cursor resume let Action Scheduler chunk a
     *     thousand-SKU first-import across multiple ticks until it
     *     settles into steady-state (then ticks become near-instant).
     *
     * Why one job not two: an ad-hoc "first-time import" + cron
     * "maintenance" split misses NEW SKUs added by the supplier
     * between manual triggers, and forces the operator to remember
     * a manual step. Editorial control over new products is solved
     * by `import_status: draft` on the source-config — products land
     * as drafts and the operator publishes on their schedule.
     *
     * Operator can still run on-demand via "Esegui adesso" on the
     * job panel (e.g. to test after wiring credentials). Cron just
     * automates what the manual button does.
     *
     * runnable_ref points at a saved source-config the operator wires
     * up in the Connetti tab. Jobs ship DISABLED so missing configs
     * aren't a runtime hazard until the operator turns them on.
     *
     * @return array<int, array{_seed_id:string, label:string, runnable_type:string, runnable_ref:string, cron:string, config:array}>
     */
    public static function defaultJobs(): array
    {
        // No `buckets` option → ImportRunner defaults to processing
        // all three (`new`, `update`, `updateStock`) every tick.
        $syncOptions = static fn( string $mapping ): array => [
            'mapping_slug'  => $mapping,
            'pipeline_slug' => 'import-default',
        ];

        return [
            [
                '_seed_id'      => 'gs-sync',
                'label'         => 'GS — Sync catalogo (idempotente, ogni 2h)',
                'runnable_type' => 'source.import',
                'runnable_ref'  => 'json/gs-prod',
                'cron'          => '0 */2 * * *',
                'config'        => [ 'options' => $syncOptions( 'gs-default' ) ],
            ],
            [
                '_seed_id'      => 'sf-sync',
                'label'         => 'SF — Sync catalogo (idempotente, ogni 2h)',
                'runnable_type' => 'source.import',
                'runnable_ref'  => 'csv/sf-prod',
                'cron'          => '0 */2 * * *',
                'config'        => [ 'options' => $syncOptions( 'sf-default' ) ],
            ],
            // KicksDB price refresh — uses the batch /stockx/prices
            // endpoint (50 SKU/call) to patch per-variant regular_price
            // on tracked products without hitting the heavy
            // products/{sku} endpoint. Disabled by default.
            //
            // runnable_ref is empty so the dispatcher falls back to
            // "first saved kicksdb source-config" — matches the
            // EnrichWithKicksDb ImportRule's fallback, so the seeded
            // job works regardless of what slug the operator chose
            // (kicksdb-prod, my-kicksdb, etc.). To target a specific
            // config, the operator edits runnable_ref in Automatizza.
            //
            // max_per_tick lives under options so ProjectExporter
            // emits it in the exported project.json (the exporter
            // serializes config.options as a separate key).
            [
                '_seed_id'      => 'kicksdb-refresh-prices',
                'label'         => 'KicksDB — Refresh prezzi tracked (ogni 6h)',
                'runnable_type' => 'kicksdb.refresh_prices',
                'runnable_ref'  => '',
                'cron'          => '0 */6 * * *',
                'config'        => [ 'options' => [ 'max_per_tick' => 500 ] ],
            ],
        ];
    }

    // ─── Rules ────────────────────────────────────────────────────

    /**
     * Rules touch existing products. Currently no defaults — the
     * seeded ones we tried (publish-batch) had compounding-pricing
     * hazards or surprise-publishing footguns. Markup is configured
     * on the source-config now (idempotent because it reads feed
     * price as input), so a periodic Rule for re-pricing isn't
     * needed for the common sync workflow.
     *
     * Operators compose Rules deliberately (e.g. "set status=draft
     * for products with stock=0"). The repository ships empty.
     */
    private function seedRules( bool $force ): int
    {
        if ( ! $this->rules ) return 0;
        $touched = 0;
        foreach ( self::defaultRules() as $r ) {
            $exists = $this->rules->find( (string) $r['slug'] ) !== null;
            if ( $exists && ! $force ) continue;
            $this->rules->save( $r );
            $touched++;
        }
        return $touched;
    }

    /** @return array<int, array{slug:string, name:string, selection:array, operations:array, checks:array, enabled:bool}> */
    public static function defaultRules(): array
    {
        return [];
    }
}
