<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * CRUD over wp_hsync_rules. A Rule = (Selection filter) + (Operation stack)
 * + (optional Checks). It's basically a scoped Pipeline persisted as a
 * first-class entity so it can be CRUD'd, listed, edited, and run as a
 * Job independently.
 *
 * Schema: id, slug, name, selection(JSON), operations(JSON), checks(JSON?),
 *         enabled, created_at, updated_at
 */
final class RuleRepository
{
    private const ID_PREFIX = 'rule_';

    public function find( string $slug ): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $table = \hsync_table( 'rules' );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE slug = %s LIMIT 1", $slug ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all( bool $enabledOnly = false ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'rules' );
        $sql   = "SELECT * FROM `$table`" . ( $enabledOnly ? ' WHERE enabled = 1' : '' ) . ' ORDER BY id ASC';
        $rows  = $wpdb->get_results( $sql, ARRAY_A );
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * @param array{slug?: string, name: string, selection: array, operations: array, checks?: array, enabled?: bool} $data
     */
    public function save( array $data ): string
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $table = \hsync_table( 'rules' );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $slug  = ! empty( $data['slug'] ) ? (string) $data['slug'] : self::generateSlug();

        $payload = [
            'slug'       => $slug,
            'name'       => (string) ( $data['name'] ?? '' ),
            'selection'  => wp_json_encode( (array) ( $data['selection'] ?? [] ) ),
            'operations' => wp_json_encode( (array) ( $data['operations'] ?? [] ) ),
            'checks'     => wp_json_encode( (array) ( $data['checks'] ?? [] ) ),
            'enabled'    => ! empty( $data['enabled'] ) ? 1 : 0,
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
        return (bool) $wpdb->delete( \hsync_table( 'rules' ), [ 'slug' => $slug ] );
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
            'selection'  => json_decode( (string) ( $row['selection'] ?? '' ), true ) ?: [],
            'operations' => json_decode( (string) ( $row['operations'] ?? '' ), true ) ?: [],
            'checks'     => json_decode( (string) ( $row['checks'] ?? '' ), true ) ?: [],
            'enabled'    => (int) $row['enabled'] === 1,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
