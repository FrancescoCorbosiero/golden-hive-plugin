<?php
declare(strict_types=1);

namespace GH\Core\Pipeline;

use GH\Core\Check\CheckRegistry;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Operation\OperationResult;
use GH\Core\Selection\Selection;
use GH\Core\Selection\SelectionMode;

/**
 * Executes a Pipeline against a Selection of products.
 *
 * Behavior:
 *  - For each product in the resolved selection, run every operation step
 *    in order, then every check step. Trace per-product, aggregate per-step.
 *  - Honors Context::deadline cooperatively: between products, if the
 *    deadline has passed, return early with cursor={index: i} so the job
 *    runner can resume next tick. No products are double-processed.
 *  - Honors dry-run: operations are NOT applied; the trace records
 *    'skipped_dry_run'. Checks still run (read-only).
 *  - Unknown step refIds are recorded as failures, not thrown — keeps a
 *    long pipeline from aborting on one missing op.
 *
 * Selection resolution lives in resolveSelection() — it's a thin shim
 * over the legacy filter engine (gh_filter_product_ids) for Filter mode.
 * The resolver is a constructor-injected callable so tests can provide
 * a fake without WP loaded.
 */
final class PipelineExecutor
{
    /** @var callable(Selection): int[] */
    private $selectionResolver;

    public function __construct(
        private readonly OperationRegistry $operations,
        private readonly CheckRegistry $checks,
        ?callable $selectionResolver = null,
    ) {
        $this->selectionResolver = $selectionResolver ?? [self::class, 'defaultResolveSelection'];
    }

    public function execute(
        Pipeline $pipeline,
        Selection $selection,
        OperationContext $ctx,
        ?array $cursor = null,
    ): PipelineResult {
        $ids = ($this->selectionResolver)($selection);
        $startAt = isset($cursor['index']) ? max(0, (int) $cursor['index']) : 0;
        $total = count($ids);

        $opSteps = $pipeline->operationSteps();
        $checkSteps = $pipeline->checkSteps();

        $stats = ['processed' => 0, 'changed' => 0, 'failed' => 0];
        $perStep = [];
        $perProduct = [];
        $blocking = [];

        for ($i = $startAt; $i < $total; $i++) {
            // Cooperative yield: deadline check happens BEFORE we start a
            // new product so partially-processed products can never occur.
            if ($ctx->base->isOverDeadline()) {
                return new PipelineResult(
                    processedCount: $stats['processed'],
                    changedCount: $stats['changed'],
                    failedCount: $stats['failed'],
                    perStep: $perStep,
                    perProduct: $perProduct,
                    blockingFailures: $blocking,
                    completed: false,
                    cursor: ['index' => $i],
                );
            }

            $pid = (int) $ids[$i];
            $trace = ['ops' => [], 'checks' => []];

            foreach ($opSteps as $idx => $step) {
                $op = $this->operations->get($step->refId);
                if (! $op) {
                    $trace['ops'][] = ['ref' => $step->refId, 'error' => 'unknown_op'];
                    $perStep[$idx]['failed'] = ($perStep[$idx]['failed'] ?? 0) + 1;
                    $stats['failed']++;
                    continue;
                }

                if ($ctx->isDryRun()) {
                    $trace['ops'][] = ['ref' => $step->refId, 'skipped_dry_run' => true];
                    $perStep[$idx]['skipped'] = ($perStep[$idx]['skipped'] ?? 0) + 1;
                    continue;
                }

                try {
                    $res = $op->apply($pid, $step->params, $ctx);
                } catch (\Throwable $e) {
                    $res = OperationResult::failed($e->getMessage());
                }

                $trace['ops'][] = [
                    'ref'     => $step->refId,
                    'changed' => $res->changed,
                    'error'   => $res->error,
                ];
                if ($res->changed) {
                    $stats['changed']++;
                }
                if ($res->error !== null) {
                    $stats['failed']++;
                    $perStep[$idx]['failed'] = ($perStep[$idx]['failed'] ?? 0) + 1;
                } else {
                    $perStep[$idx]['ok'] = ($perStep[$idx]['ok'] ?? 0) + 1;
                }
            }

            foreach ($checkSteps as $idx => $step) {
                $check = $this->checks->get($step->refId);
                if (! $check) {
                    $trace['checks'][] = ['ref' => $step->refId, 'error' => 'unknown_check'];
                    continue;
                }
                try {
                    $cr = $check->evaluate($pid, $step->params);
                } catch (\Throwable $e) {
                    $trace['checks'][] = ['ref' => $step->refId, 'error' => $e->getMessage()];
                    continue;
                }
                $trace['checks'][] = [
                    'ref'     => $step->refId,
                    'passed'  => $cr->passed,
                    'message' => $cr->message,
                ];
                if ($cr->isBlocking()) {
                    $blocking[] = [
                        'product_id' => $pid,
                        'check'      => $step->refId,
                        'message'    => $cr->message,
                    ];
                }
            }

            $perProduct[$pid] = $trace;
            $stats['processed']++;
        }

        return new PipelineResult(
            processedCount: $stats['processed'],
            changedCount: $stats['changed'],
            failedCount: $stats['failed'],
            perStep: $perStep,
            perProduct: $perProduct,
            blockingFailures: $blocking,
            completed: true,
        );
    }

    /**
     * Default resolver: handles Ids mode purely; defers Filter and All to
     * the legacy gh_filter_product_ids() when WP is loaded. Tests always
     * inject a stub resolver so this is never called from PHPUnit.
     */
    public static function defaultResolveSelection(Selection $sel): array
    {
        if ($sel->mode === SelectionMode::Ids) {
            return array_values(array_map('intval', $sel->ids));
        }

        if ($sel->mode === SelectionMode::Filter && function_exists('gh_filter_product_ids')) {
            $ids = \gh_filter_product_ids($sel->filter);
            return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        }

        // SelectionMode::All — Source-specific resolution lands in Batch 4
        // (each Source can iterate its own universe). Not callable yet.
        return [];
    }
}
