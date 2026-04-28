<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Core\Source;

use GH\Core\Source\AbstractSource;
use GH\Core\Source\Context;
use GH\Core\Source\Diff;
use GH\Core\Source\FeedItem;
use GH\Core\Source\FetchRequest;
use GH\Core\Source\FetchResult;
use GH\Core\Source\MaterializeResult;
use GH\Core\Source\SourceCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * Test-only Source that exposes the protected helpers AbstractSource
 * provides. Concrete sources will of course not do this — production
 * code uses the helpers from inside the class, not from outside.
 */
final class TestableSource extends AbstractSource
{
    public function id(): string { return 'test'; }
    public function label(): string { return 'Test'; }
    public function capabilities(): SourceCapabilities { return new SourceCapabilities(); }
    public function configSchema(): array { return []; }
    public function fetch(FetchRequest $r, Context $c): FetchResult { return new FetchResult([]); }
    public function diff(array $items, Context $c): Diff { return new Diff(); }
    public function materialize(FeedItem $i, Context $c): MaterializeResult
    {
        return MaterializeResult::skipped(null, 'test-only');
    }

    /** @param mixed[] $items */
    public function publicApplyWithBinarySplit(array $items, callable $fn, int $minBatch = 1, int $maxDepth = 8): array
    {
        return $this->applyWithBinarySplit($items, $fn, $minBatch, $maxDepth);
    }

    public function publicValidateConfig(array $config): array
    {
        return $this->validateConfig($config);
    }
}

final class AbstractSourceBinarySplitTest extends TestCase
{
    private TestableSource $src;

    protected function setUp(): void
    {
        $this->src = new TestableSource();
    }

    public function test_succeeds_in_one_call_when_callable_does_not_throw(): void
    {
        $items = [1, 2, 3, 4, 5];
        $calls = 0;
        $result = $this->src->publicApplyWithBinarySplit(
            $items,
            function (array $batch) use (&$calls) {
                $calls++;
                return array_map(static fn(int $i): array => ['id' => $i, 'ok' => true], $batch);
            },
        );

        self::assertSame(1, $calls, 'no failure means no split');
        self::assertCount(5, $result['ok']);
        self::assertCount(0, $result['failed']);
    }

    public function test_isolates_one_bad_item_via_recursive_split(): void
    {
        // Fail iff batch contains the poison item (id=3); otherwise succeed.
        $items = [1, 2, 3, 4, 5, 6, 7, 8];
        $poison = 3;

        $result = $this->src->publicApplyWithBinarySplit(
            $items,
            function (array $batch) use ($poison): array {
                foreach ($batch as $item) {
                    if ($item === $poison) {
                        throw new \RuntimeException('poison: ' . $poison);
                    }
                }
                return array_map(static fn(int $i): array => ['id' => $i], $batch);
            },
        );

        // 7 good items succeed; 1 bad item lands in failed singleton-style.
        self::assertCount(7, $result['ok'], 'all non-poison items processed');
        self::assertCount(1, $result['failed']);
        self::assertSame($poison, $result['failed'][0]['item']);
        self::assertStringContainsString('poison', $result['failed'][0]['error']);
    }

    public function test_respects_max_depth_by_failing_remaining_items(): void
    {
        // Always-failing callable + maxDepth=0 means: try once, give up,
        // mark every item failed without splitting.
        $items = [1, 2, 3, 4];

        $result = $this->src->publicApplyWithBinarySplit(
            $items,
            static function (): array {
                throw new \RuntimeException('always');
            },
            minBatch: 1,
            maxDepth: 0,
        );

        self::assertCount(0, $result['ok']);
        self::assertCount(4, $result['failed']);
    }

    public function test_handles_empty_input(): void
    {
        $result = $this->src->publicApplyWithBinarySplit(
            [],
            static fn(array $b): array => $b,
        );
        self::assertSame(['ok' => [], 'failed' => []], $result);
    }

    public function test_isolates_two_bad_items_in_a_batch(): void
    {
        $items = range(1, 16);
        $poison = [4, 11];

        $result = $this->src->publicApplyWithBinarySplit(
            $items,
            function (array $batch) use ($poison): array {
                foreach ($batch as $item) {
                    if (in_array($item, $poison, true)) {
                        throw new \RuntimeException('bad ' . $item);
                    }
                }
                return array_map(static fn(int $i): array => ['id' => $i], $batch);
            },
        );

        self::assertCount(14, $result['ok']);
        self::assertCount(2, $result['failed']);
        $failedIds = array_column($result['failed'], 'item');
        sort($failedIds);
        self::assertSame($poison, $failedIds);
    }
}
