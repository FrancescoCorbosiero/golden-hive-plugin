<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * CRUD over wp_hsync_mappings. A Mapping is a saved external→Woo field map
 * (column → product field, with per-field transforms). One source kind
 * (gs/csv) can have many saved mappings; the same shape powers both the
 * import wizard and the reusable presets dropdown.
 *
 * Schema: id, slug, name, source_kind, config(JSON), created_at, updated_at
 */
final class MappingRepository
{
    private const ID_PREFIX = 'map_';

    public function find(string $slug): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $table = \hsync_table( 'mappings' );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE slug = %s LIMIT 1", $slug ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all( ?string $sourceKind = null ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'mappings' );
        if ( $sourceKind !== null ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `$table` WHERE source_kind = %s ORDER BY id ASC", $sourceKind ),
                ARRAY_A,
            );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id ASC", ARRAY_A );
        }
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * @param array{slug?: string, name: string, source_kind: string, config: array} $data
     * @return string slug
     */
    public function save( array $data ): string
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $table = \hsync_table( 'mappings' );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $slug  = ! empty( $data['slug'] ) ? (string) $data['slug'] : self::generateSlug();

        $payload = [
            'slug'        => $slug,
            'name'        => (string) ( $data['name'] ?? '' ),
            'source_kind' => (string) ( $data['source_kind'] ?? '' ),
            'config'      => wp_json_encode( (array) ( $data['config'] ?? [] ) ),
            'updated_at'  => $now,
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

    public function delete( string $slug ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        return (bool) $wpdb->delete( \hsync_table( 'mappings' ), [ 'slug' => $slug ] );
    }

    private static function generateSlug(): string
    {
        return self::ID_PREFIX . substr( md5( uniqid( '', true ) ), 0, 12 );
    }

    private static function hydrate( array $row ): array
    {
        return [
            'id'          => (int) $row['id'],
            'slug'        => (string) $row['slug'],
            'name'        => (string) $row['name'],
            'source_kind' => (string) $row['source_kind'],
            'config'      => json_decode( (string) ( $row['config'] ?? '' ), true ) ?: [],
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }
}
