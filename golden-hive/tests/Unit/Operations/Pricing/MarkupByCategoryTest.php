<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Operations\Pricing;

use GH\Core\Operation\OperationContext;
use GH\Core\Source\Context as RunContext;
use GH\Core\Source\FeedItem;
use GH\Operations\Pricing\MarkupByCategory;
use PHPUnit\Framework\TestCase;

final class MarkupByCategoryTest extends TestCase
{
    private function ctx(bool $dryRun = false): OperationContext
    {
        return new OperationContext(
            base: new RunContext(runId: 'r', dryRun: $dryRun),
            sourceId: 'goldensneakers',
        );
    }

    // ── Pure helpers ────────────────────────────────────────

    public function test_calculate_uses_specific_category_first(): void
    {
        $map = ['sneakers' => 30.0, 'default' => 25.0];
        self::assertSame(30.0, MarkupByCategory::calculate('sneakers', $map));
    }

    public function test_calculate_falls_back_to_default(): void
    {
        $map = ['sneakers' => 30.0, 'default' => 25.0];
        self::assertSame(25.0, MarkupByCategory::calculate('unknown', $map));
    }

    public function test_calculate_returns_null_when_no_match_and_no_default(): void
    {
        $map = ['sneakers' => 30.0];
        self::assertNull(MarkupByCategory::calculate('abbigliamento', $map));
    }

    public function test_calculate_skips_zero_or_negative(): void
    {
        // After normalizeMap a non-positive percent shouldn't appear in
        // the map, but calculate() defends against direct calls too.
        $map = ['sneakers' => 0, 'default' => 25.0];
        self::assertSame(25.0, MarkupByCategory::calculate('sneakers', $map));
    }

    public function test_normalize_map_accepts_json_string(): void
    {
        $r = MarkupByCategory::normalizeMap('{"sneakers":30,"abbigliamento":50}');
        self::assertSame(['sneakers' => 30.0, 'abbigliamento' => 50.0], $r);
    }

    public function test_normalize_map_drops_non_positive_values(): void
    {
        $r = MarkupByCategory::normalizeMap(['a' => 10, 'b' => 0, 'c' => -5, 'd' => '20.5']);
        self::assertSame(['a' => 10.0, 'd' => 20.5], $r);
    }

    public function test_normalize_map_drops_empty_keys(): void
    {
        $r = MarkupByCategory::normalizeMap(['' => 30, ' ' => 40, 'sneakers' => 50]);
        self::assertSame(['sneakers' => 50.0], $r);
    }

    public function test_normalize_map_handles_invalid_json(): void
    {
        self::assertSame([], MarkupByCategory::normalizeMap('{not json'));
        self::assertSame([], MarkupByCategory::normalizeMap(null));
    }

    public function test_extract_category_prefers_gs_specific_field(): void
    {
        self::assertSame(
            'sneakers',
            MarkupByCategory::extractCategory(['_gs_category' => 'sneakers', 'category' => 'other']),
        );
    }

    public function test_extract_category_falls_back_to_generic(): void
    {
        self::assertSame(
            'abbigliamento',
            MarkupByCategory::extractCategory(['category' => 'abbigliamento']),
        );
    }

    public function test_extract_category_returns_empty_when_absent(): void
    {
        self::assertSame('', MarkupByCategory::extractCategory([]));
    }

    // ── applyDuringImport (pre-import path) ─────────────────

    public function test_apply_during_import_marks_up_regular_price(): void
    {
        $rule = new MarkupByCategory();
        $item = new FeedItem(
            sku: 'NK-001',
            data: ['_gs_category' => 'sneakers', 'regular_price' => 100.0],
        );
        $draft = $item->data;

        $rule->applyDuringImport(
            $item,
            $draft,
            ['markup_map' => ['sneakers' => 30.0]],
            $this->ctx(),
        );

        self::assertSame(130.0, $draft['regular_price']);
        self::assertSame('pricing.markup_by_category', $draft['_gh_markup_applied']['rule']);
        self::assertSame('sneakers', $draft['_gh_markup_applied']['category']);
        self::assertSame(30.0, $draft['_gh_markup_applied']['percent']);
    }

