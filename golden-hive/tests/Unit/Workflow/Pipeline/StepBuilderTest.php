<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Workflow\Pipeline;

use GH\Core\Pipeline\PipelineStep;
use GH\Core\Pipeline\PipelineStepKind;
use GH\Workflow\Pipeline\StepBuilder;
use PHPUnit\Framework\TestCase;

final class StepBuilderTest extends TestCase
{
    public function test_builds_a_valid_operation_step(): void
    {
        $r = StepBuilder::fromArray([
            'kind'   => 'operation',
            'ref_id' => 'status.set',
            'params' => ['status' => 'draft'],
        ]);
        self::assertTrue($r['ok']);
        self::assertInstanceOf(PipelineStep::class, $r['step']);
        self::assertSame(PipelineStepKind::Operation, $r['step']->kind);
        self::assertSame('status.set', $r['step']->refId);
        self::assertSame(['status' => 'draft'], $r['step']->params);
        self::assertNull($r['step']->note);
    }

    public function test_builds_an_import_rule_step(): void
    {
        $r = StepBuilder::fromArray([
            'kind'   => 'import_rule',
            'ref_id' => 'pricing.markup_by_category',
            'params' => [],
        ]);
        self::assertTrue($r['ok']);
        self::assertSame(PipelineStepKind::ImportRule, $r['step']->kind);
    }

    public function test_builds_a_check_step(): void
    {
        $r = StepBuilder::fromArray([
            'kind'   => 'check',
            'ref_id' => 'media.has_images',
            'params' => ['min' => 1],
        ]);
        self::assertTrue($r['ok']);
        self::assertSame(PipelineStepKind::Check, $r['step']->kind);
    }

    public function test_rejects_missing_kind(): void
    {
        $r = StepBuilder::fromArray(['ref_id' => 'x']);
        self::assertFalse($r['ok']);
        self::assertSame('required', $r['errors']['kind']);
    }

    public function test_rejects_invalid_kind(): void
    {
        $r = StepBuilder::fromArray(['kind' => 'bogus', 'ref_id' => 'x']);
        self::assertFalse($r['ok']);
        self::assertSame('invalid', $r['errors']['kind']);
    }

    public function test_rejects_missing_ref_id(): void
    {
        $r = StepBuilder::fromArray(['kind' => 'operation']);
        self::assertFalse($r['ok']);
        self::assertSame('required', $r['errors']['ref_id']);
    }

    public function test_rejects_non_array_params(): void
    {
        $r = StepBuilder::fromArray(['kind' => 'operation', 'ref_id' => 'x', 'params' => 'bad']);
        self::assertFalse($r['ok']);
        self::assertSame('must_be_array', $r['errors']['params']);
    }

    public function test_normalizes_empty_note_to_null(): void
    {
        $r = StepBuilder::fromArray([
            'kind' => 'operation',
            'ref_id' => 'x',
            'note' => '',
        ]);
        self::assertTrue($r['ok']);
        self::assertNull($r['step']->note);
    }

    public function test_keeps_string_note(): void
    {
        $r = StepBuilder::fromArray([
            'kind' => 'operation',
            'ref_id' => 'x',
            'note' => 'apply only on Mondays',
        ]);
        self::assertTrue($r['ok']);
        self::assertSame('apply only on Mondays', $r['step']->note);
    }

    public function test_many_from_array_aggregates_per_index_errors(): void
    {
        $r = StepBuilder::manyFromArray([
            ['kind' => 'operation', 'ref_id' => 'good'],     // ok
            ['kind' => 'bogus',     'ref_id' => 'bad_kind'], // bad
            ['kind' => 'operation'],                          // missing ref_id
        ]);
        self::assertFalse($r['ok']);
        self::assertArrayHasKey(1, $r['errors']);
        self::assertArrayHasKey(2, $r['errors']);
        self::assertSame('invalid', $r['errors'][1]['kind']);
        self::assertSame('required', $r['errors'][2]['ref_id']);
    }

    public function test_many_from_array_returns_steps_when_all_valid(): void
    {
        $r = StepBuilder::manyFromArray([
            ['kind' => 'operation', 'ref_id' => 'a'],
            ['kind' => 'check',     'ref_id' => 'b'],
        ]);
        self::assertTrue($r['ok']);
        self::assertCount(2, $r['steps']);
        self::assertSame(PipelineStepKind::Operation, $r['steps'][0]->kind);
        self::assertSame(PipelineStepKind::Check, $r['steps'][1]->kind);
    }

    public function test_many_rejects_non_array_row(): void
    {
        $r = StepBuilder::manyFromArray([
            ['kind' => 'operation', 'ref_id' => 'a'],
            'not an array',
        ]);
        self::assertFalse($r['ok']);
        self::assertSame('must_be_array', $r['errors'][1]['_root']);
    }
}
