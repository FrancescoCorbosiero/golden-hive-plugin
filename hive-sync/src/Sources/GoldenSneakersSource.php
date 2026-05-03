<?php
declare(strict_types=1);

namespace HiveSync\Sources;

use HiveSync\Core\Source\AbstractSource;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Core\Source\FetchRequest;
use HiveSync\Core\Source\FetchResult;
use HiveSync\Core\Source\MaterializeResult;
use HiveSync\Core\Source\SourceCapabilities;

/**
 * Golden Sneakers source — delegating adapter.
 *
 * The legacy procedural module (rp_rc_gs_*, ~723 LOC in
 * golden-hive/includes/feeds/feed-goldensneakers.php) remains the source
 * of truth for fetch/normalize/transform/diff/create/update. This class
 * routes through three host filters (hsync_gs_fetch / _diff /
 * _materialize) so Hive Sync stays decoupled — when phase 5 demolishes
 * the legacy modules, those filter implementations move into
 * HiveSync\ without changing this class.
 *
 * Unlike duplicating GS code into Hive Sync, this approach has zero
 * regression risk: the proven legacy code keeps doing the work.
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
        // Mirrors the schema in golden-hive's feed-credentials.php.
        // The credentials store remains single-source-of-truth in the host
        // — Hive Sync's UI will hydrate redacted secrets via the host
        // adapter when the unified form is rendered (phase 3b).
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

        if (! function_exists('hsync_gs_fetch')) {
            return new FetchResult(items: [], warnings: ['Host adapter non caricato.']);
        }

        $resp = \hsync_gs_fetch($val['config'], $request->options);
        if ($resp === null) {
            return new FetchResult(items: [], warnings: ['Host GS non disponibile.']);
        }

        // The GS API ships ONE ROW PER (SKU + size) — multiple rows
        // share an SKU when a sneaker has several sizes. We aggregate
        // those rows by SKU into a single FeedItem per product so the
        // downstream pipeline (variants, mapping, materialize) sees one
        // logical product at a time.
        //
        // If the host bridge already aggregated upstream (older bridge
        // versions sometimes did), the loop is a no-op: each SKU
        // appears once and `sizes` arrives pre-built. The aggregator
        // is idempotent — it only merges when it sees duplicate SKUs.
        $rawRows = [];
        foreach ((array) ($resp['items'] ?? []) as $row) {
            if (! is_array($row)) continue;
            // Three accepted shapes from the bridge:
            //  - { sku, data: {...}, raw: {...} }   pre-wrapped
            //  - { sku, data: {...} }               wrapped, raw missing
            //  - { ...flat fields... }              flat, no wrapper
            // Normalize all three into a single "flat row" that's safe
            // to aggregate.
            if (isset($row['data']) && is_array($row['data'])) {
                $flat = $row['data'] + (is_array($row['raw'] ?? null) ? $row['raw'] : []);
                if (isset($row['sku']) && ! isset($flat['sku'])) $flat['sku'] = (string) $row['sku'];
            } else {
                $flat = $row;
            }
            $rawRows[] = $flat;
        }

        $items = self::aggregateFlatRows($rawRows);

        // Apply the user's mapping (if any) AFTER aggregation. The
        // mapping config targets the canonical aggregated field names
        // (product_name, summary_qty, sizes.size_eu, ...).
        //
        // OVERLAY semantics: the mapped output is merged ON TOP of the
        // original aggregated data, NOT replacing it. This matters
        // because the legacy GS materialize bridge expects its own
        // native keys (product_name, sizes, image_full_url, ...) and
        // would break if we replaced them. With overlay:
        //
        //   - The bridge keeps seeing what it always saw.
        //   - The user's mapping ADDS Woo-shaped keys (regular_price,
        //     stock_quantity, ...) that downstream import-rules + a
        //     future generic upsert path can honor.
        //   - Templates / SEO meta fields the bridge doesn't know
        //     about (description, meta_title, ...) flow through.
        $mapping = (array) ($request->options['mapping'] ?? []);
        if ($mapping) {
            $remapped = [];
            foreach ($items as $item) {
                $mappedFields = CsvSource::applyMapping($item->data, $mapping);
                $newData = $mappedFields + $item->data;  // mapped fields win
                // Always preserve the SKU — the diff/dedup pipeline
                // requires it.
                $newData['sku'] = $item->sku;
                $remapped[] = new FeedItem(
                    sku:  $item->sku,
                    data: $newData,
                    raw:  $item->raw,
                );
            }
            $items = $remapped;
        }

        return new FetchResult(
            items: $items,
            stats: ((array) ($resp['stats'] ?? [])) + [ 'flat_rows' => count($rawRows), 'products' => count($items) ],
            warnings: array_map('strval', (array) ($resp['warnings'] ?? [])),
        );
    }

    /**
     * Group the upstream's flat rows by SKU. Each output FeedItem
     * carries the canonical aggregated shape:
     *
     *   data = {
     *     sku, product_name, brand_name, size_mapper_name,
     *     image_full_url, image_name,
     *     presented_price, offer_price,         // product-level prices
     *     sizes: [{ size_eu, size_us, available_quantity, barcode }],
     *     summary_qty,                          // sum across sizes
     *     manage_stock: true, stock_status: 'instock'|'outofstock',
     *   }
     *
     *   raw = the array of original flat rows for this SKU
     *
     * Field names mirror the actual GS payload (product_name not name,
     * available_quantity per row not summary_qty). The mapping editor's
     * "Anteprima sorgente" probe surfaces both lists; gs-default points
     * at the aggregated keys downstream code understands.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return FeedItem[]
     */
    private static function aggregateFlatRows(array $rows): array
    {
        $bySku = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $sku = (string) ($r['sku'] ?? '');
            if ($sku === '') continue;

            if (! isset($bySku[$sku])) {
                $bySku[$sku] = [
                    'data' => [
                        'sku'              => $sku,
                        'product_name'     => (string) ($r['product_name'] ?? ''),
                        'brand_name'       => (string) ($r['brand_name'] ?? ''),
                        'size_mapper_name' => (string) ($r['size_mapper_name'] ?? ''),
                        'image_full_url'   => (string) ($r['image_full_url'] ?? ''),
                        'image_name'       => (string) ($r['image_name'] ?? ''),
                        'presented_price'  => $r['presented_price'] ?? null,
                        'offer_price'      => $r['offer_price'] ?? null,
                        'sizes'            => [],
                        'summary_qty'      => 0,
                    ],
                    'raw' => [],
                ];
            }
            $bySku[$sku]['raw'][] = $r;

            $sizeEu = trim((string) ($r['size_eu'] ?? ''));
            if ($sizeEu === '') continue; // no size = nothing to aggregate
            $qty = (int) ($r['available_quantity'] ?? 0);
            $bySku[$sku]['data']['sizes'][] = [
                'size_eu'             => $sizeEu,
                'size_us'             => (string) ($r['size_us'] ?? ''),
                'available_quantity'  => $qty,
                'barcode'             => (string) ($r['barcode'] ?? ''),
            ];
            $bySku[$sku]['data']['summary_qty'] += $qty;
        }

        $items = [];
        foreach ($bySku as $sku => $bundle) {
            $bundle['data']['manage_stock'] = true;
            $bundle['data']['stock_status'] = $bundle['data']['summary_qty'] > 0 ? 'instock' : 'outofstock';
            $items[] = new FeedItem(
                sku: (string) $sku,
                data: $bundle['data'],
                raw:  $bundle['raw'],
            );
        }
        return $items;
    }

    public function diff(array $items, Context $ctx): Diff
    {
        if (! function_exists('hsync_gs_diff')) {
            return new Diff();
        }

        $payload = array_map(
            static fn(FeedItem $i): array => $i->data,
            $items,
        );
        $resp = \hsync_gs_diff($payload);
        if ($resp === null) {
            return new Diff();
        }

        $newItems       = self::wrapBucket((array) ($resp['new']       ?? []));
        $unchangedItems = self::wrapBucket((array) ($resp['unchanged'] ?? []));
        $updateRaw      = self::wrapBucket((array) ($resp['update']    ?? []));

        // Refine the bridge's `update` bucket into full vs stock-only
        // by comparing each item against the current Woo product. The
        // fast-stock-patch path in ImportRunner picks up `updateStock`
        // and skips media + taxonomy + full upsert.
        [ $fullBucket, $stockBucket ] = StockOnlyClassifier::split( $updateRaw );

        return new Diff(
            new: $newItems,
            update: $fullBucket,
            unchanged: $unchangedItems,
            updateStock: $stockBucket,
        );
    }

    public function materialize(FeedItem $item, Context $ctx): MaterializeResult
    {
        if ($ctx->dryRun) {
            return MaterializeResult::skipped(null, 'dry_run');
        }
        if (! function_exists('hsync_gs_materialize')) {
            return MaterializeResult::failed('Host adapter non caricato.');
        }

        $sideload = (bool) ($ctx->meta['sideload'] ?? true);
        try {
            $r = \hsync_gs_materialize($item->data, false, $sideload);
        } catch (\Throwable $e) {
            return MaterializeResult::failed($e->getMessage());
        }
        if (! is_array($r)) {
            return MaterializeResult::failed('host_returned_non_array');
        }

        $action = (string) ($r['action'] ?? 'error');
        $pid    = (int) ($r['id'] ?? 0);

        if ($action === 'error' || $pid <= 0) {
            return MaterializeResult::failed(
                error: (string) ($r['reason'] ?? 'unknown_error'),
                productId: $pid > 0 ? $pid : null,
            );
        }

        return $action === 'updated'
            ? MaterializeResult::updated($pid, $r)
            : MaterializeResult::created($pid, $r);
    }

    /**
     * @param array<int, array> $bucket
     * @return FeedItem[]
     */
    private static function wrapBucket(array $bucket): array
    {
        $out = [];
        foreach ($bucket as $woo) {
            if (! is_array($woo)) continue;
            $out[] = new FeedItem(
                sku: (string) ($woo['sku'] ?? ''),
                data: $woo,
            );
        }
        return $out;
    }
}
