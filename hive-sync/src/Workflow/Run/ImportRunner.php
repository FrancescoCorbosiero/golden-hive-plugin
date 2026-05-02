<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

use HiveSync\Core\Bootstrap;
use HiveSync\Core\Check\CheckSeverity;
use HiveSync\Core\Operation\ImportRule;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Pipeline\Pipeline;
use HiveSync\Core\Pipeline\PipelineRepository;
use HiveSync\Core\Pipeline\PipelineStepKind;
use HiveSync\Core\Repo\RunRepository;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Core\Source\FetchRequest;
use HiveSync\Core\Source\Source;

/**
 * Orchestrates one source.import run with the full lifecycle:
 *
 *   fetch (Source::fetch)
 *     → diff (Source::diff)
 *     → for each item in (new ∪ update):
 *         → pre-import checks (FeedItem-scoped)  — block-severity skips item
 *         → import-rule operations               — mutate the draft
 *         → materialize (Source::materialize)
 *         → post-import checks (productId-scoped) — block-severity counts toward
 *                                                    blocking_failures
 *
 * Pipeline lookup is via options.pipeline_slug — when absent, NO
 * pre/import/post processing happens (backward compatible: behaves
 * exactly like the phase-3b runner did).
 *
 * Cooperative deadline + cursor resume preserved from the previous
 * implementation: ticks yield with status=continue + cursor before
 * starting a new item, never mid-item.
 */
final class ImportRunner
{
    public function __construct(
        private readonly RunRepository $runs,
    ) {}

