<?php
declare(strict_types=1);

namespace GH\Core\Pipeline;

use GH\Core\Check\CheckRegistry;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Selection\Selection;

/**
 * Executes a Pipeline against a Selection of products.
 *
 * Implementation deferred to Batch 2 — this class is shipped as a stub
 * so the Job adapter (registers a 'pipeline.run' job kind) and the UI
 * builder can be coded against a known signature. Filling it in does
 * not change any consumer.
 *
 * Planned behavior (Batch 2):
 *  - Resolve each step's refId via the matching registry; fail-fast if missing
 *  - Iterate selection in chunks honoring Context::deadline (cursor return)
 *  - Pass each product through OperationSteps in order
 *  - After Operations, run CheckSteps; collect blocking failures
 *  - Emit one PipelineResult with per-step counters + per-product log
 */
final class PipelineExecutor
{
    public function __construct(
        private readonly OperationRegistry $operations,
        private readonly CheckRegistry $checks,
    ) {}

    /**
     * Stub: real execution lands in Batch 2. Calling this now intentionally
     * throws so we never accidentally ship a no-op pipeline run.
     */
    public function execute(Pipeline $pipeline, Selection $selection, OperationContext $ctx): never
    {
        throw new \LogicException(
            'PipelineExecutor::execute() is not implemented yet (Batch 2). '
            . 'Pipeline=' . $pipeline->id . ' selection=' . $selection->sourceId
        );
    }
}
