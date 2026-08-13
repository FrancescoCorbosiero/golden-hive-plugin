<?php
declare(strict_types=1);

namespace HiveSync\Tests\Unit\Workflow\Run;

use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Workflow\Run\MediaHealer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the media-heal re-bucketing contract.
 *
 * The bug it exists for: the bucket diff never inspects media, so a
 * product left imageless by a window of broken feed URLs matches the
 * feed on every compared field and sits in `unchanged` forever. The
 * bridge's "no featured image → attach" heal only runs for items in
 * `update`, so it is unreachable — the operator sees a product that
 * no amount of re-syncing can repair.
 */
final class MediaHealerTest extends TestCase
{
    private static function item(string $sku, int $pid, string $image = 'https://cdn.example/x.png'): FeedItem
    {
        $data = [ '_existing_id' => $pid ];
        if ($image !== '') $data['image_full_url'] = $image;
        return new FeedItem(sku: $sku, data: $data, raw: []);
    }

    /** Feed-side URL lookup mirroring JsonSource::imageUrls. */
    private static function urlsOf(): callable
    {
        return static function (FeedItem $item): array {
            $u = (string) ($item->data['image_full_url'] ?? '');
            return $u === '' ? [] : [ $u ];
        };
    }

    /** @param int[] $brokenIds */
    private static function brokenBeing(array $brokenIds): callable
    {
        return static fn(array $ids): array => array_values(array_intersect($ids, $brokenIds));
    }

    public function testImagelessUnchangedItemIsPulledIntoUpdate(): void
    {
        $broken = self::item('A', 10);
        $fine   = self::item('B', 20);
        $diff   = new Diff(unchanged: [$broken, $fine]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));

        $this->assertSame(1, $r['healed']);
        $this->assertCount(1, $r['diff']->update);
        $this->assertSame('A', $r['diff']->update[0]->sku);
        // The healthy one stays put — heal is surgical, not a re-import.
        $this->assertCount(1, $r['diff']->unchanged);
        $this->assertSame('B', $r['diff']->unchanged[0]->sku);
    }

    public function testImagelessUpdateStockItemIsPulledOutOfTheFastPatchPath(): void
    {
        // updateStock items skip materialize entirely (fastStockPatch),
        // so leaving a broken one there means it never heals even though
        // it IS being written to on every run.
        $diff = new Diff(updateStock: [self::item('A', 10)]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));

        $this->assertSame(1, $r['healed']);
        $this->assertCount(1, $r['diff']->update);
        $this->assertSame([], $r['diff']->updateStock);
    }

    public function testItemWithoutFeedImageIsNeverRequeued(): void
    {
        // The perpetual-work guard: the feed has nothing to attach, so
        // re-queueing could never succeed — it would just re-enter the
        // pool on every run forever.
        $diff = new Diff(unchanged: [self::item('A', 10, '')]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));

        $this->assertSame(0, $r['healed']);
        $this->assertCount(1, $r['diff']->unchanged);
        $this->assertSame([], $r['diff']->update);
    }

    public function testProductsThatAlreadyHaveAnImageAreLeftAlone(): void
    {
        $diff = new Diff(unchanged: [self::item('A', 10), self::item('B', 20)]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([]));

        $this->assertSame(0, $r['healed']);
        $this->assertCount(2, $r['diff']->unchanged);
        // Same Diff instance returned on the no-op path — no needless copy.
        $this->assertSame($diff, $r['diff']);
    }

    public function testConvergesToANoOpOnceHealed(): void
    {
        // Run 1 repairs; run 2 (product now has an image) finds nothing.
        // Without this property the option couldn't be left on a cron job.
        $diff = new Diff(unchanged: [self::item('A', 10)]);

        $first = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));
        $this->assertSame(1, $first['healed']);

        $second = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([]));
        $this->assertSame(0, $second['healed']);
    }

    public function testItemsWithoutAnExistingIdAreIgnored(): void
    {
        // No _existing_id means nothing to look up (and nothing to heal).
        $orphan = new FeedItem(sku: 'A', data: ['image_full_url' => 'https://cdn.example/x.png'], raw: []);
        $diff   = new Diff(unchanged: [$orphan]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));

        $this->assertSame(0, $r['healed']);
        $this->assertCount(1, $r['diff']->unchanged);
    }

    public function testNewAndUpdateBucketsArePreservedVerbatim(): void
    {
        // `new` has no product to heal; `update` already routes through
        // materialize. Neither may be disturbed by the re-bucketing.
        $new    = new FeedItem(sku: 'N', data: [], raw: []);
        $update = self::item('U', 30);
        $diff   = new Diff(new: [$new], update: [$update], unchanged: [self::item('A', 10)]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10, 30]));

        $this->assertSame([$new], $r['diff']->new);
        // Pre-existing update items keep their position; healed items append.
        $this->assertCount(2, $r['diff']->update);
        $this->assertSame('U', $r['diff']->update[0]->sku);
        $this->assertSame('A', $r['diff']->update[1]->sku);
        $this->assertSame(1, $r['healed']);
    }

    public function testSameProductInBothBucketsIsQueuedOnlyOnce(): void
    {
        // Defensive: a source that put one pid in two buckets must not
        // produce two writes of the same product.
        $diff = new Diff(
            unchanged:   [self::item('A', 10)],
            updateStock: [self::item('A', 10)],
        );

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));

        $this->assertSame(1, $r['healed']);
        $this->assertCount(1, $r['diff']->update);
    }

    public function testHealedItemsCarryTheMarkerAndUntouchedOnesDoNot(): void
    {
        // The marker is what lets a bucket-restricted job run the repair
        // without also processing the `update` items it deliberately skips.
        $preExisting = self::item('U', 30);
        $diff = new Diff(update: [$preExisting], unchanged: [self::item('A', 10)]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([10]));

        $this->assertArrayNotHasKey(MediaHealer::MARKER, $r['diff']->update[0]->data);
        $this->assertTrue($r['diff']->update[1]->data[MediaHealer::MARKER]);
    }

    public function testMarkerDoesNotDisturbTheRestOfTheDraft(): void
    {
        $item = new FeedItem(
            sku:  'A',
            data: ['_existing_id' => 10, 'image_full_url' => 'https://cdn.example/x.png', 'regular_price' => '120'],
            raw:  ['row' => 1],
        );
        $r = MediaHealer::rebucket(new Diff(unchanged: [$item]), self::urlsOf(), self::brokenBeing([10]));

        $healed = $r['diff']->update[0];
        $this->assertSame('A', $healed->sku);
        $this->assertSame('120', $healed->data['regular_price']);
        $this->assertSame(10, $healed->data['_existing_id']);
        $this->assertSame(['row' => 1], $healed->raw);
    }

    public function testBucketsStayListShapedForThePositionalCursor(): void
    {
        // The runner indexes the processing queue positionally for the
        // resume cursor; a hole in the array keys would desync it.
        $diff = new Diff(unchanged: [
            self::item('A', 10), self::item('B', 20), self::item('C', 30),
        ]);

        $r = MediaHealer::rebucket($diff, self::urlsOf(), self::brokenBeing([20]));

        $this->assertSame([0, 1], array_keys($r['diff']->unchanged));
        $this->assertSame([0], array_keys($r['diff']->update));
    }
}