    public function test_apply_during_import_uses_default_when_category_absent(): void
    {
        $rule = new MarkupByCategory();
        $item = new FeedItem(sku: 'X', data: ['regular_price' => 200.0]);
        $draft = $item->data;

        $rule->applyDuringImport(
            $item,
            $draft,
            ['markup_map' => ['default' => 50.0]],
            $this->ctx(),
        );

        self::assertSame(300.0, $draft['regular_price']);
    }

    public function test_apply_during_import_no_op_when_no_match_no_default(): void
    {
        $rule = new MarkupByCategory();
        $item = new FeedItem(sku: 'X', data: ['_gs_category' => 'unknown', 'regular_price' => 50.0]);
        $draft = $item->data;

        $rule->applyDuringImport(
            $item,
            $draft,
            ['markup_map' => ['sneakers' => 30.0]],
            $this->ctx(),
        );

        self::assertSame(50.0, $draft['regular_price']);
        self::assertArrayNotHasKey('_gh_markup_applied', $draft);
    }

    public function test_apply_during_import_no_op_on_zero_or_missing_price(): void
    {
        $rule = new MarkupByCategory();
        $item = new FeedItem(sku: 'X', data: ['_gs_category' => 'sneakers']);
        $draft = $item->data;

        $rule->applyDuringImport($item, $draft, ['markup_map' => ['sneakers' => 30.0]], $this->ctx());

        self::assertArrayNotHasKey('_gh_markup_applied', $draft);
    }

    public function test_apply_during_import_no_op_on_empty_map(): void
    {
        $rule = new MarkupByCategory();
        $item = new FeedItem(sku: 'X', data: ['_gs_category' => 'sneakers', 'regular_price' => 100.0]);
        $draft = $item->data;

        $rule->applyDuringImport($item, $draft, ['markup_map' => []], $this->ctx());

        self::assertSame(100.0, $draft['regular_price']);
    }

    public function test_apply_during_import_rounds_to_2_decimals(): void
    {
        $rule = new MarkupByCategory();
        $item = new FeedItem(sku: 'X', data: ['_gs_category' => 'a', 'regular_price' => 99.99]);
        $draft = $item->data;

        $rule->applyDuringImport(
            $item,
            $draft,
            ['markup_map' => ['a' => 33.0]],   // 99.99 * 1.33 = 132.9867 → 132.99
            $this->ctx(),
        );

        self::assertSame(132.99, $draft['regular_price']);
    }

    // ── apply (post-hoc path) ───────────────────────────────

    public function test_apply_rejects_empty_map(): void
    {
        $rule = new MarkupByCategory();
        $r = $rule->apply(1, ['markup_map' => []], $this->ctx());
        self::assertSame('invalid_markup_map', $r->error);
    }

    public function test_apply_rejects_invalid_target(): void
    {
        $rule = new MarkupByCategory();
        $r = $rule->apply(1, [
            'markup_map' => ['default' => 30.0],
            'target'     => 'wholesale',
        ], $this->ctx());
        self::assertSame('invalid_target', $r->error);
    }

    public function test_apply_with_no_wp_returns_no_markup_for_category(): void
    {
        // No wp_get_post_terms → resolveProductCategorySlug returns ''.
        // calculate('') falls back to 'default'; no default in map → null
        // → reports the no-markup-for-category error with the resolved
        // category slug surfaced for debugging.
        $rule = new MarkupByCategory();
        $r = $rule->apply(1, [
            'markup_map' => ['sneakers' => 30.0],
        ], $this->ctx());
        self::assertStringContainsString('no_markup_for_category', $r->error ?? '');
    }
}
