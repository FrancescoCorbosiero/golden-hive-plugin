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
        } catch ( \HiveSync\Core\Source\TransientSourceException $e ) {
            // Transient upstream failure (5xx / cURL timeout /
            // 200-empty-body on a multi-MB CSV). Mark the DB run
            // failed so the audit log explains why, then re-throw
            // so the AJAX handler's catch returns recoverable:true.
            // The JS tick loop retry-with-backoff (2s/4s/8s) will
            // re-attempt the same cursor; after maxRetries the user
            // gets a "Riprendi da qui" button. Without this re-throw
            // the runner would treat the empty FetchResult as
            // completion and silently drop the unprocessed tail of
            // the feed — the "Reconciled 156/10454, 10298
            // unaccounted" symptom.
            $this->runs->finish( $runId, 'failed', [ 'error' => $e->getMessage(), 'recoverable' => true ] );
            throw $e;
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
            'fetched'       => count( $items ),
            'new'           => count( $diff->new ),
            'update'        => count( $diff->update ),
            'update_stock'  => count( $diff->updateStock ),
            'unchanged'     => count( $diff->unchanged ),
            'created'       => 0,
            'updated'       => 0,
            'stock_patched' => 0,
            'skipped'       => 0,
            'failed'        => 0,
            'pre_blocked'   => 0,
            'post_blocked'  => 0,
        ];

        // `options.buckets` lets a job restrict processing to a subset
        // of diff buckets. Defaults to the full set so existing jobs
        // keep working. Canonical bucket names: 'new', 'update',
        // 'updateStock'. The fast-patch path runs ONLY on 'updateStock'.
        $allowedBuckets = self::normalizeBuckets( $options['buckets'] ?? null );

        // Build the processing queue as [bucket, item] pairs so we can
        // dispatch differently per bucket within the same loop. Order is
        // intentional: fast stock patches first (they're cheap and the
        // operator gets quick feedback), then full updates, then
        // creations (heaviest, last in case the deadline trips).
        $process = [];
        if ( in_array( 'updateStock', $allowedBuckets, true ) ) {
            foreach ( $diff->updateStock as $item ) $process[] = [ 'updateStock', $item ];
        }
        if ( in_array( 'update', $allowedBuckets, true ) ) {
            foreach ( $diff->update as $item ) $process[] = [ 'update', $item ];
        }
        if ( in_array( 'new', $allowedBuckets, true ) ) {
            foreach ( $diff->new as $item ) $process[] = [ 'new', $item ];
        }

        // `options.limit` caps the processing pool to the first N items
        // (after bucket ordering: stock-patch → update → new). Useful for
        // testing on big feeds without touching the full catalog. 0 (or
        // unset) means no cap. Applied AFTER bucket filtering so the
        // user sees an honest count: with limit=50 + buckets=[new], you
        // get the first 50 NEW items, never 50 mixed.
        $limit = (int) ( $options['limit'] ?? 0 );
        if ( $limit > 0 && count( $process ) > $limit ) {
            $process = array_slice( $process, 0, $limit );
            $summary['limited_to'] = $limit;
        }
        $total = count( $process );

        $rows = [];

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

            [ $bucket, $item ] = $process[ $i ];
            $rowTrace = [ 'sku' => $item->sku, 'pid' => null, 'action' => 'skipped', 'error' => null, 'pre' => [], 'post' => [], 'bucket' => $bucket ];

            // ─── Fast-stock-patch path ─────────────────────────────
            // Items in updateStock changed ONLY price/stock fields.
            // Skip the pipeline + the source's materialize entirely —
            // patch directly via WC product setters. This is the
            // perf-critical path for the "refresh-stocks" job that
            // runs every few minutes against thousands of products.
            if ( $bucket === 'updateStock' ) {
                if ( $ctx->dryRun ) {
                    $rowTrace['action'] = 'skipped';
                    $rowTrace['reason'] = 'dry_run';
                    $summary['skipped']++;
                    $rows[] = $rowTrace;
                    continue;
                }
                try {
                    $patched = self::fastStockPatch( $item );
                } catch ( \Throwable $e ) {
                    $rowTrace['action'] = 'failed';
                    $rowTrace['error']  = $e->getMessage();
                    $summary['failed']++;
                    $rows[] = $rowTrace;
                    continue;
                }
                if ( $patched === null ) {
                    $rowTrace['action'] = 'skipped';
                    $rowTrace['reason'] = 'sku_not_found_in_woo';
                    $summary['skipped']++;
                } else {
                    $rowTrace['pid']    = $patched;
                    $rowTrace['action'] = 'stock_patched';
                    $summary['stock_patched']++;
                }
                $rows[] = $rowTrace;
                continue;
            }

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
                    // Per-job step-param overrides — lets multiple jobs
                    // share one pipeline def while differing on a
                    // single knob (e.g. SF subsets that share
                    // `import-sf-with-markup` but each carries its
                    // own markup percent).
                    $stepParams = self::overrideStepParams( $step->refId, $step->params, $options );
                    try {
                        $op->applyDuringImport( $item, $draft, $stepParams, $opCtx );
                    } catch ( \Throwable $e ) {
                        // An import-rule failure doesn't block — log + continue
                        $rowTrace['pre'][] = [ 'ref' => $step->refId, 'rule_error' => $e->getMessage() ];
                    }
                }
            }
            $effectiveItem = $draft === $item->data
                ? $item
                : new FeedItem( sku: $item->sku, data: $draft, raw: $item->raw );

            // ─── Pre-materialize shape diagnostics ─────────────────
            // Surfaces in the run report so SF-style "imported but no
            // variants/prices" mysteries are debuggable from the
            // Storico tab without needing a debug session. Read-only:
            // never blocks materialize, only annotates the row trace.
            $rowTrace['shape'] = [
                'type'           => isset( $draft['type'] ) ? (string) $draft['type'] : '(unset)',
                'variations'     => isset( $draft['variations'] ) && is_array( $draft['variations'] ) ? count( $draft['variations'] ) : 0,
                'attribute_keys' => isset( $draft['attributes'] ) && is_array( $draft['attributes'] ) ? array_keys( $draft['attributes'] ) : [],
                'has_reg_price'  => isset( $draft['regular_price'] ) && $draft['regular_price'] !== '',
                'flavor'         => isset( $draft['_hsync_flavor'] ) ? (string) $draft['_hsync_flavor'] : '',
            ];

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

            // Post-materialize sanity: what does the Woo product
            // ACTUALLY look like after the bridge ran? This catches
            // the "shape pre-materialize was perfect but Woo wrote
            // an empty shell" class of bug where the bridge silently
            // drops variants or fails to attach prices/stocks. Five
            // signals: type, variations count, first variant's
            // reg/sale/qty, parent's stock_status. Read-only:
            // adds latency only when wc_get_product() resolves the
            // freshly-created/updated product (which is in WC's
            // object cache anyway).
            if ( $r->productId !== null && $r->productId > 0 && function_exists( 'wc_get_product' ) ) {
                $writtenShape = [ 'pid' => (int) $r->productId ];
                $w = \wc_get_product( (int) $r->productId );
                if ( $w ) {
                    $writtenShape['type']         = $w->get_type();
                    $writtenShape['stock_status'] = (string) $w->get_stock_status();
                    if ( $w->is_type( 'variable' ) ) {
                        $children = $w->get_children();
                        $writtenShape['variations_in_woo'] = count( $children );
                        if ( $children ) {
                            $first = \wc_get_product( (int) $children[0] );
                            if ( $first ) {
                                $writtenShape['first_variation'] = [
                                    'sku'            => (string) $first->get_sku(),
                                    'regular_price'  => (string) $first->get_regular_price(),
                                    'sale_price'     => (string) $first->get_sale_price(),
                                    'stock_quantity' => $first->get_stock_quantity(),
                                    'stock_status'   => (string) $first->get_stock_status(),
                                ];
                            }
                        }
                    } else {
                        $writtenShape['regular_price']  = (string) $w->get_regular_price();
                        $writtenShape['sale_price']     = (string) $w->get_sale_price();
                        $writtenShape['stock_quantity'] = $w->get_stock_quantity();
                    }
                } else {
                    $writtenShape['error'] = 'wc_get_product returned null';
                }
                $rowTrace['written'] = $writtenShape;
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

        $this->runs->progress( $runId, $total, $summary['created'] + $summary['updated'] + $summary['stock_patched'], $summary['failed'] );
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

    /**
     * Per-job step-param patches. Today only `markup_percent_override`
     * is supported — sufficient for the SF "one pipeline N subsets"
     * use case. Add more here as new shared-pipeline scenarios appear.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $runOptions
     * @return array<string, mixed>
     */
    private static function overrideStepParams( string $refId, array $params, array $runOptions ): array
    {
        if ( $refId === 'pricing.markup_percent' && isset( $runOptions['markup_percent_override'] ) ) {
            $params['percent'] = (float) $runOptions['markup_percent_override'];
        }
        return $params;
    }

    /**
     * Coerce the `options.buckets` setting into a canonical list. Defaults
     * to all three so a plain run-without-options keeps working unchanged.
     *
     * @param mixed $raw
     * @return array<int, string>
     */
    private static function normalizeBuckets( mixed $raw ): array
    {
        $all = [ 'new', 'update', 'updateStock' ];
        if ( $raw === null || $raw === '' ) return $all;
        if ( is_string( $raw ) ) {
            $raw = array_map( 'trim', explode( ',', $raw ) );
        }
        if ( ! is_array( $raw ) ) return $all;
        $out = [];
        foreach ( $raw as $b ) {
            $b = is_string( $b ) ? trim( $b ) : '';
            if ( in_array( $b, $all, true ) && ! in_array( $b, $out, true ) ) $out[] = $b;
        }
        return $out ?: $all;
    }

    /**
     * Light-weight stock + price patch — bypasses the source's full
     * materialize, which would re-download media + re-resolve taxonomy
     * + re-render templates.
     *
     * Two paths by parent type:
     *  - simple    → patch the parent's price/stock fields directly.
     *  - variable  → patch each VARIATION by SKU (deterministic
     *                <parent>-<size> from the SF/GS transforms),
     *                then sync the parent. Patching a variable parent's
     *                regular_price/stock_quantity is meaningless —
     *                Woo computes those from children.
     *
     * Returns the product id on success, null when the SKU has no match
     * in Woo (caller treats as skipped, not failed).
     */
    private static function fastStockPatch( FeedItem $item ): ?int
    {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) return null;
        $pid = \wc_get_product_id_by_sku( $item->sku );
        if ( ! $pid ) return null;
        $product = \wc_get_product( $pid );
        if ( ! $product ) return null;

        $data = $item->data;

        // Track parent dirtiness so we can save once at the end
        // regardless of which conditions fired. set_status alone
        // wouldn't otherwise persist for variable products where
        // manage_stock is already false.
        $parentDirty = false;

        // Sync parent status from the incoming payload regardless of
        // type. Without this, flipping import_status on the source-
        // config and re-syncing leaves existing products at their
        // original status because most re-imports route here (the
        // bucket diff classifies cosmetic changes as updateStock) and
        // we never reach gh_sf_update_product / gh_create_*_product
        // which DO sync status. Operator sees "products imported as
        // draft" even after switching to publish.
        if ( isset( $data['status'] ) && $data['status'] !== '' ) {
            $incomingStatus = (string) $data['status'];
            if ( $product->get_status() !== $incomingStatus ) {
                $product->set_status( $incomingStatus );
                $parentDirty = true;
            }
        }

        // Variable path: patch each variation by SKU. The variations[]
        // array on the FeedItem is the source of truth (output of the
        // source's transform — e.g. CsvSource::sfTransformToWoo or the
        // GS bridge). Without this branch, fast-patch would silently
        // touch only the parent and leave every variation stale —
        // which is exactly the bug that landed SF re-imports without
        // updated prices/stocks even after a successful first import.
        if ( $product->is_type( 'variable' ) ) {
            $variations = $data['variations'] ?? null;
            if ( ! is_array( $variations ) || $variations === [] ) {
                // No variations in payload (likely a hive-sync source
                // that doesn't produce them). Bail out so the caller
                // routes to the full bridge update path instead of
                // silently no-oping. Save the parent first if status
                // changed — don't lose that mutation on the bail-out.
                if ( $parentDirty ) $product->save();
                return null;
            }

            // Make sure the parent isn't accidentally managing stock —
            // older imports left it in a half-broken state where the
            // parent had its own stock_quantity. Idempotent: re-running
            // converges the parent to the canonical "Woo aggregates
            // from variants" state.
            if ( $product->get_manage_stock() ) {
                $product->set_manage_stock( false );
                $parentDirty = true;
            }
            if ( $parentDirty ) {
                $product->save();
            }

            $touched_any = false;
            foreach ( $variations as $var_data ) {
                if ( ! is_array( $var_data ) ) continue;
                $var_sku = (string) ( $var_data['sku'] ?? '' );
                if ( $var_sku === '' ) continue;
                $var_id = \wc_get_product_id_by_sku( $var_sku );
                if ( ! $var_id ) continue;  // new size? full update path will handle creation
                $v = \wc_get_product( $var_id );
                if ( ! $v || ! $v->is_type( 'variation' ) ) continue;

                $touched = false;
                if ( array_key_exists( 'regular_price', $var_data ) ) {
                    $v->set_regular_price( (string) $var_data['regular_price'] );
                    $touched = true;
                }
                if ( array_key_exists( 'sale_price', $var_data ) ) {
                    $v->set_sale_price( (string) $var_data['sale_price'] );
                    $touched = true;
                }
                if ( array_key_exists( 'stock_quantity', $var_data ) ) {
                    $qty = (int) $var_data['stock_quantity'];
                    $v->set_manage_stock( true );
                    $v->set_stock_quantity( $qty );
                    if ( ! array_key_exists( 'stock_status', $var_data ) ) {
                        $v->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                    }
                    $touched = true;
                }
                if ( array_key_exists( 'stock_status', $var_data ) ) {
                    $v->set_stock_status( (string) $var_data['stock_status'] );
                    $touched = true;
                }
                if ( $touched ) {
                    $v->save();
                    $touched_any = true;
                }
            }

            // Re-aggregate the parent: price range + stock_status
            // + lookup tables. Without sync, the front-end keeps
            // showing stale prices even after a correct variant patch.
            if ( $touched_any && class_exists( '\\WC_Product_Variable' ) ) {
                \WC_Product_Variable::sync( $pid );
            }
            return $pid;
        }

        // Simple path: original behaviour. Patches parent fields directly.
        // $parentDirty already captures any status sync from above.
        $touched = $parentDirty;
        if ( array_key_exists( 'regular_price', $data ) ) {
            $product->set_regular_price( (string) $data['regular_price'] );
            $touched = true;
        }
        if ( array_key_exists( 'sale_price', $data ) ) {
            $product->set_sale_price( (string) $data['sale_price'] );
            $touched = true;
        }
        if ( array_key_exists( 'stock_quantity', $data ) ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $data['stock_quantity'] );
            $touched = true;
        }
        if ( array_key_exists( 'stock_status', $data ) ) {
            $product->set_stock_status( (string) $data['stock_status'] );
            $touched = true;
        }

        if ( $touched ) $product->save();
        return $pid;
    }
}

