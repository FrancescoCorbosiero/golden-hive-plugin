<?php
declare(strict_types=1);

namespace GH\Core;

use GH\Core\Check\CheckRegistry;
use GH\Core\Job\PipelineJobAdapter;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Pipeline\PipelineExecutor;
use GH\Core\Pipeline\PipelineRepository;
use GH\Core\Source\SourceRegistry;

/**
 * Static service locator for the v2 core. Wires the registries +
 * executor + job adapter once per request and exposes them via static
 * properties so legacy procedural code (AJAX handlers, job runner)
 * can reach the new objects without DI.
 *
 * Boot is idempotent: calling boot() twice is a no-op. Boot fires the
 * 'gh_core_booted' WordPress action so concrete sources/operations/checks
 * can register themselves on it (the same pattern as the legacy
 * 'gh_jobs_register' hook).
 *
 * Why static + global? Because the legacy code is procedural and we
 * want the new objects discoverable from anywhere without threading a
 * container through 100+ files. Tests construct their own instances —
 * Bootstrap itself is never invoked from PHPUnit.
 */
final class Bootstrap
{
    public static SourceRegistry $sources;
    public static OperationRegistry $operations;
    public static CheckRegistry $checks;
    public static PipelineRepository $pipelines;
    public static PipelineExecutor $executor;
    public static PipelineJobAdapter $jobAdapter;

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::$sources    = new SourceRegistry();
        self::$operations = new OperationRegistry();
        self::$checks     = new CheckRegistry();
        self::$pipelines  = new PipelineRepository();
        self::$executor   = new PipelineExecutor(self::$operations, self::$checks);
        self::$jobAdapter = new PipelineJobAdapter(self::$pipelines, self::$executor);

        self::$jobAdapter->register();

        if (function_exists('do_action')) {
            \do_action('gh_core_booted');
        }
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }
}
