<?php
declare(strict_types=1);

namespace HiveSync\Core\Repo;

/**
 * CRUD over wp_hsync_runs. A Run is the audit record for one execution
 * of a Runnable — created at start, updated incrementally as the executor
 * yields/resumes (cooperative cursoring), finalized on completion or failure.
 *
 * Schema: id, job_id, runnable_type, runnable_ref, status, started_at,
 *         finished_at, items_total, items_done, items_failed, report(JSON)
 */
final class RunRepository
{
    public function start( int $jobId, string $runnableType, string $runnableRef ): int
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            throw new \RuntimeException( 'wpdb unavailable' );
        }
        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->insert(
            \hsync_table( 'runs' ),
            [
                'job_id'         => $jobId > 0 ? $jobId : null,
                'runnable_type'  => $runnableType,
                'runnable_ref'   => $runnableRef,
                'status'         => 'running',
                'started_at'     => $now,
            ],
        );
        return (int) $wpdb->insert_id;
    }

    public function progress( int $runId, int $total, int $done, int $failed ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $wpdb->update(
            \hsync_table( 'runs' ),
            [
                'items_total'  => $total,
                'items_done'   => $done,
                'items_failed' => $failed,
            ],
            [ 'id' => $runId ],
        );
    }

    public function finish( int $runId, string $status, array $report = [] ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $wpdb->update(
            \hsync_table( 'runs' ),
            [
                'status'      => $status,
                'finished_at' => gmdate( 'Y-m-d H:i:s' ),
                'report'      => wp_json_encode( $report ),
            ],
            [ 'id' => $runId ],
        );
    }

    public function find( int $id ): ?array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return null;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `" . \hsync_table( 'runs' ) . "` WHERE id = %d", $id ),
            ARRAY_A,
        );
        return $row ? self::hydrate( $row ) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent( int $limit = 50 ): array
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return [];
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `" . \hsync_table( 'runs' ) . "` ORDER BY started_at DESC LIMIT %d",
                $limit,
            ),
            ARRAY_A,
        );
        return array_map( [ self::class, 'hydrate' ], (array) $rows );
    }

    private static function hydrate( array $row ): array
    {
        return [
            'id'             => (int) $row['id'],
            'job_id'         => $row['job_id'] !== null ? (int) $row['job_id'] : null,
            'runnable_type'  => (string) $row['runnable_type'],
            'runnable_ref'   => (string) $row['runnable_ref'],
            'status'         => (string) $row['status'],
            'started_at'     => (string) $row['started_at'],
            'finished_at'    => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
            'items_total'    => $row['items_total'] !== null ? (int) $row['items_total'] : null,
            'items_done'     => $row['items_done'] !== null ? (int) $row['items_done'] : null,
            'items_failed'   => $row['items_failed'] !== null ? (int) $row['items_failed'] : null,
            'report'         => json_decode( (string) ( $row['report'] ?? '' ), true ) ?: [],
        ];
    }
}
