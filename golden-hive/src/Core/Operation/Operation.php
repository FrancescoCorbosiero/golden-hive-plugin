<?php
declare(strict_types=1);

namespace GH\Core\Operation;

/**
 * An Operation mutates a single product. Examples (Batch 5+):
 *  - pricing.markup_percent
 *  - pricing.markup_by_category
 *  - taxonomy.assign_brand
 *  - media.clear_gallery
 *  - seo.apply_template
 *
 * Operations are the v2 replacement for the 23 ad-hoc bulk actions in
 * includes/bulk/actions.php. Each one becomes a small class registered
 * in OperationRegistry. The pipeline executor composes them.
 */
interface Operation
{
    public function id(): string;

    public function label(): string;

    /**
     * Schema describing the parameters this operation accepts.
     * Drives the params editor in the unified pipeline builder UI.
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
