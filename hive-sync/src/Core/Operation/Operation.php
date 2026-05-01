<?php
declare(strict_types=1);

namespace HiveSync\Core\Operation;

/**
 * An Operation mutates a single product. Examples:
 *  - pricing.markup_percent
 *  - pricing.markup_by_category
 *  - taxonomy.assign_brand
 *  - media.clear_gallery
 *  - seo.apply_template
 *
 * Operations are composable — the pipeline executor stacks them — and
 * runnable individually as scheduled jobs (one Operation = one Runnable).
 */
interface Operation
{
    public function id(): string;

    public function label(): string;

    /**
     * Schema describing the parameters this operation accepts. Drives the
     * params editor in the unified pipeline builder UI.
     *
     * @return array<string, array{type: string, label: string, required?: bool, default?: mixed, options?: array}>
     */
    public function paramsSchema(): array;

    /**
     * Product types this operation can apply to.
     *
     * @return string[]  e.g. ['simple', 'variable']
     */
    public function appliesTo(): array;

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult;
}
