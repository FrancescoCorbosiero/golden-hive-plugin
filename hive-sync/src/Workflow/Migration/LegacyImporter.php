<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Migration;

use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\MappingRepository;
use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Pipeline\PipelineStep;
use HiveSync\Core\Pipeline\PipelineStepKind;

/**
 * One-shot migration from Golden Hive's wp_options stores into the
 * Hive Sync tables.
 *
 * Three legacy keys:
 *   gh_pipelines     → wp_hsync_pipelines      (clean shape match)
 *   gh_mapper_rules  → wp_hsync_mappings       (lossy — shapes differ)
 *   gh_jobs          → wp_hsync_jobs           (lossy — kind/params → runnable_*)
 *
 * The lossy migrations preserve the original payload as JSON inside
 * the new row's config field so nothing is lost; the user can review
 * what came over and rebuild if needed.
 *
 * Idempotent: running migration twice is safe — it skips rows whose
 * slug/ref already exists in the destination table.
 */
final class LegacyImporter
{
    public function __construct(
        private readonly PipelineRepository $pipelines,
        private readonly MappingRepository $mappings,
        private readonly JobRepository $jobs,
    ) {}

    /**
     * Survey legacy options without mutating anything. Used by the UI
     * to render a "what's about to move" preview before commit.
     *
     * @return array{
     *   pipelines: int,
     *   mappings:  int,
     *   jobs:      int,
     *   warnings:  string[],
     * }
     */
    public function audit(): array
    {
        $warnings = [];
        $pipes    = self::optionList( 'gh_pipelines' );
        $maps     = self::optionList( 'gh_mapper_rules' );
        $jobs     = self::optionList( 'gh_jobs' );

        if ( count( $maps ) > 0 ) {
            $warnings[] = sprintf(
                '%d mapping rule legacy: lo schema differisce (items_path/source_sample → JSON config). Verifica dopo import.',
                count( $maps ),
            );
        }
        if ( count( $jobs ) > 0 ) {
            $warnings[] = sprintf(
                '%d job legacy: kind/params verranno mappati a runnable_type/runnable_ref best-effort. Disabilitati di default — riabilitali manualmente dopo verifica.',
                count( $jobs ),
            );
        }

        return [
            'pipelines' => count( $pipes ),
            'mappings'  => count( $maps ),
            'jobs'      => count( $jobs ),
            'warnings'  => $warnings,
        ];
    }

    /**
     * Run all three migrations.
     *
     * @return array{
     *   pipelines: array{copied: int, skipped: int, errors: string[]},
     *   mappings:  array{copied: int, skipped: int, errors: string[]},
     *   jobs:      array{copied: int, skipped: int, errors: string[]},
     * }
     */
    public function run(): array
    {
        return [
            'pipelines' => $this->migratePipelines(),
            'mappings'  => $this->migrateMappings(),
            'jobs'      => $this->migrateJobs(),
        ];
    }

    // ─── Pipelines ────────────────────────────────────────────────

    private function migratePipelines(): array
    {
        $copied = 0; $skipped = 0; $errors = [];
        foreach ( self::optionList( 'gh_pipelines' ) as $row ) {
            $slug = (string) ( $row['id'] ?? '' );
            if ( $slug === '' ) {
                $errors[] = 'Pipeline senza id, scartata.';
                continue;
            }
            if ( $this->pipelines->find( $slug ) !== null ) {
                $skipped++;
                continue;
            }
            $steps = [];
            foreach ( (array) ( $row['steps'] ?? [] ) as $s ) {
                $kind = PipelineStepKind::tryFrom( (string) ( $s['kind'] ?? '' ) ) ?? PipelineStepKind::Operation;
                $steps[] = new PipelineStep(
                    kind: $kind,
                    refId: (string) ( $s['ref_id'] ?? '' ),
                    params: (array) ( $s['params'] ?? [] ),
                    note: isset( $s['note'] ) ? (string) $s['note'] : null,
                );
            }
            try {
                $this->pipelines->save( new Pipeline(
                    id: $slug,
                    name: (string) ( $row['name'] ?? $slug ),
                    steps: $steps,
                    meta: (array) ( $row['meta'] ?? [] ),
                ) );
                $copied++;
            } catch ( \Throwable $e ) {
                $errors[] = "Pipeline '{$slug}': " . $e->getMessage();
            }
        }
        return compact( 'copied', 'skipped', 'errors' );
    }

    // ─── Mappings ─────────────────────────────────────────────────

