<?php
declare(strict_types=1);

namespace HiveSync\Core\Pipeline;

/**
 * A Pipeline is a saved + nameable composition of steps. Two execution
 * profiles, decided by which step kinds it contains:
 *
 *   - Pre-import : ImportRule + Check(severity=Block) steps run while
 *                  the Source materializes each FeedItem. Used to enforce
 *                  per-category markup, image validation, SKU normalization.
 *
 *   - Post       : Operation + Check steps run over a Selection of
 *                  existing products. Used as the runtime for Rules
 *                  (scoped operation bundles) and post-import audits.
 *
 * Persistence: dedicated wp_hsync_pipelines table via PipelineRepository.
 * Pipelines are referenced by id from Job params, so a scheduled import
 * carries its pipeline reference and the executor always loads the
 * current version at run time (never a snapshot).
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

    /** @return PipelineStep[] Pre-import checks (FeedItem-scoped). */
    public function preCheckSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(PipelineStep $s): bool => $s->kind === PipelineStepKind::PreCheck,
        ));
    }
}
