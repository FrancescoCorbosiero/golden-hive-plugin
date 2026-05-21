<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Schedule;

use HiveSync\Core\Bootstrap;
use HiveSync\Core\Check\CheckRegistry;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationRegistry;
use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineExecutor;
use HiveSync\Core\Pipeline\PipelineRepository;
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

/**
 * Dispatcher + lock for scheduled jobs.
 *
 *   tick()                 Called by WP-Cron every N minutes. Acquires a
 *                          short-lived lock (transient), pulls due jobs
 *                          from JobRepository::dueNow(), runs each one
 *                          serially, releases the lock.
 *
 *   runJobNow($id)         Manual entry point. Same dispatch path; bypasses
 *                          due-time checks. Used by the "Tick now" button.
 *
 *   dispatch($job)         Routes by runnable_type:
 *                            source.import / <source_id>          → ImportRunner
 *                            source.import / csv:<config_slug>    → ImportRunner with stored config
 *                            rule          / <rule_slug>          → PipelineExecutor over rule's selection
 *                            *                                    → record as 'unknown_kind'
 *
 * Each dispatched run is bracketed by RunRepository::start/finish so the
 * Runs tab shows job-driven runs alongside ad-hoc ones. Job's last_run_*
 * + next_run_at are updated on completion.
 *
 * Locking: a single transient (hsync_jobs_tick_lock, 90s TTL) serializes
 * tick(). Concurrent ticks (e.g. two WP-Cron firings overlapping) skip
 * cleanly — the second one returns 'locked'.
 */
final class JobRunner
{
    private const LOCK_KEY = 'hsync_jobs_tick_lock';
    private const LOCK_TTL = 90;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly RunRepository $runs,
        private readonly RuleRepository $rules,
        private readonly SourceConfigRepository $sourceConfigs,
        private readonly ?MappingRepository $mappings = null,
    ) {}

    /**
     * @return array{dispatched: int, skipped: int, locked: bool, results: array<int, array>}
     */
    public function tick( int $now ): array
    {
        if ( ! self::acquireLock() ) {
            return [ 'dispatched' => 0, 'skipped' => 0, 'locked' => true, 'results' => [] ];
        }

        $results = [];
        $dispatched = 0;
        $skipped = 0;

        try {
            foreach ( $this->jobs->dueNow( $now ) as $job ) {
                $envelope = $this->dispatch( $job );
                $results[ (int) $job['id'] ] = $envelope;
                if ( ($envelope['status'] ?? 'failed') === 'skipped' ) $skipped++;
                else $dispatched++;
            }
        } finally {
            self::releaseLock();
        }

        return [ 'dispatched' => $dispatched, 'skipped' => $skipped, 'locked' => false, 'results' => $results ];
    }

    public function runJobNow( int $jobId ): array
    {
        $job = $this->jobs->find( $jobId );
        if ( ! $job ) return [ 'status' => 'failed', 'error' => 'job_not_found' ];
        return $this->dispatch( $job );
    }

    /**
     * @param array<string, mixed> $job
     */
    public function dispatch( array $job ): array
    {
        $type = (string) ( $job['runnable_type'] ?? '' );
        $ref  = (string) ( $job['runnable_ref']  ?? '' );

        $envelope = match ( true ) {
            $type === 'source.import' => $this->dispatchSourceImport( $ref, (array) ( $job['config'] ?? [] ) ),
            $type === 'rule'          => $this->dispatchRule( $ref ),
            default                   => [ 'status' => 'skipped', 'reason' => 'unknown_kind:' . $type ],
        };

        $cron = (string) ( $job['cron_expr'] ?? '' );
        $nextRunAt = null;
        if ( $cron !== '' ) {
            $nextTs = CronExpr::nextRun( $cron, time() );
            if ( $nextTs !== null ) $nextRunAt = gmdate( 'Y-m-d H:i:s', $nextTs );
        }
        $this->jobs->recordRun( (int) $job['id'], (string) ( $envelope['status'] ?? 'failed' ), $nextRunAt );

        return $envelope;
    }

    /**
     * runnable_ref formats:
     *   "<source_id>"              inline config (job.config carries it)
     *   "<source_id>/<config_slug>"  named config from wp_hsync_source_configs
     *
     * Legacy migration produces "csv:<feed_id>" which doesn't have a
     * stored config — those skip with a clear reason until the user
     * rebuilds them in the new UI.
     */
    private function dispatchSourceImport( string $ref, array $jobConfig ): array
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
        // seeded jobs can reference a stable mapping by name. Edits
        // to the mapping flow through to the job on the next tick.
        if ( empty( $options['mapping'] ) && ! empty( $options['mapping_slug'] ) && $this->mappings ) {
            $mapping = $this->mappings->find( (string) $options['mapping_slug'] );
            if ( $mapping ) {
                $options['mapping'] = (array) $mapping['config'];
            }
        }

        $deadline = time() + 25;

        $importer = new ImportRunner( $this->runs );
        return $importer->run(
            source: $src,
            config: $config,
            options: $options,
            meta: [ 'trigger' => 'scheduled' ],
            dryRun: false,
            deadline: $deadline,
        );
    }

    private function dispatchRule( string $ruleSlug ): array
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

        $runId = $this->runs->start( 0, 'rule', $ruleSlug );
        $ctx   = new OperationContext( new Context( runId: (string) $runId, deadline: time() + 25 ) );
        $exec  = new PipelineExecutor( $ops, $chk );
        $result = $exec->execute( $pipeline, $selection, $ctx );

        $this->runs->progress( $runId, $result->processedCount, $result->changedCount, $result->failedCount );
        $this->runs->finish(
            $runId,
            $result->completed ? 'done' : 'continue',
            [
                'processed' => $result->processedCount,
                'changed'   => $result->changedCount,
                'failed'    => $result->failedCount,
                'blocking'  => $result->blockingFailures,
                'cursor'    => $result->cursor,
            ],
        );

        return [
            'status'  => $result->completed ? 'done' : 'continue',
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

    // ─── Lock helpers ─────────────────────────────────────────────

    private static function acquireLock(): bool
    {
        if ( ! function_exists( 'set_transient' ) ) return true;  // tests
        if ( get_transient( self::LOCK_KEY ) ) return false;
        return (bool) set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
    }

    private static function releaseLock(): void
    {
        if ( function_exists( 'delete_transient' ) ) delete_transient( self::LOCK_KEY );
    }
}
