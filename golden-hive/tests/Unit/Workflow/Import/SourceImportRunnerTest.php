<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Workflow\Import;

use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationRegistry;
use GH\Core\Pipeline\Pipeline;
use GH\Core\Pipeline\PipelineStep;
use GH\Core\Pipeline\PipelineStepKind;
use GH\Core\Source\Context;
use GH\Core\Source\Diff;
use GH\Core\Source\FeedItem;
use GH\Core\Source\FetchRequest;
use GH\Core\Source\FetchResult;
use GH\Core\Source\MaterializeResult;
use GH\Core\Source\Source;
use GH\Core\Source\SourceCapabilities;
use GH\Operations\Pricing\MarkupByCategory;
use GH\Workflow\Import\SourceImportRunner;
use PHPUnit\Framework\TestCase;

/**
 * In-memory fake Source. fetch() returns a fixed list; diff() puts
 * everything in 'new'; materialize() invents a sequential product id
 * AND records the post-rule data so tests can assert mutations
 * propagated through the runner.
 */
class FakePushSource implements Source
{
    public int $fetchCalls = 0;
    public int $diffCalls = 0;
    public int $materializeCalls = 0;
    /** @var array<string, array> */
    public array $materializedDrafts = [];
    private int $nextId = 1000;

    /** @param FeedItem[] $items */
    public function __construct(private readonly array $items) {}

    public function id(): string { return 'fake'; }
    public function label(): string { return 'Fake'; }
    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(canFetch: true, canDiff: true, canMaterialize: true);
    }
    public function configSchema(): array { return []; }

    public function fetch(FetchRequest $r, Context $c): FetchResult
    {
        $this->fetchCalls++;
        return new FetchResult(items: $this->items);
    }

    public function diff(array $items, Context $c): Diff
    {
        $this->diffCalls++;
        return new Diff(new: $items);
    }

    public function materialize(FeedItem $item, Context $c): MaterializeResult
    {
        $this->materializeCalls++;
        $this->materializedDrafts[$item->sku] = $item->data;
        return MaterializeResult::created(productId: $this->nextId++);
    }
}

final class SourceImportRunnerTest extends TestCase
{
    private function ctx(bool $dryRun = false, ?int $deadline = null): OperationContext
    {
        return new OperationContext(
            base: new Context(runId: 'r', dryRun: $dryRun, deadline: $deadline),
            sourceId: 'fake',
        );
    }

