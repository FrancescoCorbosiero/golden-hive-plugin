<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

/**
 * Result of comparing fetched FeedItems against the local catalog.
 * Four buckets so a job can subset processing:
 *
 *   - new           item not yet in Woo. Heavy path: full materialize +
 *                   media download + categorize + variants.
 *   - update        item exists but non-stock fields changed. Heavy
 *                   path: full re-materialize.
 *   - updateStock   item exists and ONLY stock_quantity / stock_status
 *                   / regular_price / sale_price changed. Light path:
 *                   patch via WC product setters, no media, no taxonomy,
 *                   no description rewrite. This is the fast bucket.
 *   - unchanged     no observable difference; skip entirely.
 *
 * `update` and `updateStock` are mutually exclusive — a source's diff
 * implementation classifies each updated item into exactly one of them.
 * Sources that don't bother to disambiguate dump everything into
 * `update`; the runner still works (just won't get the speed benefit).
 */
final class Diff
{
    /**
     * @param FeedItem[] $new
     * @param FeedItem[] $update
     * @param FeedItem[] $unchanged
     * @param FeedItem[] $updateStock
     */
    public function __construct(
        public readonly array $new = [],
        public readonly array $update = [],
        public readonly array $unchanged = [],
        public readonly array $updateStock = [],
    ) {}

    public function totalCount(): int
    {
        return count($this->new) + count($this->update)
            + count($this->updateStock) + count($this->unchanged);
    }
}
