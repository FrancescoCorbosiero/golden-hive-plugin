<?php
declare(strict_types=1);

namespace GH\Sources;

use GH\Core\Source\AbstractSource;
use GH\Core\Source\Context;
use GH\Core\Source\Diff;
use GH\Core\Source\FeedItem;
use GH\Core\Source\FetchRequest;
use GH\Core\Source\FetchResult;
use GH\Core\Source\MaterializeResult;
use GH\Core\Source\SourceCapabilities;

/**
 * Golden Sneakers feed exposed as a Source.
 *
 * Strangler-fig adapter: the existing rp_rc_gs_* procedural functions
 * (feed-goldensneakers.php, ~723 LOC) remain the source of truth for
 * fetch/normalize/transform/diff/create/update. This class wraps them
 * behind the v2 Source contract so:
 *   - the Pipeline executor and Job runner reach GS the same way they
 *     reach every other source (no per-feed switch statements upstream)
 *   - configSchema() drives the unified UI form (Batch 5)
 *   - subsequent batches can extract logic INTO this class without
 *     changing the public contract
 *
 * Behavior parity with legacy: the legacy create/update functions
 * already call gh_conflict_record_source(), so this adapter does NOT
 * call recordProvenance() — that would double-count. The conflict
 * engine integration on the v2 path will land in a dedicated batch.
 */
