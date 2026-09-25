<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

use HiveSync\Core\Bootstrap;
use HiveSync\Core\Check\CheckRegistry;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationRegistry;
use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineExecutor;
use HiveSync\Core\Pipeline\PipelineStep;
use HiveSync\Core\Pipeline\PipelineStepKind;
use HiveSync\Core\Repo\JobRepository;
use HiveSync\Core\Repo\MappingRepository;
use HiveSync\Core\Repo\RuleRepository;
use HiveSync\Core\Repo\RunRepository;
use HiveSync\Core\Repo\SourceConfigRepository;
use HiveSync\Core\Selection\Selection;
use HiveSync\Core\Selection\SelectionMode;
use HiveSync\Core\Source\Context;
use HiveSync\Workflow\Run\ImportRunner;
use HiveSync\Workflow\Run\RunCache;

/**
 * Runs scheduled jobs.
 *
 *   tick($now, $budget)   The heartbeat (WP-Cron, every minute) and
 *                         "Esegui ora". Takes the runner lease, then
 *                         DRAINS: keeps giving due jobs 25s slices until
 *                         nothing is due or the budget is spent.
 *   runJobNow($id)        "Run": leaves a run request on the job, then
 *                         ticks with a short budget. If a drain is
 *                         already going, the request is picked up by it.
 *   requestStop($id)      "Interrompi": leaves a stop request; applied
 *                         now if the lease is free, else after the
 *                         slice in progress.
 *
 * One run = one wp_hsync_runs row, however many slices it takes. Its
 * state lives in wp_hsync_jobs.run_state (see RunState) and every slice
 * dispatches the snapshot the run started with.
 *
 * Scheduling (see JobSchedule):
 *   - a scheduled run starts on its slot and immediately plans the next
 *     one, anchored on the grid — next_run_at is right while it runs;
 *   - slots that pass while it runs are skipped and counted in the run's
 *     report (`schedule.skipped_slots`), never replayed back-to-back;
 *   - a manual run leaves the grid alone.
 *
 * Per-type continuation:
 *   source.import           continues while ImportRunner hands back a
 *                           cursor.
 *   kicksdb.refresh_prices  continues until the run has refreshed its
 *                           quota (max_per_tick) or used KICKSDB_MAX_SLICES
 *                           slices — without a cap a slow API would keep
 *                           a drain calling it for the whole budget.
 *   rule                    never continues: its selection is re-resolved
 *                           every call, so a positional cursor could skip
 *                           products. An unfinished rule run ends 'partial'
 *                           and the next slot starts it over.
 */
final class JobRunner
{
    /** Per-slice cooperative deadline — the same the Importa tick loop uses. */
    public const SLICE_SECONDS = 25;
    /** A drain never starts a slice with less than this left in its budget. */
    public const MIN_SLICE_SECONDS = 8;
    /** Budget of a drain started from an AJAX request ("Esegui ora", "Run"). */
    public const INTERACTIVE_BUDGET = 25;
    /** KicksDB: max slices one run may chain. */
    public const KICKSDB_MAX_SLICES = 12;

