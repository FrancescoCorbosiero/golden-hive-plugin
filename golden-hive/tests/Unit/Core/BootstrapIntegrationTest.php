<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Core;

use GH\Core\Bootstrap;
use GH\Core\Pipeline\Pipeline;
use GH\Core\Pipeline\PipelineStep;
use GH\Core\Pipeline\PipelineStepKind;
use GH\Core\Selection\Selection;
use GH\Operations\Status\SetStatus;
use GH\Sources\WooStoreSource;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-level integration: boot the static container, register the
 * Batch 3 concrete classes by hand (the real wiring goes via
 * gh_core_booted action which is WP-only), then run an end-to-end
 * pipeline against a dry-run context. Proves the abstraction works
 * coast-to-coast without WP.
 */
final class BootstrapIntegrationTest extends TestCase
{
    public function test_end_to_end_pipeline_over_woostore_with_set_status_dry_run(): void
    {
        Bootstrap::boot(); // idempotent across the test suite

        // Manual registration substitutes for the gh_core_booted hook
        // that v2-registrations.php attaches when WP is loaded.
        Bootstrap::$sources->register(new WooStoreSource());
        Bootstrap::$operations->register(new SetStatus());

        self::assertNotNull(Bootstrap::$sources->get('woostore'));
        self::assertNotNull(Bootstrap::$operations->get('status.set'));

        $pipeline = new Pipeline(
            id: 'p_smoke',
            name: 'set draft',
            steps: [
                new PipelineStep(
                    PipelineStepKind::Operation,
                    'status.set',
                    ['status' => 'draft'],
                ),
            ],
        );
        $selection = Selection::fromIds('woostore', [101, 102, 103]);

        // Inject a fake resolver so we don't depend on WP filter engine.
        $exec = new \GH\Core\Pipeline\PipelineExecutor(
            Bootstrap::$operations,
            Bootstrap::$checks,
            static fn(Selection $s): array => $s->ids,
        );

        $ctx = new \GH\Core\Operation\OperationContext(
            base: new \GH\Core\Source\Context(runId: 'smoke', dryRun: true),
            sourceId: 'woostore',
        );

        $result = $exec->execute($pipeline, $selection, $ctx);

        self::assertTrue($result->completed);
        self::assertSame(3, $result->processedCount);
        self::assertSame(0, $result->failedCount);
        // Dry-run records 'skipped' on the op-step counter, never 'changed'.
        self::assertSame(3, $result->perStep[0]['skipped'] ?? 0);
    }
}
