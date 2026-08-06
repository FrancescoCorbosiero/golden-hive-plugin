<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Core\Source\FeedItem;
use HiveSync\Sources\StockOnlyClassifier;
use PHPUnit\Framework\TestCase;

/**
 * classify() is a pure function of the pre-loaded snapshots (no WP, no
 * DB) — exactly the posture this suite tests in. Locks the three-way
 * verdict semantics and the cheap-fields-first comparison order that
 * the loop-inversion optimization introduced.
 */
final class StockOnlyClassifierTest extends TestCase
{
    /** Parent scalar snapshot in ParentScalarLookup::load() shape. */
    private function scalars( array $overrides = [] ): array
    {
        return [
            10 => array_merge( [
                'name'              => 'Air Max 90',
                'description'       => 'Classica silhouette.',
                'short_description' => '',
                'status'            => 'publish',
                'sku'               => 'AM90',
                'regular_price'     => '150',
                'sale_price'        => '',
                'stock'             => null,
                'stock_status'      => 'instock',
                'is_variable'       => true,
            ], $overrides ),
        ];
    }

    private function variations(): array
    {
        return [
            10 => [
                'AM90-42' => [ 'regular_price' => '150', 'sale_price' => '', 'stock' => 4, 'stock_status' => 'instock' ],
                'AM90-43' => [ 'regular_price' => '150', 'sale_price' => '', 'stock' => 0, 'stock_status' => 'outofstock' ],
            ],
        ];
    }

    /** FeedItem è readonly: si costruisce il data array e POI l'item. */
    private function itemData( array $overrides = [] ): array
    {
        return array_merge( [
            '_existing_id' => 10,
            'name'         => 'Air Max 90',
            'status'       => 'publish',
            'variations'   => [
                [ 'sku' => 'AM90-42', 'regular_price' => '150', 'sale_price' => '', 'stock_quantity' => 4, 'stock_status' => 'instock', 'attributes' => [ 'pa_taglia' => '42' ] ],
                [ 'sku' => 'AM90-43', 'regular_price' => '150', 'sale_price' => '', 'stock_quantity' => 0, 'stock_status' => 'outofstock', 'attributes' => [ 'pa_taglia' => '43' ] ],
            ],
        ], $overrides );
    }

    private function item( array $overrides = [] ): FeedItem
    {
        return new FeedItem( sku: 'AM90', data: $this->itemData( $overrides ) );
    }

    private function termSlugs(): array
    {
        return [ 10 => [ '42' => true, '43' => true ] ];
    }

    public function testIdenticalItemIsUnchanged(): void
    {
        $verdict = StockOnlyClassifier::classify(
            $this->item(),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
        );
        $this->assertSame( 'unchanged', $verdict );
    }

    public function testStockDeltaRoutesToUpdateStock(): void
    {
        $data = $this->itemData();
        $data['variations'][0]['stock_quantity'] = 9;
        $verdict = StockOnlyClassifier::classify(
            new FeedItem( sku: 'AM90', data: $data ),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
        );
        $this->assertSame( 'updateStock', $verdict );
    }

    public function testNameDeltaRoutesToUpdateFull(): void
    {
        $reason  = null;
        $verdict = StockOnlyClassifier::classify(
            $this->item( [ 'name' => 'Air Max 90 SE' ] ),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
            $reason,
        );
        $this->assertSame( 'updateFull', $verdict );
        $this->assertSame( 'name', $reason['field'] ?? null );
    }

    public function testCheapFieldsAreComparedBeforeDescriptions(): void
    {
        // Both name AND description differ: the reported reason must be
        // the cheap field — descriptions are only normalized (kses) when
        // everything cheaper already matched.
        $reason  = null;
        $verdict = StockOnlyClassifier::classify(
            $this->item( [ 'name' => 'Diverso', 'description' => 'Testo nuovo.' ] ),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
            $reason,
        );
        $this->assertSame( 'updateFull', $verdict );
        $this->assertSame( 'name', $reason['field'] ?? null );
    }

    public function testNewVariationSkuForcesFullPipeline(): void
    {
        $data = $this->itemData();
        $data['variations'][] = [ 'sku' => 'AM90-44', 'stock_quantity' => 2, 'attributes' => [ 'pa_taglia' => '44' ] ];
        $reason  = null;
        $verdict = StockOnlyClassifier::classify(
            new FeedItem( sku: 'AM90', data: $data ),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
            $reason,
        );
        $this->assertSame( 'updateFull', $verdict );
        $this->assertSame( '_new_variation_sku', $reason['field'] ?? null );
    }

    public function testOrphanWooVariationForcesFullPipeline(): void
    {
        $data = $this->itemData();
        unset( $data['variations'][1] );
        $reason  = null;
        $verdict = StockOnlyClassifier::classify(
            new FeedItem( sku: 'AM90', data: $data ),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
            $reason,
        );
        $this->assertSame( 'updateFull', $verdict );
        $this->assertSame( '_orphan_variation_in_woo', $reason['field'] ?? null );
    }

    public function testSimpleProductStockDelta(): void
    {
        $scalars = [
            20 => [
                'name' => 'Borsa', 'description' => '', 'short_description' => '',
                'status' => 'publish', 'sku' => 'BAG1',
                'regular_price' => '80', 'sale_price' => '',
                'stock' => 5, 'stock_status' => 'instock', 'is_variable' => false,
            ],
        ];

        $same = new FeedItem( sku: 'BAG1', data: [
            '_existing_id' => 20, 'name' => 'Borsa', 'status' => 'publish',
            'regular_price' => '80', 'stock_quantity' => 5,
        ] );
        $this->assertSame( 'unchanged', StockOnlyClassifier::classify( $same, [], [], $scalars ) );

        $delta = new FeedItem( sku: 'BAG1', data: [
            '_existing_id' => 20, 'name' => 'Borsa', 'status' => 'publish',
            'regular_price' => '80', 'stock_quantity' => 2,
        ] );
        $this->assertSame( 'updateStock', StockOnlyClassifier::classify( $delta, [], [], $scalars ) );
    }

    public function testPriceToleranceIgnoresFormattingNoise(): void
    {
        $data = $this->itemData();
        $data['variations'][0]['regular_price'] = '150.00';
        $verdict = StockOnlyClassifier::classify(
            new FeedItem( sku: 'AM90', data: $data ),
            $this->variations(),
            $this->termSlugs(),
            $this->scalars(),
        );
        $this->assertSame( 'unchanged', $verdict, '150 vs 150.00 non è un cambiamento' );
    }
}
