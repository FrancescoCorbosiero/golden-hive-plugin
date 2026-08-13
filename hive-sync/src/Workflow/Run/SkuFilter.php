<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;

/**
 * Restrict a run to an explicit list of SKUs — the scoped replacement
 * for golden-hive's `gh_reimport_run( $feed_type, $skus, ... )`.
 *
 * ─── Why the filter runs BEFORE diff ─────────────────────────────────
 *
 * The upstream feed is a bulk endpoint: there is no way to ask it for
 * 12 SKUs, so the whole payload is downloaded either way (the legacy
 * gh_reimport_fetch did exactly the same). But diffing all 10k rows to
 * then throw away 9,988 of them is pure waste — the diff runs batched
 * SKU→id lookups plus the whole StockOnlyClassifier pass. Filtering at
 * the FeedItem level right after fetch means the diff only ever sees
 * the requested subset.
 *
 * ─── Why matched items are PROMOTED to `update` ──────────────────────
 *
 * Naming a SKU explicitly is the operator saying "I don't trust the
 * diff for this one, redo it". If matched items kept their natural
 * bucket, the common case would silently do nothing: on a settled
 * catalog a named SKU is almost always `unchanged` (skipped entirely)
 * or `updateStock` (fast-patched, which never calls materialize — so
 * no media, no taxonomy, no description). Either way the operator asks
 * for a re-import and watches zero happen, which is the exact failure
 * this whole feature exists to end. So every matched item that already
 * exists in Woo is promoted to `update` → full pipeline + materialize.
 *
 * `new` items are left in `new`: they don't exist yet, and creating
 * them IS the full path already.
 *
 * ─── Missing SKUs are reported, never silent ─────────────────────────
 *
 * A SKU the operator typed that isn't in the feed at all (typo,
 * delisted upstream, wrong source) must surface. Silently importing 10
 * of 12 and saying "done" hides the two that matter most.
 */
final class SkuFilter
{
    /**
     * Parse an operator-supplied SKU list. Accepts an array, or a
     * single string separated by newlines / commas / semicolons /
     * tabs / spaces — operators paste from spreadsheets, and the
     * separator is whatever their column happened to use.
     *
     * Returns the ORIGINAL spelling (trimmed), deduped
     * case-insensitively, so anything reported back as "missing" reads
     * exactly as the operator typed it.
     *
     * @return string[]
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) return [];

        $parts = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_scalar($v)) $parts[] = (string) $v;
            }
        } elseif (is_scalar($raw)) {
            $split = preg_split('/[\r\n,;\t ]+/', (string) $raw);
            $parts = $split === false ? [] : $split;
        }

        $out  = [];
        $seen = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $key = self::normalize($p);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $p;
        }
        return $out;
    }

    /**
     * Case-insensitive, whitespace-tolerant SKU key. Mirrors the
     * legacy gh_reimport_filter_records() comparison so a list that
     * worked in the old force re-import keeps working here.
     */
    public static function normalize(string $sku): string
    {
        return strtoupper(trim($sku));
    }

    /**
     * Keep only the FeedItems whose SKU appears in $skus.
     *
     * @param FeedItem[] $items
     * @param string[]   $skus  operator input, as returned by parse()
     * @return array{items: FeedItem[], matched: string[], missing: string[]}
     */
    public static function filterItems(array $items, array $skus): array
    {
        if (! $skus) {
            return [ 'items' => $items, 'matched' => [], 'missing' => [] ];
        }

        $wanted = [];   // normalized => original spelling
        foreach ($skus as $sku) {
            $wanted[self::normalize($sku)] = $sku;
        }

        $kept  = [];
        $found = [];
        foreach ($items as $item) {
            if (! $item instanceof FeedItem) continue;
            $key = self::normalize($item->sku);
            if ($key === '' || ! isset($wanted[$key])) continue;
            $kept[]      = $item;
            $found[$key] = true;
        }

        $missing = [];
        foreach ($wanted as $key => $original) {
            if (! isset($found[$key])) $missing[] = $original;
        }

        return [
            'items'   => $kept,
            'matched' => array_values(array_intersect_key($wanted, $found)),
            'missing' => $missing,
        ];
    }

    /**
     * Promote every existing-product bucket into `update` so an
     * explicitly-named SKU always takes the full pipeline. See the
     * class docblock for why this is not optional.
     *
     * Idempotent, and a no-op on a diff whose unchanged/updateStock
     * buckets are already empty.
     */
    public static function promote(Diff $diff): Diff
    {
        if ($diff->unchanged === [] && $diff->updateStock === []) {
            return $diff;
        }

        $update = $diff->update;
        foreach ($diff->updateStock as $item) $update[] = $item;
        foreach ($diff->unchanged   as $item) $update[] = $item;

        return new Diff(
            new:         $diff->new,
            update:      $update,
            unchanged:   [],
            updateStock: [],
        );
    }

    /**
     * Stable signature of a parsed SKU list, used to detect a RunCache
     * entry written for a DIFFERENT selection. Order-insensitive: the
     * same SKUs pasted in another order must hit the same cache, since
     * the resulting queue is identical.
     */
    public static function signature(array $skus): string
    {
        if (! $skus) return '';
        $keys = array_map([ self::class, 'normalize' ], $skus);
        sort($keys);
        return md5(implode('|', $keys));
    }
}
