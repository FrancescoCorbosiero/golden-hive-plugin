<?php
declare(strict_types=1);

namespace GH\Core\Pipeline;

/**
 * A Pipeline is a saved + nameable composition of steps. Two execution
 * profiles, decided by which step kinds it contains and when the
 * executor runs it:
 *
 *   - Pre-import : ImportRule + Check(severity=Block) steps run while
 *                  the Source materializes each FeedItem. Used to enforce
 *                  per-category markup, image validation, SKU normalization.
 *
 *   - Post       : Operation + Check steps run over a Selection of
 *                  existing products. Used as the v2 replacement for
 *                  Filter & Bulk and as the post-import audit pass.
 *
 * Persistence (Batch 2): wp_options 'gh_pipelines' as a list, via the
 * existing gh_option_list_* helpers. Pipelines are referenced by id from
 * Job params, so a scheduled import can carry its pipeline reference.
 */
final class Pipeline
{
    /**
     * @param PipelineStep[]       $steps
     * @param array<string, mixed> $meta  free-form (created_at, updated_at, …)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $steps,
        public readonly array $meta = [],
    ) {}

    /** @return PipelineStep[] */
    public function importRuleSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(PipelineStep $s): bool => $s->kind === PipelineStepKind::ImportRule,
        ));
    }

    /** @return PipelineStep[] */
    public function operationSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(PipelineStep $s): bool => $s->kind === PipelineStepKind::Operation,
        ));
    }

    /** @return PipelineStep[] */
    public function checkSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(PipelineStep $s): bool => $s->kind === PipelineStepKind::Check,
        ));
    }
}
