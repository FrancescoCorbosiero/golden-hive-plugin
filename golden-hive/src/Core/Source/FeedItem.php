<?php
declare(strict_types=1);

namespace GH\Core\Source;

/**
 * A normalized item produced by Source::fetch(). Sources translate their
 * native shape (GS JSON, SF CSV row, KicksDB API response) into this
 * uniform structure so downstream code (diff, materialize, pipeline)
 * never branches on source type.
 *
 * `data` is the normalized payload the product factory understands.
 * `raw` is the untouched original (kept for debugging/audit; never
 * reaches the product factory).
 */
final class FeedItem
{
    public function __construct(
        public readonly string $sku,
        public readonly array $data,
        public readonly array $raw = [],
    ) {}
}
