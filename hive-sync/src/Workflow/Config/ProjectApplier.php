<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Config;

use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Pipeline\PipelineStep;
use HiveSync\Core\Pipeline\PipelineStepKind;
use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\MappingRepository;
use HiveSync\Core\Repo\RuleRepository;
use HiveSync\Core\Repo\SourceConfigRepository;

/**
 * Validate + apply a project JSON document produced by
 * {@see ProjectExporter}. Three modes:
 *
 *   - validate(): structural + referential checks. Returns a flat list
 *     of error strings keyed by the offending JSON path. No DB writes.
 *
 *   - diff(): compare incoming entities to current DB state and
 *     classify each as create / update / noop. Surfaces the planned
 *     changes BEFORE the operator commits.
 *
 *   - apply(): execute the diff. Each entity type is upserted via
 *     its repository's save(); secrets sent as redacted placeholders
 *     are preserved from the existing stored row (mirrors the UI's
 *     SourceConfigRepository::save() $existingConfig contract).
 *
 * Entities NOT present in the incoming doc are LEFT ALONE by default.
 * Pass `prune: true` in apply() to also delete entities the document
 * doesn't mention — that's the "declarative source of truth" mode.
 */
final class ProjectApplier
{
    public const SECRET_PLACEHOLDER_RE = '/^•+/u';

    public function __construct(
        private readonly SourceConfigRepository $sourceConfigs,
        private readonly MappingRepository $mappings,
        private readonly PipelineRepository $pipelines,
        private readonly RuleRepository $rules,
        private readonly JobRepository $jobs,
    ) {}

