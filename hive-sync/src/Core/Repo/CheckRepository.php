<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * CRUD over wp_hsync_checks. A row here is a SAVED check definition —
 * a reusable configuration of a registered Check (id + params + phase).
 * The Check classes themselves live in code; this table persists the
 * configured instances the user composes into Pipelines and Rules.
 *
 * Schema: id, slug, name, phase, config(JSON), created_at, updated_at
 * phase ∈ { 'pre_import', 'post_import', 'audit' }
 */
final class CheckRepository
{
    private const ID_PREFIX = 'chk_';

    public function find( string $slug ): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `" . \hsync_table( 'checks' ) . "` WHERE slug = %s", $slug ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all( ?string $phase = null ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'checks' );
        if ( $phase !== null ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `$table` WHERE phase = %s ORDER BY id ASC", $phase ),
                ARRAY_A,
            );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id ASC", ARRAY_A );
        }
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * @param array{slug?: string, name: string, phase: string, config: array} $data
     */
    public function save( array $data ): string
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $table = \hsync_table( 'checks' );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $slug  = ! empty( $data['slug'] ) ? (string) $data['slug'] : self::generateSlug();

        $payload = [
            'slug'       => $slug,
            'name'       => (string) ( $data['name'] ?? '' ),
            'phase'      => (string) ( $data['phase'] ?? 'audit' ),
            'config'     => wp_json_encode( (array) ( $data['config'] ?? [] ) ),
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

    public function delete( string $slug ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        return (bool) $wpdb->delete( \hsync_table( 'checks' ), [ 'slug' => $slug ] );
    }

    private static function generateSlug(): string
    {
        return self::ID_PREFIX . substr( md5( uniqid( '', true ) ), 0, 12 );
    }

    private static function hydrate( array $row ): array
    {
        return [
            'id'         => (int) $row['id'],
            'slug'       => (string) $row['slug'],
            'name'       => (string) $row['name'],
            'phase'      => (string) $row['phase'],
            'config'     => json_decode( (string) ( $row['config'] ?? '' ), true ) ?: [],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
