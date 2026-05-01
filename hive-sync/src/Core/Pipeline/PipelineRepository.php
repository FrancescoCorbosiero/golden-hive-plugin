<?php
declare(strict_types=1);

namespace HiveSync\Core\Pipeline;

/**
 * Persistence for saved pipelines. Owns the wp_hsync_pipelines table.
 *
 * Schema (one row):
 *   id           BIGINT UNSIGNED PK
 *   slug         VARCHAR(100) UNIQUE  (id-as-string for cross-row references)
 *   name         VARCHAR(190)
 *   definition   LONGTEXT  (JSON {steps: [...], meta: {...}})
 *   created_at   DATETIME
 *   updated_at   DATETIME
 *
 * Pipelines are addressable by `slug` from Job params, so a scheduled
 * source.import job carries its pipeline reference and the executor
 * always loads the current version at run time (never a snapshot).
 */
final class PipelineRepository
{
    private const ID_PREFIX = 'pl_';

    public function find(string $slug): ?Pipeline
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $table = \hsync_table( 'pipelines' );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE slug = %s LIMIT 1", $slug ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return Pipeline[] */
    public function all(): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'pipelines' );
        $rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id ASC", ARRAY_A );
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * Persist a pipeline (create or update). Returns the stored slug.
     * If $pipeline->id is empty, a new slug is generated.
     */
    public function save(Pipeline $pipeline): string
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $table = \hsync_table( 'pipelines' );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $slug  = $pipeline->id !== '' ? $pipeline->id : self::generateSlug();

        $payload = [
            'slug'       => $slug,
            'name'       => $pipeline->name,
            'definition' => wp_json_encode( [
                'steps' => array_map( static fn( PipelineStep $s ): array => [
                    'kind'   => $s->kind->value,
                    'ref_id' => $s->refId,
                    'params' => $s->params,
                    'note'   => $s->note,
                ], $pipeline->steps ),
                'meta'  => $pipeline->meta,
            ] ),
            'updated_at' => $now,
        ];

        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM `$table` WHERE slug = %s", $slug ),
        );

        if ( $existing ) {
            $wpdb->update( $table, $payload, [ 'id' => (int) $existing ] );
        } else {
            $payload['created_at'] = $now;
            $wpdb->insert( $table, $payload );
        }

        return $slug;
    }

    public function delete(string $slug): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        $table = \hsync_table( 'pipelines' );
        return (bool) $wpdb->delete( $table, [ 'slug' => $slug ] );
    }

    private static function generateSlug(): string
    {
        return self::ID_PREFIX . substr( md5( uniqid( '', true ) ), 0, 12 );
    }

    private static function hydrate(array $row): Pipeline
    {
        $def = json_decode( (string) ( $row['definition'] ?? '' ), true ) ?: [];
        $steps = [];
        foreach ( ( $def['steps'] ?? [] ) as $s ) {
            $kind = PipelineStepKind::tryFrom( (string) ( $s['kind'] ?? '' ) ) ?? PipelineStepKind::Operation;
            $steps[] = new PipelineStep(
                kind: $kind,
                refId: (string) ( $s['ref_id'] ?? '' ),
                params: (array) ( $s['params'] ?? [] ),
                note: isset( $s['note'] ) ? (string) $s['note'] : null,
            );
        }
        $meta = (array) ( $def['meta'] ?? [] );
        $meta['created_at'] = (string) ( $row['created_at'] ?? '' );
        $meta['updated_at'] = (string) ( $row['updated_at'] ?? '' );
        return new Pipeline(
            id: (string) ( $row['slug'] ?? '' ),
            name: (string) ( $row['name'] ?? '' ),
            steps: $steps,
            meta: $meta,
        );
    }
}
