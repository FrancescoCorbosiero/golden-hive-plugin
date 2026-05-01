<?php
declare(strict_types=1);

namespace HiveSync\Core\Pipeline;

/**
 * Aggregate outcome of one PipelineExecutor::execute() call.
 *
 *  - completed=true   : the entire selection was processed; cursor is null
 *  - completed=false  : the executor yielded mid-loop (deadline reached);
 *                       cursor carries enough state to resume next tick
 *
 * `perStep` is keyed by step index → ['ok'=>int, 'failed'=>int, 'skipped'=>int]
 * `perProduct` is keyed by product id → trace (ops + checks executed)
 * `blockingFailures` collects every Check that failed with severity=Block
 */
final class PipelineResult
{
    public function __construct(
        public readonly int $processedCount,
        public readonly int $changedCount,
        public readonly int $failedCount,
        public readonly array $perStep,
        public readonly array $perProduct,
        public readonly array $blockingFailures,
        public readonly bool $completed,
        public readonly ?array $cursor = null,
    ) {}

    /**
     * Translate into the envelope shape the job runner expects.
     *
     * @return array{status: string, summary?: array, cursor?: array, progress?: array}
     */
    public function toJobEnvelope(): array
    {
        $summary = [
            'processed' => $this->processedCount,
            'changed'   => $this->changedCount,
            'failed'    => $this->failedCount,
            'blocking'  => count($this->blockingFailures),
            'per_step'  => $this->perStep,
        ];

        if (! $this->completed) {
            return [
                'status'   => 'continue',
                'cursor'   => $this->cursor ?? [],
                'progress' => $summary,
            ];
        }

        return ['status' => 'done', 'summary' => $summary];
    }
}
