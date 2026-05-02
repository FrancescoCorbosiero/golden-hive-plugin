<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Seed;

use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Pipeline\PipelineStep;
use HiveSync\Core\Pipeline\PipelineStepKind;
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
    ) {}

    /**
     * @return array{mappings: int, pipelines: int}  counts of newly inserted rows
     */
    public function install(): array
    {
        return [
            'mappings'  => $this->seedMappings(),
            'pipelines' => $this->seedPipelines(),
        ];
    }

    // ─── Mappings ─────────────────────────────────────────────────

    private function seedMappings(): int
    {
        $inserted = 0;
        foreach ( self::defaultMappings() as $m ) {
            if ( $this->mappings->find( $m['slug'] ) !== null ) continue;
            $this->mappings->save( $m );
            $inserted++;
        }
        return $inserted;
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
                'config'      => [
                    'sku'            => 'sku',
                    'name'           => 'name',
                    'regular_price'  => 'presented_price',
                    'sale_price'     => 'offer_price',
                    'price'          => 'offer_price',
                    'stock_quantity' => 'available_summary_quantity',
                    'stock_status'   => 'stock_status',
                    'manage_stock'   => 'manage_stock',
                    'featured_image' => 'image_full_url',
                    'image'          => 'image_full_url',
                    'brands'         => 'brand_name',
                    'pa_marca'       => 'brand_name',
                    'pa_taglia'      => 'sizes.size_eu',
                    'size_mapper'    => 'size_mapper_name',
                    // Templated fields the user can refine per-deployment.
                    // Empty by default; uncomment / customize after import.
                    // 'short_description' => '<p>Sneakers <strong>{brand_name}</strong>. {name}.</p>',
                    // 'description'       => '<p>{name} — modello {brand_name}. Codice: {sku}.</p>',
                    // 'meta_title'        => '{name} — {brand_name} | Hive',
                    // 'meta_description'  => 'Acquista {name} originali. Spedizione veloce.',
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
        ];
    }

    // ─── Pipelines ────────────────────────────────────────────────

    private function seedPipelines(): int
    {
        $inserted = 0;
        foreach ( self::defaultPipelines() as $def ) {
            if ( $this->pipelines->find( $def['slug'] ) !== null ) continue;
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
            $inserted++;
        }
        return $inserted;
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
                        'ref_id' => 'taxonomy.resolve',
                        'params' => [ 'create_missing' => true ],
                        'note'   => 'Resolve categories/brands/tags + pa_* attributes',
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
}