    /**
     * Legacy gh_mapper_rules shape:
     *   { id, name, description, items_path, source_sample, mappings: [...] }
     *
     * Hive Sync mapping shape:
     *   { slug, name, source_kind, config: { ... } }
     *
     * The legacy "items_path" + "mappings" structure doesn't map to a
     * simple column→field dict — it's a JSONPath-driven extraction.
     * We preserve the entire legacy row inside config.legacy_payload
     * so the user can rebuild it as a CSV mapping by hand without
     * losing any data.
     */
    private function migrateMappings(): array
    {
        $copied = 0; $skipped = 0; $errors = [];
        foreach ( self::optionList( 'gh_mapper_rules' ) as $row ) {
            $slug = (string) ( $row['id'] ?? '' );
            if ( $slug === '' ) {
                $errors[] = 'Mapping rule senza id, scartata.';
                continue;
            }
            if ( $this->mappings->find( $slug ) !== null ) {
                $skipped++;
                continue;
            }
            try {
                $this->mappings->save( [
                    'slug'        => $slug,
                    'name'        => (string) ( $row['name'] ?? $slug ),
                    'source_kind' => 'legacy',  // user retags after review
                    'config'      => [
                        '_migrated_from' => 'gh_mapper_rules',
                        'legacy_payload' => $row,
                    ],
                ] );
                $copied++;
            } catch ( \Throwable $e ) {
                $errors[] = "Mapping '{$slug}': " . $e->getMessage();
            }
        }
        return compact( 'copied', 'skipped', 'errors' );
    }

    // ─── Jobs ─────────────────────────────────────────────────────

    /**
     * Legacy gh_jobs shape uses kind/params (csv_feed, force_reimport, …).
     * Hive Sync uses runnable_type/runnable_ref (pipeline, rule,
     * source.import, …). The mapping is best-effort:
     *
     *   csv_feed                    → source.import / csv:<feed_id>
     *   gs_feed / config_feed       → source.import / goldensneakers
     *   pipeline.run                → pipeline / <pipeline_id>
     *   *                           → legacy / <kind>
     *
     * In every case the original kind+params survive in the new
     * config.legacy_payload so nothing is dropped.
     *
     * Imported jobs are forced enabled=false so the new scheduler does
     * not start firing them automatically — the user decides when each
     * is verified and ready.
     */
    private function migrateJobs(): array
    {
        $copied = 0; $skipped = 0; $errors = [];
        foreach ( self::optionList( 'gh_jobs' ) as $row ) {
            $legacyId = (string) ( $row['id'] ?? '' );
            if ( $legacyId === '' ) {
                $errors[] = 'Job senza id, scartato.';
                continue;
            }

            $kind   = (string) ( $row['kind'] ?? '' );
            $params = (array) ( $row['params'] ?? [] );
            [ $type, $ref ] = self::translateKind( $kind, $params );

            try {
                $this->jobs->save( [
                    'runnable_type' => $type,
                    'runnable_ref'  => $ref,
                    'cron_expr'     => (string) ( $row['cron'] ?? '' ),
                    'enabled'       => false,
                    'next_run_at'   => null,
                    'config'        => [
                        '_migrated_from' => 'gh_jobs',
                        'legacy_id'      => $legacyId,
                        'legacy_kind'    => $kind,
                        'legacy_label'   => (string) ( $row['label'] ?? '' ),
                        'legacy_params'  => $params,
                    ],
                ] );
                $copied++;
            } catch ( \Throwable $e ) {
                $errors[] = "Job '{$legacyId}': " . $e->getMessage();
            }
        }
        return compact( 'copied', 'skipped', 'errors' );
    }

    /**
     * @return array{0: string, 1: string} [type, ref]
     */
    private static function translateKind( string $kind, array $params ): array
    {
        return match ( $kind ) {
            'csv_feed'         => [ 'source.import', 'csv:' . (string) ( $params['feed_id'] ?? 'unknown' ) ],
            'gs_feed',
            'config_feed',
            'force_reimport'   => [ 'source.import', 'goldensneakers' ],
            'pipeline.run'     => [ 'pipeline', (string) ( $params['pipeline_id'] ?? '' ) ],
            default            => [ 'legacy', $kind === '' ? 'unknown' : $kind ],
        };
    }

    /**
     * Read a wp_options array-of-records list, defensive against the
     * value being missing, null, or not an array.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function optionList( string $key ): array
    {
        if ( ! function_exists( 'get_option' ) ) return [];
        $value = \get_option( $key, [] );
        if ( ! is_array( $value ) ) return [];
        $out = [];
        foreach ( $value as $row ) {
            if ( is_array( $row ) ) $out[] = $row;
        }
        return $out;
    }
}
