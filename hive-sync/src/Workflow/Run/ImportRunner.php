<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Run;

use HiveSync\Core\Repo\RunRepository;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\FetchRequest;
use HiveSync\Core\Source\Source;

/**
 * Orchestrates one source.import run: fetch → diff → materialize each
 * item, recording progress against wp_hsync_runs and respecting the
 * cooperative deadline (so a long import yields cleanly to the next
 * job tick).
 *
 * Returns a structured envelope the AJAX layer renders verbatim:
 *   ['status'   => 'done' | 'continue' | 'failed',
 *    'run_id'   => int,
 *    'summary'  => ['fetched','new','update','unchanged','created','updated','failed','skipped'],
 *    'warnings' => string[],
 *    'rows'     => [ [pid, sku, action, error?], ... ]   // capped at 100 for UI
 *   ]
 *
 * Phase 3b uses this for ad-hoc "Run now" — same path will back the
 * Job runner in phase 4 by passing $cursor through.
 */
final class ImportRunner
{
    public function __construct(
        private readonly RunRepository $runs,
    ) {}

    /**
     * @param array<string, mixed> $config   Source config (validated by AbstractSource)
     * @param array<string, mixed> $options  Fetch-time options (mapping, price_mode, …)
     * @param array<string, mixed> $meta     Run-context meta (sideload flag, trigger, …)
     */
    public function run(
        Source $source,
        array $config,
        array $options = [],
        array $meta = [],
        bool $dryRun = false,
        ?int $deadline = null,
    ): array {
        $runId = $this->runs->start( 0, 'source.import', $source->id() );
        $ctx = new Context(
            runId: (string) $runId,
            dryRun: $dryRun,
            deadline: $deadline,
            meta: $meta,
        );

        try {
            $fetch = $source->fetch( new FetchRequest( $config, $options ), $ctx );
        } catch ( \Throwable $e ) {
            $this->runs->finish( $runId, 'failed', [ 'error' => $e->getMessage() ] );
            return [ 'status' => 'failed', 'run_id' => $runId, 'error' => $e->getMessage() ];
        }

        $items = $fetch->items;
        $diff  = $source->diff( $items, $ctx );

        $summary = [
            'fetched'   => count( $items ),
            'new'       => count( $diff->new ),
            'update'    => count( $diff->update ),
            'unchanged' => count( $diff->unchanged ),
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => count( $diff->unchanged ),
            'failed'    => 0,
        ];

        $rows = [];
        $process = array_merge( $diff->new, $diff->update );
        $total   = count( $process );

        foreach ( $process as $i => $item ) {
            if ( $ctx->isOverDeadline() ) {
                $this->runs->progress( $runId, $total, $summary['created'] + $summary['updated'], $summary['failed'] );
                $this->runs->finish( $runId, 'continue', [ 'summary' => $summary, 'cursor' => [ 'index' => $i ] ] );
                return [
                    'status'   => 'continue',
                    'run_id'   => $runId,
                    'summary'  => $summary,
                    'warnings' => $fetch->warnings,
                    'rows'     => array_slice( $rows, 0, 100 ),
                ];
            }

            try {
                $r = $source->materialize( $item, $ctx );
            } catch ( \Throwable $e ) {
                $r = null;
                $rows[] = [ 'sku' => $item->sku, 'action' => 'failed', 'error' => $e->getMessage() ];
                $summary['failed']++;
                continue;
            }

            $action = $r->action;
            switch ( $action ) {
                case 'created': $summary['created']++; break;
                case 'updated': $summary['updated']++; break;
                case 'failed':  $summary['failed']++;  break;
                case 'skipped': $summary['skipped']++; break;
            }

            $rows[] = [
                'pid'    => $r->productId,
                'sku'    => $item->sku,
                'action' => $action,
                'error'  => $r->error,
            ];
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
        ];
    }
}
