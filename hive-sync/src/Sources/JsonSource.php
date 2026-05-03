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
 * Generic JSON feed source — fetches a URL with optional Bearer / Cookie
 * auth, parses the response, and yields one FeedItem per product.
 *
 * Two response handling modes (`flavor` config):
 *
 *   - `generic` (default) — each row in the response IS one product.
 *     Pass-through; the user's mapping config translates field names
 *     to the Woo shape.
 *
 *   - `goldensneakers` — the upstream API ships ONE ROW PER (sku +
 *     size). Group rows by SKU and apply the legacy GS transform so
 *     the bridge's rp_rc_gs_create_product / _update_product receives
 *     the exact (type / attributes / variations) shape it expects.
 *
 * Materialize routes by flavor too: `goldensneakers` flavor uses the
 * legacy bridge filter (preserves the variant + media-sideload logic
 * that's already proven), `generic` falls back to the generic
 * hsync_upsert_product host adapter — that path is stub-level today
 * but works for plain "create/update simple product by SKU" flows.
 */
final class JsonSource extends AbstractSource
{
    public const ID = 'json';

    public const FLAVOR_GENERIC = 'generic';
    public const FLAVOR_GS      = 'goldensneakers';

    public function id(): string { return self::ID; }
    public function label(): string { return 'JSON — Feed da URL (Bearer / Cookie auth)'; }

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
        return [
            'url' => [
                'type'     => 'url',
                'label'    => 'API URL',
                'required' => true,
                'max'      => 4096,
            ],
            'token' => [
                'type'     => 'secret',
                'label'    => 'Bearer token (opzionale)',
                'required' => false,
                'max'      => 8192,
            ],
            'cookie' => [
                'type'     => 'secret',
                'label'    => 'Cookie (opzionale)',
                'required' => false,
                'max'      => 16384,
            ],
            'flavor' => [
                'type'    => 'enum',
                'label'   => 'Formato del feed JSON',
                'options' => [ self::FLAVOR_GENERIC, self::FLAVOR_GS ],
                'option_labels' => [
                    self::FLAVOR_GENERIC => 'Generico — 1 elemento JSON = 1 prodotto',
                    self::FLAVOR_GS      => 'Golden Sneakers — 1 elemento = 1 taglia (raggruppa per SKU)',
                ],
                'default' => self::FLAVOR_GENERIC,
                'description' => 'Lascia "Generico" se il tuo feed restituisce una lista normale di prodotti. Scegli "Golden Sneakers" SOLO per il feed GS, dove ogni riga rappresenta una singola taglia di un prodotto e va raggruppata.',
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
                warnings: ['Config invalida.'],
            );
        }
        $cfg = $val['config'];

        $url    = (string) ($cfg['url']    ?? '');
        $token  = (string) ($cfg['token']  ?? '');
        $cookie = (string) ($cfg['cookie'] ?? '');
        $flavor = (string) ($cfg['flavor'] ?? self::FLAVOR_GENERIC);
        if ($url === '') {
            return new FetchResult(items: [], warnings: ['URL mancante nella config.']);
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
            return new FetchResult(items: [], warnings: ["HTTP $code dalla sorgente JSON."]);
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return new FetchResult(items: [], warnings: ['Risposta non è JSON valido.']);
        }

        // Accept top-level array OR common wrapper keys.
        $rawRows = $decoded;
        foreach (['data', 'items', 'results', 'products'] as $k) {
            if (isset($rawRows[$k]) && is_array($rawRows[$k])) {
                $rawRows = $rawRows[$k];
                break;
            }
        }
        if (! array_is_list($rawRows)) {
            return new FetchResult(items: [], warnings: ['Risposta JSON: lista di righe non rilevata.']);
        }

        $items = $flavor === self::FLAVOR_GS
            ? self::aggregateFlatRows($rawRows)
            : self::wrapRows($rawRows);

        // Apply the user's mapping (if any) AFTER fetch. OVERLAY
        // semantics: mapped output is merged ON TOP so source-native
        // keys remain available for downstream code that expects them
        // (e.g. the GS bridge looks for _gs_brand, sizes[], etc.).
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
     * Generic mode: each row is one product. SKU is required for diff +
     * dedup; rows missing it are dropped with a warning (collected in
     * stats but the import keeps going).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return FeedItem[]
     */
    private static function wrapRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $sku = (string) ($r['sku'] ?? '');
            if ($sku === '') continue;
            $out[] = new FeedItem(sku: $sku, data: $r, raw: $r);
        }
        return $out;
    }

    /**
     * Golden Sneakers mode: group flat rows by SKU. The aggregated data
     * carries the API-native field names (product_name, brand_name,
     * presented_price, ...) AND runs through the legacy
     * rp_rc_gs_transform_to_woo so the bridge's create/update receives
     * the variants + attributes shape it was written against.
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

            $bridgeProduct = [
                'sku'        => $sku,
                'name'       => $bundle['product_name'],
                'brand'      => $bundle['brand_name'],
                'model'      => '',
                'image_url'  => $bundle['image_full_url'],
                'image_name' => $bundle['image_name'],
                'sizes'      => $bundle['sizes'],
            ];
            $woo = self::transformToWoo($bridgeProduct);

            // Surface the API-native field names back on top so the
            // mapping editor's "Anteprima sorgente" probe shows them.
            $woo['product_name']     = $bundle['product_name'];
            $woo['brand_name']       = $bundle['brand_name'];
            $woo['size_mapper_name'] = $bundle['size_mapper_name'];
            $woo['image_full_url']   = $bundle['image_full_url'];
            $woo['image_name']       = $bundle['image_name'];
            $woo['summary_qty']      = (int) array_sum(array_column($bundle['sizes'], 'available_quantity'));

            $items[] = new FeedItem(
                sku:  (string) $sku,
                data: $woo,
                raw:  $rawRows,
            );
        }
        return $items;
    }

    /**
     * Port of golden-hive's rp_rc_gs_transform_to_woo. Produces the
     * exact (type / attributes / variations) shape the legacy GS
     * bridge expects. Without this the bridge silently creates simple
     * out-of-stock products with no variants.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private static function transformToWoo(array $product): array
    {
        $sizes    = (array) ($product['sizes'] ?? []);
        $allEu    = array_column($sizes, 'size_eu');
        $hasSizes = count($sizes) > 1
            || (count($sizes) === 1 && ! empty($sizes[0]['size_eu']));
        $type     = $hasSizes ? 'variable' : 'simple';

        $gsCategory = 'sneakers';
        if (! empty($allEu)) {
            $alphaCount = 0;
            foreach ($allEu as $sz) {
                if (preg_match('/^[A-Z]{1,3}(\/[A-Z]{1,3})?$/i', trim((string) $sz))) {
                    $alphaCount++;
                }
            }
            if ($alphaCount > count($allEu) / 2) $gsCategory = 'abbigliamento';
        }

        $woo = [
            'name'              => (string) ($product['name'] ?? ''),
            'sku'               => (string) ($product['sku']  ?? ''),
            'type'              => $type,
            'status'            => 'publish',
            '_gs_brand'         => (string) ($product['brand']     ?? ''),
            '_gs_model'         => (string) ($product['model']     ?? ''),
            '_gs_image_url'     => (string) ($product['image_url'] ?? ''),
            '_gs_tag'           => 'super-sale',
            '_gs_category'      => $gsCategory,
        ];

        $brand     = (string) ($product['brand'] ?? '');
        $brandAttr = $brand !== ''
            ? ['pa_brand' => ['options' => [$brand], 'visible' => true, 'variation' => false]]
            : [];

        if ($type === 'simple') {
            $base = (float) ($sizes[0]['presented_price'] ?? 0);
            $woo['regular_price'] = (string) round($base);
            $woo['sale_price']    = '';
            $qty                  = (int) ($sizes[0]['available_quantity'] ?? 0);
            $woo['manage_stock']   = true;
            $woo['stock_quantity'] = $qty;
            $woo['stock_status']   = $qty > 0 ? 'instock' : 'outofstock';
            if ($brandAttr) $woo['attributes'] = $brandAttr;
            return $woo;
        }

        $woo['attributes'] = [
            'pa_taglia' => ['options' => array_values(array_unique($allEu)), 'visible' => true, 'variation' => true],
        ] + $brandAttr;

        $variations = [];
        $totalQty   = 0;
        foreach ($sizes as $size) {
            $pp  = (float) ($size['presented_price']    ?? 0);
            $qty = (int)   ($size['available_quantity'] ?? 0);
            $totalQty += $qty;
            $variations[] = [
                'attributes'     => ['pa_taglia' => (string) ($size['size_eu'] ?? '')],
                'sku'            => (string) $woo['sku'] . '-EU' . (string) ($size['size_eu'] ?? ''),
                'manage_stock'   => true,
                'stock_quantity' => $qty,
                'stock_status'   => $qty > 0 ? 'instock' : 'outofstock',
                'status'         => 'publish',
                'regular_price'  => (string) round($pp),
                'sale_price'     => '',
            ];
        }
        $woo['variations']     = $variations;
        $woo['manage_stock']   = false;
        $woo['stock_quantity'] = $totalQty;
        $woo['stock_status']   = $totalQty > 0 ? 'instock' : 'outofstock';
        return $woo;
    }

    public function diff(array $items, Context $ctx): Diff
    {
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

        [$updateFull, $updateStock] = StockOnlyClassifier::split($update);

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

        // Pick the materialize path by flavor. The GS flavor goes
        // through the legacy bridge filter (which knows variants +
        // sideload). The generic flavor uses hsync_upsert_product
        // (host adapter) — currently a stub but the right shape for
        // future generic JSON feeds.
        $flavor = (string) ($item->data['_hsync_flavor'] ?? self::FLAVOR_GENERIC);
        // Heuristic for legacy items missing the marker: if the data
        // carries _gs_brand it's the GS-transformed shape, route there.
        if ($flavor !== self::FLAVOR_GS && isset($item->data['_gs_brand'])) {
            $flavor = self::FLAVOR_GS;
        }

        if ($flavor === self::FLAVOR_GS) {
            if (! function_exists('hsync_gs_materialize')) {
                return MaterializeResult::failed('Bridge GS non disponibile.');
            }
            $sideload = (bool) ($ctx->meta['sideload'] ?? true);
            try {
                $r = \hsync_gs_materialize($item->data, false, $sideload);
            } catch (\Throwable $e) {
                return MaterializeResult::failed($e->getMessage());
            }
            return self::interpretBridgeResponse($r);
        }

        // Generic path — host adapter does the actual upsert.
        if (! function_exists('hsync_upsert_product')) {
            return MaterializeResult::failed('Host adapter generico non disponibile.');
        }
        try {
            $pid = \hsync_upsert_product($item->data, ['sku' => $item->sku]);
        } catch (\Throwable $e) {
            return MaterializeResult::failed($e->getMessage());
        }
        if ($pid === null || $pid <= 0) {
            return MaterializeResult::failed('upsert_returned_no_id');
        }
        // Without a richer return contract we can't tell created vs
        // updated — assume "updated" when the SKU was already present
        // (the diff would have classified it; we re-check here).
        $existed = function_exists('wc_get_product_id_by_sku')
            ? (int) \wc_get_product_id_by_sku($item->sku) === $pid && ! empty($item->data['_existing_id'])
            : ! empty($item->data['_existing_id']);
        return $existed
            ? MaterializeResult::updated($pid)
            : MaterializeResult::created($pid);
    }

    /**
     * @param mixed $r
     */
    private static function interpretBridgeResponse($r): MaterializeResult
    {
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
