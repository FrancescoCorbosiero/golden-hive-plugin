<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

final class FetchResult
{
    /**
     * @param FeedItem[]           $items
     * @param array<string, mixed> $stats     fetch-time counters (rows, bytes, duration_ms…)
     * @param string[]             $warnings  non-fatal issues surfaced to the UI
     */
    public function __construct(
        public readonly array $items,
        public readonly array $stats = [],
        public readonly array $warnings = [],
    ) {}
}
