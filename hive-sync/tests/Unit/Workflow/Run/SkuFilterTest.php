<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Workflow\Run\SkuFilter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SKU-scoped re-import contract — the replacement for
 * golden-hive's gh_reimport_run( $feed_type, $skus, ... ).
 *
 * Two properties matter most and are easy to regress:
 *   - a named SKU is ALWAYS re-imported through the full pipeline,
 *     even when the diff considers it unchanged (otherwise the whole
 *     feature silently does nothing on a settled catalog);
 *   - a named SKU absent from the feed is REPORTED, never swallowed.
 */
final class SkuFilterTest extends TestCase
{
    private static function item(string $sku): FeedItem
    {
        return new FeedItem(sku: $sku, data: ['_existing_id' => 1], raw: []);
    }

    // ─── parse ───────────────────────────────────────────────────────

    public function testParseSplitsOnEverySeparatorOperatorsActuallyPaste(): void
    {
        $this->assertSame(['A1', 'B2', 'C3', 'D4'], SkuFilter::parse("A1\nB2, C3;D4"));
        $this->assertSame(['A1', 'B2'], SkuFilter::parse('A1 B2'));
        $this->assertSame(['A1', 'B2'], SkuFilter::parse("A1\r\nB2"));
    }

    public function testParseAcceptsAnArray(): void
    {
        $this->assertSame(['A1', 'B2'], SkuFilter::parse(['A1', 'B2']));
    }

    public function testParseDedupesCaseInsensitivelyKeepingTheFirstSpelling(): void
    {
        $this->assertSame(['Ab1'], SkuFilter::parse("Ab1\nAB1\nab1"));
    }

    public function testParseDropsEmptiesAndWhitespace(): void
    {
        $this->assertSame(['A1'], SkuFilter::parse("  \n A1 \n\n , ,  "));
        $this->assertSame([], SkuFilter::parse(''));
        $this->assertSame([], SkuFilter::parse(null));
        $this->assertSame([], SkuFilter::parse([]));
    }

    // ─── filterItems ─────────────────────────────────────────────────

    public function testFilterKeepsOnlyRequestedSkus(): void
    {
        $items = [self::item('A1'), self::item('B2'), self::item('C3')];

        $r = SkuFilter::filterItems($items, ['A1', 'C3']);

        $this->assertCount(2, $r['items']);
        $this->assertSame(['A1', 'C3'], array_map(fn($i) => $i->sku, $r['items']));
        $this->assertSame([], $r['missing']);
    }

    public function testFilterIsCaseInsensitive(): void
    {
        // Mirrors the legacy gh_reimport_filter_records() comparison, so
        // a list that worked in the old force re-import still works.
        $r = SkuFilter::filterItems([self::item('Ab-1')], ['AB-1']);

        $this->assertCount(1, $r['items']);
        $this->assertSame([], $r['missing']);
    }

    public function testMissingSkusAreReportedInTheOperatorsOwnSpelling(): void
    {
        $r = SkuFilter::filterItems([self::item('A1')], ['A1', 'nope-2', 'Nope-3']);

        $this->assertCount(1, $r['items']);
        $this->assertSame(['nope-2', 'Nope-3'], $r['missing']);
    }

    public function testEmptySkuListIsAPassThrough(): void
    {
        $items = [self::item('A1'), self::item('B2')];
        $r = SkuFilter::filterItems($items, []);

        $this->assertSame($items, $r['items']);
        $this->assertSame([], $r['missing']);
    }

    public function testFeedItemsWithNoSkuAreNeverMatched(): void
    {
        $blank = new FeedItem(sku: '', data: [], raw: []);
        $r = SkuFilter::filterItems([$blank], ['A1']);

        $this->assertSame([], $r['items']);
        $this->assertSame(['A1'], $r['missing']);
    }

    // ─── promote ─────────────────────────────────────────────────────

    public function testUnchangedItemsArePromotedSoANamedSkuIsAlwaysReimported(): void
    {
        // THE canonical test: on a settled catalog a named SKU is
        // normally `unchanged`. Without promotion the re-import would
        // silently process nothing.
        $diff = new Diff(unchanged: [self::item('A1')]);

        $out = SkuFilter::promote($diff);

        $this->assertCount(1, $out->update);
        $this->assertSame('A1', $out->update[0]->sku);
        $this->assertSame([], $out->unchanged);
    }

    public function testUpdateStockItemsArePromotedOffTheFastPatchPath(): void
    {
        // fastStockPatch never calls materialize — no media, no taxonomy.
        // A named SKU must not get that treatment.
        $diff = new Diff(updateStock: [self::item('A1')]);

        $out = SkuFilter::promote($diff);

        $this->assertCount(1, $out->update);
        $this->assertSame([], $out->updateStock);
    }

    public function testNewItemsStayNew(): void
    {
        // `new` already IS the full path; promoting would mislabel a
        // creation as an update.
        $diff = new Diff(new: [self::item('A1')]);

        $out = SkuFilter::promote($diff);

        $this->assertCount(1, $out->new);
        $this->assertSame([], $out->update);
    }

    public function testPromoteIsIdempotent(): void
    {
        $diff = new Diff(update: [self::item('A1')], unchanged: [self::item('B2')]);

        $once  = SkuFilter::promote($diff);
        $twice = SkuFilter::promote($once);

        $this->assertCount(2, $twice->update);
        $this->assertSame($once, $twice);   // already-promoted → same instance
    }

    public function testPromotePreservesEveryItem(): void
    {
        $diff = new Diff(
            new:         [self::item('N1')],
            update:      [self::item('U1')],
            unchanged:   [self::item('X1')],
            updateStock: [self::item('S1')],
        );

        $out = SkuFilter::promote($diff);

        $this->assertSame(4, $out->totalCount());
        $this->assertSame(['U1', 'S1', 'X1'], array_map(fn($i) => $i->sku, $out->update));
    }

    // ─── signature ───────────────────────────────────────────────────

    public function testSignatureIsOrderAndCaseInsensitive(): void
    {
        // Same selection pasted in another order must reuse the cached
        // diff — the resulting queue is identical.
        $this->assertSame(
            SkuFilter::signature(['A1', 'B2']),
            SkuFilter::signature(['b2', 'a1']),
        );
    }

    public function testSignatureDistinguishesDifferentSelections(): void
    {
        $this->assertNotSame(
            SkuFilter::signature(['A1', 'B2']),
            SkuFilter::signature(['A1', 'B3']),
        );
        // And an empty selection is distinct from any selection at all —
        // this is what stops a full-feed cache being reused for a
        // narrowed run (and vice versa).
        $this->assertSame('', SkuFilter::signature([]));
        $this->assertNotSame('', SkuFilter::signature(['A1']));
    }
}
