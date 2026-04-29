<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Operations\Specifics;

use GH\Core\Operation\OperationContext;
use GH\Core\Source\Context as RunContext;
use GH\Operations\Pricing\AdjustPrice;
use GH\Operations\Pricing\MarkupPercent;
use GH\Operations\Pricing\SetSalePercent;
use GH\Operations\Stock\SetStockQuantity;
use GH\Operations\Stock\SetStockStatus;
use GH\Operations\Taxonomy\AssignBrand;
use GH\Operations\Taxonomy\AssignCategory;
use PHPUnit\Framework\TestCase;

/**
 * Operation-specific input validation tests. The CatalogContractTest
 * already pins the broad shape; this file covers per-op edge cases
 * that wouldn't be caught by the generic loop.
 */
final class InputValidationTest extends TestCase
{
    private function ctx(bool $dryRun = false): OperationContext
    {
        return new OperationContext(
            base: new RunContext(runId: 'r', dryRun: $dryRun),
            sourceId: 'woostore',
        );
    }

    public function test_markup_percent_rejects_zero_or_negative(): void
    {
        $op = new MarkupPercent();
        self::assertSame('invalid_percent', $op->apply(1, ['percent' => 0],   $this->ctx())->error);
        self::assertSame('invalid_percent', $op->apply(1, ['percent' => -10], $this->ctx())->error);
    }

    public function test_markup_percent_rejects_invalid_target(): void
    {
        $op = new MarkupPercent();
        $r = $op->apply(1, ['percent' => 10, 'target' => 'wholesale'], $this->ctx());
        self::assertSame('invalid_target', $r->error);
    }

    public function test_set_sale_percent_rejects_out_of_range(): void
    {
        $op = new SetSalePercent();
        // 0% and 100% are edge cases — both nonsensical for a sale.
        self::assertSame('invalid_percent', $op->apply(1, ['percent' => 0],   $this->ctx())->error);
        self::assertSame('invalid_percent', $op->apply(1, ['percent' => 100], $this->ctx())->error);
        self::assertSame('invalid_percent', $op->apply(1, ['percent' => 150], $this->ctx())->error);
    }

    public function test_adjust_price_zero_amount_is_a_noop_failure(): void
    {
        // amount=0 reports invalid rather than silently doing nothing —
        // a no-op pipeline step is almost always a bug.
        $op = new AdjustPrice();
        self::assertSame('invalid_amount', $op->apply(1, ['amount' => 0], $this->ctx())->error);
    }

    public function test_adjust_price_accepts_negative(): void
    {
        // Discount via negative amount should pass validation. Real
        // mutation hits the legacy check (which is missing in tests).
        $op = new AdjustPrice();
        $r = $op->apply(1, ['amount' => -5], $this->ctx());
        self::assertNotSame('invalid_amount', $r->error);
        self::assertNotSame('invalid_target', $r->error);
    }

    public function test_assign_brand_normalizes_csv_string_to_int_list(): void
    {
        // The form sends raw user text on int_list fields until the
        // future term-picker UI lands. Normalizer must accept "12,34,56".
        $op = new AssignBrand();
        $r = $op->apply(1, ['brand_ids' => '12, 34 , 56'], $this->ctx(dryRun: true));
        self::assertTrue($r->changed);
        self::assertSame([12, 34, 56], $r->changes['brand_ids']);
    }

    public function test_assign_brand_normalizes_json_string(): void
    {
        $op = new AssignBrand();
        $r = $op->apply(1, ['brand_ids' => '[1, 2, 3]'], $this->ctx(dryRun: true));
        self::assertTrue($r->changed);
        self::assertSame([1, 2, 3], $r->changes['brand_ids']);
    }

    public function test_assign_brand_drops_zero_and_negative_ids(): void
    {
        $op = new AssignBrand();
        $r = $op->apply(1, ['brand_ids' => [12, 0, -5, 34, '34']], $this->ctx(dryRun: true));
        self::assertTrue($r->changed);
        self::assertSame([12, 34], $r->changes['brand_ids']);
    }

    public function test_assign_brand_rejects_empty_after_normalize(): void
    {
        $op = new AssignBrand();
        self::assertSame('invalid_brand_ids', $op->apply(1, ['brand_ids' => []],         $this->ctx())->error);
        self::assertSame('invalid_brand_ids', $op->apply(1, ['brand_ids' => '0, -1'],    $this->ctx())->error);
        self::assertSame('invalid_brand_ids', $op->apply(1, ['brand_ids' => 'abc'],      $this->ctx())->error);
    }

    public function test_assign_category_uses_same_normalizer(): void
    {
        $op = new AssignCategory();
        $r = $op->apply(1, ['category_ids' => '7,8,9'], $this->ctx(dryRun: true));
        self::assertSame([7, 8, 9], $r->changes['category_ids']);
    }

    public function test_stock_status_rejects_unknown_value(): void
    {
        $op = new SetStockStatus();
        self::assertSame('invalid_stock_status', $op->apply(1, ['stock_status' => 'maybe'], $this->ctx())->error);
    }

    public function test_stock_quantity_rejects_negative(): void
    {
        $op = new SetStockQuantity();
        self::assertSame('invalid_quantity', $op->apply(1, ['quantity' => -3], $this->ctx())->error);
    }

    public function test_stock_quantity_zero_is_valid(): void
    {
        // Zero is a legitimate "out of stock" quantity, not invalid.
        $op = new SetStockQuantity();
        $r = $op->apply(1, ['quantity' => 0], $this->ctx(dryRun: true));
        self::assertTrue($r->changed);
        self::assertSame(0, $r->changes['quantity']);
    }
}
