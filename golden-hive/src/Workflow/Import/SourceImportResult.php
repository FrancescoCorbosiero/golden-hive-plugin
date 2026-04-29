<?php
declare(strict_types=1);

namespace GH\Workflow\Import;

/**
 * Result envelope for one SourceImportRunner::run() invocation.
 * Same contract shape as PipelineResult so the job adapter can
 * translate to the runner envelope (done | continue) uniformly.
 */
final class SourceImportResult
{
    public function __construct(
        public readonly int $totalCount,
        public readonly int $processedCount,
        public readonly int $createdCount,
        public readonly int $updatedCount,
        public readonly int $skippedCount,
        public readonly int $failedCount,
        public readonly array $perItem,         // sku => trace
        public readonly array $warnings,
        public readonly bool $completed,
        public readonly ?array $cursor = null,  // {phase, index} when yielding
    ) {}

    public function toJobEnvelope(): array
    {
        $summary = [
            'total'     => $this->totalCount,
            'processed' => $this->processedCount,
            'created'   => $this->createdCount,
            'updated'   => $this->updatedCount,
            'skipped'   => $this->skippedCount,
            'failed'    => $this->failedCount,
            'warnings'  => $this->warnings,
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