    /**
     * @param array<string, mixed> $config   Source config
     * @param array<string, mixed> $options  options.pipeline_slug enables lifecycle;
     *                                       options.mapping passes through to fetch
     * @param array<string, mixed> $meta     Run-context meta (trigger, …)
     * @param array{index?:int,run_id?:int}|null $cursor
     */
    public function run(
        Source $source,
        array $config,
        array $options = [],
        array $meta = [],
        bool $dryRun = false,
        ?int $deadline = null,
        ?array $cursor = null,
    ): array {
        $startIndex = isset( $cursor['index'] ) ? max( 0, (int) $cursor['index'] ) : 0;
        $runId      = isset( $cursor['run_id'] ) && (int) $cursor['run_id'] > 0
            ? (int) $cursor['run_id']
            : $this->runs->start( 0, 'source.import', $source->id() );

        $ctx = new Context(
            runId: (string) $runId,
            dryRun: $dryRun,
            deadline: $deadline,
            meta: $meta,
        );
        $opCtx = new OperationContext( base: $ctx, sourceId: $source->id() );

        // Pipeline (optional). Each step's registry lookup is lazy.
        $pipeline = self::loadPipeline( (string) ( $options['pipeline_slug'] ?? '' ) );

        try {
            $fetch = $source->fetch( new FetchRequest( $config, $options ), $ctx );
        } catch ( \Throwable $e ) {
            $this->runs->finish( $runId, 'failed', [ 'error' => $e->getMessage() ] );
            return [ 'status' => 'failed', 'run_id' => $runId, 'error' => $e->getMessage() ];
        }

        $items = $fetch->items;
        $diff  = $source->diff( $items, $ctx );

        // `unchanged` is reported as a top-level metric — items the diff
        // declared identical to the existing product, so they were never
        // queued for processing. `skipped` is reserved for items that
        // entered the loop but materialize() returned action='skipped'
        // (e.g. dry-run, conflict-engine veto, no-op upsert). Conflating
        // the two hides genuine processing decisions behind the skip
        // counter — keep them strictly separate.
        $summary = [
            'fetched'      => count( $items ),
            'new'          => count( $diff->new ),
            'update'       => count( $diff->update ),
            'unchanged'    => count( $diff->unchanged ),
            'created'      => 0,
            'updated'      => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'pre_blocked'  => 0,
            'post_blocked' => 0,
        ];

        $rows    = [];
        $process = array_merge( $diff->new, $diff->update );
        $total   = count( $process );

        for ( $i = $startIndex; $i < $total; $i++ ) {
            if ( $ctx->isOverDeadline() ) {
                $this->runs->progress( $runId, $total, $summary['created'] + $summary['updated'], $summary['failed'] );
                $this->runs->finish( $runId, 'continue', [ 'summary' => $summary, 'cursor' => [ 'index' => $i ] ] );
                return [
                    'status'   => 'continue',
                    'run_id'   => $runId,
                    'cursor'   => [ 'index' => $i, 'run_id' => $runId ],
                    'summary'  => $summary,
                    'warnings' => $fetch->warnings,
                    'rows'     => array_slice( $rows, 0, 100 ),
                    'progress' => [ 'done' => $i, 'total' => $total ],
                ];
            }

            $item = $process[ $i ];
            $rowTrace = [ 'sku' => $item->sku, 'pid' => null, 'action' => 'skipped', 'error' => null, 'pre' => [], 'post' => [] ];

            // ─── Pre-import checks ──────────────────────────────────
            $preBlocked = false;
            if ( $pipeline ) {
                foreach ( $pipeline->preCheckSteps() as $step ) {
                    $check = Bootstrap::$importChecks?->get( $step->refId );
                    if ( ! $check ) continue;
                    try {
                        $cr = $check->evaluate( $item, $step->params );
                    } catch ( \Throwable $e ) {
                        $rowTrace['pre'][] = [ 'ref' => $step->refId, 'error' => $e->getMessage() ];
                        continue;
                    }
                    $rowTrace['pre'][] = [ 'ref' => $step->refId, 'passed' => $cr->passed, 'message' => $cr->message ];
                    if ( ! $cr->passed && $cr->severity === CheckSeverity::Block ) {
                        $preBlocked = true;
                        break;
                    }
                }
            }
            if ( $preBlocked ) {
                $summary['pre_blocked']++;
                $rowTrace['action'] = 'pre_blocked';
                $rowTrace['error']  = 'pre-import check block-severity failure';
                $rows[] = $rowTrace;
                continue;
            }

            // ─── Import-rule operations (mutate the draft) ─────────
            $draft = $item->data;
            if ( $pipeline ) {
                foreach ( $pipeline->importRuleSteps() as $step ) {
                    $op = Bootstrap::$operations?->get( $step->refId );
                    if ( ! $op instanceof ImportRule ) continue;
                    try {
                        $op->applyDuringImport( $item, $draft, $step->params, $opCtx );
                    } catch ( \Throwable $e ) {
                        // An import-rule failure doesn't block — log + continue
                        $rowTrace['pre'][] = [ 'ref' => $step->refId, 'rule_error' => $e->getMessage() ];
                    }
                }
            }
            $effectiveItem = $draft === $item->data
                ? $item
                : new FeedItem( sku: $item->sku, data: $draft, raw: $item->raw );

            // ─── Materialize ───────────────────────────────────────
            try {
                $r = $source->materialize( $effectiveItem, $ctx );
            } catch ( \Throwable $e ) {
                $rowTrace['action'] = 'failed';
                $rowTrace['error']  = $e->getMessage();
                $summary['failed']++;
                $rows[] = $rowTrace;
                continue;
            }

            $rowTrace['pid']    = $r->productId;
            $rowTrace['action'] = $r->action;
            $rowTrace['error']  = $r->error;
            // Surface the materialize-skip reason on the row so the UI
            // can explain why an item was skipped instead of leaving the
            // user to guess (dry-run vs. conflict veto vs. no-op upsert).
            if ( $r->action === 'skipped' && isset( $r->details['reason'] ) ) {
                $rowTrace['reason'] = (string) $r->details['reason'];
            }
            switch ( $r->action ) {
                case 'created': $summary['created']++; break;
                case 'updated': $summary['updated']++; break;
                case 'failed':  $summary['failed']++;  break;
                case 'skipped': $summary['skipped']++; break;
            }

            // ─── Post-import checks ────────────────────────────────
            if ( $pipeline && $r->isSuccess() && $r->productId !== null ) {
                foreach ( $pipeline->checkSteps() as $step ) {
                    $check = Bootstrap::$checks?->get( $step->refId );
                    if ( ! $check ) continue;
                    try {
                        $cr = $check->evaluate( $r->productId, $step->params );
                    } catch ( \Throwable $e ) {
                        $rowTrace['post'][] = [ 'ref' => $step->refId, 'error' => $e->getMessage() ];
                        continue;
                    }
                    $rowTrace['post'][] = [ 'ref' => $step->refId, 'passed' => $cr->passed, 'message' => $cr->message ];
                    if ( ! $cr->passed && $cr->severity === CheckSeverity::Block ) {
                        $summary['post_blocked']++;
                    }
                }
            }

            $rows[] = $rowTrace;
        }

        $this->runs->progress( $runId, $total, $summary['created'] + $summary['updated'], $summary['failed'] );
        $this->runs->finish( $runId, 'done', [
            'summary'  => $summary,
            'warnings' => $fetch->warnings,
        ] );

        return [
            'status'   => 'done',
            'run_id'   => $runId,
            'summary'  => $summary,
            'warnings' => $fetch->warnings,
            'rows'     => array_slice( $rows, 0, 100 ),
            'progress' => [ 'done' => $total, 'total' => $total ],
        ];
    }

    private static function loadPipeline( string $slug ): ?Pipeline
    {
        if ( $slug === '' ) return null;
        $repo = new PipelineRepository();
        return $repo->find( $slug );
    }
}

