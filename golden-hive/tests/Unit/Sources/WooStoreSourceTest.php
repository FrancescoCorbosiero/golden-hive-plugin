<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Sources;

use GH\Core\Source\Context;
use GH\Core\Source\FetchRequest;
use GH\Sources\WooStoreSource;
use PHPUnit\Framework\TestCase;

final class WooStoreSourceTest extends TestCase
{
    public function test_id_is_woostore(): void
    {
        $s = new WooStoreSource();
        self::assertSame('woostore', $s->id());
        self::assertSame(WooStoreSource::ID, $s->id());
    }

    public function test_capabilities_mark_it_selection_only(): void
    {
        $caps = (new WooStoreSource())->capabilities();
        self::assertTrue($caps->canSelectLocal);
        self::assertFalse($caps->canFetch);
        self::assertFalse($caps->canDiff);
        self::assertFalse($caps->canMaterialize);
    }

    public function test_config_schema_is_empty(): void
    {
        self::assertSame([], (new WooStoreSource())->configSchema());
    }

    public function test_fetch_throws_loud_logic_exception(): void
    {
        $s = new WooStoreSource();
        $this->expectException(\LogicException::class);
        $s->fetch(new FetchRequest(), new Context(runId: 'r'));
    }

    public function test_diff_returns_empty_diff(): void
    {
        $diff = (new WooStoreSource())->diff([], new Context(runId: 'r'));
        self::assertSame(0, $diff->totalCount());
    }
}
