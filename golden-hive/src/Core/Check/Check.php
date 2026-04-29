<?php
declare(strict_types=1);

namespace GH\Core\Check;

/**
 * A Check inspects one product and returns pass/fail. Examples (Batch 5+):
 *  - media.has_images           (at least N images present)
 *  - pricing.markup_applied     (regular >= cost * margin)
 *  - taxonomy.has_category      (at least one product_cat assigned)
 *  - variants.complete          (all expected sizes present and in stock)
 *  - kicksdb.synced             (last sync within X hours)
 *
 * Checks compose into a Pipeline alongside Operations, addressing the
 * "post-import checks" pillar of the redesign. Two wiring modes:
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
