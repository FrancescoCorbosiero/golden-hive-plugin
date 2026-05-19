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
            'import_status' => [
                'type'    => 'enum',
                'label'   => 'Stato iniziale dei prodotti importati',
                'options' => [ 'publish', 'draft' ],
                'option_labels' => [
                    'publish' => 'Pubblicato — visibile sul sito appena importato',
                    'draft'   => 'Bozza — invisibile finché non lo pubblichi (serve una Regola)',
                ],
                'default' => 'publish',
                'description' => 'Imposta "Bozza" se vuoi importare in staging e poi attivare i prodotti a gruppi (per categoria/brand) tramite una Regola periodica nel tab Regole. Si applica solo ai NUOVI prodotti — quelli esistenti mantengono il loro stato.',
            ],
            'markup_percent' => [
                'type'    => 'int',
                'label'   => 'Markup percentuale di fallback',
                'default' => 0,
                'description' => 'Es. 20 = +20%, -10 = -10% (sconto). Applicato a TUTTI i prodotti che non matchano una regola in "Markup per categoria/brand". Idempotente — il prezzo Woo finale è sempre `prezzo_feed × (1+pct/100)`, non si accumula.',
            ],
            'markup_rules' => [
                'type'    => 'markup_rules',
                'label'   => 'Markup per categoria/brand/etc.',
                'default' => [],
                'description' => 'Sovrascrivi il markup di fallback per sottoinsiemi del catalogo. Esempio: brand "Nike" → 40%, categoria "abbigliamento" → 20%. Le regole sono valutate dall\'alto verso il basso, prima match vince. Campi tipici: per GS `_gs_brand` / `_gs_category`, per SF `_sf_brand` / `_sf_category` / `_sf_subcategory`. Idempotente come il fallback.',
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

        // Retry on transient HTTP failures (5xx / 408 / 429 / WP_Error
        // / 200-empty-body). After retries are exhausted the helper
        // throws TransientSourceException → propagates to ImportRunner
        // → AJAX returns recoverable:true → JS tick loop retries from
        // the same cursor. Without this, a single mid-import network
        // blip drops the unprocessed tail of the feed.
        $r = self::httpGetWithRetries($url, [
            'headers'    => $headers,
            'user-agent' => 'HiveSync/' . (defined('HSYNC_VERSION') ? HSYNC_VERSION : '1.0'),
        ]);
        $code = $r['code'];
        $body = $r['body'];
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

        // Honor the import_status knob: stamp the desired creation
        // status onto each FeedItem.data so the host bridge / Woo
        // factory pick it up on CREATE. Existing products keep their
        // current status (the bridge doesn't touch it on update paths).
        // 'publish' is the historical default — pre-existing configs
        // that don't carry the knob keep the old behavior.
        $importStatus = (string) ($cfg['import_status'] ?? 'publish');
        if (! in_array($importStatus, ['publish', 'draft'], true)) {
            $importStatus = 'publish';
        }

        // Markup: per-rule lookup against feed fields, falling back to
        // a flat `markup_percent`. Multiplied into the feed price
        // IN-source so every downstream path (new / update /
        // fast-stock-patch) sees the same final price. Idempotent
        // because input is always raw feed → output is always
        // feed*(1+pct), no compounding across runs.
        $markupFallbackPct = (float) ($cfg['markup_percent'] ?? 0);
        $markupRules       = MarkupResolver::normalize($cfg['markup_rules'] ?? []);

        $items = $flavor === self::FLAVOR_GS
            ? self::aggregateFlatRows($rawRows, $importStatus, $markupRules, $markupFallbackPct)
            : self::wrapRows($rawRows, $importStatus, $markupRules, $markupFallbackPct);

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
                // Mapped `pa_*` keys (brand/model/gender/color/material/...)
                // are promoted into $data['attributes'] so the bridge
                // wires them as Woo product attributes instead of leaving
                // them as orphan top-level scalars.
                AttributeMerger::promoteFromDraft($newData);
                $remapped[] = new FeedItem(
                    sku:  $item->sku,
                    data: $newData,
                    raw:  $item->raw,
                );
            }
            $items = $remapped;
        } else {
            // Even without an operator-supplied mapping, the GS/SF
            // transforms may surface pa_* keys via the bundled product
            // shape — promote them so attributes stay in sync.
            $promoted = [];
            foreach ($items as $item) {
                $data = $item->data;
                AttributeMerger::promoteFromDraft($data);
                $promoted[] = $data === $item->data
                    ? $item
                    : new FeedItem(sku: $item->sku, data: $data, raw: $item->raw);
            }
            $items = $promoted;
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
    private static function wrapRows(array $rows, string $importStatus = 'publish', array $markupRules = [], float $markupFallbackPct = 0.0): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $sku = (string) ($r['sku'] ?? '');
            if ($sku === '') continue;
            // Default the status on rows that don't declare one. Mapped
            // configs that explicitly set `status` win — the operator
            // staying in control of per-item overrides.
            if (! isset($r['status']) || $r['status'] === '') {
                $r['status'] = $importStatus;
            }
            // Resolve markup against rules (first match wins, fallback
            // otherwise) using the row's own data. Skipped when
            // multiplier collapses to 1.0 (no-op).
            $multiplier = MarkupResolver::multiplierFor($r, $markupRules, $markupFallbackPct);
            if ($multiplier !== 1.0) {
                foreach (['regular_price', 'sale_price', 'price'] as $field) {
                    if (isset($r[$field]) && is_numeric($r[$field])) {
                        $r[$field] = (string) round((float) $r[$field] * $multiplier, 2);
                    }
                }
            }
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
    private static function aggregateFlatRows(array $rows, string $importStatus = 'publish', array $markupRules = [], float $markupFallbackPct = 0.0): array
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
            // Resolve markup against the bridgeProduct (carries
            // _gs_brand, _gs_category, ...) so rules can target GS
            // taxonomy without tunneling. Per-product, evaluated once.
            $multiplier = MarkupResolver::multiplierFor($bridgeProduct, $markupRules, $markupFallbackPct);
            $woo = self::transformToWoo($bridgeProduct, $importStatus, $multiplier);

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
    private static function transformToWoo(array $product, string $importStatus = 'publish', float $multiplier = 1.0): array
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
            'status'            => $importStatus,
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
            $woo['regular_price'] = (string) round($base * $multiplier);
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
                // ALWAYS publish — `product_variation` post type only
                // accepts publish/private/trash. status='draft' makes
                // the data store silently drop the save and the
                // variable parent ends up with zero children (visible
                // in WC admin as "Ancora nessuna variante"). The
                // user's draft preference applies to the PARENT only;
                // storefront visibility is gated by the parent.
                'status'         => 'publish',
                'regular_price'  => (string) round($pp * $multiplier),
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

        // Batch SKU → pid lookup. Replaces an O(N) loop of
        // wc_get_product_id_by_sku() calls (each ~5ms) with one SQL
        // round-trip. Required to keep diff under the 25s tick budget
        // for catalogs >2k items.
        $skus = [];
        foreach ($items as $item) {
            if ($item instanceof FeedItem && $item->sku !== '') $skus[] = $item->sku;
        }
        $existingMap = SkuLookup::mapSkusToIds($skus);

        foreach ($items as $item) {
            if (! $item instanceof FeedItem) continue;
            if ($item->sku === '') {
                $new[] = $item;
                continue;
            }
            $existingId = (int) ($existingMap[$item->sku] ?? 0);
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

        // 3-way split: every existing SKU is one of unchanged / stock
        // / full. Without the `unchanged` path, the diff was reporting
        // every existing item as work to do on every run — the
        // "4 full / 435 stock at each run with no actual feed change"
        // symptom. The classifier now compares per-variation stock
        // against Woo (via batched VariationLookup) so a stable feed
        // produces an empty update bucket and zero writes.
        [$updateFull, $updateStock, $unchanged] = StockOnlyClassifier::split($update, $ctx);

        return new Diff(
            new: $new,
            update: $updateFull,
            unchanged: $unchanged,
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
        return match ($action) {
            'updated'   => MaterializeResult::updated($pid, $r),
            'recreated' => MaterializeResult::recreated($pid, $r),
            default     => MaterializeResult::created($pid, $r),
        };
    }
}
