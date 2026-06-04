<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Sources;

use HiveSync\Sources\CsvSource;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the bespoke StockFirmati diff comparison core
 * (CsvSource::sfProductNeedsUpdate).
 *
 * The bug this guards against: SF was forced through the generic
 * StockOnlyClassifier, whose 500-item circuit breaker + kses
 * description comparison labelled the ENTIRE catalog as "update" on
 * every run. The canonical proof of the fix is
 * {@see testUnchangedVariableProductIsNotFlagged} — a product whose
 * feed values match Woo exactly must return false (→ `unchanged`),
 * which is precisely what the old path could never deliver at scale.
 *
 * Pure function: no WordPress, no DB. Snapshots are passed in the same
 * shape ParentScalarLookup / VariationLookup produce.
 */
final class SfDiffTest extends TestCase
{
    /** A variation snapshot keyed by SKU, two sizes, both in stock. */
    private function existingVariations(): array
    {
        return [
            'ABC-42' => ['regular_price' => '120', 'sale_price' => '87', 'stock' => 3, 'stock_status' => 'instock'],
            'ABC-43' => ['regular_price' => '120', 'sale_price' => '87', 'stock' => 0, 'stock_status' => 'outofstock'],
        ];
    }

    /** Feed item matching existingVariations() exactly. */
    private function matchingVariableItem(): array
    {
        return [
            'sku'  => 'ABC',
            'type' => 'variable',
            'variations' => [
                ['sku' => 'ABC-42', 'regular_price' => '120', 'sale_price' => '87', 'stock_quantity' => 3, 'stock_status' => 'instock'],
                ['sku' => 'ABC-43', 'regular_price' => '120', 'sale_price' => '87', 'stock_quantity' => 0, 'stock_status' => 'outofstock'],
            ],
        ];
    }

    public function testUnchangedVariableProductIsNotFlagged(): void
    {
        // THE regression: identical feed vs Woo → unchanged, not update.
        $this->assertFalse(
            CsvSource::sfProductNeedsUpdate($this->matchingVariableItem(), null, $this->existingVariations())
        );
    }

    public function testPriceFormattingDifferenceIsNotAPhantomDiff(): void
    {
        // Woo stores "120.00" / "87.0"; feed carries "120" / "87".
        // Tolerant numeric compare must treat these as equal.
        $existing = [
            'ABC-42' => ['regular_price' => '120.00', 'sale_price' => '87.0', 'stock' => 3, 'stock_status' => 'instock'],
            'ABC-43' => ['regular_price' => '120.00', 'sale_price' => '87.0', 'stock' => 0, 'stock_status' => 'outofstock'],
        ];
        $this->assertFalse(
            CsvSource::sfProductNeedsUpdate($this->matchingVariableItem(), null, $existing)
        );
    }

    public function testVariationStockChangeIsFlagged(): void
    {
        $item = $this->matchingVariableItem();
        $item['variations'][0]['stock_quantity'] = 5; // was 3
        $this->assertTrue(
            CsvSource::sfProductNeedsUpdate($item, null, $this->existingVariations())
        );
    }

    public function testVariationSalePriceChangeIsFlagged(): void
    {
        $item = $this->matchingVariableItem();
        $item['variations'][1]['sale_price'] = '79'; // was 87
        $this->assertTrue(
            CsvSource::sfProductNeedsUpdate($item, null, $this->existingVariations())
        );
    }

    public function testNewSizeUpstreamIsFlagged(): void
    {
        $item = $this->matchingVariableItem();
        $item['variations'][] = ['sku' => 'ABC-44', 'regular_price' => '120', 'sale_price' => '87', 'stock_quantity' => 2, 'stock_status' => 'instock'];
        $this->assertTrue(
            CsvSource::sfProductNeedsUpdate($item, null, $this->existingVariations())
        );
    }

    public function testDiscontinuedSizeInWooIsFlagged(): void
    {
        // Feed dropped ABC-43; Woo still has it → must update so the
        // bridge can zero the orphan variation's stock.
        $item = $this->matchingVariableItem();
        $item['variations'] = [$item['variations'][0]]; // keep only ABC-42
        $this->assertTrue(
            CsvSource::sfProductNeedsUpdate($item, null, $this->existingVariations())
        );
    }

    public function testSimpleUnchangedIsNotFlagged(): void
    {
        $item = [
            'sku' => 'BAG-1', 'type' => 'simple',
            'regular_price' => '200', 'sale_price' => '150',
            'stock_quantity' => 4, 'stock_status' => 'instock',
        ];
        $scalar = ['regular_price' => '200', 'sale_price' => '150', 'stock' => 4, 'stock_status' => 'instock'];
        $this->assertFalse(CsvSource::sfProductNeedsUpdate($item, $scalar, []));
    }

    public function testSimpleStockChangeIsFlagged(): void
    {
        $item = [
            'sku' => 'BAG-1', 'type' => 'simple',
            'regular_price' => '200', 'sale_price' => '150',
            'stock_quantity' => 0, 'stock_status' => 'outofstock',
        ];
        $scalar = ['regular_price' => '200', 'sale_price' => '150', 'stock' => 4, 'stock_status' => 'instock'];
        $this->assertTrue(CsvSource::sfProductNeedsUpdate($item, $scalar, []));
    }

    public function testSimpleWithMissingSnapshotIsFlagged(): void
    {
        $item = ['sku' => 'BAG-1', 'type' => 'simple', 'regular_price' => '200'];
        $this->assertTrue(CsvSource::sfProductNeedsUpdate($item, null, []));
    }

    public function testVariableClaimedButNoVariationsIsFlagged(): void
    {
        $item = ['sku' => 'ABC', 'type' => 'variable', 'variations' => []];
        $this->assertTrue(CsvSource::sfProductNeedsUpdate($item, null, $this->existingVariations()));
    }
}
