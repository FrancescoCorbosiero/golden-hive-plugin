<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Core;

use GH\Core\Bootstrap;
use GH\Operations\Status\SetStatus;
use GH\Sources\GoldenSneakersSource;
use GH\Sources\WooStoreSource;
use PHPUnit\Framework\TestCase;

/**
 * Bootstrap::sourcesAsArray / operationsAsArray / checksAsArray are
 * the JSON shape consumed by the v2 Workflow tab. Pin the contract.
 *
 * Note: Bootstrap is a static singleton and other test files (the
 * integration test) also register items into it. We assert by ID
 * presence rather than exact count to remain order-independent.
 */
final class BootstrapSerializationTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::boot();
        // Idempotent — re-registering by id overwrites in the registry's
        // internal map, so this is safe even if other tests already added
        // these objects.
        Bootstrap::$sources->register(new WooStoreSource());
        Bootstrap::$sources->register(new GoldenSneakersSource());
        Bootstrap::$operations->register(new SetStatus());
    }

    public function test_sources_as_array_returns_well_known_shape(): void
    {
        $arr = Bootstrap::sourcesAsArray();
        self::assertNotEmpty($arr);

        $byId = [];
        foreach ($arr as $s) {
            self::assertArrayHasKey('id', $s);
            self::assertArrayHasKey('label', $s);
            self::assertArrayHasKey('capabilities', $s);
            self::assertArrayHasKey('config_schema', $s);
            $byId[$s['id']] = $s;
        }

        self::assertArrayHasKey('woostore', $byId);
        self::assertArrayHasKey('goldensneakers', $byId);

        // WooStore: selection-only
        $woo = $byId['woostore'];
        self::assertTrue($woo['capabilities']['canSelectLocal']);
        self::assertFalse($woo['capabilities']['canFetch']);
        self::assertSame([], $woo['config_schema']);

        // GS: full push feed
        $gs = $byId['goldensneakers'];
        self::assertTrue($gs['capabilities']['canFetch']);
        self::assertTrue($gs['capabilities']['canMaterialize']);
        self::assertArrayHasKey('url', $gs['config_schema']);
        self::assertArrayHasKey('token', $gs['config_schema']);
        self::assertSame('secret', $gs['config_schema']['token']['type']);
    }

    public function test_operations_as_array_includes_set_status(): void
    {
        $arr = Bootstrap::operationsAsArray();
        $byId = [];
        foreach ($arr as $op) {
            self::assertArrayHasKey('id', $op);
            self::assertArrayHasKey('label', $op);
            self::assertArrayHasKey('params_schema', $op);
            self::assertArrayHasKey('applies_to', $op);
            self::assertArrayHasKey('is_import_rule', $op);
            $byId[$op['id']] = $op;
        }

        self::assertArrayHasKey('status.set', $byId);
        $set = $byId['status.set'];
        self::assertFalse($set['is_import_rule']);
        self::assertContains('simple', $set['applies_to']);
        self::assertSame('enum', $set['params_schema']['status']['type']);
    }

    public function test_checks_as_array_is_empty_until_a_check_ships(): void
    {
        // No Check is registered yet (Batch 6+). The endpoint must still
        // return [] cleanly so the UI can render an empty state.
        $arr = Bootstrap::checksAsArray();
        self::assertSame([], $arr);
    }
}
