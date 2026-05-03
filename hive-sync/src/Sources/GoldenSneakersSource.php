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
                $bySku[$sku] = [
                    'sku'              => $sku,
                    'product_name'     => (string) ($r['product_name'] ?? ''),
                    'brand_name'       => (string) ($r['brand_name']   ?? ''),
                    'size_mapper_name' => (string) ($r['size_mapper_name'] ?? ''),
                    'image_full_url'   => (string) ($r['image_full_url'] ?? ''),
                    'image_name'       => (string) ($r['image_name'] ?? ''),
                    'sizes'            => [],
                    '_raw_rows'        => [],
                ];
            }
            $bySku[$sku]['_raw_rows'][] = $r;

            $sizeEu = trim((string) ($r['size_eu'] ?? ''));
            if ($sizeEu === '') continue;
            $bySku[$sku]['sizes'][] = [
                'size_eu'            => $sizeEu,
                'size_us'            => (string) ($r['size_us'] ?? ''),
                'available_quantity' => (int) ($r['available_quantity'] ?? 0),
                'barcode'            => (string) ($r['barcode'] ?? ''),
                'offer_price'        => (float) ($r['offer_price']     ?? 0),
                'presented_price'    => (float) ($r['presented_price'] ?? 0),
            ];
        }

        $items = [];
        foreach ($bySku as $sku => $bundle) {
            $rawRows = $bundle['_raw_rows'];
            unset($bundle['_raw_rows']);

            // Re-key the bundle into the shape rp_rc_gs_normalize would
            // have produced, then run the legacy transform inline so the
            // bridge's create_product / update_product receive the
            // exact shape they were written against (type, attributes,
            // variations[], stock_quantity per variant, etc.).
            $bridgeProduct = [
                'sku'        => $sku,
                'name'       => $bundle['product_name'],
                'brand'      => $bundle['brand_name'],
                'model'      => '',
                'image_url'  => $bundle['image_full_url'],
                'image_name' => $bundle['image_name'],
                'sizes'      => $bundle['sizes'],
            ];
            $woo = self::transformToWoo( $bridgeProduct );

            // Add the original API-native fields back on top so the
            // mapping editor's autocomplete still surfaces the upstream
            // names (product_name, presented_price, ...).
            $woo['product_name']     = $bundle['product_name'];
            $woo['brand_name']       = $bundle['brand_name'];
            $woo['size_mapper_name'] = $bundle['size_mapper_name'];
            $woo['image_full_url']   = $bundle['image_full_url'];
            $woo['image_name']       = $bundle['image_name'];
            $woo['summary_qty']      = (int) array_sum( array_column( $bundle['sizes'], 'available_quantity' ) );

            $items[] = new FeedItem(
                sku:  (string) $sku,
                data: $woo,
                raw:  $rawRows,
            );
        }
        return $items;
    }

    /**
     * Port of golden-hive's rp_rc_gs_transform_to_woo (feed-goldensneakers.php
     * line 180). Produces the EXACT shape the bridge's
     * rp_rc_gs_create_product / rp_rc_gs_update_product reads from:
     *
     *   - type:           'simple' when 0/1 sizes, 'variable' otherwise
     *   - status:         'publish'
     *   - _gs_brand / _gs_model / _gs_image_url / _gs_tag / _gs_category
     *   - For 'simple':   regular_price / sale_price / stock_quantity /
     *                     stock_status / manage_stock / attributes:[pa_brand]
     *   - For 'variable': attributes:[pa_taglia + pa_brand] +
     *                     variations:[{attributes, sku, regular_price,
     *                                  sale_price, stock_quantity, ...}]
     *
     * Without this transform the bridge sees only raw aggregated data,
     * defaults to creating a `simple` product with no attributes and
     * empty stock — exactly the symptom (out-of-stock, no variants)
     * the operator reported.
     *
     * @param array<string, mixed> $product  Aggregated GS product
     * @return array<string, mixed>          Bridge-ready Woo shape
     */
    private static function transformToWoo( array $product ): array
    {
        $sizes     = (array) ( $product['sizes'] ?? [] );
        $allEu     = array_column( $sizes, 'size_eu' );
        $hasSizes  = count( $sizes ) > 1
            || ( count( $sizes ) === 1 && ! empty( $sizes[0]['size_eu'] ) );
        $type      = $hasSizes ? 'variable' : 'simple';

        // Sneakers-vs-apparel heuristic mirrored from the legacy
        // transform: alphabetic size labels (S, M, L, XL) → apparel.
        $gsCategory = 'sneakers';
        if ( ! empty( $allEu ) ) {
            $alphaCount = 0;
            foreach ( $allEu as $sz ) {
                if ( preg_match( '/^[A-Z]{1,3}(\/[A-Z]{1,3})?$/i', trim( (string) $sz ) ) ) {
                    $alphaCount++;
                }
            }
            if ( $alphaCount > count( $allEu ) / 2 ) $gsCategory = 'abbigliamento';
        }

        $woo = [
            'name'              => (string) ( $product['name'] ?? '' ),
            'sku'               => (string) ( $product['sku']  ?? '' ),
            'type'              => $type,
            'status'            => 'publish',
            '_gs_brand'         => (string) ( $product['brand']     ?? '' ),
            '_gs_model'         => (string) ( $product['model']     ?? '' ),
            '_gs_image_url'     => (string) ( $product['image_url'] ?? '' ),
            '_gs_tag'           => 'super-sale',
            '_gs_category'      => $gsCategory,
        ];

        $brand     = (string) ( $product['brand'] ?? '' );
        $brandAttr = $brand !== ''
            ? [ 'pa_brand' => [ 'options' => [ $brand ], 'visible' => true, 'variation' => false ] ]
            : [];

        if ( $type === 'simple' ) {
            $base = (float) ( $sizes[0]['presented_price'] ?? 0 );
            $woo['regular_price'] = (string) round( $base );
            $woo['sale_price']    = '';
            $qty                  = (int) ( $sizes[0]['available_quantity'] ?? 0 );
            $woo['manage_stock']   = true;
            $woo['stock_quantity'] = $qty;
            $woo['stock_status']   = $qty > 0 ? 'instock' : 'outofstock';
            if ( $brandAttr ) $woo['attributes'] = $brandAttr;
            return $woo;
        }

        // Variable product: build pa_taglia attribute + per-size variants.
        $woo['attributes'] = [
            'pa_taglia' => [ 'options' => array_values( array_unique( $allEu ) ), 'visible' => true, 'variation' => true ],
        ] + $brandAttr;

        $variations = [];
        $totalQty   = 0;
        foreach ( $sizes as $size ) {
            $pp  = (float) ( $size['presented_price']    ?? 0 );
            $qty = (int)   ( $size['available_quantity'] ?? 0 );
            $totalQty += $qty;
            $variations[] = [
                'attributes'     => [ 'pa_taglia' => (string) ( $size['size_eu'] ?? '' ) ],
                'sku'            => (string) $woo['sku'] . '-EU' . (string) ( $size['size_eu'] ?? '' ),
                'manage_stock'   => true,
                'stock_quantity' => $qty,
                'stock_status'   => $qty > 0 ? 'instock' : 'outofstock',
                'status'         => 'publish',
                'regular_price'  => (string) round( $pp ),
                'sale_price'     => '',
            ];
        }
        $woo['variations']     = $variations;
        // Parent-level stock summary so the bridge's update path can
        // detect stock-only changes against the existing parent.
        $woo['manage_stock']   = false;
        $woo['stock_quantity'] = $totalQty;
        $woo['stock_status']   = $totalQty > 0 ? 'instock' : 'outofstock';
        return $woo;
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
