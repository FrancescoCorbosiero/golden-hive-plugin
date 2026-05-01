<?php
declare(strict_types=1);

namespace HiveSync\Core;

/**
 * A unit of work the scheduler can run, from a single Operation up to a full
 * import Pipeline. Anything implementing this can be:
 *   - run ad-hoc via runner->runNow($runnable)
 *   - scheduled via runner->schedule($runnable, $cronExpr)
 *
 * Sources, mappings, single Operations, single Checks, Rules, and Pipelines
 * all collapse onto this one interface. The scheduler does not care which.
 */
interface Runnable {

    /**
     * Stable identifier for this runnable instance. Used as the
     * (runnable_type, runnable_ref) key in hsync_jobs and hsync_runs.
     */
    public function id(): string;

    /**
     * Short human label for UI / logs.
     */
    public function describe(): string;

    /**
     * Execute. Implementations should be idempotent where the underlying
     * operation allows it. Context carries run_id, dry_run flag, logger, etc.
     */
    public function run( RunContext $context ): RunResult;
}
