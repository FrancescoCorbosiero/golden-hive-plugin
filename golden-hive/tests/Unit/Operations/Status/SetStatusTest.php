<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Operations\Status;

use GH\Core\Operation\OperationContext;
use GH\Core\Source\Context as RunContext;
use GH\Operations\Status\SetStatus;
use PHPUnit\Framework\TestCase;

final class SetStatusTest extends TestCase
{
    private SetStatus $op;

    protected function setUp(): void
    {
        $this->op = new SetStatus();
    }

    private function ctx(bool $dryRun = false): OperationContext
    {
        return new OperationContext(
            base: new RunContext(runId: 'r', dryRun: $dryRun),
            sourceId: 'woostore',
        );
    }

    public function test_id_and_label_are_stable(): void
    {
        self::assertSame('status.set', $this->op->id());
        self::assertNotSame('', $this->op->label());
    }

    public function test_params_schema_declares_status_enum(): void
    {
        $schema = $this->op->paramsSchema();
        self::assertArrayHasKey('status', $schema);
        self::assertSame('enum', $schema['status']['type']);
        self::assertContains('publish', $schema['status']['options']);
        self::assertContains('draft', $schema['status']['options']);
        self::assertContains('private', $schema['status']['options']);
        self::assertContains('pending', $schema['status']['options']);
    }

    public function test_applies_to_all_product_types(): void
    {
        $types = $this->op->appliesTo();
        self::assertContains('simple', $types);
        self::assertContains('variable', $types);
        self::assertContains('grouped', $types);
        self::assertContains('external', $types);
    }

    public function test_rejects_invalid_status(): void
    {
        $r = $this->op->apply(123, ['status' => 'bogus'], $this->ctx());
        self::assertFalse($r->changed);
        self::assertSame('invalid_status', $r->error);
    }

    public function test_rejects_missing_status(): void
    {
        $r = $this->op->apply(123, [], $this->ctx());
        self::assertSame('invalid_status', $r->error);
    }

    public function test_rejects_invalid_product_id(): void
    {
        $r = $this->op->apply(0, ['status' => 'draft'], $this->ctx());
        self::assertSame('invalid_product_id', $r->error);
    }

    public function test_dry_run_reports_change_without_calling_wp(): void
    {
        // wp_update_post is intentionally undefined here; dry-run must
        // succeed regardless because the gate runs first.
        self::assertFalse(function_exists('wp_update_post'));

        $r = $this->op->apply(123, ['status' => 'draft'], $this->ctx(dryRun: true));
        self::assertTrue($r->changed);
        self::assertNull($r->error);
        self::assertTrue($r->changes['dry_run']);
        self::assertSame('draft', $r->changes['status']);
    }

    public function test_real_run_fails_gracefully_when_wp_unavailable(): void
    {
        // Outside WP, apply() must not fatal — it surfaces a clean error
        // so callers (job runner) can record it and continue.
        self::assertFalse(function_exists('wp_update_post'));
        $r = $this->op->apply(123, ['status' => 'draft'], $this->ctx());
        self::assertFalse($r->changed);
        self::assertSame('wp_update_post unavailable', $r->error);
    }
}
