<?php
declare(strict_types=1);

namespace GH\Core\Operation;

use GH\Core\Source\Context as RunContext;

/**
 * Context for one operation invocation. Wraps the run-level Context and
 * adds operation-specific data the conflict engine needs:
 *  - sourceId    : which Source this operation is acting on behalf of
 *                  (so the conflict engine can route slice ownership).
 *                  Null for user-driven local edits.
 *  - sliceOwners : slice => source id, e.g. ['pricing' => 'goldensneakers'].
 *                  Lets a single operation declare what it intends to write,
 *                  which the conflict engine then approves or vetoes.
 */
final class OperationContext
{
    public function __construct(
        public readonly RunContext $base,
        public readonly ?string $sourceId = null,
        public readonly array $sliceOwners = [],
    ) {}

    public function isDryRun(): bool
    {
        return $this->base->dryRun;
    }
}