    private RunnerLease $lease;
    private JobSchedule $schedule;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly RunRepository $runs,
        private readonly RuleRepository $rules,
        private readonly SourceConfigRepository $sourceConfigs,
        private readonly ?MappingRepository $mappings = null,
        ?RunnerLease $lease = null,
        ?JobSchedule $schedule = null,
    ) {
        $this->lease    = $lease ?? RunnerLease::forSite();
        $this->schedule = $schedule ?? JobSchedule::forSite();
    }

    public function schedule(): JobSchedule
    {
        return $this->schedule;
    }

    /**
     * @return array{dispatched: int, skipped: int, locked: bool, passes: int, slices: int, stopped: string, results: array<int, array<string, mixed>>}
     */
    public function tick( int $now, float $budget = self::INTERACTIVE_BUDGET ): array
    {
        $jobs = $this->housekeep( $this->jobs->all(), $now );

        // Idle heartbeat — the common case, every minute: two cheap reads,
        // no lease write.
        if ( ! self::hasWork( $jobs, $now ) ) {
            return self::tickResult( false, [ 'passes' => 0, 'slices' => 0, 'stopped' => 'nothing_due', 'results' => [] ] );
        }
        if ( ! $this->lease->acquire() ) {
            return self::tickResult( true, [ 'passes' => 0, 'slices' => 0, 'stopped' => 'locked', 'results' => [] ] );
        }

        // A fatal mid-slice (OOM, time limit) skips `finally`. Release on
        // shutdown too, so the next heartbeat doesn't wait out the TTL.
        // No-op after a normal release, and a CAS on our own token: it can
        // never drop a lease another process has taken over.
        $lease = $this->lease;
        register_shutdown_function( static function () use ( $lease ): void {
            $lease->release();
        } );

        try {
            $out = ( new Drainer( $budget, self::MIN_SLICE_SECONDS ) )->drain(
                function (): array {
                    $this->applyControls();
                    return DuePolicy::select( $this->jobs->all(), time() );
                },
                fn( array $job, float $left ): ?array => $this->runSlice( (int) ( $job['id'] ?? 0 ), $left ),
                fn(): bool => $this->lease->renew() && self::refreshHeadroom(),
                static fn(): float => microtime( true ),
                static function (): void {
                    self::flushRuntimeCache();
                },
            );
        } finally {
            $this->lease->release();
        }

        return self::tickResult( false, $out );
    }

    /**
     * "Run" on a job: request a run, then try to serve it right away.
     *
     * @return array<string, mixed> the slice envelope, or status=queued when
     *                              the runner is busy (the drain in progress
     *                              picks the request up on its next pass)
     */
    public function runJobNow( int $jobId, float $budget = self::INTERACTIVE_BUDGET ): array
    {
        if ( ! $this->jobs->find( $jobId ) ) {
            return [ 'status' => 'failed', 'error' => 'job_not_found' ];
        }
        $this->jobs->requestControl( $jobId, DuePolicy::CONTROL_RUN );

        $tick = $this->tick( time(), $budget );
        $env  = $tick['results'][ $jobId ] ?? null;
        if ( $env === null ) {
            return [ 'status' => 'queued', 'job_id' => $jobId, 'runner_busy' => $tick['locked'] ];
        }
        return $env + [ 'job_id' => $jobId ];
    }

    /**
     * "Interrompi" on a job.
     *
     * @return array{stopped: bool, requested?: bool, idle?: bool, error?: string}
     */
    public function requestStop( int $jobId ): array
    {
        $job = $this->jobs->find( $jobId );
        if ( ! $job ) return [ 'stopped' => false, 'error' => 'job_not_found' ];

        if ( ! RunState::isRunning( $job['run_state'] ?? null ) ) {
            // Nothing in flight; drop a pending "Run" if there is one.
            if ( ( $job['run_control'] ?? '' ) === DuePolicy::CONTROL_RUN ) {
                $this->jobs->clearControl( $jobId, DuePolicy::CONTROL_RUN );
            }
            return [ 'stopped' => true, 'idle' => true ];
        }

        $this->jobs->requestControl( $jobId, DuePolicy::CONTROL_STOP );
        if ( ! $this->lease->acquire() ) {
            // A drain holds the lease: it applies the stop right after the
            // slice it is running (see settle()).
            return [ 'stopped' => false, 'requested' => true ];
        }
        try {
            $this->applyControls();
        } finally {
            $this->lease->release();
        }
        return [ 'stopped' => true ];
    }

    /**
     * A job is being deleted: close its run in flight so the run row and
     * its fetch cache don't linger. Safe without the lease — if a drain
     * is mid-slice on it, settle() finds the job gone and closes it again.
     *
     * @param array<string, mixed> $job
     */
    public function forgetRun( array $job ): void
    {
        $runId = RunState::runId( is_array( $job['run_state'] ?? null ) ? $job['run_state'] : null );
        if ( $runId > 0 ) {
            $this->runs->cancel( $runId, 'job_deleted' );
            RunCache::clear( $runId );
        }
    }

    // ─── Drain internals ──────────────────────────────────────────

    /**
     * Lock-free upkeep on the raw job list, before deciding whether to
     * take the lease at all:
     *   - arm enabled cron jobs that have no slot yet (fresh from the
     *     seeder or a project.json apply, which don't compute one — those
     *     jobs used to sit enabled and never run);
     *   - drop a stop request left on a job that is no longer running.
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    private function housekeep( array $jobs, int $now ): array
    {
        foreach ( $jobs as $i => $job ) {
            $id      = (int) ( $job['id'] ?? 0 );
            $running = RunState::isRunning( $job['run_state'] ?? null );

            if ( ! empty( $job['enabled'] ) && ! $running && ( $job['next_run_at'] ?? null ) === null ) {
                $next = $this->schedule->nextAfter( $job['cron_expr'] ?? null, $now );
                if ( $next !== null && $this->jobs->armNextRun( $id, (string) JobSchedule::formatUtc( $next ) ) ) {
                    $jobs[ $i ]['next_run_at'] = JobSchedule::formatUtc( $next );
                }
            }
            if ( ( $job['run_control'] ?? '' ) === DuePolicy::CONTROL_STOP && ! $running ) {
                $this->jobs->clearControl( $id, DuePolicy::CONTROL_STOP );
                $jobs[ $i ]['run_control'] = null;
            }
        }
        return $jobs;
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    private static function hasWork( array $jobs, int $now ): bool
    {
        foreach ( $jobs as $job ) {
            if ( DuePolicy::isDue( $job, $now ) || DuePolicy::hasPendingStop( $job ) ) return true;
        }
        return false;
    }

    /**
     * Apply pending requests (lease held).
     */
    private function applyControls(): void
    {
        foreach ( $this->jobs->all() as $job ) {
            $control = (string) ( $job['run_control'] ?? '' );
            if ( $control === '' ) continue;

            $id      = (int) $job['id'];
            $state   = $job['run_state'] ?? null;
            $running = RunState::isRunning( $state );

            if ( $control === DuePolicy::CONTROL_STOP ) {
                if ( $running ) $this->cancelRun( $job, 'operator' );
                $this->jobs->clearControl( $id, DuePolicy::CONTROL_STOP );
            } elseif ( $control === DuePolicy::CONTROL_RUN && $running ) {
                // "Run" on a run already in flight: that IS the requested
                // run. Promote it to manual so it also finishes on a
                // disabled job — which is how a paused run is resumed.
                if ( ! RunState::isManual( $state ) ) {
                    $state['trigger'] = RunState::TRIGGER_MANUAL;
                    $this->jobs->saveRunState( $id, $state, (string) ( $job['last_run_status'] ?? 'continue' ) );
                }
                $this->jobs->clearControl( $id, DuePolicy::CONTROL_RUN );
            }
        }
    }

    /**
     * One slice of one job (lease held). Null when, on re-read, the job
     * is no longer due (edited, stopped or deleted since the pass began).
     *
     * @return array<string, mixed>|null
     */
    private function runSlice( int $jobId, float $secondsLeft ): ?array
    {
        $job = $jobId > 0 ? $this->jobs->find( $jobId ) : null;
        $now = time();
        if ( ! $job || ! DuePolicy::isDue( $job, $now ) ) return null;

        $state   = $job['run_state'] ?? null;
        $control = (string) ( $job['run_control'] ?? '' );

        if ( ! RunState::isRunning( $state ) ) {
            $manual  = $control === DuePolicy::CONTROL_RUN;
            // The slot this run serves: the due one. A "Run" pressed while
            // the job's slot has also arrived serves that slot too — else
            // the slot would be reported as skipped once the run ends.
            $planned = JobSchedule::parseUtc( $job['next_run_at'] ?? null );
            $slot    = ! empty( $job['enabled'] ) && $planned !== null && $planned <= $now ? $planned : null;
            $state   = RunState::begin(
                $manual ? RunState::TRIGGER_MANUAL : RunState::TRIGGER_SCHEDULE,
                $slot,
                $now,
                RunState::snapshotOf( $job ),
            );
            // Serving a slot plans the next one right away, anchored on it.
            // Otherwise (a manual run between slots, or on a disabled job)
            // the schedule is left alone.
            $next = $slot !== null ? $this->schedule->planAfter( $job['cron_expr'] ?? null, $slot, $now ) : null;
            $this->jobs->beginRun( $jobId, $state, JobSchedule::formatUtc( $next ), $slot !== null );
            if ( $manual ) $this->jobs->clearControl( $jobId, DuePolicy::CONTROL_RUN );
        } elseif ( $control === DuePolicy::CONTROL_RUN ) {
            $this->jobs->clearControl( $jobId, DuePolicy::CONTROL_RUN );
        }

        $sliceSeconds = (int) max( 5, min( self::SLICE_SECONDS, floor( $secondsLeft ) ) );
        try {
            $envelope = $this->dispatchSlice( $jobId, $state, time() + $sliceSeconds );
        } catch ( \Throwable $e ) {
            // A transient fetch error arrives here with the run row already
            // closed 'failed' by ImportRunner; anything else leaves it open.
            $envelope = [ 'status' => 'failed', 'error' => $e->getMessage() ];
            $runId    = RunState::runId( $state );
            if ( $runId > 0 ) {
                $envelope['run_id'] = $runId;
                $row = $this->runs->find( $runId );
                if ( $row && in_array( (string) $row['status'], [ 'running', 'continue' ], true ) ) {
                    $this->runs->finish( $runId, 'failed', [ 'error' => $e->getMessage() ] + (array) $row['report'] );
                }
            }
        }
        return $this->settle( $jobId, $state, $envelope );
    }

    /**
     * Fold a slice's outcome into the job: keep the run going, or close it
     * and move the schedule on.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function settle( int $jobId, array $state, array $envelope ): array
    {
        $now    = time();
        $status = (string) ( $envelope['status'] ?? 'failed' );
        $type   = (string) ( $state['snapshot']['runnable_type'] ?? '' );

        $state = RunState::advance( $state, $envelope, $now );
        if ( isset( $envelope['cumulative'] ) && is_array( $envelope['cumulative'] ) ) {
            $state['totals'] = $envelope['cumulative'];
        }
        [ $continues, $finalStatus ] = $this->verdict( $type, $status, $envelope, $state );

        $fresh = $this->jobs->find( $jobId );
        if ( $fresh === null ) {
            // Deleted mid-slice: nothing will ever advance this run again.
            if ( $continues ) $this->forgetRun( [ 'run_state' => $state ] );
            return $envelope;
        }

        if ( $continues ) {
            if ( ( $fresh['run_control'] ?? '' ) === DuePolicy::CONTROL_STOP ) {
                $this->cancelRun( [ 'run_state' => $state ] + $fresh, 'operator' );
                $this->jobs->clearControl( $jobId, DuePolicy::CONTROL_STOP );
                return $envelope + [ 'cancelled' => true ];
            }
            $this->jobs->saveRunState( $jobId, $state, 'continue' );
            return $envelope;
        }

        $after = $this->scheduleAfterRun( $fresh, $now );
        $this->jobs->finishRun( $jobId, $finalStatus, JobSchedule::formatUtc( $after['next'] ) );

        $runId = RunState::runId( $state );
        if ( $runId > 0 ) {
            if ( $finalStatus !== $status && $type === 'kicksdb.refresh_prices' ) {
                $this->runs->setStatus( $runId, $finalStatus );
            }
            $this->annotateSchedule( $runId, $state, $jobId, $after['skipped'] );
        }
        if ( ( $fresh['run_control'] ?? '' ) === DuePolicy::CONTROL_STOP ) {
            // The stop raced a slice that finished the run anyway.
            $this->jobs->clearControl( $jobId, DuePolicy::CONTROL_STOP );
        }
        return $envelope;
    }

    /**
     * Does the run continue after this slice, and if not, with what status
     * does it close?
     *
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $state    already advanced
     * @return array{0: bool, 1: string}
     */
    private function verdict( string $type, string $status, array $envelope, array $state ): array
    {
        if ( $status !== 'continue' ) {
            return [ false, $status === '' ? 'failed' : $status ];
        }
        switch ( $type ) {
            case 'source.import':
                return ! empty( $envelope['cursor'] ) ? [ true, 'continue' ] : [ false, 'partial' ];
            case 'kicksdb.refresh_prices':
                if ( (int) ( $state['totals']['processed'] ?? 0 ) >= self::kicksDbQuota( $state ) ) return [ false, 'done' ];
                if ( (int) ( $state['slices'] ?? 0 ) >= self::KICKSDB_MAX_SLICES ) return [ false, 'partial' ];
                return [ true, 'continue' ];
            default:
                return [ false, 'partial' ];
        }
    }

    /**
     * Close a run in flight (lease held): run row → cancelled, cache
     * dropped, job back on its schedule.
     *
     * @param array<string, mixed> $job
     */
    private function cancelRun( array $job, string $reason ): void
    {
        $id    = (int) ( $job['id'] ?? 0 );
        $state = is_array( $job['run_state'] ?? null ) ? $job['run_state'] : null;
        $runId = RunState::runId( $state );
        if ( $runId > 0 ) {
            $this->runs->cancel( $runId, $reason );
            RunCache::clear( $runId );
        }
        $after = $this->scheduleAfterRun( $job, time() );
        $this->jobs->finishRun( $id, 'cancelled', JobSchedule::formatUtc( $after['next'] ) );
        if ( $runId > 0 && $state !== null ) {
            $this->annotateSchedule( $runId, $state, $id, $after['skipped'] );
        }
    }

    /**
     * Where the job's schedule goes once its run is over. A disabled job
     * (a manual run, or a run stopped while paused) keeps whatever it had:
     * it isn't on the schedule, and re-enabling it re-plans from then.
     *
     * @param array<string, mixed> $job
     * @return array{next: ?int, skipped: int}
     */
    private function scheduleAfterRun( array $job, int $now ): array
    {
        $planned = JobSchedule::parseUtc( $job['next_run_at'] ?? null );
        if ( empty( $job['enabled'] ) ) {
            return [ 'next' => $planned, 'skipped' => 0 ];
        }
        return $this->schedule->afterRun( $job['cron_expr'] ?? null, $planned, $now );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function annotateSchedule( int $runId, array $state, int $jobId, int $skipped ): void
    {
        $this->runs->annotate( $runId, [
            'schedule' => [
                'job_id'        => $jobId,
                'trigger'       => (string) ( $state['trigger'] ?? '' ),
                'slot_at'       => $state['slot_at'] ?? null,
                'started_at'    => $state['started_at'] ?? null,
                'slices'        => (int) ( $state['slices'] ?? 0 ),
                'skipped_slots' => $skipped,
            ],
        ] );
    }

    /**
     * Route one slice by the run's snapshot type.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function dispatchSlice( int $jobId, array $state, int $deadline ): array
    {
        $snap   = is_array( $state['snapshot'] ?? null ) ? $state['snapshot'] : [];
        $type   = (string) ( $snap['runnable_type'] ?? '' );
        $ref    = (string) ( $snap['runnable_ref']  ?? '' );
        $config = is_array( $snap['config'] ?? null ) ? $snap['config'] : [];

        return match ( true ) {
            $type === 'source.import'          => $this->dispatchSourceImport( $ref, $config, $jobId, $state, $deadline ),
            $type === 'rule'                   => $this->dispatchRule( $ref, $jobId, $deadline ),
            $type === 'kicksdb.refresh_prices' => $this->dispatchKicksDbRefreshPrices( $ref, $config, $jobId, $state, $deadline ),
            default                            => [ 'status' => 'skipped', 'reason' => 'unknown_kind:' . $type ],
        };
    }

    /**
     * runnable_ref formats:
     *   "<source_id>"              inline config (job.config carries it)
     *   "<source_id>/<config_slug>"  named config from wp_hsync_source_configs
     *
     * Legacy migration produces "csv:<feed_id>" which doesn't have a
     * stored config — those skip with a clear reason until the user
     * rebuilds them in the new UI.
     *
     * @param array<string, mixed> $jobConfig
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function dispatchSourceImport( string $ref, array $jobConfig, int $jobId, array $state, int $deadline ): array
    {
        if ( str_contains( $ref, ':' ) ) {
            return [ 'status' => 'skipped', 'reason' => 'legacy_ref_needs_rebuild:' . $ref ];
        }

        $sourceId   = $ref;
        $configSlug = '';
        if ( str_contains( $ref, '/' ) ) {
            [ $sourceId, $configSlug ] = explode( '/', $ref, 2 );
        }
        // The Automatizza job editor sets runnable_ref to the source id
        // alone and stores the chosen saved-config in config.config_slug
        // (the "Crea automazione" button bakes it into the ref instead).
        // Honor both — without this, editor-built jobs dispatch with an
        // empty config and fetch nothing, silently.
        if ( $configSlug === '' && ! empty( $jobConfig['config_slug'] ) ) {
            $configSlug = (string) $jobConfig['config_slug'];
        }

        if ( ! Bootstrap::$sources ) {
            return [ 'status' => 'failed', 'error' => 'bootstrap_not_initialized' ];
        }
        $src = Bootstrap::$sources->get( $sourceId );
        if ( ! $src ) {
            return [ 'status' => 'failed', 'error' => 'source_not_registered:' . $sourceId ];
        }

        $config = (array) ( $jobConfig['inline_config'] ?? [] );
        if ( $configSlug !== '' ) {
            $stored = $this->sourceConfigs->find( $configSlug );
            // Fail loudly: a job pointing at a missing config used to run
            // with an empty config (no url/token) and report "done / 0",
            // indistinguishable from a healthy no-op. Surface it instead.
            if ( ! $stored ) {
                return [ 'status' => 'failed', 'error' => 'source_config_not_found:' . $configSlug ];
            }
            $config = (array) $stored['config'];
        }
        $options = (array) ( $jobConfig['options'] ?? [] );

        // Resolve mapping_slug → options.mapping at dispatch time so
        // seeded jobs can reference a stable mapping by name.
        if ( empty( $options['mapping'] ) && ! empty( $options['mapping_slug'] ) && $this->mappings ) {
            $mapping = $this->mappings->find( (string) $options['mapping_slug'] );
            if ( $mapping ) {
                $options['mapping'] = (array) $mapping['config'];
            }
        }

        $cursor = is_array( $state['cursor'] ?? null ) && $state['cursor'] ? $state['cursor'] : null;

        $importer = new ImportRunner( $this->runs );
        return $importer->run(
            source: $src,
            config: $config,
            options: $options,
            meta: [
                'trigger' => RunState::isManual( $state ) ? 'manual' : 'scheduled',
                // Links the wp_hsync_runs row to this job, so the Storico
                // and the job card name the job instead of just "json".
                'job_id'  => $jobId,
                'ref'     => $configSlug !== '' ? $sourceId . '/' . $configSlug : $sourceId,
            ],
            dryRun: false,
            deadline: $deadline,
            cursor: $cursor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchRule( string $ruleSlug, int $jobId, int $deadline ): array
    {
        $rule = $this->rules->find( $ruleSlug );
        if ( ! $rule )            return [ 'status' => 'failed', 'error' => 'rule_not_found:' . $ruleSlug ];
        if ( empty( $rule['enabled'] ) ) return [ 'status' => 'skipped', 'reason' => 'rule_disabled' ];

        $ops = Bootstrap::$operations ?? new OperationRegistry();
        $chk = Bootstrap::$checks     ?? new CheckRegistry();

        $steps = [];
        foreach ( (array) ( $rule['operations'] ?? [] ) as $s ) {
            $steps[] = new PipelineStep(
                kind: PipelineStepKind::Operation,
                refId: (string) ( $s['ref_id'] ?? '' ),
                params: (array) ( $s['params'] ?? [] ),
            );
        }
        foreach ( (array) ( $rule['checks'] ?? [] ) as $s ) {
            $steps[] = new PipelineStep(
                kind: PipelineStepKind::Check,
                refId: (string) ( $s['ref_id'] ?? '' ),
                params: (array) ( $s['params'] ?? [] ),
            );
        }
        $pipeline = new Pipeline( id: $ruleSlug, name: (string) $rule['name'], steps: $steps );

        $sel = (array) ( $rule['selection'] ?? [] );
        $mode = SelectionMode::tryFrom( (string) ( $sel['mode'] ?? 'all' ) ) ?? SelectionMode::All;
        $selection = match ( $mode ) {
            SelectionMode::Ids    => Selection::fromIds( 'woostore', (array) ( $sel['ids']    ?? [] ) ),
            SelectionMode::Filter => Selection::fromFilter( 'woostore', (array) ( $sel['filter'] ?? [] ) ),
            SelectionMode::All    => Selection::all( 'woostore' ),
        };

        $runId = $this->runs->start( $jobId, 'rule', $ruleSlug );
        $ctx   = new OperationContext( new Context( runId: (string) $runId, deadline: $deadline ) );
        $exec  = new PipelineExecutor( $ops, $chk );
        $result = $exec->execute( $pipeline, $selection, $ctx );

        // A rule run is never resumed (see class docblock): an unfinished
        // one is 'partial', not 'continue' — "continue" in the Storico
        // would promise a next slice that never comes.
        $status = $result->completed ? 'done' : 'partial';

        $this->runs->progress( $runId, $result->processedCount, $result->changedCount, $result->failedCount );
        $this->runs->finish(
            $runId,
            $status,
            [
                'processed' => $result->processedCount,
                'changed'   => $result->changedCount,
                'failed'    => $result->failedCount,
                'blocking'  => $result->blockingFailures,
                'cursor'    => $result->cursor,
            ],
        );

        return [
            'status'  => $status,
            'run_id'  => $runId,
            'summary' => [
                'processed'     => $result->processedCount,
                'changed'       => $result->changedCount,
                'failed'        => $result->failedCount,
                'blocking'      => count( $result->blockingFailures ),
                // Sample errors so the operator sees WHY a Rule mass-
                // failed without having to dig into per-product traces.
                'error_samples' => $result->errorSamples,
            ],
        ];
    }

    /**
     * runnable_ref = saved kicksdb source-config slug (e.g. 'kicksdb-prod').
     * config can carry { max_per_tick: int }. Defaults to 500.
     *
     * No fetch/diff/materialize — the refresher is a focused batch
     * patcher that bypasses the import pipeline entirely. It reads
     * the kicksdb_cache for tracked SKUs, batches /stockx/prices, and
     * patches per-variant regular_price via the WC API.
     *
     * Multi-slice: returns status=continue when the deadline hits; the
     * rotation order (post meta _hsync_kicksdb_last_price_sync) brings the
     * un-refreshed SKUs back to the head of the queue, so no cursor is
     * needed. All slices of one run share one wp_hsync_runs row and its
     * counters add up (`cumulative`).
     *
     * @param array<string, mixed> $jobConfig
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function dispatchKicksDbRefreshPrices( string $configSlug, array $jobConfig, int $jobId, array $state, int $deadline ): array
    {
        // Resolve the kicksdb config row. Try the explicit
        // runnable_ref first; if missing (e.g. the seeded job ships
        // with runnable_ref='kicksdb-prod' but the operator saved
        // their config under a different slug), fall back to the
        // FIRST saved kicksdb config — matches what the
        // EnrichWithKicksDb ImportRule does so the two surfaces are
        // forgiving in the same way.
        $row = $configSlug !== '' ? $this->sourceConfigs->find( $configSlug ) : null;
        if ( ! $row ) {
            $all = $this->sourceConfigs->all( 'kicksdb' );
            $row = $all[0] ?? null;
        }
        if ( ! $row ) {
            return [ 'status' => 'skipped', 'reason' => 'no_kicksdb_config_saved' ];
        }
        $config = (array) $row['config'];

        // Honor the global enabled toggle on the source-config. Same
        // switch that gates the enricher + the discovery source — one
        // place to disable everything KicksDB.
        if ( ( $config['enabled'] ?? 'yes' ) === 'no' ) {
            return [ 'status' => 'skipped', 'reason' => 'kicksdb_disabled' ];
        }
        $apiKey = (string) ( $config['api_key'] ?? '' );
        if ( $apiKey === '' ) {
            return [ 'status' => 'skipped', 'reason' => 'no_api_key' ];
        }

        $client = new \HiveSync\KicksDb\Client(
            apiKey:  $apiKey,
            baseUrl: (string) ( $config['base_url'] ?? 'https://api.kicks.dev/v3' ),
            timeout: 30,
        );
        $markupCfg = (array) ( $config['markup'] ?? [] );
        $markupCfg['vat_percent'] = (float) ( $config['vat_percent']
            ?? $markupCfg['vat_percent']
            ?? 22.0 );
        $calc      = \HiveSync\KicksDb\MarkupCalculator::fromConfig( $markupCfg );
        $cache     = new \HiveSync\Core\Repo\KicksDbCacheRepository();
        $market    = (string) ( $config['market'] ?? 'IT' );
        $refresher = new \HiveSync\KicksDb\PriceRefresher(
            client: $client,
            cache:  $cache,
            calc:   $calc,
            market: $market,
        );

        $maxPerTick = self::kicksDbQuota( [ 'snapshot' => [ 'config' => $jobConfig ] ] );

        // One run row for the whole run: reuse the one the first slice
        // opened (unless the Storico was purged under us).
        $resolvedSlug = (string) ( $row['slug'] ?? $configSlug );
        $runId = RunState::runId( $state );
        if ( $runId <= 0 || $this->runs->find( $runId ) === null ) {
            $runId = $this->runs->start( $jobId, 'kicksdb.refresh_prices', $resolvedSlug );
        }
        $result = $refresher->run( $maxPerTick, $deadline );
        $status = (string) ( $result['status'] ?? 'done' );

        $prev = is_array( $state['totals'] ?? null ) ? $state['totals'] : [];
        $cumulative = [];
        foreach ( [ 'processed', 'updated', 'unchanged', 'skipped', 'errors' ] as $k ) {
            $cumulative[ $k ] = (int) ( $prev[ $k ] ?? 0 ) + (int) ( $result[ $k ] ?? 0 );
        }

        $this->runs->progress( $runId, $maxPerTick, $cumulative['updated'], $cumulative['errors'] );
        $this->runs->finish( $runId, $status, $result + [ 'cumulative' => $cumulative ] );

        return [
            'status'     => $status,
            'run_id'     => $runId,
            'report'     => $result,
            'cumulative' => $cumulative,
        ];
    }

    /**
     * max_per_tick from BOTH the root config and config.options — the
     * seeded job nests it under options (so project.json round-trips),
     * legacy or hand-built jobs may place it at the root.
     *
     * @param array<string, mixed> $state
     */
    private static function kicksDbQuota( array $state ): int
    {
        $cfg = (array) ( $state['snapshot']['config'] ?? [] );
        return max( 50, (int) ( $cfg['max_per_tick'] ?? ( $cfg['options']['max_per_tick'] ?? 500 ) ) );
    }

    // ─── Process housekeeping ─────────────────────────────────────

    /**
     * Checked before every slice of a drain. Resets the PHP time limit
     * (the budget, not PHP, bounds the drain) and stops when memory is
     * getting tight: a drain chains many slices in ONE process, and
     * WooCommerce objects pile up in the runtime cache.
     */
    private static function refreshHeadroom(): bool
    {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }
        $limit = self::memoryLimitBytes();
        return $limit <= 0 || memory_get_usage( true ) < (int) ( $limit * 0.8 );
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim( (string) ini_get( 'memory_limit' ) );
        if ( $raw === '' || $raw === '-1' ) return -1;
        if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
            return (int) \wp_convert_hr_to_bytes( $raw );
        }
        $num  = (int) $raw;
        $unit = strtolower( substr( $raw, -1 ) );
        return match ( $unit ) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    /**
     * Between passes: drop the in-memory object cache so a long drain
     * doesn't accumulate every product it touched. Only the runtime
     * layer — never a persistent cache's shared contents.
     */
    private static function flushRuntimeCache(): void
    {
        if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) ) {
            if ( \wp_cache_supports( 'flush_runtime' ) ) {
                \wp_cache_flush_runtime();
            }
            return;
        }
        if ( function_exists( 'wp_using_ext_object_cache' ) && ! \wp_using_ext_object_cache() && function_exists( 'wp_cache_flush' ) ) {
            \wp_cache_flush();
        }
    }

    /**
     * @param array{passes: int, slices: int, stopped: string, results: array<int, array<string, mixed>>} $out
     * @return array{dispatched: int, skipped: int, locked: bool, passes: int, slices: int, stopped: string, results: array<int, array<string, mixed>>}
     */
    private static function tickResult( bool $locked, array $out ): array
    {
        $skipped = 0;
        foreach ( $out['results'] as $env ) {
            if ( ( $env['status'] ?? '' ) === 'skipped' ) $skipped++;
        }
        return [
            'dispatched' => count( $out['results'] ) - $skipped,
            'skipped'    => $skipped,
            'locked'     => $locked,
            'passes'     => $out['passes'],
            'slices'     => $out['slices'],
            'stopped'    => $out['stopped'],
            'results'    => $out['results'],
        ];
    }
}
