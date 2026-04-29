<?php
declare(strict_types=1);

namespace GH\Core\Selection;

/**
 * A Selection is "what the user picked, against what Source". Three
 * modes covering the granular-selection pillar:
 *
 *  - Ids    : explicit list of WC product ids (or external SKUs for
 *             remote sources). Captures the manual checkbox flow.
 *  - Filter : condition set the executor evaluates at run time (so
 *             scheduled jobs always work on current data, not a snapshot).
 *  - All    : every item the source produces (use with care).
 *
 * Selection is source-aware: the same Filter shape ('only items with
 * brand=Nike') resolves differently against WooStoreSource vs
 * GoldenSneakersSource. Sources expose how to resolve a Selection in
 * their own terms (Batch 2).
 */
final class Selection
{
    public function __construct(
        public readonly string $sourceId,
        public readonly SelectionMode $mode,
        /** @var int[]|string[] */
        public readonly array $ids = [],
        public readonly array $filter = [],
    ) {}

    public static function fromIds(string $sourceId, array $ids): self
    {
        return new self(
            sourceId: $sourceId,
            mode: SelectionMode::Ids,
            ids: array_values(array_unique($ids)),
        );
    }

    public static function fromFilter(string $sourceId, array $filter): self
    {
        return new self(
            sourceId: $sourceId,
            mode: SelectionMode::Filter,
            filter: $filter,
        );
    }

    public static function all(string $sourceId): self
    {
        return new self(sourceId: $sourceId, mode: SelectionMode::All);
    }
}
