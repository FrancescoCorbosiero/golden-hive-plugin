<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * CRUD over wp_hsync_source_configs. A SourceConfig is a saved bundle
 * of fields matching a Source's configSchema() — one named "GS prod"
 * plus another named "GS staging" so the user doesn't retype URLs and
 * tokens on every run.
 *
 * Secrets are stored cleartext (same posture as Hive Commerce's
 * feed-credentials.php — see CONVENTIONS.md). Redaction happens at
 * the AJAX/UI layer so plaintext never reaches the browser unless
 * the user explicitly requests it.
 *
 * Schema: id, slug, name, source_kind, config(JSON), created_at, updated_at
 */
final class SourceConfigRepository
{
    private const ID_PREFIX = 'cfg_';
    private const SECRET_KEYS = [ 'token', 'cookie', 'api_key', 'password', 'secret' ];

    public function find( string $slug ): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `" . \hsync_table( 'source_configs' ) . "` WHERE slug = %s", $slug ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all( ?string $sourceKind = null ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'source_configs' );
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

    /** @return array<int, array<string, mixed>> */
    public function allRedacted( ?string $sourceKind = null ): array
    {
        return array_map( [ self::class, 'redact' ], $this->all( $sourceKind ) );
    }

    /**
     * @param array{slug?: string, name: string, source_kind: string, config: array} $data
     * @param array<string, mixed> $existingConfig  When updating, current
     *        stored values — used to preserve secrets the client sent
     *        as redacted placeholders (• characters).
     */
    public function save( array $data, array $existingConfig = [] ): string
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $table = \hsync_table( 'source_configs' );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $slug  = ! empty( $data['slug'] ) ? (string) $data['slug'] : self::generateSlug();

        // Hydrate redacted secrets from prior stored values: client may
        // post '••••XXXX' when the user didn't change a saved password.
        $config = self::hydrateSecrets( (array) ( $data['config'] ?? [] ), $existingConfig );

        $payload = [
            'slug'        => $slug,
            'name'        => (string) ( $data['name'] ?? '' ),
            'source_kind' => (string) ( $data['source_kind'] ?? '' ),
            'config'      => wp_json_encode( $config ),
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
        return (bool) $wpdb->delete( \hsync_table( 'source_configs' ), [ 'slug' => $slug ] );
    }

    /**
     * Replace secret values with masked placeholders, keeping the last
     * 4 chars so the user can identify which token is stored without
     * exposing the full value.
     */
    public static function redact( array $row ): array
    {
        $cfg = (array) ( $row['config'] ?? [] );
        foreach ( self::SECRET_KEYS as $k ) {
            if ( isset( $cfg[ $k ] ) && is_string( $cfg[ $k ] ) && $cfg[ $k ] !== '' ) {
                $tail = substr( $cfg[ $k ], -4 );
                $cfg[ $k ] = '••••' . $tail;
            }
        }
        $row['config'] = $cfg;
        return $row;
    }

    /**
     * For each secret key, if the incoming value is a redaction
     * placeholder (starts with • OR equals empty string while existing
     * had a value), restore from the stored value. This lets the UI
     * post the form without re-typing passwords.
     */
    private static function hydrateSecrets( array $incoming, array $existing ): array
    {
        foreach ( self::SECRET_KEYS as $k ) {
            $hasIncoming = array_key_exists( $k, $incoming );
            $incomingVal = $hasIncoming ? (string) $incoming[ $k ] : '';
            $isRedacted  = $incomingVal !== '' && preg_match( '/^•+/u', $incomingVal );
            if ( $hasIncoming && ( $isRedacted || $incomingVal === '' ) && isset( $existing[ $k ] ) ) {
                $incoming[ $k ] = $existing[ $k ];
            }
        }
        return $incoming;
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
