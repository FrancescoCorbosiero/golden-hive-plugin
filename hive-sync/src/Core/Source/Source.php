<?php
declare(strict_types=1);

namespace HiveSync\Core\Source;

/**
 * A Source provides items (FeedItem) that can be selected, diffed against
 * the local catalog and materialized into WooCommerce products.
 *
 * Concrete implementations (phase 3+): GoldenSneakers, Csv, and the local
 * catalog itself (WooStoreSource for export).
 *
 * Contract is intentionally minimal — shared behavior (retry/binary-split,
 * provenance write, conflict engine integration) lives in AbstractSource.
 */
interface Source
{
    public function id(): string;

    public function label(): string;

    public function capabilities(): SourceCapabilities;

    /**
     * Schema describing the config fields this source needs (URL, token, etc.).
     * Drives the unified UI form so each source no longer defines its own HTML.
     *
     * @return array<string, array{type: string, label: string, required?: bool, secret?: bool, options?: array, max?: int}>
     */
    public function configSchema(): array;

    public function fetch(FetchRequest $request, Context $ctx): FetchResult;

    /**
     * @param FeedItem[] $items
     */
    public function diff(array $items, Context $ctx): Diff;

    public function materialize(FeedItem $item, Context $ctx): MaterializeResult;
}
