<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Core\Pipeline;

use GH\Core\Check\Check;
use GH\Core\Check\CheckRegistry;
use GH\Core\Check\CheckResult;
use GH\Core\Check\CheckSeverity;
use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Operation\OperationResult;
use GH\Core\Pipeline\Pipeline;
use GH\Core\Pipeline\PipelineExecutor;
use GH\Core\Pipeline\PipelineStep;
use GH\Core\Pipeline\PipelineStepKind;
use GH\Core\Selection\Selection;
use GH\Core\Source\Context as RunContext;
use PHPUnit\Framework\TestCase;

// ─── Test doubles ──────────────────────────────────────────────

final class FakeOperation implements Operation
{
    public int $applyCount = 0;
    /** @var int[] */ public array $seenProductIds = [];

    public function __construct(
        private readonly string $opId,
        private readonly bool $shouldFail = false,
        private readonly bool $shouldThrow = false,
    ) {}

    public function id(): string { return $this->opId; }
    public function label(): string { return $this->opId; }
    public function paramsSchema(): array { return []; }
    public function appliesTo(): array { return ['simple', 'variable']; }

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        $this->applyCount++;
        $this->seenProductIds[] = $productId;

        if ($this->shouldThrow) {
            throw new \RuntimeException('boom');
        }
        if ($this->shouldFail) {
            return OperationResult::failed('mock failure');
        }
        return OperationResult::changedWith(['mock' => true]);
    }
}

final class FakeCheck implements Check
{
    public int $evalCount = 0;

    public function __construct(
        private readonly string $checkId,
        private readonly bool $passes = true,
        private readonly CheckSeverity $sev = CheckSeverity::Warn,
    ) {}

    public function id(): string { return $this->checkId; }
    public function label(): string { return $this->checkId; }
    public function paramsSchema(): array { return []; }
    public function defaultSeverity(): CheckSeverity { return $this->sev; }

    public function evaluate(int $productId, array $params): CheckResult
    {
        $this->evalCount++;
        return $this->passes
            ? CheckResult::pass()
            : CheckResult::fail("fail on {$productId}", $this->sev);
    }
}

// ─── Tests ─────────────────────────────────────────────────────

final class PipelineExecutorTest extends TestCase
{
    private OperationRegistry $ops;
    private CheckRegistry $checks;

    protected function setUp(): void
    {
        $this->ops = new OperationRegistry();
        $this->checks = new CheckRegistry();
    }

    private function ctx(bool $dryRun = false, ?int $deadline = null): OperationContext
    {
        return new OperationContext(
            base: new RunContext(runId: 'run_test', dryRun: $dryRun, deadline: $deadline),
            sourceId: 'woostore',
        );
    }

    private function executorWithIds(array $ids): PipelineExecutor
    {
        return new PipelineExecutor(
            $this->ops,
            $this->checks,
            static fn(Selection $s): array => $ids,
        );
    }

    public function test_runs_each_operation_for_each_product_in_order(): void
    {
        $opA = new FakeOperation('op.a');
        $opB = new FakeOperation('op.b');
        $this->ops->register($opA);
        $this->ops->register($opB);

        $pipeline = new Pipeline(
            id: 'p1',
            name: 'two ops',
            steps: [
                new PipelineStep(PipelineStepKind::Operation, 'op.a'),
                new PipelineStep(PipelineStepKind::Operation, 'op.b'),
            ],
        );
        $sel = Selection::fromIds('woostore', [10, 20, 30]);

        $result = $this->executorWithIds([10, 20, 30])
            ->execute($pipeline, $sel, $this->ctx());

        self::assertTrue($result->completed);
        self::assertSame(3, $result->processedCount);
        self::assertSame(6, $result->changedCount, 'every product * every op = 6 changes');
        self::assertSame([10, 20, 30], $opA->seenProductIds);
        self::assertSame([10, 20, 30], $opB->seenProductIds);
    }

    public function test_unknown_operation_records_failure_and_continues(): void
    {
        $real = new FakeOperation('op.real');
        $this->ops->register($real);

        $pipeline = new Pipeline(
            id: 'p',
            name: 'with hole',
            steps: [
                new PipelineStep(PipelineStepKind::Operation, 'op.missing'),
                new PipelineStep(PipelineStepKind::Operation, 'op.real'),
            ],
        );
        $sel = Selection::fromIds('woostore', [1, 2]);

        $result = $this->executorWithIds([1, 2])
            ->execute($pipeline, $sel, $this->ctx());

        self::assertSame(2, $result->processedCount);
        self::assertSame(2, $result->failedCount, 'one failure per product for missing op');
        self::assertSame(2, $real->applyCount, 'real op still ran');
    }

