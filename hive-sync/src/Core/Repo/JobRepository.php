<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * CRUD over wp_hsync_jobs. A Job is a scheduled or ad-hoc Runnable
 * reference: (runnable_type, runnable_ref) plus an optional cron_expr.
 *
 * runnable_type ∈ { 'pipeline', 'rule', 'source.import', 'operation', 'check' }
 * runnable_ref  → slug of the corresponding entity (or composite for ad-hoc)
 *
 * Schema: id, runnable_type, runnable_ref, cron_expr, enabled, next_run_at,
 *         last_run_at, last_run_status, config(JSON), created_at, updated_at
 */
final class JobRepository
{
    public function find( int $id ): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $table = \hsync_table( 'jobs' );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d LIMIT 1", $id ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all( bool $enabledOnly = false ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'jobs' );
        $sql   = "SELECT * FROM `$table`" . ( $enabledOnly ? ' WHERE enabled = 1' : '' ) . ' ORDER BY id ASC';
        $rows  = $wpdb->get_results( $sql, ARRAY_A );
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /** @return array<int, array<string, mixed>> */
    public function dueNow( int $now ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $table = \hsync_table( 'jobs' );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `$table` WHERE enabled = 1 AND next_run_at IS NOT NULL AND next_run_at <= %s ORDER BY next_run_at ASC",
                gmdate( 'Y-m-d H:i:s', $now ),
            ),
            ARRAY_A,
        );
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    /**
     * @param array{id?: int, runnable_type: string, runnable_ref: string, cron_expr?: ?string, enabled?: bool, next_run_at?: ?string, config?: array} $data
     * @return int job id
     */
    public function save( array $data ): int
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $table = \hsync_table( 'jobs' );
        $now   = gmdate( 'Y-m-d H:i:s' );

        $payload = [
            'runnable_type' => (string) ( $data['runnable_type'] ?? '' ),
            'runnable_ref'  => (string) ( $data['runnable_ref'] ?? '' ),
            'cron_expr'     => isset( $data['cron_expr'] ) && $data['cron_expr'] !== '' ? (string) $data['cron_expr'] : null,
            'enabled'       => ! empty( $data['enabled'] ) ? 1 : 0,
            'next_run_at'   => isset( $data['next_run_at'] ) && $data['next_run_at'] !== '' ? (string) $data['next_run_at'] : null,
            'config'        => wp_json_encode( (array) ( $data['config'] ?? [] ) ),
            'updated_at'    => $now,
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $payload, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        }

        $payload['created_at'] = $now;
        $wpdb->insert( $table, $payload );
        return (int) $wpdb->insert_id;
    }

    public function recordRun( int $jobId, string $status, ?string $nextRunAt ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $wpdb->update(
            \hsync_table( 'jobs' ),
            [
                'last_run_at'     => gmdate( 'Y-m-d H:i:s' ),
                'last_run_status' => $status,
                'next_run_at'     => $nextRunAt,
                'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ 'id' => $jobId ],
        );
    }

    public function delete( int $id ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        return (bool) $wpdb->delete( \hsync_table( 'jobs' ), [ 'id' => $id ] );
    }

    private static function hydrate( array $row ): array
    {
        return [
            'id'              => (int) $row['id'],
            'runnable_type'   => (string) $row['runnable_type'],
            'runnable_ref'    => (string) $row['runnable_ref'],
            'cron_expr'       => $row['cron_expr'] !== null ? (string) $row['cron_expr'] : null,
            'enabled'         => (int) $row['enabled'] === 1,
            'next_run_at'     => $row['next_run_at'] !== null ? (string) $row['next_run_at'] : null,
            'last_run_at'     => $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null,
            'last_run_status' => $row['last_run_status'] !== null ? (string) $row['last_run_status'] : null,
            'config'          => json_decode( (string) ( $row['config'] ?? '' ), true ) ?: [],
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
        ];
    }
}
