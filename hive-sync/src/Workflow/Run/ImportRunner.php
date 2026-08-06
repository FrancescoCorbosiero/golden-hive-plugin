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
use HiveSync\Core\Source\Diff;
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

        // Resolve runId. A cursor from the JS tick loop (or from a
        // resumed cron tick) MAY carry a run_id that no longer
        // corresponds to a live row in wp_hsync_runs — typically after
        // an uninstall+reinstall (the table drops, AUTO_INCREMENT
        // restarts at 1, leftover RunCache transients keyed on
        // pre-reinstall runIds collide with the new sequence). Without
        // validating, ImportRunner would read a STALE Diff from the
        // previous install, skip fetch+diff, and the loop would process
        // phantom items whose _existing_id no longer matches the
        // current Woo state — producing the silent 0-counter "phantom
        // run" symptom. Validate the cursor's run_id against the
        // repository; on miss, discard both cursor.run_id AND
        // cursor.index and start a fresh run.
        $cursorRunId = isset( $cursor['run_id'] ) ? (int) $cursor['run_id'] : 0;
        if ( $cursorRunId > 0 && $this->runs->find( $cursorRunId ) === null ) {
            // Evict the orphan RunCache entry so no later codepath
            // picks it up by accident, then forget the cursor entirely.
            RunCache::clear( $cursorRunId );
            $cursorRunId = 0;
            $startIndex  = 0;
        }
        $runId = $cursorRunId > 0
            ? $cursorRunId
            : $this->runs->start( 0, 'source.import', $source->id() );

        // Was this run resumed from a validated cursor, or freshly
        // minted on this tick? Drives whether we may trust a
        // pre-existing RunCache entry (see the cache lookup below).
        $isResume = $cursorRunId > 0;

        // Normalize the three-way run mode. UI exposes a segmented
        // control "Completa | Solo dati | Solo media"; legacy
        // skip_media=1 maps to data_only for back-compat.
        //   full       — products + media in one pass (default)
        //   data_only  — products, skip media.download + bridge sideload
        //   media_only — pre-stage media into the preimport map as
        //                orphan attachments, skip product writes
        // The mapping invariant (URL → attachment_id in the preimport
        // map) is identical in all three modes — only what work runs
        // changes. Order-agnostic: media_only then full = full then
        // media_only = identical end state.
        $mode = isset( $options['mode'] ) ? (string) $options['mode'] : '';
        if ( $mode === '' ) {
            $mode = ! empty( $options['skip_media'] ) ? 'data_only' : 'full';
        }
        if ( ! in_array( $mode, [ 'full', 'data_only', 'media_only' ], true ) ) {
            $mode = 'full';
        }

        // data_only also propagates to ctx.meta.sideload=false so the
        // GS/SF bridges skip their gh_*_sideload_images fallback path
        // (which would otherwise re-sideload anything missing from the
        // pre-import map — exactly what data_only is trying to avoid).
        if ( $mode === 'data_only' ) {
            $meta['sideload'] = false;
        }

        $ctx = new Context(
            runId: (string) $runId,
            dryRun: $dryRun,
            deadline: $deadline,
            meta: $meta,
        );
        $opCtx = new OperationContext( base: $ctx, sourceId: $source->id() );

        // Pipeline (optional). Each step's registry lookup is lazy.
        $pipeline = self::loadPipeline( (string) ( $options['pipeline_slug'] ?? '' ) );

        // Resumed ticks reuse the fetch+diff from tick 1 via a
        // gzcompressed transient keyed to runId. Saves ~5-10s of
        // HTTP+parse+bucket-walk per tick on large feeds (10k items
        // × 200 ticks first-import = ~30 min of pure repetition
        // eliminated). On miss (tick 1, or cache evicted), fall
        // through to the normal fetch+diff path and re-populate.
        //
        // Consult the cache whenever a RESUMED run has one — NOT only
        // when startIndex>0. When fetch+diff alone consumes the whole
        // 25s budget (a large SF/GS feed: multi-MB download + parse),
        // the item loop trips the deadline at index 0 and yields
        // cursor.index=0. Gating the cache on startIndex>0 would then
        // re-fetch on every resumed tick and never advance past the
        // first item — a permanent stall.
        //
        // But a FRESHLY MINTED run (no validated cursor) must NEVER read
        // a pre-existing RunCache entry. After an uninstall/reinstall (or
        // any wipe of wp_hsync_runs) the AUTO_INCREMENT restarts at 1, so
        // a brand-new run_id=1 collides with the stale `hsync_run_cache_1`
        // transient left by the previous install. Reading it feeds tick 1
        // a stale (often empty) Diff → the loop imports nothing → the
        // silent 0-counter "phantom run". A run we just started cannot
        // legitimately have a cache yet (it's written below, on this very
        // tick), so evict any colliding leftover and force a fresh
        // fetch+diff. Only the resume path — whose run_id was validated
        // against wp_hsync_runs above — may hydrate from cache.
        if ( $isResume ) {
            $cached = RunCache::get( $runId );
            if ( $cached === null && $startIndex > 0 ) {
                // Cache evasa/scaduta A META' run (TTL 2h, o eviction
                // dell'object cache). Il re-fetch+re-diff qui sotto
                // produce una coda DIVERSA: gli item gia' importati si
                // riclassificano unchanged/updateStock e spariscono dal
                // bucket new, quindi un indice POSIZIONALE nella coda
                // ricostruita salterebbe item reali mai processati — il
                // run chiudeva 'done' perdendo silenziosamente la testa
                // della coda. Il diff e' idempotente per costruzione:
                // ripartire da 0 ri-attraversa gli item gia' fatti come
                // no-op e processa davvero i mancanti.
                $startIndex = 0;
            }
        } else {
            RunCache::clear( $runId );
            $cached = null;
        }
        $fetchWarnings  = [];
        $fetchedCount   = 0;
        $unchangedCount = 0;

        // Hoisted: serve gia' qui per decidere COSA cachare (il bucket
        // unchanged e' processato solo dai run force_recreate).
        $forceRecreate = ! empty( $options['force_recreate'] );

        // Una cache scritta da un run non-force porta unchanged strippato;
        // se il resume chiede force_recreate la coda force deve includere
        // anche quegli item → la cache non basta, si ri-diffa da zero.
        if ( $forceRecreate && $cached !== null
            && (int) $cached['unchanged_count'] > 0
            && count( $cached['diff']->unchanged ) === 0 ) {
            $cached     = null;
            $startIndex = 0;
        }

        if ( $cached !== null ) {
            $fetchWarnings  = $cached['warnings'];
            $fetchedCount   = $cached['fetched_count'];
            $diff           = $cached['diff'];
            $unchangedCount = (int) $cached['unchanged_count'];
        } else {
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
                RunCache::clear( $runId );
                $this->runs->finish( $runId, 'failed', [ 'error' => $e->getMessage(), 'recoverable' => true ] );
                throw $e;
            } catch ( \Throwable $e ) {
                RunCache::clear( $runId );
                $this->runs->finish( $runId, 'failed', [ 'error' => $e->getMessage() ] );
                return [ 'status' => 'failed', 'run_id' => $runId, 'error' => $e->getMessage() ];
            }
            $items          = $fetch->items;
            $diff           = $source->diff( $items, $ctx );
            $fetchWarnings  = $fetch->warnings;
            $fetchedCount   = count( $items );
            $unchangedCount = count( $diff->unchanged );
            // Cache a raw-stripped copy. FeedItem.raw is audit-only and
            // never reaches materialize (it reads ->data), but on a 10k
            // SF feed it roughly doubles the serialized blob — and the
            // smaller the cached value, the more reliably it fits a WP
            // transient (max_allowed_packet on the DB path, item-size
            // caps on object caches). The unchanged bucket (~95% of a
            // settled catalog) is stripped too — the loop never touches
            // it, only the count is reported — EXCEPT on force_recreate
            // runs, whose queue is rebuilt from unchanged as well. This
            // tick keeps the live $diff (with raw) for its summary +
            // processing.
            RunCache::set(
                $runId,
                $fetchWarnings,
                $fetchedCount,
                self::cacheableDiff( $diff, $forceRecreate ),
                $unchangedCount
            );
        }

        // `unchanged` is reported as a top-level metric — items the diff
        // declared identical to the existing product, so they were never
        // queued for processing. `skipped` is reserved for items that
        // entered the loop but materialize() returned action='skipped'
        // (e.g. dry-run, conflict-engine veto, no-op upsert). Conflating
        // the two hides genuine processing decisions behind the skip
        // counter — keep them strictly separate.
        $summary = [
            'fetched'       => $fetchedCount,
            'new'           => count( $diff->new ),
            'update'        => count( $diff->update ),
            'update_stock'  => count( $diff->updateStock ),
            // Dal conteggio dedicato, NON da count(diff->unchanged): sui
            // tick resumed il Diff cachato porta il bucket strippato.
            'unchanged'     => $unchangedCount,
            'created'       => 0,
            'updated'       => 0,
            'recreated'     => 0,
            'stock_patched' => 0,
            'skipped'       => 0,
            'failed'        => 0,
            'pre_blocked'   => 0,
            'post_blocked'  => 0,
        ];

        // Surface why the classifier sent things to updateFull. Only
        // attached when there's data (cache hits on resumed ticks have
        // an empty snapshot because split() didn't run this tick) so
        // the JS only sees the histogram on the tick that produced it
        // (typically tick 1). Sources whose diff() doesn't go through
        // StockOnlyClassifier simply emit an empty `reasons` map.
        if ( ! empty( \HiveSync\Sources\StockOnlyClassifier::$lastDiagnostics['reasons'] ) ) {
            arsort( \HiveSync\Sources\StockOnlyClassifier::$lastDiagnostics['reasons'] );
            $summary['classifier_diagnostics'] = \HiveSync\Sources\StockOnlyClassifier::$lastDiagnostics;
        }

        // `options.buckets` lets a job restrict processing to a subset
        // of diff buckets. Defaults to the full set so existing jobs
        // keep working. Canonical bucket names: 'new', 'update',
        // 'updateStock'. The fast-patch path runs ONLY on 'updateStock'.
        $allowedBuckets = self::normalizeBuckets( $options['buckets'] ?? null );

        // `options.force_recreate` is the operator's escape hatch when
        // the idempotent re-sync didn't converge: every existing SKU is
        // pushed through the bridge's recreate path (wipe variations +
        // pa_* terms + _product_attributes, then re-write from feed).
        // The classifier's "unchanged" / "updateStock" verdicts are
        // overridden — those items also enter the full materialize
        // path so the reset fires. Each item gets a `_gh_force_recreate`
        // marker the bridge filters on. `new` items aren't affected
        // (no existing product to reset) and the marker is harmless on
        // them anyway. This is a one-shot operator action — never set
        // it on a cron job, that's what conflict rules + the
        // self-heal classifier are for.
        $forceRecreate = ! empty( $options['force_recreate'] );

        // Build the processing queue as [bucket, item] pairs so we can
        // dispatch differently per bucket within the same loop. Order is
        // intentional: fast stock patches first (they're cheap and the
        // operator gets quick feedback), then full updates, then
        // creations (heaviest, last in case the deadline trips).
        $process = [];
        if ( $forceRecreate ) {
            // Skip the fast-patch path entirely — recreate needs the
            // full bridge flow, the variation IDs are about to be
            // discarded anyway. Re-bucket everything into `update` so
            // the loop hits the materialize branch. `unchanged` items
            // are pulled in too — that's the whole point: nothing
            // "looks unchanged" when the operator asked for a rewrite.
            $forceQueue = [];
            foreach ( $diff->update as $item )      $forceQueue[] = $item;
            foreach ( $diff->updateStock as $item ) $forceQueue[] = $item;
            foreach ( $diff->unchanged as $item )   $forceQueue[] = $item;

            if ( in_array( 'update', $allowedBuckets, true ) ) {
                foreach ( $forceQueue as $item ) {
                    $process[] = [ 'update', self::stampForceRecreate( $item ) ];
                }
            }
            if ( in_array( 'new', $allowedBuckets, true ) ) {
                foreach ( $diff->new as $item ) $process[] = [ 'new', $item ];
            }
            $summary['force_recreate'] = count( $forceQueue );
        } else {
            if ( in_array( 'updateStock', $allowedBuckets, true ) ) {
                foreach ( $diff->updateStock as $item ) $process[] = [ 'updateStock', $item ];
            }
            if ( in_array( 'update', $allowedBuckets, true ) ) {
                foreach ( $diff->update as $item ) $process[] = [ 'update', $item ];
            }
            if ( in_array( 'new', $allowedBuckets, true ) ) {
                foreach ( $diff->new as $item ) $process[] = [ 'new', $item ];
            }
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

        // ─── media_only branch ─────────────────────────────────────
        // Pre-stage every item's image URLs into the preimport map
        // as orphan attachments (no product attach, _gh_preimport_pending=1).
        // A subsequent run in `full` mode resolves URL → attachment
        // from the same map and attaches without re-downloading.
        // Reuses the per-item cursor + cooperative deadline.
        if ( $mode === 'media_only' ) {
            $stats = $summary;
            $stats['media_downloaded']     = $cursor['media_downloaded']     ?? 0;
            $stats['media_skipped']        = $cursor['media_skipped']        ?? 0;
            $stats['media_errors']         = $cursor['media_errors']         ?? 0;
            $stats['items_without_images'] = $cursor['items_without_images'] ?? 0;

            // Batch N items per inner round so each HTTP wave has
            // enough URLs to fill the concurrency window (~50 items
            // × 3 images = 150 URLs ≈ 6 sliding-window rounds at
            // concurrency 24). Smaller batches waste connection
            // setup; larger ones risk overshooting the deadline.
            $batchSize = 50;
            $i = $startIndex;
            while ( $i < $total ) {
                if ( $ctx->isOverDeadline() ) {
                    $this->runs->progress( $runId, $total, $stats['media_downloaded'], $stats['media_errors'] );
                    $this->runs->finish( $runId, 'continue', [
                        'summary' => $stats,
                        'cursor'  => [
                            'index'                 => $i,
                            'media_downloaded'      => $stats['media_downloaded'],
                            'media_skipped'         => $stats['media_skipped'],
                            'media_errors'          => $stats['media_errors'],
                            'items_without_images'  => $stats['items_without_images'],
                        ],
                    ] );
                    return [
                        'status'   => 'continue',
                        'run_id'   => $runId,
                        'cursor'   => [
                            'index'                 => $i,
                            'run_id'                => $runId,
                            'media_downloaded'      => $stats['media_downloaded'],
                            'media_skipped'         => $stats['media_skipped'],
                            'media_errors'          => $stats['media_errors'],
                            'items_without_images'  => $stats['items_without_images'],
                        ],
                        'summary'  => $stats,
                        'warnings' => $fetchWarnings,
                        'rows'     => [],
                        'progress' => [ 'done' => $i, 'total' => $total ],
                    ];
                }

                $end       = min( $i + $batchSize, $total );
                $batchUrls = [];
                for ( $j = $i; $j < $end; $j++ ) {
                    [ , $item ] = $process[ $j ];
                    $urls = $source->imageUrls( $item );
                    if ( empty( $urls ) ) {
                        $stats['items_without_images']++;
                        continue;
                    }
                    foreach ( $urls as $u ) $batchUrls[] = $u;
                }
                $batchUrls = array_values( array_unique( $batchUrls ) );

                if ( ! empty( $batchUrls ) ) {
                    $resolved = \function_exists( 'hsync_preimport_media_batch' )
                        ? \hsync_preimport_media_batch( $batchUrls, [ 'mode' => 'media_only', 'run_id' => $runId ] )
                        : [];
                    $hit = count( $resolved );
                    // Each input URL is either resolved (download success
                    // or already-mapped skip) or errored. The bridge
                    // doesn't distinguish in the return shape, so we
                    // estimate: assume hits are downloads+skips, the
                    // rest are errors. Good enough for a progress meter.
                    $stats['media_downloaded'] += $hit;
                    $stats['media_errors']     += max( 0, count( $batchUrls ) - $hit );
                }

                $i = $end;
            }

            $this->runs->progress( $runId, $total, $stats['media_downloaded'], $stats['media_errors'] );
            RunCache::clear( $runId );
            $this->runs->finish( $runId, 'done', [
                'summary'  => $stats,
                'warnings' => $fetchWarnings,
            ] );
            return [
                'status'   => 'done',
                'run_id'   => $runId,
                'summary'  => $stats,
                'warnings' => $fetchWarnings,
                'rows'     => [],
                'progress' => [ 'done' => $total, 'total' => $total ],
            ];
        }

        $rows = [];

        for ( $i = $startIndex; $i < $total; $i++ ) {
            if ( $ctx->isOverDeadline() ) {
                $this->runs->progress( $runId, $total, $summary['created'] + $summary['updated'] + $summary['recreated'], $summary['failed'] );
                $this->runs->finish( $runId, 'continue', [ 'summary' => $summary, 'cursor' => [ 'index' => $i ] ] );
                return [
                    'status'   => 'continue',
                    'run_id'   => $runId,
                    'cursor'   => [ 'index' => $i, 'run_id' => $runId ],
                    'summary'  => $summary,
                    'warnings' => $fetchWarnings,
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
                // mode=data_only drops the media.download step from
                // the per-item pipeline (paired with ctx.meta.sideload=false
                // upstream so bridges skip their fallback too). A
                // later run in `full` mode sends the same products
                // through `update` and media.download then resolves
                // images — either from the preimport map (when a
                // prior media_only pass pre-staged them) or by
                // sideloading on-demand.
                $skipMedia = $mode === 'data_only';
                foreach ( $pipeline->importRuleSteps() as $step ) {
                    if ( $skipMedia && $step->refId === 'media.download' ) continue;
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
            // reg/sale/qty, parent's stock_status.
            //
            // Capped at the first 100 rows per tick — esattamente cio'
            // che il return consuma (array_slice($rows, 0, 100)). NON
            // e' gratis: get_children() rilegge il transient figli che
            // la write del bridge ha appena invalidato (query piena) +
            // 2 hydration per item. Su un first-import da 10k pagava
            // ~30.000 query di sola telemetria che nessuno leggeva.
            if ( count( $rows ) < 100 && $r->productId !== null && $r->productId > 0 && function_exists( 'wc_get_product' ) ) {
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
                case 'created':   $summary['created']++;   break;
                case 'updated':   $summary['updated']++;   break;
                case 'recreated': $summary['recreated']++; break;
                case 'failed':    $summary['failed']++;    break;
                case 'skipped':   $summary['skipped']++;   break;
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

        $this->runs->progress( $runId, $total, $summary['created'] + $summary['updated'] + $summary['recreated'] + $summary['stock_patched'], $summary['failed'] );
        RunCache::clear( $runId );
        $this->runs->finish( $runId, 'done', [
            'summary'  => $summary,
            'warnings' => $fetchWarnings,
        ] );

        return [
            'status'   => 'done',
            'run_id'   => $runId,
            'summary'  => $summary,
            'warnings' => $fetchWarnings,
            'rows'     => array_slice( $rows, 0, 100 ),
            'progress' => [ 'done' => $total, 'total' => $total ],
        ];
    }

    /**
     * Build a cache-friendly copy of a Diff with FeedItem.raw stripped.
     * raw is audit/debug only — materialize reads ->data exclusively —
     * so dropping it changes nothing downstream while roughly halving
     * the serialized size of a large SF/GS diff. FeedItem is readonly,
     * so each item carrying a non-empty raw is rebuilt; empty-raw items
     * pass through untouched.
     *
     * The unchanged bucket is dropped entirely unless $keepUnchanged
     * (force_recreate runs, whose queue rebuilds from it): the loop
     * never reads it — only its count is reported, and that travels as
     * a separate scalar in the RunCache payload. On a settled catalog
     * unchanged is ~95% of the items, so this cuts the per-tick
     * decode+unserialize cost and the transient size by ~10-20x.
     */
    private static function cacheableDiff( Diff $diff, bool $keepUnchanged = false ): Diff
    {
        $strip = static function ( array $items ): array {
            $out = [];
            foreach ( $items as $it ) {
                $out[] = ( $it instanceof FeedItem && $it->raw !== [] )
                    ? new FeedItem( sku: $it->sku, data: $it->data, raw: [] )
                    : $it;
            }
            return $out;
        };

        return new Diff(
            new:         $strip( $diff->new ),
            update:      $strip( $diff->update ),
            unchanged:   $keepUnchanged ? $strip( $diff->unchanged ) : [],
            updateStock: $strip( $diff->updateStock ),
        );
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
     * Stamp the `_gh_force_recreate` marker on a FeedItem so the host
     * bridge (gs/sf) routes through the recreate path instead of the
     * regular update. Returns a new FeedItem — the original is left
     * alone because FeedItem is readonly.
     */
    private static function stampForceRecreate( FeedItem $item ): FeedItem
    {
        $data = $item->data;
        $data['_gh_force_recreate'] = true;
        return new FeedItem( sku: $item->sku, data: $data, raw: $item->raw );
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
        if ( ! function_exists( 'wc_get_product' ) ) return null;

        // diff() stampa _existing_id su ogni item che instrada qui (lo
        // stesso contratto che StockOnlyClassifier::classify onora):
        // fidarsi evita il roundtrip wc_get_product_id_by_sku — una meta
        // query per item sul path che i docs chiamano perf-critico.
        // Fallback al lookup solo per item costruiti a mano.
        $pid = (int) ( $item->data['_existing_id'] ?? 0 );
        if ( $pid <= 0 ) {
            if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) return null;
            $pid = (int) \wc_get_product_id_by_sku( $item->sku );
        }
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

            // Risolvi le variazioni dal SET FIGLI del parent, non con una
            // wc_get_product_id_by_sku globale per taglia: una passata di
            // priming (post+meta dei figli) + lettura _sku dalla cache.
            // Il loop legacy pagava ~2 query per variazione — su un
            // refresh stock tipico (5k prodotti × 15 taglie) ~150.000
            // query di solo lookup. In piu' il match e' ora scopato al
            // parent: una collisione di SKU con la variazione di un ALTRO
            // prodotto non puo' piu' farci patchare il prodotto sbagliato.
            $child_ids = array_map( 'intval', $product->get_children() );
            if ( $child_ids && function_exists( '_prime_post_caches' ) ) {
                \_prime_post_caches( $child_ids, false, true );
            }
            $vid_by_sku    = [];
            $vid_by_sku_lc = [];
            foreach ( $child_ids as $cid ) {
                $csku = (string) \get_post_meta( $cid, '_sku', true );
                if ( $csku === '' ) continue;
                $vid_by_sku[ $csku ]                  ??= $cid;
                $vid_by_sku_lc[ strtolower( $csku ) ] ??= $cid;
            }

            $touched_any = false;
            foreach ( $variations as $var_data ) {
                if ( ! is_array( $var_data ) ) continue;
                $var_sku = (string) ( $var_data['sku'] ?? '' );
                if ( $var_sku === '' ) continue;
                $var_id = $vid_by_sku[ $var_sku ]
                    ?? $vid_by_sku_lc[ strtolower( $var_sku ) ]
                    ?? 0;
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

