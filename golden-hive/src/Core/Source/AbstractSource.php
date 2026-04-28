<?php
declare(strict_types=1);

namespace GH\Core\Source;

/**
 * Base class for Source implementations. Will provide shared behavior in
 * Batch 2:
 *   - retry with binary-split on timeout (hardening priority #1)
 *   - taxonomy pre-creation with cached slug→ID map (priority #2)
 *   - image sideload with pre-import map validation
 *   - provenance write (gh_conflict_record_source) — orthogonal to engine
 *   - conflict engine integration (gh_conflict_resolve before mutation)
 *   - error normalization
 *
 * Batch 1 ships only the shape: every concrete Source must extend this
 * class, not implement Source directly. That guarantees the cross-cutting
 * concerns above land in one place when Batch 2 fills in the helpers.
 */
abstract class AbstractSource implements Source
{
    abstract public function id(): string;

    abstract public function label(): string;

    abstract public function capabilities(): SourceCapabilities;

    abstract public function configSchema(): array;

    abstract public function fetch(FetchRequest $request, Context $ctx): FetchResult;

    abstract public function diff(array $items, Context $ctx): Diff;

    abstract public function materialize(FeedItem $item, Context $ctx): MaterializeResult;
}
