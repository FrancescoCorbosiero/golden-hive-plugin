<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Workflow\Preview;

use GH\Workflow\Preview\InMemoryPaginator;
use PHPUnit\Framework\TestCase;

final class InMemoryPaginatorTest extends TestCase
{
    /** @return array<int, array{sku: string, name: string}> */
    private static function fakeItems(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['sku' => 'SKU' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'name' => 'Item ' . $i];
        }
        return $out;
    }

    public function test_returns_empty_page_for_empty_input(): void
    {
        $r = InMemoryPaginator::filterAndPaginate([], '', 1, 50);
        self::assertSame([], $r['items']);
        self::assertSame(0, $r['total']);
        self::assertSame(1, $r['page']);
        self::assertSame(50, $r['per_page']);
    }

    public function test_paginates_without_search(): void
    {
        $items = self::fakeItems(120);
        $r = InMemoryPaginator::filterAndPaginate($items, '', 1, 50);
        self::assertCount(50, $r['items']);
        self::assertSame(120, $r['total']);
        self::assertSame('SKU001', $r['items'][0]['sku']);
        self::assertSame('SKU050', $r['items'][49]['sku']);

        $r2 = InMemoryPaginator::filterAndPaginate($items, '', 3, 50);
        self::assertCount(20, $r2['items'], '120 - 100 = 20 items on last page');
        self::assertSame('SKU101', $r2['items'][0]['sku']);
    }

    public function test_search_matches_sku_substring_case_insensitive(): void
    {
        $items = self::fakeItems(20);
        $r = InMemoryPaginator::filterAndPaginate($items, 'sku00', 1, 50);
        self::assertSame(9, $r['total'], 'sku001..sku009');
        self::assertSame('SKU001', $r['items'][0]['sku']);
    }

    public function test_search_matches_name_substring(): void
    {
        $items = [
            ['sku' => 'A', 'name' => 'Adidas Stan Smith'],
            ['sku' => 'B', 'name' => 'Nike Air Force'],
            ['sku' => 'C', 'name' => 'Adidas Samba'],
        ];
        $r = InMemoryPaginator::filterAndPaginate($items, 'adidas', 1, 50);
        self::assertSame(2, $r['total']);
        self::assertSame('A', $r['items'][0]['sku']);
        self::assertSame('C', $r['items'][1]['sku']);
    }

    public function test_search_misses_return_empty_total_zero(): void
    {
        $items = self::fakeItems(10);
        $r = InMemoryPaginator::filterAndPaginate($items, 'zzz_no_match', 1, 50);
        self::assertSame(0, $r['total']);
        self::assertSame([], $r['items']);
    }

    public function test_clamps_out_of_range_page_to_last_valid_page(): void
    {
        $items = self::fakeItems(60);
        // Request page 9 with per_page=50 → only 2 pages exist.
        $r = InMemoryPaginator::filterAndPaginate($items, '', 9, 50);
        self::assertSame(2, $r['page']);
        self::assertCount(10, $r['items']);
        self::assertSame('SKU051', $r['items'][0]['sku']);
    }

    public function test_normalizes_invalid_inputs(): void
    {
        $items = self::fakeItems(5);
        $r = InMemoryPaginator::filterAndPaginate($items, '', -3, 0);
        self::assertSame(1, $r['page']);
        self::assertSame(1, $r['per_page'], 'per_page coerced to >= 1');
        self::assertCount(1, $r['items']);
    }

    public function test_search_tolerates_missing_fields(): void
    {
        $items = [
            ['sku' => 'A'],                 // no name
            ['name' => 'Lonely'],           // no sku
            ['sku' => 'B', 'name' => 'X'],
        ];
        $r = InMemoryPaginator::filterAndPaginate($items, 'lonely', 1, 50);
        self::assertSame(1, $r['total']);
        self::assertSame('Lonely', $r['items'][0]['name']);
    }
}
