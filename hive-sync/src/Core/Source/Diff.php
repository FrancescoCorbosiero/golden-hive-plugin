<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

/**
 * Result of comparing fetched FeedItems against the local catalog.
 * Bucketed exactly like the legacy GS feed diff so existing UI concepts
 * (counters, preview tables) port cleanly.
 */
final class Diff
{
    /**
     * @param FeedItem[] $new
     * @param FeedItem[] $update
     * @param FeedItem[] $unchanged
     */
    public function __construct(
        public readonly array $new = [],
        public readonly array $update = [],
        public readonly array $unchanged = [],
    ) {}

    public function totalCount(): int
    {
        return count($this->new) + count($this->update) + count($this->unchanged);
    }
}
