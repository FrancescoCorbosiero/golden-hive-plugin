<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Config;

use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\MappingRepository;
use HiveSync\Core\Repo\RuleRepository;
use HiveSync\Core\Repo\SourceConfigRepository;

/**
 * Dump every config-as-code surface (sources, mappings, pipelines,
 * rules, jobs) into a single project JSON document. The output is
 * stable, idempotent, and round-trippable through {@see ProjectApplier}.
 *
 * Schema version: hive-sync/project/v1. The `$schema` key in the
 * output is the contract — bump the version when adding fields that
 * older importers can't ignore.
 *
 * Secrets are redacted (••••XXXX last-4 form) on export so the
 * document is safe to copy/paste into a chat with an LLM. The
 * applier interprets the placeholders as "unchanged" and preserves
 * the stored values.
 */
final class ProjectExporter
{
    public const SCHEMA = 'hive-sync/project/v1';
    public const VERSION = 1;

    public function __construct(
        private readonly SourceConfigRepository $sourceConfigs,
        private readonly MappingRepository $mappings,
        private readonly PipelineRepository $pipelines,
        private readonly RuleRepository $rules,
        private readonly JobRepository $jobs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return [
            '$schema'     => self::SCHEMA,
            'version'     => self::VERSION,
            'exported_at' => gmdate( 'c' ),
            'site_url'    => function_exists( 'get_site_url' ) ? (string) \get_site_url() : '',
            'sources'     => $this->exportSources(),
            'mappings'    => $this->exportMappings(),
            'pipelines'   => $this->exportPipelines(),
            'rules'       => $this->exportRules(),
            'jobs'        => $this->exportJobs(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportSources(): array
    {
        $out = [];
        foreach ( $this->sourceConfigs->allRedacted() as $row ) {
            $out[] = [
                'slug'        => (string) ( $row['slug'] ?? '' ),
                'name'        => (string) ( $row['name'] ?? '' ),
                'kind'        => (string) ( $row['source_kind'] ?? '' ),
                'config'      => (array) ( $row['config'] ?? [] ),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportMappings(): array
    {
        $out = [];
        foreach ( $this->mappings->all() as $row ) {
            $out[] = [
                'slug'        => (string) ( $row['slug'] ?? '' ),
                'name'        => (string) ( $row['name'] ?? '' ),
                'source_kind' => (string) ( $row['source_kind'] ?? '' ),
                'config'      => (array) ( $row['config'] ?? [] ),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportPipelines(): array
    {
        $out = [];
        foreach ( $this->pipelines->all() as $pipeline ) {
            $steps = [];
            foreach ( $pipeline->steps as $step ) {
                $steps[] = [
                    'kind'   => $step->kind->value,
                    'ref_id' => $step->refId,
                    'params' => $step->params,
                    'note'   => $step->note,
                ];
            }
            $out[] = [
                'slug'  => $pipeline->id,
                'name'  => $pipeline->name,
                'steps' => $steps,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportRules(): array
    {
        $out = [];
        foreach ( $this->rules->all() as $row ) {
            $out[] = [
                'slug'       => (string) ( $row['slug'] ?? '' ),
                'name'       => (string) ( $row['name'] ?? '' ),
                'enabled'    => (bool) ( $row['enabled'] ?? false ),
                'selection'  => (array) ( $row['selection'] ?? [] ),
                'operations' => (array) ( $row['operations'] ?? [] ),
                'checks'     => (array) ( $row['checks'] ?? [] ),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportJobs(): array
    {
        $out = [];
        foreach ( $this->jobs->all() as $row ) {
            $config = (array) ( $row['config'] ?? [] );
            // Stable identity: prefer the seeder marker when present,
            // fallback to a deterministic slug derived from the
            // runnable + cron so user jobs round-trip cleanly.
            $slug = (string) ( $config['_seed_id'] ?? '' );
            if ( $slug === '' ) {
                $slug = self::deriveJobSlug(
                    (string) ( $row['runnable_type'] ?? '' ),
                    (string) ( $row['runnable_ref']  ?? '' ),
                    (string) ( $row['cron_expr']     ?? '' ),
                );
            }
            // Drop seeder bookkeeping keys from the exported `options`
            // — the applier re-stamps them based on the slug.
            unset( $config['_seed_id'], $config['_seed_label'] );
            $out[] = [
                'slug'          => $slug,
                'label'         => (string) ( $config['_label'] ?? '' ),
                'runnable_type' => (string) ( $row['runnable_type'] ?? '' ),
                'runnable_ref'  => (string) ( $row['runnable_ref']  ?? '' ),
                'cron'          => (string) ( $row['cron_expr']     ?? '' ),
                'enabled'       => (bool)   ( $row['enabled']       ?? false ),
                'options'       => (array)  ( $config['options']    ?? [] ),
            ];
        }
        return $out;
    }

    private static function deriveJobSlug( string $type, string $ref, string $cron ): string
    {
        $base = strtolower( $type . '-' . $ref );
        $base = preg_replace( '/[^a-z0-9]+/', '-', $base ) ?? $base;
        $base = trim( $base, '-' );
        $hash = substr( md5( $type . '|' . $ref . '|' . $cron ), 0, 6 );
        return ( $base !== '' ? $base : 'job' ) . '-' . $hash;
    }
}
