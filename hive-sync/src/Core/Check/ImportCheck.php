<?php
declare(strict_types=1);

namespace HiveSync\Core\Check;

use HiveSync\Core\Source\FeedItem;

/**
 * Pre-import check: inspects a FeedItem (the normalized payload coming
 * out of Source::fetch) BEFORE it reaches Source::materialize. Used
 * for the "is this row missing required fields / corrupted / no media
 * URL" gates from the original brief.
 *
 * Distinct from Check (post-import) because the input is fundamentally
 * different — there's no productId yet, so wp/wc lookups against the
 * existing catalog don't apply. CheckResult shape is shared so the
 * pipeline executor can treat blocking failures uniformly.
 *
 *   evaluate(FeedItem $item, array $params): CheckResult
 *     - passes when the row is OK to materialize
 *     - fails with severity=Block to skip materialize for this item
 *     - fails with severity=Warn to record + continue
 */
interface ImportCheck
{
    public function id(): string;

    public function label(): string;

    /**
     * @return array<string, array{type: string, label: string, default?: mixed}>
     */
    public function paramsSchema(): array;

    public function defaultSeverity(): CheckSeverity;

    public function evaluate( FeedItem $item, array $params ): CheckResult;
}
