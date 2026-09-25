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
 *         last_run_at, last_run_status, config(JSON), run_state(JSON),
 *         run_control, created_at, updated_at
 *
 * Column ownership — the rule that keeps concurrent writers from
 * clobbering each other:
 *
 *   operator (editor, project.json, seeder) → runnable_*, cron_expr,
 *                                              enabled, config
 *   runner (lease holder only)              → run_state, last_run_*
 *   both                                    → next_run_at (editor re-arms
 *                                              it on cron/enable changes,
 *                                              the runner plans slots)
 *   requests (AJAX, no lease)               → run_control, via the CAS
 *                                              helpers below
 *
 * save() never writes run_state / run_control, and the runner's writes
 * never touch config — each UPDATE lists only its own columns.
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

    // ─── Runner-owned writes (lease holder only) ──────────────────

    /**
     * A run starts: store its state and, for a scheduled run, the next
     * planned slot. Manual runs leave the schedule alone ($setNext=false).
     *
     * @param array<string, mixed> $runState
     */
    public function beginRun( int $jobId, array $runState, ?string $nextRunAt, bool $setNext ): void
    {
        $fields = [
            'run_state'       => wp_json_encode( $runState ),
            'last_run_status' => 'running',
            'last_run_at'     => gmdate( 'Y-m-d H:i:s' ),
        ];
        if ( $setNext ) $fields['next_run_at'] = $nextRunAt;
        $this->updateColumns( $jobId, $fields );
    }

    /**
     * After a slice that leaves the run in flight.
     *
     * @param array<string, mixed> $runState
     */
    public function saveRunState( int $jobId, array $runState, string $status ): void
    {
        $this->updateColumns( $jobId, [
            'run_state'       => wp_json_encode( $runState ),
            'last_run_status' => $status,
            'last_run_at'     => gmdate( 'Y-m-d H:i:s' ),
        ] );
    }

    /**
     * The run is over (done / failed / partial / cancelled): clear its
     * state and set the next slot.
     */
    public function finishRun( int $jobId, string $status, ?string $nextRunAt ): void
    {
        $this->updateColumns( $jobId, [
            'run_state'       => null,
            'last_run_status' => $status,
            'last_run_at'     => gmdate( 'Y-m-d H:i:s' ),
            'next_run_at'     => $nextRunAt,
        ] );
    }

    /**
     * Raw run_state write — the one-shot migration of legacy
     * config._resume_cursor cursors uses it.
     *
     * @param array<string, mixed>|null $runState
     */
    public function setRunState( int $jobId, ?array $runState ): void
    {
        $this->updateColumns( $jobId, [
            'run_state' => $runState === null ? null : wp_json_encode( $runState ),
        ] );
    }

    public function setNextRunAt( int $jobId, ?string $nextRunAt ): void
    {
        $this->updateColumns( $jobId, [ 'next_run_at' => $nextRunAt ] );
    }

    /**
     * Config-only write (migrations). Operator-owned column: the runner
     * never calls this.
     *
     * @param array<string, mixed> $config
     */
    public function updateConfig( int $jobId, array $config ): void
    {
        $this->updateColumns( $jobId, [ 'config' => wp_json_encode( $config ) ] );
    }

    // ─── Lock-free writes (single atomic statements) ──────────────

    /**
     * Give an enabled cron job its first slot. Conditional on the slot
     * still being empty, so it can't overwrite one planned meanwhile.
     */
    public function armNextRun( int $jobId, string $nextRunAt ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        $table = \hsync_table( 'jobs' );
        $rows = $wpdb->query( $wpdb->prepare(
            "UPDATE `$table` SET next_run_at = %s WHERE id = %d AND next_run_at IS NULL",
            $nextRunAt,
            $jobId,
        ) );
        return $rows === 1;
    }

    /**
     * Leave a request for the runner: 'run' or 'stop'. Last click wins.
     */
    public function requestControl( int $jobId, string $control ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        $rows = $wpdb->update(
            \hsync_table( 'jobs' ),
            [ 'run_control' => $control ],
            [ 'id' => $jobId ],
        );
        return $rows !== false;
    }

    /**
     * Consume a request — only if it is still the one we acted on, so a
     * click that landed meanwhile is not swallowed.
     */
    public function clearControl( int $jobId, string $expected ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $table = \hsync_table( 'jobs' );
        $wpdb->query( $wpdb->prepare(
            "UPDATE `$table` SET run_control = NULL WHERE id = %d AND run_control = %s",
            $jobId,
            $expected,
        ) );
    }

    public function delete( int $id ): bool
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return false;
        return (bool) $wpdb->delete( \hsync_table( 'jobs' ), [ 'id' => $id ] );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateColumns( int $jobId, array $fields ): void
    {
        global $wpdb;
        if ( ! isset( $wpdb ) ) return;
        $fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        $wpdb->update( \hsync_table( 'jobs' ), $fields, [ 'id' => $jobId ] );
    }

    private static function hydrate( array $row ): array
    {
        $runState = null;
        if ( isset( $row['run_state'] ) && $row['run_state'] !== null && $row['run_state'] !== '' ) {
            $decoded  = json_decode( (string) $row['run_state'], true );
            $runState = is_array( $decoded ) ? $decoded : null;
        }
        $control = isset( $row['run_control'] ) && $row['run_control'] !== null && $row['run_control'] !== ''
            ? (string) $row['run_control']
            : null;

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
            'run_state'       => $runState,
            'run_control'     => $control,
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
        ];
    }
}
