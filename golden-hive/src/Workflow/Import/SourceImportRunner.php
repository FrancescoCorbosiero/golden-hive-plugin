<?php
declare(strict_types=1);

namespace GH\Workflow\Import;

use GH\Core\Operation\ImportRule;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Pipeline\Pipeline;
use GH\Core\Pipeline\PipelineStepKind;
use GH\Core\Source\Context;
use GH\Core\Source\Diff;
use GH\Core\Source\FeedItem;
use GH\Core\Source\Source;

/**
 * Coast-to-coast runner for the source.import job kind.
 *
 *   1. Fetch from the Source (or use a passed-in Diff for resumption)
 *   2. For each FeedItem in {new, update}:
 *      a. Apply pre-import pipeline ImportRule steps to mutate the draft
 *      b. Invoke Source::materialize on the (possibly-mutated) item
 *      c. Record per-item trace
 *   3. Honor Context::deadline cooperatively → return cursor for resume
 *
 * Designed for unit-testing without WP: Source / OperationRegistry are
 * injected, and the only WP-specific concern (the legacy ImportRule
 * apply path) is reached only via OperationContext mode flags.
 *
 * Post-import Operations + Checks (the ones that touch existing product
 * IDs) belong to a separate run via PipelineExecutor — keeping the two
 * loops separate is what makes each one cursor-resumable in isolation.
 */
final class SourceImportRunner
{
    public function __construct(
        private readonly OperationRegistry $operations,
    ) {}

    /**
     * @param Pipeline|null $pipeline Pre-import rules; only ImportRule steps are consulted.
     * @param array{phase?: string, index?: int}|null $cursor
     */
    public function run(
        Source $source,
        array $config,
        ?Pipeline $pipeline,
        OperationContext $opCtx,
        ?array $cursor = null,
        ?Diff $cachedDiff = null,
    ): SourceImportResult {
        $base = $opCtx->base;

        // Diff resolution: caller passes $cachedDiff on continuation so
        // we don't re-fetch on every tick. First tick: fetch + diff.
        $warnings = [];
        if ($cachedDiff === null) {
            $fetch = $source->fetch(
                new \GH\Core\Source\FetchRequest(config: $config),
                $base,
            );
            $warnings = $fetch->warnings;
            $diff = $source->diff($fetch->items, $base);
        } else {
            $diff = $cachedDiff;
        }

        $items = array_merge($diff->new, $diff->update);
        $total = count($items);

        $importRuleSteps = $pipeline?->importRuleSteps() ?? [];

        $stats = [
            'processed' => 0, 'created' => 0, 'updated' => 0,
            'skipped'   => 0, 'failed'  => 0,
        ];
        $perItem = [];

        $startAt = isset($cursor['index']) ? max(0, (int) $cursor['index']) : 0;

        for ($i = $startAt; $i < $total; $i++) {
            // Cooperative yield BEFORE starting a new item, so we never
            // produce partially-applied state.
            if ($base->isOverDeadline()) {
                return new SourceImportResult(
                    totalCount: $total,
                    processedCount: $stats['processed'],
                    createdCount: $stats['created'],
                    updatedCount: $stats['updated'],
                    skippedCount: $stats['skipped'],
                    failedCount: $stats['failed'],
                    perItem: $perItem,
                    warnings: $warnings,
                    completed: false,
                    cursor: ['index' => $i],
                );
            }

            $item = $items[$i];

            // 1. Apply ImportRule steps to a mutable draft.
            $draft = $item->data;
            $ruleTrace = [];
            foreach ($importRuleSteps as $step) {
                $rule = $this->operations->get($step->refId);
                if (! $rule instanceof ImportRule) {
                    $ruleTrace[] = ['ref' => $step->refId, 'error' => 'not_an_import_rule'];
                    continue;
                }
                try {
                    $rule->applyDuringImport($item, $draft, $step->params, $opCtx);
                    $ruleTrace[] = ['ref' => $step->refId, 'ok' => true];
                } catch (\Throwable $e) {
                    $ruleTrace[] = ['ref' => $step->refId, 'error' => $e->getMessage()];
                }
            }

            // 2. Materialize the (possibly-mutated) item. Reconstruct as
            //    a new FeedItem so the Source receives the post-rule draft.
            $mutatedItem = $draft === $item->data
                ? $item
                : new FeedItem(sku: $item->sku, data: $draft, raw: $item->raw);

            try {
                $matResult = $source->materialize($mutatedItem, $base);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $perItem[$item->sku] = [
                    'rules'  => $ruleTrace,
                    'action' => 'failed',
                    'error'  => $e->getMessage(),
                ];
                $stats['processed']++;
                continue;
            }

            // 3. Bucket result.
            $stats['processed']++;
            switch ($matResult->action) {
                case 'created': $stats['created']++; break;
                case 'updated': $stats['updated']++; break;
                case 'skipped': $stats['skipped']++; break;
                default:        $stats['failed']++;  break;
            }

            $perItem[$item->sku] = [
                'rules'      => $ruleTrace,
                'action'     => $matResult->action,
                'product_id' => $matResult->productId,
                'error'      => $matResult->error,
            ];
        }

        return new SourceImportResult(
            totalCount: $total,
            processedCount: $stats['processed'],
            createdCount: $stats['created'],
            updatedCount: $stats['updated'],
            skippedCount: $stats['skipped'],
            failedCount: $stats['failed'],
            perItem: $perItem,
            warnings: $warnings,
            completed: true,
        );
    }
}
