<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\RunRepository;

/**
 * One-shot move of existing jobs onto the 1.2 scheduler:
 *
 *   1. A run in flight under the old runner kept its cursor in
 *      config._resume_cursor. It moves to run_state, so the upgrade
 *      resumes it instead of dropping it (and the key leaves config,
 *      where the editor's save could overwrite it).
 *   2. Seeded labels carried the cadence ("GS — Sync catalogo
 *      (idempotente, ogni 2h)"). The operator changes the cron, the
 *      label keeps lying next to it. The cadence is stripped — the card
 *      shows the real one, from the cron.
 *   3. next_run_at is re-planned in the site timezone: slots computed by
 *      the old UTC reading would otherwise fire once more at the old hour.
 *      A slot already due is left alone (it runs on the next heartbeat).
 *
 * Idempotent: every step is a no-op on a job already migrated.
 */
final class SchedulerMigration
{
    /**
     * @return array{resumed: int, relabelled: int, replanned: int}
     */
    public static function run( JobRepository $jobs, RunRepository $runs, JobSchedule $schedule, int $now ): array
    {
        $tally = [ 'resumed' => 0, 'relabelled' => 0, 'replanned' => 0 ];

        foreach ( $jobs->all() as $job ) {
            $id     = (int) $job['id'];
            $config = is_array( $job['config'] ?? null ) ? $job['config'] : [];
            $dirty  = false;

            // 1. Legacy cursor → run_state.
            $running = RunState::isRunning( $job['run_state'] ?? null );
            if ( array_key_exists( '_resume_cursor', $config ) ) {
                $cursor = $config['_resume_cursor'];
                unset( $config['_resume_cursor'] );
                $dirty = true;

                if ( ! $running && is_array( $cursor ) && $cursor !== [] ) {
                    $runId = (int) ( $cursor['run_id'] ?? 0 );
                    $row   = $runId > 0 ? $runs->find( $runId ) : null;
                    $state = self::legacyRunState( [ 'config' => $config ] + $job, $cursor, $row, $now );
                    $jobs->setRunState( $id, $state );
                    $job['run_state'] = $state;
                    $running = true;
                    $tally['resumed']++;
                }
            }

            // 2. Cadence out of the labels.
            foreach ( [ '_seed_label', '_label' ] as $key ) {
                if ( ! isset( $config[ $key ] ) || ! is_string( $config[ $key ] ) ) continue;
                $clean = self::stripCadence( $config[ $key ] );
                if ( $clean !== $config[ $key ] ) {
                    $config[ $key ] = $clean;
                    $dirty = true;
                    if ( $key === '_seed_label' ) $tally['relabelled']++;
                }
            }

            if ( $dirty ) $jobs->updateConfig( $id, $config );

            // 3. Re-plan in the site timezone.
            if ( ! empty( $job['enabled'] ) ) {
                $planned = JobSchedule::parseUtc( $job['next_run_at'] ?? null );
                // A resumed run's next_run_at is the old runner's "resume
                // now" timestamp, not a slot — always re-plan those.
                if ( $running || $planned === null || $planned > $now ) {
                    $next = $schedule->nextAfter( $job['cron_expr'] ?? null, $now );
                    $nextUtc = JobSchedule::formatUtc( $next );
                    if ( $nextUtc !== ( $job['next_run_at'] ?? null ) ) {
                        $jobs->setNextRunAt( $id, $nextUtc );
                        $tally['replanned']++;
                    }
                }
            }
        }
        return $tally;
    }

    /**
     * "GS — Sync catalogo (idempotente, ogni 2h)" → "GS — Sync catalogo".
     * Only a parenthetical that states a cadence ("ogni …") is removed.
     */
    public static function stripCadence( string $label ): string
    {
        $clean = preg_replace( '/\s*\((?:[^()]*,\s*)?ogni\s[^()]*\)/iu', '', $label );
        $clean = trim( (string) $clean );
        return $clean !== '' ? $clean : $label;
    }

    /**
     * run_state for a run the old runner left in flight.
     *
     * @param array<string, mixed>      $job    hydrated job (config already without _resume_cursor)
     * @param array<string, mixed>      $cursor the legacy cursor
     * @param array<string, mixed>|null $runRow its wp_hsync_runs row, when still there
     * @return array<string, mixed>
     */
    public static function legacyRunState( array $job, array $cursor, ?array $runRow, int $now ): array
    {
        $startedTs = JobSchedule::parseUtc( $runRow['started_at'] ?? null )
            ?? JobSchedule::parseUtc( $job['last_run_at'] ?? null )
            ?? $now;

        $state = RunState::begin( RunState::TRIGGER_SCHEDULE, null, $startedTs, RunState::snapshotOf( $job ) );
        $state['cursor']        = $cursor;
        $state['run_id']        = (int) ( $cursor['run_id'] ?? 0 ) ?: null;
        $state['last_slice_at'] = $job['last_run_at'] ?? null;
        if ( $runRow ) {
            $state['progress'] = [
                'done'  => (int) ( $cursor['index'] ?? 0 ),
                'total' => (int) ( $runRow['items_total'] ?? 0 ),
            ];
        }
        return $state;
    }
}
