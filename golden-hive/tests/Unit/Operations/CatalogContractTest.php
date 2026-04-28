<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Operations;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Source\Context as RunContext;
use GH\Operations\Pricing\AdjustPrice;
use GH\Operations\Pricing\MarkupPercent;
use GH\Operations\Pricing\SetSalePercent;
use GH\Operations\Status\SetStatus;
use GH\Operations\Stock\SetStockQuantity;
use GH\Operations\Stock\SetStockStatus;
use GH\Operations\Taxonomy\AssignBrand;
use GH\Operations\Taxonomy\AssignCategory;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the v2 Operations catalog. Loops every shipped
 * Operation through the same sanity checks so adding a new one is
 * automatically covered for the basics.
 *
 * The bigger per-op behavioral tests (validation rejection, dry-run
 * shape, legacy-unavailable handling) follow this in a second pass.
 */
final class CatalogContractTest extends TestCase
{
    /** @return iterable<string, array{0: Operation}> */
    public static function operations(): iterable
    {
        yield 'status.set'             => [new SetStatus()];
        yield 'pricing.markup_percent' => [new MarkupPercent()];
        yield 'pricing.set_sale_percent' => [new SetSalePercent()];
        yield 'pricing.adjust_price'   => [new AdjustPrice()];
        yield 'taxonomy.assign_brand'  => [new AssignBrand()];
        yield 'taxonomy.assign_category' => [new AssignCategory()];
        yield 'stock.set_status'       => [new SetStockStatus()];
        yield 'stock.set_quantity'     => [new SetStockQuantity()];
    }

    /** @dataProvider operations */
    public function test_id_is_dotted_namespaced(Operation $op): void
    {
        $id = $op->id();
        self::assertNotSame('', $id);
        self::assertStringContainsString('.', $id, 'IDs follow <bucket>.<verb> convention');
    }

    /** @dataProvider operations */
    public function test_label_is_non_empty(Operation $op): void
    {
        self::assertNotSame('', trim($op->label()));
    }

    /** @dataProvider operations */
    public function test_params_schema_is_array(Operation $op): void
    {
        $schema = $op->paramsSchema();
        self::assertIsArray($schema);
        // Every field declares a type (the form renderer assumes this).
        foreach ($schema as $field => $spec) {
            self::assertIsArray($spec, "Field '{$field}' must be an associative spec array");
            self::assertArrayHasKey('type', $spec, "Field '{$field}' must declare a type");
        }
    }

    /** @dataProvider operations */
    public function test_applies_to_lists_at_least_one_product_type(Operation $op): void
    {
        $types = $op->appliesTo();
        self::assertIsArray($types);
        self::assertNotEmpty($types);
    }

    /** @dataProvider operations */
    public function test_invalid_params_return_failed_not_throw(Operation $op): void
    {
        // Empty params is intentionally invalid for every shipped op.
        // The op must surface a clean OperationResult::failed, not an
        // exception — the executor wraps throws but we want the cheaper
        // path for known bad input.
        $r = $op->apply(123, [], $this->ctx());
        self::assertFalse($r->changed);
        self::assertNotNull($r->error);
    }

    /** @dataProvider operations */
    public function test_legacy_unavailable_returns_failed(Operation $op): void
    {
        // None of the legacy gh_apply_* / rp_cm_* / wp_update_post are
        // defined in the unit-test process. With valid-looking params
        // every op must reach the legacy-availability check and surface
        // a clean failure.
        $r = $op->apply(123, $this->validParamsFor($op), $this->ctx());
        self::assertFalse($r->changed);
        self::assertNotNull($r->error);
    }

    /** @dataProvider operations */
    public function test_dry_run_short_circuits_legacy(Operation $op): void
    {
        // Dry-run must succeed even with no WP loaded — that's the whole
        // point of the gate: the user trusts dry-run output before
        // committing to a real run.
        $r = $op->apply(123, $this->validParamsFor($op), $this->ctx(dryRun: true));
        self::assertTrue($r->changed, $op->id() . ' dry-run must report changed=true');
        self::assertNull($r->error);
        self::assertSame(true, $r->changes['dry_run'] ?? null);
    }

    private function ctx(bool $dryRun = false): OperationContext
    {
        return new OperationContext(
            base: new RunContext(runId: 'r', dryRun: $dryRun),
            sourceId: 'woostore',
        );
    }

    /**
     * Per-op smallest valid params shape that passes input validation
     * but fails at the legacy-availability gate. Keep in sync with each
     * Operation's paramsSchema.
     */
    private function validParamsFor(Operation $op): array
    {
        return match ($op->id()) {
            'status.set'               => ['status' => 'draft'],
            'pricing.markup_percent'   => ['percent' => 10],
            'pricing.set_sale_percent' => ['percent' => 25],
            'pricing.adjust_price'     => ['amount' => 5],
            'taxonomy.assign_brand'    => ['brand_ids' => [42]],
            'taxonomy.assign_category' => ['category_ids' => [42]],
            'stock.set_status'         => ['stock_status' => 'instock'],
            'stock.set_quantity'       => ['quantity' => 7],
            default                    => [],
        };
    }
}
