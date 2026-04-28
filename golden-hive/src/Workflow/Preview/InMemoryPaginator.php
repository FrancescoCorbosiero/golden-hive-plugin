<?php
declare(strict_types=1);

namespace GH\Workflow\Preview;

/**
 * Pure-PHP in-memory filter + paginate for the Workflow tab's preview
 * table when the source produces items in memory (e.g. GS fetch result
 * cached in a transient).
 *
 * Two responsibilities:
 *  1. Apply a free-text search across `sku` and `name` (case-insensitive
 *     substring). Other fields are intentionally NOT searched — keeping
 *     the contract small lets the UI promise consistent semantics across
 *     all sources.
 *  2. Slice the filtered list into a page.
 *
 * Local-catalog previews (WooStoreSource) bypass this class entirely:
 * they use WC_Product_Query whose own `s=` argument handles search at
 * SQL level. This class is only for fetched-in-memory item lists.
 */
final class InMemoryPaginator
{
    public const PER_PAGE_DEFAULT = 50;

    /**
     * @param array<int, array{sku?: string, name?: string}> $items
     * @return array{items: array, total: int, page: int, per_page: int}
     */
    public static function filterAndPaginate(
        array $items,
        string $search,
        int $page,
        int $perPage = self::PER_PAGE_DEFAULT,
    ): array {
        $perPage = max(1, $perPage);
        $page    = max(1, $page);

        if ($search !== '') {
            $items = self::applySearch($items, $search);
        }

        $total  = count($items);
        $offset = ($page - 1) * $perPage;
        // Snap page back to the last valid page when an out-of-range
        // page is requested (e.g. user was on page 3, then a search
        // narrowed to 1 page). Caller sees the clamped page in the
        // response so the UI stays consistent.
        if ($total > 0 && $offset >= $total) {
            $page   = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
        }

        return [
            'items'    => array_slice($items, $offset, $perPage),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<int, array{sku?: string, name?: string}> $items
     * @return array<int, array>
     */
    private static function applySearch(array $items, string $search): array
    {
        $needle = mb_strtolower($search);
        $matches = [];
        foreach ($items as $item) {
            $sku  = mb_strtolower((string) ($item['sku']  ?? ''));
            $name = mb_strtolower((string) ($item['name'] ?? ''));
            if ($sku !== '' && str_contains($sku, $needle)) {
                $matches[] = $item;
                continue;
            }
            if ($name !== '' && str_contains($name, $needle)) {
                $matches[] = $item;
            }
        }
        return $matches;
    }
}
