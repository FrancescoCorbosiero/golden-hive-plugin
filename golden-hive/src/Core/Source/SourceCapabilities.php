<?php
declare(strict_types=1);

namespace GH\Core\Source;

/**
 * Declares what a Source can do. Drives UI: e.g. WooStoreSource has
 * canFetch=false (catalog is already local) but canSelectLocal=true,
 * while GoldenSneakers has canFetch=true and canSelectLocal=false.
 */
final class SourceCapabilities
{
    public function __construct(
        public readonly bool $canFetch = true,
        public readonly bool $canDiff = true,
        public readonly bool $canMaterialize = true,
        public readonly bool $canSelectLocal = false,
        public readonly bool $supportsQuickPatch = false,
        public readonly bool $supportsImageSideload = true,
    ) {}
}
