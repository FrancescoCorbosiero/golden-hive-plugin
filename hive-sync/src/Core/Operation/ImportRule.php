<?php
declare(strict_types=1);

namespace HiveSync\Core\Operation;

use HiveSync\Core\Source\FeedItem;

/**
 * An ImportRule is an Operation that ALSO knows how to mutate a draft
 * product before it is materialized — i.e. it can act on the FeedItem
 * during import, not just on an existing WC product after the fact.
 *
 * Modeled as interface inheritance: an ImportRule IS-AN Operation, plus
 * one extra entry point. The same params, the same UI editor, two
 * execution phases.
 *
 * Examples:
 *  - markup_by_category (different markup per category at import time)
 *  - media_sideload     (pre-fetch images before product create)
 *  - sku_prefix         (rewrite SKU before create)
 */
interface ImportRule extends Operation
{
    /**
     * Mutate the in-flight product draft before materialize() runs. The
     * draft is passed by reference so multiple rules can compose.
     */
    public function applyDuringImport(FeedItem $item, array &$draft, array $params, OperationContext $ctx): void;
}