    /**
     * @param array<string, mixed> $project
     * @return array{ok: bool, errors: array<int, string>}
     */
    public function validate( array $project ): array
    {
        $errors = [];

        $schema = (string) ( $project['$schema'] ?? '' );
        if ( $schema !== '' && $schema !== ProjectExporter::SCHEMA ) {
            $errors[] = '$schema: expected "' . ProjectExporter::SCHEMA . '", got "' . $schema . '"';
        }

        $sectionsAreLists = [
            'sources'   => true,
            'mappings'  => true,
            'pipelines' => true,
            'rules'     => true,
            'jobs'      => true,
        ];
        foreach ( $sectionsAreLists as $section => $_ ) {
            if ( ! array_key_exists( $section, $project ) ) continue;
            if ( ! is_array( $project[ $section ] ) ) {
                $errors[] = "$section: must be an array";
                continue;
            }
            if ( $project[ $section ] !== [] && ! array_is_list( $project[ $section ] ) ) {
                $errors[] = "$section: must be a JSON array, got an object";
            }
        }

        $sourceSlugs   = self::collectSlugs( $project['sources']   ?? [], 'sources' );
        $mappingSlugs  = self::collectSlugs( $project['mappings']  ?? [], 'mappings' );
        $pipelineSlugs = self::collectSlugs( $project['pipelines'] ?? [], 'pipelines' );

        // Per-entity field validation.
        foreach ( (array) ( $project['sources'] ?? [] ) as $i => $row ) {
            if ( ! is_array( $row ) ) { $errors[] = "sources[$i]: must be an object"; continue; }
            self::requireString( $row, 'slug', "sources[$i]", $errors );
            self::requireString( $row, 'kind', "sources[$i]", $errors );
            if ( isset( $row['config'] ) && ! is_array( $row['config'] ) ) {
                $errors[] = "sources[$i].config: must be an object";
            }
        }
        foreach ( (array) ( $project['mappings'] ?? [] ) as $i => $row ) {
            if ( ! is_array( $row ) ) { $errors[] = "mappings[$i]: must be an object"; continue; }
            self::requireString( $row, 'slug', "mappings[$i]", $errors );
            self::requireString( $row, 'source_kind', "mappings[$i]", $errors );
            if ( isset( $row['config'] ) && ! is_array( $row['config'] ) ) {
                $errors[] = "mappings[$i].config: must be an object";
            }
        }
        foreach ( (array) ( $project['pipelines'] ?? [] ) as $i => $row ) {
            if ( ! is_array( $row ) ) { $errors[] = "pipelines[$i]: must be an object"; continue; }
            self::requireString( $row, 'slug', "pipelines[$i]", $errors );
            $steps = $row['steps'] ?? [];
            if ( ! is_array( $steps ) ) {
                $errors[] = "pipelines[$i].steps: must be an array";
            } else {
                foreach ( $steps as $j => $step ) {
                    if ( ! is_array( $step ) ) { $errors[] = "pipelines[$i].steps[$j]: must be an object"; continue; }
                    self::requireString( $step, 'kind',   "pipelines[$i].steps[$j]", $errors );
                    self::requireString( $step, 'ref_id', "pipelines[$i].steps[$j]", $errors );
                    if ( isset( $step['kind'] ) && PipelineStepKind::tryFrom( (string) $step['kind'] ) === null ) {
                        $errors[] = "pipelines[$i].steps[$j].kind: unknown kind '{$step['kind']}' (expected pre_check|import_rule|operation|check)";
                    }
                }
            }
        }
        foreach ( (array) ( $project['rules'] ?? [] ) as $i => $row ) {
            if ( ! is_array( $row ) ) { $errors[] = "rules[$i]: must be an object"; continue; }
            self::requireString( $row, 'slug', "rules[$i]", $errors );
        }
        foreach ( (array) ( $project['jobs'] ?? [] ) as $i => $row ) {
            if ( ! is_array( $row ) ) { $errors[] = "jobs[$i]: must be an object"; continue; }
            self::requireString( $row, 'slug',          "jobs[$i]", $errors );
            self::requireString( $row, 'runnable_type', "jobs[$i]", $errors );
            self::requireString( $row, 'runnable_ref',  "jobs[$i]", $errors );

            // Referential checks: jobs that name a mapping_slug /
            // pipeline_slug must point at an entity that either
            // exists in the doc OR already exists in the DB.
            $opts = (array) ( $row['options'] ?? [] );
            $needMapping  = (string) ( $opts['mapping_slug']  ?? '' );
            $needPipeline = (string) ( $opts['pipeline_slug'] ?? '' );
            if ( $needMapping !== '' && ! in_array( $needMapping, $mappingSlugs, true ) ) {
                $existsInDb = $this->mappings->find( $needMapping ) !== null;
                if ( ! $existsInDb ) {
                    $errors[] = "jobs[$i].options.mapping_slug: '$needMapping' not found in document or database";
                }
            }
            if ( $needPipeline !== '' && ! in_array( $needPipeline, $pipelineSlugs, true ) ) {
                $existsInDb = $this->pipelines->find( $needPipeline ) !== null;
                if ( ! $existsInDb ) {
                    $errors[] = "jobs[$i].options.pipeline_slug: '$needPipeline' not found in document or database";
                }
            }

            // runnable_ref convention: <kind>/<source_config_slug>
            // (e.g. 'json/gs-prod'). Validate the slug-half resolves.
            $ref = (string) ( $row['runnable_ref'] ?? '' );
            if ( str_contains( $ref, '/' ) ) {
                [ , $cfgSlug ] = explode( '/', $ref, 2 );
                if ( $cfgSlug !== '' && ! in_array( $cfgSlug, $sourceSlugs, true ) ) {
                    $existsInDb = $this->sourceConfigs->find( $cfgSlug ) !== null;
                    if ( ! $existsInDb ) {
                        $errors[] = "jobs[$i].runnable_ref: source-config '$cfgSlug' not found in document or database";
                    }
                }
            }
        }

        return [ 'ok' => $errors === [], 'errors' => $errors ];
    }

    /**
     * Compare the document to the current DB state without writing.
     *
     * @param array<string, mixed> $project
     * @return array<string, array<string, array<string, mixed>>>
     *         Section → slug → { op, summary }
     */
    public function diff( array $project ): array
    {
        return [
            'sources'   => $this->diffSources(   (array) ( $project['sources']   ?? [] ) ),
            'mappings'  => $this->diffMappings(  (array) ( $project['mappings']  ?? [] ) ),
            'pipelines' => $this->diffPipelines( (array) ( $project['pipelines'] ?? [] ) ),
            'rules'     => $this->diffRules(     (array) ( $project['rules']     ?? [] ) ),
            'jobs'      => $this->diffJobs(      (array) ( $project['jobs']      ?? [] ) ),
        ];
    }

