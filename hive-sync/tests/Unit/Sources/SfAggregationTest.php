<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Core\Source\FeedItem;
use HiveSync\Sources\CsvSource;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks the SF stock-aggregation semantics across the O(S²)→O(S)
 * rewrite of the MODEL-row pass: the PRODUCT row's QUANTITY claim
 * stands for size-less items and is REPLACED by the running sum of
 * size quantities as soon as MODEL rows appear.
 */
final class SfAggregationTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $rows */
    private function normalize( array $rows ): array
    {
        $m = new ReflectionMethod( CsvSource::class, 'sfNormalizeAndTransform' );
        /** @var FeedItem[] $items */
        $items = $m->invoke( null, $rows, 1.0 );
        $out = [];
        foreach ( $items as $item ) {
            $out[ $item->sku ] = $item->data;
        }
        return $out;
    }

    public function testSimpleItemKeepsProductRowQuantity(): void
    {
        $data = $this->normalize( [
            [ 'RECORD_TYPE' => 'PRODUCT', 'SKU' => 'BAG1', 'BRAND' => 'Guess', 'Titel_ITA' => 'Borsa', 'STREET_PRICE' => '80', 'PRICE' => '40', 'QUANTITY' => '7' ],
        ] );

        $this->assertSame( 'simple', $data['BAG1']['type'] );
        $this->assertSame( 7, $data['BAG1']['stock_quantity'] );
        $this->assertSame( 'instock', $data['BAG1']['stock_status'] );
    }

    public function testModelRowsReplaceTheProductRowClaim(): void
    {
        // PRODUCT dichiara 99; le taglie reali sommano 3+5=8. La somma
        // delle MODEL row deve vincere (semantica legacy gh_sf_normalize).
        $data = $this->normalize( [
            [ 'RECORD_TYPE' => 'PRODUCT', 'SKU' => 'SHOE1', 'BRAND' => 'Nike', 'Titel_ITA' => 'Scarpa', 'STREET_PRICE' => '150', 'PRICE' => '70', 'QUANTITY' => '99' ],
            [ 'RECORD_TYPE' => 'MODEL', 'SKU' => 'SHOE1', 'MODEL_SIZE' => '42', 'QUANTITY' => '3', 'PRICE' => '70' ],
            [ 'RECORD_TYPE' => 'MODEL', 'SKU' => 'SHOE1', 'MODEL_SIZE' => '43', 'QUANTITY' => '5', 'PRICE' => '70' ],
        ] );

        $this->assertSame( 'variable', $data['SHOE1']['type'] );

        $quantities = array_column( $data['SHOE1']['variations'], 'stock_quantity', 'sku' );
        $this->assertSame( 3, $quantities['SHOE1-42'] ?? null );
        $this->assertSame( 5, $quantities['SHOE1-43'] ?? null );
    }

    public function testZeroQuantitySizesSumToOutOfStock(): void
    {
        $data = $this->normalize( [
            [ 'RECORD_TYPE' => 'PRODUCT', 'SKU' => 'SHOE2', 'BRAND' => 'Nike', 'Titel_ITA' => 'Scarpa 2', 'STREET_PRICE' => '150', 'PRICE' => '70', 'QUANTITY' => '50' ],
            [ 'RECORD_TYPE' => 'MODEL', 'SKU' => 'SHOE2', 'MODEL_SIZE' => '42', 'QUANTITY' => '0', 'PRICE' => '70' ],
            [ 'RECORD_TYPE' => 'MODEL', 'SKU' => 'SHOE2', 'MODEL_SIZE' => '43', 'QUANTITY' => '0', 'PRICE' => '70' ],
        ] );

        $quantities = array_column( $data['SHOE2']['variations'], 'stock_quantity', 'sku' );
        $this->assertSame( 0, $quantities['SHOE2-42'] ?? null );
        $this->assertSame( 0, $quantities['SHOE2-43'] ?? null );
    }
}