    public function test_dry_run_skips_operation_apply_but_still_runs_checks(): void
    {
        $op = new FakeOperation('op.a');
        $check = new FakeCheck('chk.a', passes: true);
        $this->ops->register($op);
        $this->checks->register($check);

        $pipeline = new Pipeline(
            id: 'p',
            name: 'dry',
            steps: [
                new PipelineStep(PipelineStepKind::Operation, 'op.a'),
                new PipelineStep(PipelineStepKind::Check, 'chk.a'),
            ],
        );
        $sel = Selection::fromIds('woostore', [1, 2]);

        $result = $this->executorWithIds([1, 2])
            ->execute($pipeline, $sel, $this->ctx(dryRun: true));

        self::assertSame(0, $op->applyCount, 'no apply in dry-run');
        self::assertSame(2, $check->evalCount, 'checks still run in dry-run');
        self::assertSame(0, $result->changedCount);
        self::assertSame(2, $result->processedCount);
    }

    public function test_blocking_check_failures_are_collected(): void
    {
        $blocking = new FakeCheck('chk.must', passes: false, sev: CheckSeverity::Block);
        $this->checks->register($blocking);

        $pipeline = new Pipeline(
            id: 'p',
            name: 'gated',
            steps: [
                new PipelineStep(PipelineStepKind::Check, 'chk.must'),
            ],
        );
        $sel = Selection::fromIds('woostore', [11, 22]);

        $result = $this->executorWithIds([11, 22])
            ->execute($pipeline, $sel, $this->ctx());

        self::assertCount(2, $result->blockingFailures);
        self::assertSame(11, $result->blockingFailures[0]['product_id']);
        self::assertSame('chk.must', $result->blockingFailures[0]['check']);
    }

    public function test_yields_when_deadline_exceeded_and_returns_cursor(): void
    {
        $op = new FakeOperation('op.slow');
        $this->ops->register($op);

        $pipeline = new Pipeline(
            id: 'p',
            name: 'long',
            steps: [new PipelineStep(PipelineStepKind::Operation, 'op.slow')],
        );
        $sel = Selection::fromIds('woostore', [1, 2, 3, 4, 5]);

        // Deadline already in the past — executor must yield BEFORE
        // processing any product (cursor=index 0).
        $result = $this->executorWithIds([1, 2, 3, 4, 5])
            ->execute($pipeline, $sel, $this->ctx(deadline: time() - 10));

        self::assertFalse($result->completed);
        self::assertSame(['index' => 0], $result->cursor);
        self::assertSame(0, $op->applyCount);
        self::assertSame(0, $result->processedCount);
    }

    public function test_resumes_from_cursor(): void
    {
        $op = new FakeOperation('op.r');
        $this->ops->register($op);

        $pipeline = new Pipeline(
            id: 'p',
            name: 'resume',
            steps: [new PipelineStep(PipelineStepKind::Operation, 'op.r')],
        );
        $sel = Selection::fromIds('woostore', [1, 2, 3, 4, 5]);

        $result = $this->executorWithIds([1, 2, 3, 4, 5])
            ->execute($pipeline, $sel, $this->ctx(), cursor: ['index' => 3]);

        self::assertTrue($result->completed);
        self::assertSame(2, $result->processedCount, 'only items at index 3 and 4');
        self::assertSame([4, 5], $op->seenProductIds);
    }

    public function test_thrown_operation_becomes_failure_not_fatal(): void
    {
        $bad = new FakeOperation('op.bad', shouldThrow: true);
        $good = new FakeOperation('op.good');
        $this->ops->register($bad);
        $this->ops->register($good);

        $pipeline = new Pipeline(
            id: 'p',
            name: 'mixed',
            steps: [
                new PipelineStep(PipelineStepKind::Operation, 'op.bad'),
                new PipelineStep(PipelineStepKind::Operation, 'op.good'),
            ],
        );
        $sel = Selection::fromIds('woostore', [1]);

        $result = $this->executorWithIds([1])
            ->execute($pipeline, $sel, $this->ctx());

        self::assertSame(1, $result->processedCount);
        self::assertSame(1, $result->failedCount);
        self::assertSame(1, $result->changedCount, 'good op still ran after bad threw');
    }

    public function test_envelope_translates_completed_run(): void
    {
        $this->ops->register(new FakeOperation('op.a'));
        $pipeline = new Pipeline('p', 'n', [new PipelineStep(PipelineStepKind::Operation, 'op.a')]);
        $sel = Selection::fromIds('woostore', [1]);

        $result = $this->executorWithIds([1])
            ->execute($pipeline, $sel, $this->ctx());

        $env = $result->toJobEnvelope();
        self::assertSame('done', $env['status']);
        self::assertSame(1, $env['summary']['processed']);
    }

    public function test_envelope_translates_yielded_run(): void
    {
        $this->ops->register(new FakeOperation('op.a'));
        $pipeline = new Pipeline('p', 'n', [new PipelineStep(PipelineStepKind::Operation, 'op.a')]);
        $sel = Selection::fromIds('woostore', [1, 2]);

        $result = $this->executorWithIds([1, 2])
            ->execute($pipeline, $sel, $this->ctx(deadline: time() - 1));

        $env = $result->toJobEnvelope();
        self::assertSame('continue', $env['status']);
        self::assertSame(['index' => 0], $env['cursor']);
    }
}
