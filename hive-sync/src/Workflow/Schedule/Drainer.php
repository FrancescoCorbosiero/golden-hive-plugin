<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

/**
 * The drain loop: keep giving due jobs slices until nothing is due or
 * the wall-clock budget is spent. Pure — every side effect is a callable,
 * so the loop's stopping rules are unit-tested without WordPress.
 *
 * Why a loop and not "one slice per cron tick": a run needing N slices
 * used to take N heartbeats. At 25s of work per 5-minute tick, a
 * 3-minute sync took an hour and a big SF import took most of a night —
 * and while a run is going its job doesn't start new ones, so the
 * schedule "skipped" slot after slot. Inside one cron request the loop
 * chains slices back to back; the next heartbeat picks up whatever the
 * budget left over.
 *
 * Each pass re-reads the due list, so a job that finishes drops out,
 * a job whose slot arrived mid-drain joins, and a "Run" pressed while
 * the loop is going is picked up on the next pass.
 */
final class Drainer
{
    /** Hard stop on passes — a safety net against a pathological loop. */
    public const MAX_PASSES = 500;

    public function __construct(
        private readonly float $budgetSeconds,
        private readonly float $minSliceSeconds,
    ) {}

    /**
     * @param callable(): array<int, array<string, mixed>>        $dueJobs   fresh due list, in order (once per pass)
     * @param callable(array<string, mixed>, float): ?array       $runSlice  (job, seconds left) → envelope, or null
     *                                                                        when the job turned out not to be due
     * @param callable(): bool                                    $keepGoing checked before every slice (lease, memory)
     * @param callable(): float                                   $clock     seconds, monotonic enough (microtime)
     * @param (callable(): void)|null                             $betweenPasses
     * @return array{passes: int, slices: int, stopped: string, results: array<int, array<string, mixed>>}
     */
    public function drain(
        callable $dueJobs,
        callable $runSlice,
        callable $keepGoing,
        callable $clock,
        ?callable $betweenPasses = null,
    ): array {
        $start   = $clock();
        $passes  = 0;
        $slices  = 0;
        $results = [];
        $stopped = 'drained';

        while ( true ) {
            $jobs = $dueJobs();
            if ( ! $jobs ) {
                $stopped = $passes === 0 ? 'nothing_due' : 'drained';
                break;
            }
            if ( $passes >= self::MAX_PASSES ) {
                $stopped = 'max_passes';
                break;
            }
            $passes++;

            $ranThisPass = 0;
            foreach ( $jobs as $job ) {
                $left = $this->budgetSeconds - ( $clock() - $start );
                // The first slice always runs — an interactive "Esegui ora"
                // with a small budget must still do something.
                if ( $slices > 0 && $left < $this->minSliceSeconds ) {
                    $stopped = 'budget';
                    break 2;
                }
                if ( ! $keepGoing() ) {
                    $stopped = 'halted';
                    break 2;
                }
                $envelope = $runSlice( $job, max( $left, $this->minSliceSeconds ) );
                if ( $envelope === null ) continue;

                $results[ (int) ( $job['id'] ?? 0 ) ] = $envelope;
                $slices++;
                $ranThisPass++;
            }

            if ( $ranThisPass === 0 ) {
                // Everything listed as due declined on re-check (state
                // moved under us). Re-querying would list the same jobs
                // again — stop instead of spinning.
                $stopped = 'drained';
                break;
            }
            if ( $betweenPasses !== null ) $betweenPasses();
        }

        return [
            'passes'  => $passes,
            'slices'  => $slices,
            'stopped' => $stopped,
            'results' => $results,
        ];
    }
}
