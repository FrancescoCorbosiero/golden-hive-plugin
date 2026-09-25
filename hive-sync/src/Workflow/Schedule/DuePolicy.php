<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * Which jobs get a slice right now, and in what order. Pure.
 *
 * A job is due when:
 *   - it has a run in flight and is enabled — the run continues (this is
 *     what used to wait for the next 5-minute tick between every 25s
 *     slice, ~8% duty cycle, turning 3 minutes of work into an hour);
 *   - it has a run in flight started with "Run" — manual runs finish
 *     even on a disabled job (the operator asked for this one run);
 *   - "Run" was pressed (run_control = 'run');
 *   - it is enabled and its next_run_at slot has arrived.
 *
 * A disabled job with a scheduled run in flight is PAUSED: the run keeps
 * its cursor and resumes when the job is re-enabled.
 *
 * A pending stop (run_control = 'stop') is never due — the runner
 * applies it instead of starting another slice.
 */
final class DuePolicy
{
    public const CONTROL_RUN  = 'run';
    public const CONTROL_STOP = 'stop';

    /**
     * @param array<string, mixed> $job hydrated job row
     */
    public static function isDue( array $job, int $now ): bool
    {
        $control = (string) ( $job['run_control'] ?? '' );
        if ( $control === self::CONTROL_STOP ) return false;

        $state = $job['run_state'] ?? null;
        if ( RunState::isRunning( $state ) ) {
            return ! empty( $job['enabled'] )
                || RunState::isManual( $state )
                || $control === self::CONTROL_RUN;
        }

        if ( $control === self::CONTROL_RUN ) return true;
        if ( empty( $job['enabled'] ) ) return false;

        $next = JobSchedule::parseUtc( $job['next_run_at'] ?? null );
        return $next !== null && $next <= $now;
    }

    /**
     * True when a stop is pending on a run that is actually in flight —
     * the one control that needs the lease to apply.
     *
     * @param array<string, mixed> $job
     */
    public static function hasPendingStop( array $job ): bool
    {
        return ( $job['run_control'] ?? '' ) === self::CONTROL_STOP
            && RunState::isRunning( $job['run_state'] ?? null );
    }

    /**
     * Due jobs in dispatch order:
     *   1. "Run" requests — someone is watching the screen;
     *   2. runs in flight, least recently served first — round-robin, so
     *      a huge SF import can't starve a 30-minute GS sync;
     *   3. scheduled slots, oldest first.
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    public static function select( array $jobs, int $now ): array
    {
        $due = [];
        foreach ( $jobs as $job ) {
            if ( is_array( $job ) && self::isDue( $job, $now ) ) $due[] = $job;
        }

        usort( $due, static function ( array $a, array $b ): int {
            $ka = self::sortKey( $a );
            $kb = self::sortKey( $b );
            return $ka <=> $kb;
        } );
        return $due;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{0: int, 1: string, 2: int}
     */
    private static function sortKey( array $job ): array
    {
        $state   = $job['run_state'] ?? null;
        $running = RunState::isRunning( $state );
        if ( ( $job['run_control'] ?? '' ) === self::CONTROL_RUN && ! $running ) {
            return [ 0, '', (int) ( $job['id'] ?? 0 ) ];
        }
        if ( $running ) {
            // Never-served ('') sorts before any timestamp.
            return [ 1, (string) ( $state['last_slice_at'] ?? '' ), (int) ( $job['id'] ?? 0 ) ];
        }
        return [ 2, (string) ( $job['next_run_at'] ?? '' ), (int) ( $job['id'] ?? 0 ) ];
    }
}