final class GoldenSneakersSource extends AbstractSource
{
    public const ID = 'goldensneakers';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Golden Sneakers';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            canFetch: true,
            canDiff: true,
            canMaterialize: true,
            canSelectLocal: false,
            supportsQuickPatch: false,
            supportsImageSideload: true,
        );
    }

    public function configSchema(): array
    {
        // Mirrors the schema in feeds/feed-credentials.php for 'goldensneakers'.
        // The credentials module is still the storage; this schema is what
        // the v2 generic UI form (Batch 5) will render. Hydration of redacted
        // secrets happens upstream (AJAX layer / job param builder), same as
        // the legacy flow — fetch() always sees plaintext.
        return [
            'url' => [
                'type'     => 'url',
                'label'    => 'API URL',
                'required' => true,
                'max'      => 4096,
            ],
            'token' => [
                'type'     => 'secret',
                'label'    => 'Bearer token',
                'required' => true,
                'max'      => 8192,
            ],
            'cookie' => [
                'type'     => 'secret',
                'label'    => 'Cookie',
                'required' => false,
                'max'      => 16384,
            ],
            'format' => [
                'type'     => 'enum',
                'label'    => 'Formato risposta',
                'required' => false,
                'options'  => ['hierarchical', 'flat'],
            ],
        ];
    }

    public function fetch(FetchRequest $request, Context $ctx): FetchResult
    {
        $val = $this->validateConfig($request->config);
        if (! $val['ok']) {
            return new FetchResult(
                items: [],
                stats: ['errors' => $val['errors']],
                warnings: ['Config invalida — verifica url/token.'],
            );
        }

        if (! function_exists('rp_rc_gs_fetch')
            || ! function_exists('rp_rc_gs_transform_all')
            || ! function_exists('rp_rc_gs_normalize_hierarchical')
            || ! function_exists('rp_rc_gs_normalize_flat')) {
            return new FetchResult(
                items: [],
                warnings: ['Modulo legacy GS non caricato (rp_rc_gs_*).'],
            );
        }

        $raw = \rp_rc_gs_fetch($val['config']);
        if (function_exists('is_wp_error') && \is_wp_error($raw)) {
            return new FetchResult(
                items: [],
                warnings: [(string) $raw->get_error_message()],
            );
        }
        if (! is_array($raw)) {
            return new FetchResult(items: [], warnings: ['Fetch GS: risposta non array.']);
        }

        $format = (string) ($val['config']['format'] ?? 'hierarchical');
        $normalized = $format === 'flat'
            ? \rp_rc_gs_normalize_flat($raw)
            : \rp_rc_gs_normalize_hierarchical($raw);

        $priceMode = (string) ($request->options['price_mode'] ?? 'direct');
        $saleMult = (float) ($request->options['sale_mult'] ?? 1.3);
        $wooProducts = \rp_rc_gs_transform_all($normalized, $priceMode, $saleMult);

        $items = [];
        foreach ($wooProducts as $i => $woo) {
            $items[] = new FeedItem(
                sku: (string) ($woo['sku'] ?? ''),
                data: $woo,
                raw: $normalized[$i] ?? [],
            );
        }

        return new FetchResult(
            items: $items,
            stats: [
                'total'      => count($items),
                'format'     => $format,
                'price_mode' => $priceMode,
            ],
        );
    }

    public function diff(array $items, Context $ctx): Diff
    {
        if (! function_exists('rp_rc_gs_diff')) {
            // Legacy not loaded — no diff possible. Return empty Diff so
            // the executor records "nothing to do" rather than fataling.
            return new Diff();
        }

        // Legacy diff() expects an array of woo product arrays — not FeedItems.
        $wooProducts = array_map(
            static fn(FeedItem $item): array => $item->data,
            $items,
        );

        $legacy = \rp_rc_gs_diff($wooProducts);

        return new Diff(
            new: self::wrapBucket((array) ($legacy['new'] ?? [])),
            update: self::wrapBucket((array) ($legacy['update'] ?? [])),
            unchanged: self::wrapBucket((array) ($legacy['unchanged'] ?? [])),
        );
    }

    public function materialize(FeedItem $item, Context $ctx): MaterializeResult
    {
        if ($ctx->dryRun) {
            return MaterializeResult::skipped(null, 'dry_run');
        }
        if (! function_exists('rp_rc_gs_create_product')
            || ! function_exists('rp_rc_gs_update_product')) {
            return MaterializeResult::failed('Modulo legacy GS non caricato.');
        }

        // Existence check by SKU. Legacy diff() puts _existing_id on update
        // bucket items, but materialize() may be called with a bare FeedItem
        // (e.g. from a re-import without re-running diff), so we look it up
        // again to be safe.
        $existingId = (int) ($item->data['_existing_id'] ?? 0);
        if ($existingId === 0 && function_exists('wc_get_product_id_by_sku') && $item->sku !== '') {
            $existingId = (int) \wc_get_product_id_by_sku($item->sku);
        }

        $sideload = (bool) ($ctx->meta['sideload'] ?? true);
        $isUpdate = $existingId > 0;

        try {
            if ($isUpdate) {
                $data = $item->data;
                $data['_existing_id'] = $existingId;
                $r = \rp_rc_gs_update_product($data);
            } else {
                $r = \rp_rc_gs_create_product($item->data, $sideload);
            }
        } catch (\Throwable $e) {
            return MaterializeResult::failed($e->getMessage());
        }

        $action = (string) ($r['action'] ?? 'error');
        $pid = (int) ($r['id'] ?? 0);

        if ($action === 'error' || $pid <= 0) {
            return MaterializeResult::failed(
                error: (string) ($r['reason'] ?? 'unknown_error'),
                productId: $pid > 0 ? $pid : null,
            );
        }

        // Provenance is recorded inside the legacy create/update — do not
        // double-record here. Conflict-engine integration on the v2 path
        // is intentionally deferred to a focused batch.

        return $action === 'updated'
            ? MaterializeResult::updated($pid, $r)
            : MaterializeResult::created($pid, $r);
    }

    /**
     * Convert a legacy diff bucket (array of woo product arrays) into
     * an array of FeedItem.
     *
     * @param array<int, array> $bucket
     * @return FeedItem[]
     */
    private static function wrapBucket(array $bucket): array
    {
        $out = [];
        foreach ($bucket as $woo) {
            if (! is_array($woo)) {
                continue;
            }
            $out[] = new FeedItem(
                sku: (string) ($woo['sku'] ?? ''),
                data: $woo,
            );
        }
        return $out;
    }
}
