<?php
declare(strict_types=1);

namespace HiveSync\Core\Check;

/**
 * A Check inspects one product and returns pass/fail. Examples:
 *  - media.has_images           (at least N images present)
 *  - pricing.markup_applied     (regular >= cost * margin)
 *  - taxonomy.has_category      (at least one product_cat assigned)
 *  - variants.complete          (all expected sizes present and in stock)
 *
 * Checks compose into a Pipeline alongside Operations:
 *   - post-import gate : fails block import (severity=Block)
 *   - audit            : run on existing catalog, results surfaced in UI
 */
interface Check
{
    public function id(): string;

    public function label(): string;

    /**
     * @return array<string, array{type: string, label: string, default?: mixed}>
     */
    public function paramsSchema(): array;

    public function defaultSeverity(): CheckSeverity;

    public function evaluate(int $productId, array $params): CheckResult;
}
