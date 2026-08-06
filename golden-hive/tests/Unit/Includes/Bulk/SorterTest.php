<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Includes\Bulk;

use PHPUnit\Framework\TestCase;
use WC_Product;

require_once __DIR__ . '/../../../../includes/bulk/sorter.php';

/**
 * Coverage for the decorate/sort/undecorate core and the two behavioral
 * fixes that rode along:
 *  - sale_first uses is_on_sale() — get_sale_price() on a variable
 *    parent is ALWAYS '' so the old comparator ranked nothing;
 *  - unknown rules are rejected instead of silently rewriting
 *    menu_order in arbitrary order.
 */
final class SorterTest extends TestCase
{
    /** @param array<string, mixed> $data */
    private static function product( array $data ): WC_Product
    {
        return new WC_Product( $data );
    }

    private static function names( array $products ): array
    {
        return array_map( static fn( WC_Product $p ): string => $p->get_name(), $products );
    }

    public function testNameAscIsCaseInsensitiveAndStable(): void
    {
        $sorted = gh_sort_products_list( [
            self::product( [ 'id' => 1, 'name' => 'bravo' ] ),
            self::product( [ 'id' => 2, 'name' => 'Alfa' ] ),
            self::product( [ 'id' => 3, 'name' => 'alfa' ] ), // pari chiave: dopo #2 (input order)
        ], 'name_asc' );

        $this->assertSame( [ 'Alfa', 'alfa', 'bravo' ], self::names( $sorted ) );
    }

    public function testNameSortKeepsLegacyByteCompareForNumericNames(): void
    {
        // strcmp: '10' < '9' (byte '1' < '9'). A numeric-aware compare
        // would flip these — the legacy comparator used strcmp and the
        // rewrite must not change published catalog order.
        $sorted = gh_sort_products_list( [
            self::product( [ 'id' => 1, 'name' => '9' ] ),
            self::product( [ 'id' => 2, 'name' => '10' ] ),
        ], 'name_asc' );

        $this->assertSame( [ '10', '9' ], self::names( $sorted ) );
    }

    public function testPriceDesc(): void
    {
        $sorted = gh_sort_products_list( [
            self::product( [ 'id' => 1, 'name' => 'cheap', 'price' => '10' ] ),
            self::product( [ 'id' => 2, 'name' => 'mid', 'price' => '99.5' ] ),
            self::product( [ 'id' => 3, 'name' => 'pricey', 'price' => '250' ] ),
        ], 'price_desc' );

        $this->assertSame( [ 'pricey', 'mid', 'cheap' ], self::names( $sorted ) );
    }

    public function testStockFirstPutsInStockOnTop(): void
    {
        // Simple products: il valore stock si legge dal prodotto stesso.
        // (Il ramo variable idrata i figli via wc_get_product, che in
        // unit-test non esiste per contratto — vedi tests/wp-stubs.php.)
        $sorted = gh_sort_products_list( [
            self::product( [ 'id' => 2, 'name' => 'sold-out', 'stock_status' => 'outofstock' ] ),
            self::product( [ 'id' => 1, 'name' => 'available', 'stock_status' => 'instock' ] ),
            self::product( [ 'id' => 3, 'name' => 'also-out', 'stock_status' => 'outofstock' ] ),
        ], 'stock_first' );

        $this->assertSame( [ 'available', 'sold-out', 'also-out' ], self::names( $sorted ) );
    }

    public function testSaleFirstRanksVariableParentsViaIsOnSale(): void
    {
        // Regression: sale_price on a variable parent is '' by design, so
        // the old get_sale_price() comparator scored every variable 0 and
        // the whole sort was a no-op in input order.
        $sorted = gh_sort_products_list( [
            self::product( [ 'id' => 1, 'name' => 'full-price', 'type' => 'variable', 'on_sale' => false ] ),
            self::product( [ 'id' => 2, 'name' => 'discounted', 'type' => 'variable', 'on_sale' => true ] ),
        ], 'sale_first' );

        $this->assertSame( [ 'discounted', 'full-price' ], self::names( $sorted ) );
    }

    public function testVariantCountDesc(): void
    {
        $sorted = gh_sort_products_list( [
            self::product( [ 'id' => 1, 'name' => 'few', 'type' => 'variable', 'children' => [ 11 ] ] ),
            self::product( [ 'id' => 2, 'name' => 'many', 'type' => 'variable', 'children' => [ 21, 22, 23 ] ] ),
        ], 'variant_count_desc' );

        $this->assertSame( [ 'many', 'few' ], self::names( $sorted ) );
    }

    public function testUnknownRuleIsRejectedWithoutWriting(): void
    {
        $result = gh_sort_products( [], 'definitely_not_a_rule' );

        $this->assertSame( 'unknown_rule', $result['error'] ?? null );
        $this->assertSame( 0, $result['updated'] );

        $preview = gh_sort_preview( [], 'definitely_not_a_rule' );
        $this->assertSame( 'unknown_rule', $preview['error'] ?? null );
    }
}
