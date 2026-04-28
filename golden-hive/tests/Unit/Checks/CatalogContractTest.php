<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Checks;

use GH\Checks\Media\HasImages;
use GH\Checks\Pricing\SaleBelowRegular;
use GH\Checks\Taxonomy\HasCategory;
use GH\Core\Check\Check;
use GH\Core\Check\CheckSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the v2 Checks catalog. Same data-provided pattern
 * as Operations: every shipped Check goes through the same sanity
 * checks so adding a new one gets coverage automatically.
 */
final class CatalogContractTest extends TestCase
{
    /** @return iterable<string, array{0: Check}> */
    public static function checks(): iterable
    {
        yield 'media.has_images'           => [new HasImages()];
        yield 'taxonomy.has_category'      => [new HasCategory()];
        yield 'pricing.sale_below_regular' => [new SaleBelowRegular()];
    }

    /** @dataProvider checks */
    public function test_id_is_dotted_namespaced(Check $c): void
    {
        $id = $c->id();
        self::assertNotSame('', $id);
        self::assertStringContainsString('.', $id, 'IDs follow <bucket>.<verb> convention');
    }

    /** @dataProvider checks */
    public function test_label_is_non_empty(Check $c): void
    {
        self::assertNotSame('', trim($c->label()));
    }

    /** @dataProvider checks */
    public function test_params_schema_is_typed_array(Check $c): void
    {
        $schema = $c->paramsSchema();
        self::assertIsArray($schema);
        foreach ($schema as $field => $spec) {
            self::assertIsArray($spec, "Field '{$field}' must be a spec array");
            self::assertArrayHasKey('type', $spec);
        }
        // Every check exposes a 'severity' override per the design pillar.
        self::assertArrayHasKey('severity', $schema, $c->id() . ' must expose a severity param');
        self::assertSame('enum', $schema['severity']['type']);
        self::assertContains('warn',  $schema['severity']['options']);
        self::assertContains('block', $schema['severity']['options']);
    }

    /** @dataProvider checks */
    public function test_default_severity_is_a_valid_enum(Check $c): void
    {
        self::assertInstanceOf(CheckSeverity::class, $c->defaultSeverity());
    }

    /** @dataProvider checks */
    public function test_evaluate_in_unit_test_mode_returns_pass(Check $c): void
    {
        // No WP loaded → optimistic pass. This is the precondition that
        // lets the per-check Specifics tests cover real verdict logic
        // via the pure-PHP helpers (count / verdict static methods).
        self::assertFalse(function_exists('wc_get_product'));
        $r = $c->evaluate(123, []);
        self::assertTrue($r->passed);
    }
}
