<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Sources;

use GH\Core\Source\Context;
use GH\Core\Source\FeedItem;
use GH\Core\Source\FetchRequest;
use GH\Sources\GoldenSneakersSource;
use PHPUnit\Framework\TestCase;

/**
 * Without WP loaded, the legacy rp_rc_gs_* functions are not defined.
 * The adapter must degrade gracefully — never fatal — and surface
 * actionable diagnostics. These tests pin that contract; full
 * fetch/diff/materialize behavior is verified via integration tests
 * (deferred — they require WP + WC).
 */
final class GoldenSneakersSourceTest extends TestCase
{
    private GoldenSneakersSource $src;

    protected function setUp(): void
    {
        $this->src = new GoldenSneakersSource();
    }

    public function test_id_and_label(): void
    {
        self::assertSame('goldensneakers', $this->src->id());
        self::assertSame(GoldenSneakersSource::ID, $this->src->id());
        self::assertNotSame('', $this->src->label());
    }

    public function test_capabilities_match_a_full_push_feed(): void
    {
        $caps = $this->src->capabilities();
        self::assertTrue($caps->canFetch);
        self::assertTrue($caps->canDiff);
        self::assertTrue($caps->canMaterialize);
        self::assertTrue($caps->supportsImageSideload);
        self::assertFalse($caps->canSelectLocal);
    }

    public function test_config_schema_mirrors_legacy_credentials_shape(): void
    {
        $schema = $this->src->configSchema();
        // Same fields as feeds/feed-credentials.php 'goldensneakers' entry.
        self::assertArrayHasKey('url', $schema);
        self::assertArrayHasKey('token', $schema);
        self::assertArrayHasKey('cookie', $schema);
        self::assertArrayHasKey('format', $schema);

        self::assertSame('url',    $schema['url']['type']);
        self::assertSame('secret', $schema['token']['type']);
        self::assertSame('secret', $schema['cookie']['type']);
        self::assertSame('enum',   $schema['format']['type']);

        self::assertTrue($schema['url']['required']);
        self::assertTrue($schema['token']['required']);
        self::assertFalse($schema['cookie']['required']);

        self::assertSame(['hierarchical', 'flat'], $schema['format']['options']);
    }

    public function test_fetch_with_invalid_config_returns_warning_not_throw(): void
    {
        $req = new FetchRequest(config: ['url' => 'not-a-url']);
        $r = $this->src->fetch($req, new Context(runId: 'r'));

        self::assertSame([], $r->items);
        self::assertNotEmpty($r->warnings);
        self::assertArrayHasKey('errors', $r->stats);
        self::assertSame('invalid_url', $r->stats['errors']['url']);
        self::assertSame('required', $r->stats['errors']['token']);
    }

    public function test_fetch_with_legacy_unloaded_returns_warning(): void
    {
        // Config valid, but rp_rc_gs_fetch is not defined in this test process.
        self::assertFalse(function_exists('rp_rc_gs_fetch'));

        $req = new FetchRequest(config: [
            'url'   => 'https://api.example.com/feed',
            'token' => 'tok-abc',
        ]);
        $r = $this->src->fetch($req, new Context(runId: 'r'));

        self::assertSame([], $r->items);
        self::assertNotEmpty($r->warnings);
        self::assertStringContainsString('legacy', strtolower($r->warnings[0]));
    }

    public function test_diff_with_legacy_unloaded_returns_empty_diff(): void
    {
        self::assertFalse(function_exists('rp_rc_gs_diff'));

        $items = [new FeedItem(sku: 'X', data: ['sku' => 'X'])];
        $diff = $this->src->diff($items, new Context(runId: 'r'));

        self::assertSame(0, $diff->totalCount());
    }

    public function test_materialize_dry_run_short_circuits_before_legacy(): void
    {
        // Even though the legacy fns aren't loaded, dry-run must return
        // 'skipped' cleanly — that's the precondition for the executor's
        // dry-run mode to behave consistently across sources.
        self::assertFalse(function_exists('rp_rc_gs_create_product'));

        $item = new FeedItem(sku: 'A', data: ['sku' => 'A', 'name' => 'X']);
        $r = $this->src->materialize($item, new Context(runId: 'r', dryRun: true));

        self::assertSame('skipped', $r->action);
        self::assertNull($r->productId);
    }

    public function test_materialize_with_legacy_unloaded_returns_failed(): void
    {
        self::assertFalse(function_exists('rp_rc_gs_create_product'));

        $item = new FeedItem(sku: 'B', data: ['sku' => 'B', 'name' => 'Y']);
        $r = $this->src->materialize($item, new Context(runId: 'r'));

        self::assertSame('failed', $r->action);
        self::assertStringContainsString('legacy', strtolower((string) $r->error));
    }
}
