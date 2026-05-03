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
        return 'JSON — Feed Golden Sneakers';
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
        $cfg = $val['config'];

        // Direct HTTP fetch — bypasses the legacy bridge so the editor's
        // probe sees the actual API response shape (one row per
        // SKU+size, with the upstream's native field names) instead of
        // whatever shape the bridge happens to normalize to. The bridge
        // is still used for materialize via hsync_gs_materialize.
        $url     = (string) ($cfg['url']    ?? '');
        $token   = (string) ($cfg['token']  ?? '');
        $cookie  = (string) ($cfg['cookie'] ?? '');
        if ($url === '' || $token === '') {
            return new FetchResult(items: [], warnings: ['URL o token mancanti nella config.']);
        }

        $headers = [ 'Accept' => 'application/json' ];
        if ($token  !== '') $headers['Authorization'] = 'Bearer ' . $token;
        if ($cookie !== '') $headers['Cookie']        = $cookie;

        $resp = wp_remote_get($url, [
            'headers'    => $headers,
            'timeout'    => 30,
            'user-agent' => 'HiveSync/' . (defined('HSYNC_VERSION') ? HSYNC_VERSION : '1.0'),
        ]);
        if (is_wp_error($resp)) {
            return new FetchResult(items: [], warnings: ['Errore HTTP: ' . $resp->get_error_message()]);
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            return new FetchResult(items: [], warnings: ["HTTP $code dalla sorgente GS."]);
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return new FetchResult(items: [], warnings: ['Risposta GS non è JSON valido.']);
        }

        // Accept both flat-array AND wrapped responses: top-level array,
        // {data: [...]}, {items: [...]}, {results: [...]}.
        $rawRows = $decoded;
        foreach (['data', 'items', 'results'] as $k) {
            if (isset($rawRows[$k]) && is_array($rawRows[$k])) {
                $rawRows = $rawRows[$k];
                break;
            }
        }
        if (! array_is_list($rawRows)) {
            return new FetchResult(items: [], warnings: ['Risposta GS: lista di righe non rilevata.']);
        }

        $items = self::aggregateFlatRows($rawRows);

        // Apply the user's mapping (if any) AFTER aggregation. OVERLAY
        // semantics: mapped output is merged ON TOP of the aggregated
        // data so the legacy bridge's materialize keeps seeing its
        // expected native keys while downstream import-rules + UI also
        // see the user's Woo-shaped keys.
        $mapping = (array) ($request->options['mapping'] ?? []);
        if ($mapping) {
            $remapped = [];
            foreach ($items as $item) {
                $mappedFields = CsvSource::applyMapping($item->data, $mapping);
                $newData = $mappedFields + $item->data;
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
            stats: [ 'flat_rows' => count($rawRows), 'products' => count($items) ],
            warnings: [],
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
                $productName = (string) ($r['product_name'] ?? '');
                $brand       = (string) ($r['brand_name']   ?? '');
                $imageUrl    = (string) ($r['image_full_url'] ?? '');
                $presented   = $r['presented_price'] ?? null;
                $offer       = $r['offer_price']     ?? null;

                $bySku[$sku]['data'] = [
                    // ── GS API native names (what the editor probe shows) ──
                    'sku'              => $sku,
                    'product_name'     => $productName,
                    'brand_name'       => $brand,
                    'size_mapper_name' => (string) ($r['size_mapper_name'] ?? ''),
                    'image_full_url'   => $imageUrl,
                    'image_name'       => (string) ($r['image_name'] ?? ''),
                    'presented_price'  => $presented,
                    'offer_price'      => $offer,
                    'sizes'            => [],
                    'summary_qty'      => 0,

                    // ── Bridge-compatible aliases (legacy materialize) ──
                    // The legacy rp_rc_gs_apply expects these field names
                    // verbatim. Duplicating them here means the bridge
                    // keeps working without any changes on its side.
                    'name'             => $productName,
                    'brand'            => $brand,
                    'model'            => '',
                    'image_url'        => $imageUrl,
                    'type'             => 'variable',
                    'status'           => 'publish',
                    '_gs_brand'        => $brand,
                    '_gs_model'        => '',
                    '_gs_image_url'    => $imageUrl,
                    '_gs_tag'          => 'super-sale',

                    // ── Woo-shaped (consumed by import-rules + UI) ──
                    'regular_price'    => $presented !== null ? (string) $presented : '',
                    'sale_price'       => $offer     !== null ? (string) $offer     : '',
                    'manage_stock'     => true,
                ];
                $bySku[$sku]['raw'] = [];
            }
            $bySku[$sku]['raw'][] = $r;

            $sizeEu = trim((string) ($r['size_eu'] ?? ''));
            if ($sizeEu === '') continue; // no size = nothing to aggregate
            $qty = (int) ($r['available_quantity'] ?? 0);
            $bySku[$sku]['data']['sizes'][] = [
                'size_eu'            => $sizeEu,
                'size_us'            => (string) ($r['size_us'] ?? ''),
                'available_quantity' => $qty,
                'barcode'            => (string) ($r['barcode'] ?? ''),
                // Bridge-compatible per-size pricing (legacy expects
                // offer_price + presented_price ON each size record).
                'offer_price'        => $r['offer_price']     ?? null,
                'presented_price'    => $r['presented_price'] ?? null,
            ];
            $bySku[$sku]['data']['summary_qty'] += $qty;
        }

        $items = [];
        foreach ($bySku as $sku => $bundle) {
            $bundle['data']['stock_status']   = $bundle['data']['summary_qty'] > 0 ? 'instock' : 'outofstock';
            $bundle['data']['stock_quantity'] = $bundle['data']['summary_qty'];
            // Bridge-compatible aggregate stock field name.
            $bundle['data']['total_available'] = $bundle['data']['summary_qty'];
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
        // Self-contained diff: just check SKU existence in Woo. Same
        // approach as CsvSource — a feed item is `update` if its SKU
        // already exists, `new` otherwise. Refinement into update vs
        // updateStock happens in StockOnlyClassifier::split which
        // compares per-field against the existing product.
        $new = $update = [];
        $skuLookup = function_exists('wc_get_product_id_by_sku');

        foreach ($items as $item) {
            if (! $item instanceof FeedItem) continue;
            if ($item->sku === '') {
                $new[] = $item;
                continue;
            }
            $existingId = $skuLookup ? (int) \wc_get_product_id_by_sku($item->sku) : 0;
            if ($existingId > 0) {
                $update[] = new FeedItem(
                    sku:  $item->sku,
                    data: $item->data + ['_existing_id' => $existingId],
                    raw:  $item->raw,
                );
            } else {
                $new[] = $item;
            }
        }

        [ $updateFull, $updateStock ] = StockOnlyClassifier::split($update);

        return new Diff(
            new: $new,
            update: $updateFull,
            unchanged: [],
            updateStock: $updateStock,
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

}