    /**
     * Persist the document. Returns a per-section count of writes.
     *
     * @param array<string, mixed> $project
     * @param array{prune?: bool} $options
     * @return array{
     *   ok: bool,
     *   counts: array<string, array{created:int, updated:int, deleted:int, noop:int}>,
     *   errors: array<int, string>,
     * }
     */
    public function apply( array $project, array $options = [] ): array
    {
        $validation = $this->validate( $project );
        if ( ! $validation['ok'] ) {
            return [ 'ok' => false, 'counts' => [], 'errors' => $validation['errors'] ];
        }

        $prune = ! empty( $options['prune'] );

        // Apply order matters: source-configs and mappings/pipelines
        // first (jobs reference them via slug). Rules last —
        // they're independent.
        $counts = [
            'sources'   => $this->applySources(   (array) ( $project['sources']   ?? [] ), $prune ),
            'mappings'  => $this->applyMappings(  (array) ( $project['mappings']  ?? [] ), $prune ),
            'pipelines' => $this->applyPipelines( (array) ( $project['pipelines'] ?? [] ), $prune ),
            'rules'     => $this->applyRules(     (array) ( $project['rules']     ?? [] ), $prune ),
            'jobs'      => $this->applyJobs(      (array) ( $project['jobs']      ?? [] ), $prune ),
        ];

        return [ 'ok' => true, 'counts' => $counts, 'errors' => [] ];
    }

    // ─── Diff helpers ─────────────────────────────────────────────