    /** @return FeedItem[] */
    private function items(int $n, string $category = 'sneakers'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = new FeedItem(
                sku: 'SKU' . $i,
                data: [
                    '_gs_category'  => $category,
                    'regular_price' => 100.0,
                    'name'          => 'Item ' . $i,
                ],
            );
        }
        return $out;
    }

    public function test_runs_without_pipeline_just_materializes(): void
    {
        $src = new FakePushSource($this->items(3));
        $runner = new SourceImportRunner(new OperationRegistry());

        $r = $runner->run($src, [], null, $this->ctx());

        self::assertTrue($r->completed);
        self::assertSame(3, $r->totalCount);
        self::assertSame(3, $r->processedCount);
        self::assertSame(3, $r->createdCount);
        self::assertSame(0, $r->failedCount);
        self::assertSame(1, $src->fetchCalls);
        self::assertSame(3, $src->materializeCalls);
    }

    public function test_import_rule_mutates_draft_before_materialize(): void
    {
        $src   = new FakePushSource($this->items(2, 'sneakers'));
        $regs  = new OperationRegistry();
        $regs->register(new MarkupByCategory());

        $pipeline = new Pipeline(
            id: 'p', name: 'markup', steps: [
                new PipelineStep(
                    kind: PipelineStepKind::ImportRule,
                    refId: MarkupByCategory::ID,
                    params: ['markup_map' => ['sneakers' => 30.0]],
                ),
            ],
        );

        $runner = new SourceImportRunner($regs);
        $r = $runner->run($src, [], $pipeline, $this->ctx());

        self::assertTrue($r->completed);
        self::assertSame(2, $r->createdCount);

        // Each materialized draft must carry the marked-up price.
        foreach ($src->materializedDrafts as $draft) {
            self::assertSame(130.0, $draft['regular_price']);
            self::assertSame('sneakers', $draft['_gh_markup_applied']['category']);
        }
    }

    public function test_unknown_import_rule_recorded_as_per_item_error_not_fatal(): void
    {
        $src = new FakePushSource($this->items(1));
        $pipeline = new Pipeline(
            id: 'p', name: 'broken', steps: [
                new PipelineStep(PipelineStepKind::ImportRule, 'pricing.does_not_exist'),
            ],
        );

        $runner = new SourceImportRunner(new OperationRegistry());
        $r = $runner->run($src, [], $pipeline, $this->ctx());

        // Materialize still succeeds; rule trace records the missing rule.
        self::assertSame(1, $r->createdCount);
        $trace = $r->perItem['SKU1'] ?? null;
        self::assertNotNull($trace);
        self::assertSame('not_an_import_rule', $trace['rules'][0]['error']);
    }

    public function test_post_op_step_is_ignored_in_import_loop(): void
    {
        // Operations that aren't ImportRules are explicitly skipped here —
        // they belong to a separate post-import PipelineExecutor pass.
        // The runner must NOT call apply() on a plain Operation.
        $src  = new FakePushSource($this->items(1));
        $regs = new OperationRegistry();
        $regs->register(new \GH\Operations\Status\SetStatus());

        $pipeline = new Pipeline(
            id: 'p', name: 'mixed', steps: [
                new PipelineStep(PipelineStepKind::Operation, 'status.set', ['status' => 'draft']),
            ],
        );

        $runner = new SourceImportRunner($regs);
        $r = $runner->run($src, [], $pipeline, $this->ctx());

        self::assertSame(1, $r->createdCount);
        // perItem rules trace empty — Operation step has kind != import_rule
        // and the runner only consults importRuleSteps().
        self::assertSame([], $r->perItem['SKU1']['rules']);
    }

    public function test_yields_at_deadline_returns_continue_cursor(): void
    {
        $src = new FakePushSource($this->items(5));
        $runner = new SourceImportRunner(new OperationRegistry());

        // Deadline already past → yield BEFORE the first item.
        $r = $runner->run($src, [], null, $this->ctx(deadline: time() - 5));

        self::assertFalse($r->completed);
        self::assertSame(['index' => 0], $r->cursor);
        self::assertSame(0, $src->materializeCalls, 'nothing materialized before deadline yield');

        $env = $r->toJobEnvelope();
        self::assertSame('continue', $env['status']);
        self::assertSame(['index' => 0], $env['cursor']);
    }

    public function test_resumes_from_cursor_with_cached_diff(): void
    {
        $src = new FakePushSource($this->items(5));
        $runner = new SourceImportRunner(new OperationRegistry());

        // Pre-build the diff we'd get from a real fetch.
        $cachedDiff = new Diff(new: $this->items(5));

        $r = $runner->run(
            source: $src,
            config: [],
            pipeline: null,
            opCtx: $this->ctx(),
            cursor: ['index' => 3],
            cachedDiff: $cachedDiff,
        );

        self::assertTrue($r->completed);
        self::assertSame(2, $r->processedCount, 'index 3 + 4 only');
        self::assertSame(0, $src->fetchCalls, 'cached diff suppresses fetch');
        self::assertSame(2, $src->materializeCalls);
    }

    public function test_envelope_done_summary_shape(): void
    {
        $src = new FakePushSource($this->items(2));
        $runner = new SourceImportRunner(new OperationRegistry());

        $env = $runner->run($src, [], null, $this->ctx())->toJobEnvelope();

        self::assertSame('done', $env['status']);
        self::assertSame(2, $env['summary']['total']);
        self::assertSame(2, $env['summary']['created']);
        self::assertSame(0, $env['summary']['failed']);
    }

    public function test_materialize_thrown_becomes_per_item_failure(): void
    {
        $src = new class(items: $this->items(2)) extends FakePushSource {
            public function materialize(FeedItem $item, Context $c): MaterializeResult
            {
                if ($item->sku === 'SKU1') throw new \RuntimeException('boom');
                return parent::materialize($item, $c);
            }
        };
        $runner = new SourceImportRunner(new OperationRegistry());

        $r = $runner->run($src, [], null, $this->ctx());

        self::assertTrue($r->completed);
        self::assertSame(2, $r->processedCount);
        self::assertSame(1, $r->failedCount);
        self::assertSame(1, $r->createdCount);
        self::assertSame('failed', $r->perItem['SKU1']['action']);
        self::assertSame('boom', $r->perItem['SKU1']['error']);
    }
}
