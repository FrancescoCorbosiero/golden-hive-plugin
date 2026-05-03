<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Seed;

use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Pipeline\PipelineStep;
use HiveSync\Core\Pipeline\PipelineStepKind;
use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\MappingRepository;

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
                'source_kind' => 'goldensneakers',
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
                ],
            ],
            [
                'slug'        => 'csv-minimal',
                'name'        => 'CSV — minimal (sku/name/price/stock)',
                'source_kind' => 'csv',
                'config'      => [
                    'sku'            => 'sku',
                    'name'           => 'name',
                    'regular_price'  => 'regular_price',
                    'sale_price'     => 'sale_price',
                    'stock_quantity' => 'stock_quantity',
                    'stock_status'   => 'stock_status',
                ],
            ],
            [
                'slug'        => 'sf-default',
                'name'        => 'StockFirmati — default',
                'source_kind' => 'csv',
                'config'      => [
                    // Field names mirror the SF CSV header used by the
                    // legacy gh_rc_sf_* importer. Adjust only if SF
                    // changes its column layout.
                    'sku'            => 'codice',
                    'name'           => 'descrizione',
                    'regular_price'  => 'prezzo_listino',
                    'sale_price'     => 'prezzo_vendita',
                    'stock_quantity' => 'giacenza',
                    'stock_status'   => 'disponibile',
                    'image_url'      => 'immagine',
                    'gallery_urls'   => 'gallery',
                    'brand'          => 'marca',
                    'categories'     => 'categoria',
                    'pa_taglia'      => 'taglie',
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
        return $touched;
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
                        'params' => [ 'concurrency' => 10, 'skip_if_set' => true ],
                        'note'   => 'Parallel curl_multi sideload (host adapter)',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'taxonomy.auto_categorize',
                        'params' => [
                            'sneakers_label' => 'Sneakers',
                            'apparel_label'  => 'Abbigliamento',
                            'override'       => false,
                        ],
                        'note'   => 'Fill categories from size shape when feed lacks taxonomy',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'taxonomy.resolve',
                        'params' => [ 'create_missing' => true ],
                        'note'   => 'Resolve categories/brands/tags + pa_* attributes (creates Sneakers/Abbigliamento if missing)',
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
            [
                'slug'  => 'import-sf-with-markup',
                'name'  => 'Import SF — con markup (parametrizzato)',
                'steps' => [
                    [
                        'kind'   => 'pre_check',
                        'ref_id' => 'import.has_required_fields',
                        'params' => [ 'fields' => 'sku,name', 'severity' => 'block' ],
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'pricing.markup_percent',
                        'params' => [ 'percent' => 30, 'target' => 'both', 'rounding' => '99', 'floor' => 10 ],
                        'note'   => 'Override percent per ogni subset/job (es. 25 / 30 / 40 / 50)',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'media.download',
                        'params' => [ 'concurrency' => 8, 'skip_if_set' => true ],
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'taxonomy.auto_categorize',
                        'params' => [ 'override' => false ],
                        'note'   => 'Fallback se SF non porta categoria',
                    ],
                    [
                        'kind'   => 'import_rule',
                        'ref_id' => 'taxonomy.resolve',
                        'params' => [ 'create_missing' => true ],
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
        ];
    }

    // ─── Jobs ─────────────────────────────────────────────────────
    //
    // Jobs are addressed by integer id, not slug. To make seeding
    // idempotent we tag each seeded job with `config._seed_id` and
    // skip on re-seed when a row already carries that marker. With
    // force=true existing seeded jobs are updated in place; user
    // jobs (no marker) are never touched.

    private function seedJobs( bool $force ): int
    {
        if ( ! $this->jobs ) return 0;
        $existing = $this->jobs->all();
        $existingBySeed = [];
        foreach ( $existing as $row ) {
            $sid = (string) ( ( $row['config']['_seed_id'] ?? '' ) );
            if ( $sid !== '' ) $existingBySeed[ $sid ] = (int) $row['id'];
        }

        $touched = 0;
        foreach ( self::defaultJobs() as $def ) {
            $seedId = (string) $def['_seed_id'];
            $isExisting = isset( $existingBySeed[ $seedId ] );
            if ( $isExisting && ! $force ) continue;

            $config = (array) $def['config'];
            $config['_seed_id'] = $seedId;
            $config['_seed_label'] = (string) $def['label'];

            $payload = [
                'runnable_type' => (string) $def['runnable_type'],
                'runnable_ref'  => (string) $def['runnable_ref'],
                'cron_expr'     => (string) $def['cron'],
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
        return $touched;
    }

    /**
     * Default job lineup. The runnable_ref `goldensneakers/<slug>`
     * points at the saved source-config the operator creates in the
     * Connetti tab — until that exists the jobs do nothing useful, so
     * shipping them disabled is safe.
     *
     * @return array<int, array{_seed_id:string, label:string, runnable_type:string, runnable_ref:string, cron:string, config:array}>
     */
    public static function defaultJobs(): array
    {
        // Saved source-config slugs the operator creates in the
        // Connetti tab. The seeder ships jobs disabled, so missing
        // configs aren't a runtime hazard until the operator wires
        // them up + flips the toggle.
        $gsRef = 'goldensneakers/gs-prod';
        $sfRef = 'csv/sf-prod';

        $jobs = [
            // ─── GS — three-bucket maintenance trio ────────────────
            [
                '_seed_id'      => 'gs-add-new',
                'label'         => 'GS — Aggiungi nuovi prodotti',
                'runnable_type' => 'source.import',
                'runnable_ref'  => $gsRef,
                'cron'          => '*/30 * * * *',
                'config'        => [
                    'options' => [
                        'mapping_slug'  => 'gs-default',
                        'pipeline_slug' => 'import-default',
                        'buckets'       => [ 'new' ],
                    ],
                ],
            ],
            [
                '_seed_id'      => 'gs-refresh-stocks',
                'label'         => 'GS — Refresh prezzi e stock',
                'runnable_type' => 'source.import',
                'runnable_ref'  => $gsRef,
                'cron'          => '*/15 * * * *',
                'config'        => [
                    'options' => [
                        'mapping_slug' => 'gs-default',
                        'buckets'      => [ 'updateStock' ],
                    ],
                ],
            ],
            [
                '_seed_id'      => 'gs-re-update',
                'label'         => 'GS — Re-update completo (campi non-stock)',
                'runnable_type' => 'source.import',
                'runnable_ref'  => $gsRef,
                'cron'          => '0 */6 * * *',
                'config'        => [
                    'options' => [
                        'mapping_slug'  => 'gs-default',
                        'pipeline_slug' => 'import-default',
                        'buckets'       => [ 'update' ],
                    ],
                ],
            ],
        ];

        // ─── SF — sample subset trio (Sneakers @ markup 30%) ─────
        // Clone these per category subset (Abbigliamento @ 25%, etc.)
        // The subset is the (category_filter, markup_percent) pair —
        // one source-config + one mapping + one base pipeline serve
        // every subset; only the runtime params per job differ.
        foreach ( self::sfSubsetTemplates() as $subset ) {
            [ $catFilter, $markupPct, $tag ] = [ $subset['filter'], $subset['markup'], $subset['tag'] ];

            $jobs[] = [
                '_seed_id'      => "sf-{$tag}-add-new",
                'label'         => "SF [{$tag} +{$markupPct}%] — Aggiungi nuovi prodotti",
                'runnable_type' => 'source.import',
                'runnable_ref'  => $sfRef,
                'cron'          => '*/45 * * * *',
                'config'        => [
                    'options' => [
                        'mapping_slug'    => 'sf-default',
                        'pipeline_slug'   => 'import-sf-with-markup',
                        'buckets'         => [ 'new' ],
                        'category_filter' => $catFilter,
                        // markup_percent_override is read by the
                        // dispatcher and patched into the pipeline's
                        // pricing.markup_percent step at run time, so
                        // every subset uses the same pipeline def.
                        'markup_percent_override' => $markupPct,
                    ],
                ],
            ];
            $jobs[] = [
                '_seed_id'      => "sf-{$tag}-refresh-stocks",
                'label'         => "SF [{$tag} +{$markupPct}%] — Refresh prezzi e stock",
                'runnable_type' => 'source.import',
                'runnable_ref'  => $sfRef,
                'cron'          => '*/20 * * * *',
                'config'        => [
                    'options' => [
                        'mapping_slug'            => 'sf-default',
                        'buckets'                 => [ 'updateStock' ],
                        'category_filter'         => $catFilter,
                        'markup_percent_override' => $markupPct,
                    ],
                ],
            ];
            $jobs[] = [
                '_seed_id'      => "sf-{$tag}-re-update",
                'label'         => "SF [{$tag} +{$markupPct}%] — Re-update completo",
                'runnable_type' => 'source.import',
                'runnable_ref'  => $sfRef,
                'cron'          => '0 */8 * * *',
                'config'        => [
                    'options' => [
                        'mapping_slug'            => 'sf-default',
                        'pipeline_slug'           => 'import-sf-with-markup',
                        'buckets'                 => [ 'update' ],
                        'category_filter'         => $catFilter,
                        'markup_percent_override' => $markupPct,
                    ],
                ],
            ];
        }

        return $jobs;
    }

    /**
     * Sample subset definitions — the operator edits these in the UI
     * after seeding (or duplicates a job and tweaks the markup +
     * filter). Each tuple becomes 3 jobs (new / refresh / re-update).
     *
     * @return array<int, array{tag:string, filter:array<int,string>, markup:int}>
     */
    private static function sfSubsetTemplates(): array
    {
        return [
            [ 'tag' => 'sneakers',      'filter' => [ 'sneakers', 'scarpe' ],     'markup' => 30 ],
            [ 'tag' => 'abbigliamento', 'filter' => [ 'abbigliamento', 'cloth' ], 'markup' => 40 ],
            [ 'tag' => 'accessori',     'filter' => [ 'accessori', 'cappello', 'borsa' ], 'markup' => 50 ],
        ];
    }
}