    /** @param array<int, mixed> $rows  @return array<string, array<string, mixed>> */
    private function diffSources( array $rows ): array
    {
        $out = [];
        $existing = self::indexBy( $this->sourceConfigs->all(), 'slug' );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $out[ $slug ] = [
                'op'   => isset( $existing[ $slug ] ) ? 'update' : 'create',
                'name' => (string) ( $row['name'] ?? '' ),
                'kind' => (string) ( $row['kind'] ?? '' ),
            ];
        }
        return $out;
    }

    /** @param array<int, mixed> $rows  @return array<string, array<string, mixed>> */
    private function diffMappings( array $rows ): array
    {
        $out = [];
        $existing = self::indexBy( $this->mappings->all(), 'slug' );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $out[ $slug ] = [
                'op'         => isset( $existing[ $slug ] ) ? 'update' : 'create',
                'name'       => (string) ( $row['name'] ?? '' ),
                'fields'     => count( (array) ( $row['config'] ?? [] ) ),
                'source_kind'=> (string) ( $row['source_kind'] ?? '' ),
            ];
        }
        return $out;
    }

    /** @param array<int, mixed> $rows  @return array<string, array<string, mixed>> */
    private function diffPipelines( array $rows ): array
    {
        $out = [];
        $existing = [];
        foreach ( $this->pipelines->all() as $p ) $existing[ $p->id ] = true;
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $out[ $slug ] = [
                'op'    => isset( $existing[ $slug ] ) ? 'update' : 'create',
                'name'  => (string) ( $row['name'] ?? '' ),
                'steps' => count( (array) ( $row['steps'] ?? [] ) ),
            ];
        }
        return $out;
    }

    /** @param array<int, mixed> $rows  @return array<string, array<string, mixed>> */
    private function diffRules( array $rows ): array
    {
        $out = [];
        $existing = self::indexBy( $this->rules->all(), 'slug' );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $out[ $slug ] = [
                'op'      => isset( $existing[ $slug ] ) ? 'update' : 'create',
                'name'    => (string) ( $row['name'] ?? '' ),
                'enabled' => (bool)   ( $row['enabled'] ?? false ),
            ];
        }
        return $out;
    }

    /** @param array<int, mixed> $rows  @return array<string, array<string, mixed>> */
    private function diffJobs( array $rows ): array
    {
        $out = [];
        $existingBySlug = $this->indexJobsBySlug();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $out[ $slug ] = [
                'op'           => isset( $existingBySlug[ $slug ] ) ? 'update' : 'create',
                'cron'         => (string) ( $row['cron'] ?? '' ),
                'runnable_ref' => (string) ( $row['runnable_ref'] ?? '' ),
                'enabled'      => (bool)   ( $row['enabled'] ?? false ),
            ];
        }
        return $out;
    }

    // ─── Apply helpers ────────────────────────────────────────────

    /**
     * @param array<int, mixed> $rows
     * @return array{created:int, updated:int, deleted:int, noop:int}
     */
    private function applySources( array $rows, bool $prune ): array
    {
        $existing = self::indexBy( $this->sourceConfigs->all(), 'slug' );
        $tally = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'noop' => 0 ];
        $seen = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $seen[ $slug ] = true;

            $isUpdate = isset( $existing[ $slug ] );
            $existingConfig = $isUpdate ? (array) ( $existing[ $slug ]['config'] ?? [] ) : [];
            $incomingConfig = self::stripRedactedSecrets( (array) ( $row['config'] ?? [] ) );

            $this->sourceConfigs->save( [
                'slug'        => $slug,
                'name'        => (string) ( $row['name'] ?? '' ),
                'source_kind' => (string) ( $row['kind'] ?? '' ),
                'config'      => $incomingConfig,
            ], $existingConfig );

            $isUpdate ? $tally['updated']++ : $tally['created']++;
        }

        if ( $prune ) {
            foreach ( $existing as $slug => $_row ) {
                if ( ! isset( $seen[ $slug ] ) ) {
                    if ( $this->sourceConfigs->delete( $slug ) ) $tally['deleted']++;
                }
            }
        }
        return $tally;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{created:int, updated:int, deleted:int, noop:int}
     */
    private function applyMappings( array $rows, bool $prune ): array
    {
        $existing = self::indexBy( $this->mappings->all(), 'slug' );
        $tally = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'noop' => 0 ];
        $seen = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $seen[ $slug ] = true;

            $isUpdate = isset( $existing[ $slug ] );
            $this->mappings->save( [
                'slug'        => $slug,
                'name'        => (string) ( $row['name'] ?? '' ),
                'source_kind' => (string) ( $row['source_kind'] ?? '' ),
                'config'      => (array)  ( $row['config'] ?? [] ),
            ] );
            $isUpdate ? $tally['updated']++ : $tally['created']++;
        }

        if ( $prune ) {
            foreach ( $existing as $slug => $_row ) {
                if ( ! isset( $seen[ $slug ] ) ) {
                    if ( $this->mappings->delete( $slug ) ) $tally['deleted']++;
                }
            }
        }
        return $tally;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{created:int, updated:int, deleted:int, noop:int}
     */
    private function applyPipelines( array $rows, bool $prune ): array
    {
        $existingIds = [];
        foreach ( $this->pipelines->all() as $p ) $existingIds[ $p->id ] = true;
        $tally = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'noop' => 0 ];
        $seen = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $seen[ $slug ] = true;

            $steps = [];
            foreach ( (array) ( $row['steps'] ?? [] ) as $s ) {
                if ( ! is_array( $s ) ) continue;
                $kind = PipelineStepKind::tryFrom( (string) ( $s['kind'] ?? '' ) ) ?? PipelineStepKind::Operation;
                $steps[] = new PipelineStep(
                    kind:   $kind,
                    refId:  (string) ( $s['ref_id'] ?? '' ),
                    params: (array)  ( $s['params'] ?? [] ),
                    note:   isset( $s['note'] ) && $s['note'] !== null ? (string) $s['note'] : null,
                );
            }

            $isUpdate = isset( $existingIds[ $slug ] );
            $this->pipelines->save( new Pipeline(
                id:    $slug,
                name:  (string) ( $row['name'] ?? '' ),
                steps: $steps,
            ) );
            $isUpdate ? $tally['updated']++ : $tally['created']++;
        }

        if ( $prune ) {
            foreach ( array_keys( $existingIds ) as $slug ) {
                if ( ! isset( $seen[ $slug ] ) ) {
                    if ( $this->pipelines->delete( $slug ) ) $tally['deleted']++;
                }
            }
        }
        return $tally;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{created:int, updated:int, deleted:int, noop:int}
     */
    private function applyRules( array $rows, bool $prune ): array
    {
        $existing = self::indexBy( $this->rules->all(), 'slug' );
        $tally = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'noop' => 0 ];
        $seen = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $seen[ $slug ] = true;

            $isUpdate = isset( $existing[ $slug ] );
            $this->rules->save( [
                'slug'       => $slug,
                'name'       => (string) ( $row['name'] ?? '' ),
                'enabled'    => (bool)   ( $row['enabled'] ?? false ),
                'selection'  => (array)  ( $row['selection']  ?? [] ),
                'operations' => (array)  ( $row['operations'] ?? [] ),
                'checks'     => (array)  ( $row['checks']     ?? [] ),
            ] );
            $isUpdate ? $tally['updated']++ : $tally['created']++;
        }

        if ( $prune ) {
            foreach ( $existing as $slug => $_row ) {
                if ( ! isset( $seen[ $slug ] ) ) {
                    if ( $this->rules->delete( $slug ) ) $tally['deleted']++;
                }
            }
        }
        return $tally;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{created:int, updated:int, deleted:int, noop:int}
     */
    private function applyJobs( array $rows, bool $prune ): array
    {
        $existingBySlug = $this->indexJobsBySlug();
        $tally = [ 'created' => 0, 'updated' => 0, 'deleted' => 0, 'noop' => 0 ];
        $seen = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $slug = (string) ( $row['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $seen[ $slug ] = true;

            $payload = [
                'runnable_type' => (string) ( $row['runnable_type'] ?? '' ),
                'runnable_ref'  => (string) ( $row['runnable_ref']  ?? '' ),
                'cron_expr'     => (string) ( $row['cron']          ?? '' ),
                'enabled'       => (bool)   ( $row['enabled']       ?? false ),
                'config'        => [
                    '_seed_id'    => $slug,
                    '_seed_label' => (string) ( $row['label'] ?? '' ),
                    '_label'      => (string) ( $row['label'] ?? '' ),
                    'options'     => (array)  ( $row['options'] ?? [] ),
                ],
            ];
            if ( isset( $existingBySlug[ $slug ] ) ) {
                $payload['id'] = (int) $existingBySlug[ $slug ]['id'];
                $tally['updated']++;
            } else {
                $tally['created']++;
            }
            $this->jobs->save( $payload );
        }

        if ( $prune ) {
            foreach ( $existingBySlug as $slug => $jobRow ) {
                if ( ! isset( $seen[ $slug ] ) ) {
                    if ( $this->jobs->delete( (int) $jobRow['id'] ) ) $tally['deleted']++;
                }
            }
        }
        return $tally;
    }

    // ─── Internal helpers ─────────────────────────────────────────

    /**
     * Drop secret keys whose value is a redaction placeholder. The
     * SourceConfigRepository::save() $existingConfig argument fills
     * the value back in from the stored row.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function stripRedactedSecrets( array $config ): array
    {
        $out = [];
        foreach ( $config as $k => $v ) {
            if ( is_string( $v ) && $v !== '' && preg_match( self::SECRET_PLACEHOLDER_RE, $v ) ) {
                continue;
            }
            $out[ $k ] = $v;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function indexBy( array $rows, string $key ): array
    {
        $out = [];
        foreach ( $rows as $row ) {
            $k = (string) ( $row[ $key ] ?? '' );
            if ( $k !== '' ) $out[ $k ] = $row;
        }
        return $out;
    }

    /**
     * Index existing jobs by their stable slug — derived the same way
     * the exporter does (config._seed_id, falling back to a deterministic
     * hash of runnable_type/ref/cron). Required because `wp_hsync_jobs`
     * has no slug column.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexJobsBySlug(): array
    {
        $out = [];
        foreach ( $this->jobs->all() as $row ) {
            $config = (array) ( $row['config'] ?? [] );
            $slug = (string) ( $config['_seed_id'] ?? '' );
            if ( $slug === '' ) {
                $base = strtolower( ( $row['runnable_type'] ?? '' ) . '-' . ( $row['runnable_ref'] ?? '' ) );
                $base = preg_replace( '/[^a-z0-9]+/', '-', $base ) ?? $base;
                $base = trim( $base, '-' );
                $hash = substr( md5(
                    ( $row['runnable_type'] ?? '' ) . '|'
                    . ( $row['runnable_ref'] ?? '' ) . '|'
                    . ( $row['cron_expr'] ?? '' )
                ), 0, 6 );
                $slug = ( $base !== '' ? $base : 'job' ) . '-' . $hash;
            }
            $out[ $slug ] = $row;
        }
        return $out;
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, string>
     */
    private static function collectSlugs( array $rows, string $section ): array
    {
        $out = [];
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && isset( $row['slug'] ) && is_string( $row['slug'] ) ) {
                $out[] = $row['slug'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> &$errors
     */
    private static function requireString( array $row, string $key, string $path, array &$errors ): void
    {
        if ( ! isset( $row[ $key ] ) ) {
            $errors[] = "$path.$key: required";
            return;
        }
        if ( ! is_string( $row[ $key ] ) || $row[ $key ] === '' ) {
            $errors[] = "$path.$key: must be a non-empty string";
        }
    }
}
