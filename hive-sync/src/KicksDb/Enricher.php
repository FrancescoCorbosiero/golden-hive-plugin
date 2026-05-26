<?php
declare(strict_types=1);

namespace HiveSync\KicksDb;

use HiveSync\Core\Repo\KicksDbCacheRepository;

/**
 * Merges a KicksDB product payload into a feed draft. Single source
 * of truth for the GS↔KicksDB precedence rules — used by both the
 * EnrichWithKicksDb ImportRule (running inside the GS pipeline) and
 * KicksDbSource (standalone discovery).
 *
 * Precedence (agreed with the operator):
 *
 *   - Product-level fields (description, brand, model, colorway,
 *     gallery, gender, material, release date): KicksDB WINS where it
 *     has a value, because the KicksDB catalog is far richer than what
 *     GS exposes. GS values stay as fallback only.
 *
 *   - Variant list: KicksDB provides the full size run (with market
 *     price + synthetic stock). GS variants overlay on matching sizes
 *     (by pa_taglia) with real price + real stock + sale-price
 *     semantics — the "super-sale" UX.
 *
 *   - Parent regular_price: irrelevant for variable products. Woo
 *     computes the price range from variations.
 *
 * Per-variation sale pricing:
 *
 *   - GS-covered variant:  regular_price = KicksDB market × tiered
 *                          markup × VAT, sale_price = GS feed price
 *                          (already marked up by the GS source).
 *                          Customer sees "€market crossed out, €gs now".
 *
 *   - KicksDB-only variant: regular_price = KicksDB market × tiered
 *                          markup × VAT, sale_price = (empty),
 *                          stock = synthetic by price tier.
 *
 * Cache-first: lookups hit wp_hsync_kicksdb_cache by (sku, market);
 * misses call the API and write back with TTL. Negative-cached on
 * miss so unknown SKUs don't hammer the API every re-sync.
 *
 * Idempotent by construction: same payload + same draft → same merged
 * output. No reads from Woo, no random tie-breakers. Re-runs on a
 * stable feed produce zero variation churn.
 */
final class Enricher
{
    public function __construct(
        private readonly Client $client,
        private readonly KicksDbCacheRepository $cache,
        private readonly MarkupCalculator $calc,
        private readonly string $market = 'IT',
        private readonly int $cacheTtl = 86400,
    ) {}

    /**
     * Lookup KicksDB for a SKU using cache-first. Returns null on cold
     * miss + API failure (caller treats as "no enrichment available").
     *
     * @return array|null  raw KicksDB product payload, or null
     */
    public function lookup( string $sku ): ?array
    {
        if ( $sku === '' ) return null;
        $hit = $this->cache->get( $sku, $this->market );
        if ( $hit !== null ) {
            return empty( $hit['_miss'] ) ? $hit : null;
        }
        if ( ! $this->client->isConfigured() ) return null;
        $payload = $this->client->getProduct( $sku );
        if ( $payload === null ) {
            // Negative-cache (short TTL): dampens the API for unknown
            // SKUs in a single re-sync cycle without holding a stale
            // "not found" verdict forever.
            $this->cache->put( $sku, $this->market, [ '_miss' => true ], min( 3600, $this->cacheTtl ) );
            return null;
        }
        $this->cache->put( $sku, $this->market, $payload, $this->cacheTtl );
        return $payload;
    }

    /**
     * Merge a KicksDB payload into a draft. Mutates and returns the
     * draft (idempotent: re-running with the same inputs is a no-op
     * on the merged output).
     *
     * @param array  $draft   feed draft (e.g. GS item.data), may be empty
     * @param array  $payload raw KicksDB payload from lookup()
     * @param string $mode    'overwrite' (default) — KicksDB wins for
     *                        every non-empty product-level field. Used
     *                        by the GS-pipeline ImportRule, where the
     *                        GS draft is mostly placeholder.
     *                        'preserve' — KicksDB only fills draft
     *                        slots that are empty. Used by the Woo-
     *                        catalog discovery mode so a hand-curated
     *                        description/gallery on an existing product
     *                        is not clobbered. Variants are merged
     *                        unconditionally in both modes — the whole
     *                        point of the integration is to overlay
     *                        the full size run + tiered pricing.
     */
    public function merge( array $draft, array $payload, string $mode = 'overwrite' ): array
    {
        if ( ! is_array( $payload ) || ! empty( $payload['_miss'] ) ) return $draft;

        $preserve = $mode === 'preserve';

        // ─── Product-level fields ───────────────────────────────────
        // KicksDB wins where non-empty UNLESS preserve mode is on (in
        // which case it only fills empty draft slots). GS fallback is
        // implicit — the GS-derived draft just stays untouched.
        $title     = self::firstNonEmpty( $payload['title'] ?? null, $payload['name'] ?? null, $payload['product_name'] ?? null );
        $brand     = self::firstNonEmpty( $payload['brand'] ?? null, $payload['brand_name'] ?? null );
        $colorway  = self::firstNonEmpty( $payload['colorway'] ?? null, $payload['color'] ?? null, $payload['traits']['colorway'] ?? null );
        $gender    = self::firstNonEmpty( $payload['gender'] ?? null, $payload['traits']['gender'] ?? null );
        $material  = self::firstNonEmpty( $payload['material'] ?? null, $payload['traits']['material'] ?? null );
        $releaseDt = self::firstNonEmpty( $payload['release_date'] ?? null, $payload['traits']['release_date'] ?? null );
        $images    = self::extractImages( $payload );

        $set = function ( string $key, string $value ) use ( &$draft, $preserve ) {
            if ( $value === '' ) return;
            if ( $preserve && ! empty( $draft[ $key ] ) ) return;
            $draft[ $key ] = $value;
        };

        $set( 'name', $title );
        if ( $brand !== '' ) {
            $set( 'brand', $brand );
            $set( 'pa_brand', $brand );
        }
        $set( 'pa_model', $title );
        $set( 'pa_color', $colorway );
        $set( 'pa_gender', $gender );
        $set( 'pa_material', $material );
        $set( 'pa_release_date', $releaseDt );

        if ( ! empty( $images ) ) {
            // In preserve mode, leave the operator's chosen feature image
            // alone. Otherwise overwrite with the KicksDB hero.
            if ( ! $preserve || empty( $draft['image_url'] ) ) {
                $draft['image_url'] = $images[0];
            }
            if ( ! $preserve || empty( $draft['featured_image'] ) ) {
                $draft['featured_image'] = $images[0];
            }
            // In preserve mode, don't append to a non-empty gallery — the
            // operator may have curated it; KicksDB additions would feel
            // arbitrary. Overwrite mode merges (union, dedup).
            if ( ! $preserve || empty( $draft['gallery_urls'] ) ) {
                $draft['gallery_urls'] = array_values( array_unique( array_merge(
                    (array) ( $draft['gallery_urls'] ?? [] ),
                    $images,
                ) ) );
            }
        }

        // Description fields: these were already preserve-by-default
        // (only filled empties) before the mode flag existed — keep
        // that behaviour for both modes.
        if ( empty( $draft['description'] ) && ! empty( $payload['description'] ) ) {
            $draft['description'] = (string) $payload['description'];
        }
        if ( empty( $draft['short_description'] ) && ! empty( $payload['short_description'] ) ) {
            $draft['short_description'] = (string) $payload['short_description'];
        }

        // ─── Variant merge ──────────────────────────────────────────
        // ALWAYS run regardless of mode — the full size run + tiered
        // pricing are the value the integration brings.
        $draft['variations'] = $this->mergeVariations( $draft, $payload );
        $draft['type']       = 'variable';

        // Declare pa_taglia as the variation attribute with the union
        // of all sizes so the materialize bridge wires variations to
        // the parent correctly. AttributeMerger handles the other pa_*
        // promotions further down the pipeline.
        $sizes = [];
        foreach ( $draft['variations'] as $v ) {
            $taglia = (string) ( $v['attributes']['pa_taglia'] ?? '' );
            if ( $taglia !== '' && ! in_array( $taglia, $sizes, true ) ) $sizes[] = $taglia;
        }
        if ( $sizes ) {
            $draft['attributes']              = (array) ( $draft['attributes'] ?? [] );
            $draft['attributes']['pa_taglia'] = [
                'options'   => $sizes,
                'visible'   => true,
                'variation' => true,
            ];
        }

        // Trace markers — read by the Storico tab and the run shape
        // diagnostic. Never written to Woo product meta.
        $draft['_kicksdb_enriched'] = true;
        $draft['_kicksdb_sku']      = (string) ( $payload['sku'] ?? $payload['style_id'] ?? '' );

        return $draft;
    }

    /**
     * Build the merged variation list.
     *
     * Walk every KicksDB variant — if a GS overlay exists for that
     * size, emit regular_price = KicksDB market (markup+VAT) and
     * sale_price = GS price. Otherwise emit regular_price = KicksDB
     * market, no sale, synthetic stock.
     *
     * Then append GS-only sizes (KicksDB doesn't list them — rare).
     *
     * Stable ordering by numeric size so the variation dropdown is
     * predictable.
     *
     * @return array<int, array>  Woo-shaped variations
     */
    private function mergeVariations( array $draft, array $payload ): array
    {
        $gsBySize = [];
        foreach ( (array) ( $draft['variations'] ?? [] ) as $v ) {
            if ( ! is_array( $v ) ) continue;
            $size = (string) ( $v['attributes']['pa_taglia'] ?? '' );
            if ( $size === '' ) continue;
            $gsBySize[ $size ] = $v;
        }

        $parentSku = (string) ( $draft['sku'] ?? $payload['sku'] ?? '' );
        $variations = [];
        $seenSizes  = [];

        foreach ( self::extractVariants( $payload ) as $kv ) {
            $size = (string) ( $kv['size_eu'] ?? $kv['size'] ?? '' );
            if ( $size === '' || isset( $seenSizes[ $size ] ) ) continue;
            $seenSizes[ $size ] = true;

            $marketPrice = self::extractMarketPrice( $kv, $payload );
            $regular     = $marketPrice > 0 ? $this->calc->calculate( $marketPrice ) : 0.0;

            if ( isset( $gsBySize[ $size ] ) ) {
                // GS overlay: real stock + GS price as sale.
                $gs      = $gsBySize[ $size ];
                $gsPrice = (float) ( $gs['regular_price'] ?? $gs['price'] ?? 0 );
                $hasSale = $regular > 0 && $gsPrice > 0 && $gsPrice < $regular;
                $variations[] = [
                    'sku'            => (string) ( $gs['sku'] ?? ( $parentSku . '-' . $size ) ),
                    'regular_price'  => $regular > 0 ? (string) $regular : (string) $gsPrice,
                    'sale_price'     => $hasSale ? (string) $gsPrice : '',
                    'stock_quantity' => (int) ( $gs['stock_quantity'] ?? 0 ),
                    'stock_status'   => (string) ( $gs['stock_status'] ?? ( ( (int) ( $gs['stock_quantity'] ?? 0 ) ) > 0 ? 'instock' : 'outofstock' ) ),
                    'manage_stock'   => true,
                    'attributes'     => [ 'pa_taglia' => $size ],
                    '_source'        => 'gs+kicksdb',
                ];
            } else {
                // KicksDB-only: synthetic stock, no sale.
                $stock = $marketPrice > 0 ? $this->calc->syntheticStock( $marketPrice ) : 12;
                $variations[] = [
                    'sku'            => (string) ( $kv['sku'] ?? ( $parentSku . '-' . $size ) ),
                    'regular_price'  => $regular > 0 ? (string) $regular : '',
                    'sale_price'     => '',
                    'stock_quantity' => $stock,
                    'stock_status'   => $stock > 0 ? 'instock' : 'outofstock',
                    'manage_stock'   => true,
                    'attributes'     => [ 'pa_taglia' => $size ],
                    '_source'        => 'kicksdb',
                ];
            }
        }

        // GS-only sizes (rare — KicksDB doesn't list them). Pass through
        // as plain GS variations; no KicksDB regular_price reference.
        foreach ( $gsBySize as $size => $gs ) {
            if ( isset( $seenSizes[ $size ] ) ) continue;
            $variations[] = [
                'sku'            => (string) ( $gs['sku'] ?? ( $parentSku . '-' . $size ) ),
                'regular_price'  => (string) ( $gs['regular_price'] ?? $gs['price'] ?? '' ),
                'sale_price'     => (string) ( $gs['sale_price'] ?? '' ),
                'stock_quantity' => (int) ( $gs['stock_quantity'] ?? 0 ),
                'stock_status'   => (string) ( $gs['stock_status'] ?? 'instock' ),
                'manage_stock'   => true,
                'attributes'     => [ 'pa_taglia' => $size ],
                '_source'        => 'gs',
            ];
        }

        // Stable numeric ordering for the storefront dropdown.
        usort( $variations, function ( $a, $b ) {
            $sa = (float) ( $a['attributes']['pa_taglia'] ?? 0 );
            $sb = (float) ( $b['attributes']['pa_taglia'] ?? 0 );
            return $sa <=> $sb;
        } );
        return $variations;
    }

    /** @return array<int, array> */
    private static function extractVariants( array $payload ): array
    {
        foreach ( [ $payload['variants'] ?? null, $payload['variations'] ?? null, $payload['sizes'] ?? null ] as $c ) {
            if ( is_array( $c ) && $c ) return array_values( array_filter( $c, 'is_array' ) );
        }
        return [];
    }

    private static function extractMarketPrice( array $variant, array $payload ): float
    {
        foreach ( [
            $variant['market_price']  ?? null,
            $variant['lowest_ask']    ?? null,
            $variant['last_sale']     ?? null,
            $variant['retail_price']  ?? null,
            $variant['price']         ?? null,
            $payload['market_price']  ?? null,
            $payload['retail_price']  ?? null,
        ] as $c ) {
            if ( is_numeric( $c ) && (float) $c > 0 ) return (float) $c;
        }
        return 0.0;
    }

    private static function extractImages( array $payload ): array
    {
        $out = [];
        foreach ( [ $payload['image_url'] ?? null, $payload['image'] ?? null, $payload['featured_image'] ?? null ] as $c ) {
            if ( is_string( $c ) && $c !== '' ) $out[] = $c;
        }
        foreach ( [ $payload['images'] ?? null, $payload['gallery'] ?? null ] as $list ) {
            if ( ! is_array( $list ) ) continue;
            foreach ( $list as $img ) {
                if ( is_string( $img ) && $img !== '' ) $out[] = $img;
                elseif ( is_array( $img ) && ! empty( $img['url'] ) ) $out[] = (string) $img['url'];
            }
        }
        return array_values( array_unique( array_filter( $out, fn( $u ) => is_string( $u ) && preg_match( '#^https?://#i', $u ) ) ) );
    }

    /** @param mixed ...$candidates */
    private static function firstNonEmpty( ...$candidates ): string
    {
        foreach ( $candidates as $c ) {
            if ( is_string( $c ) && $c !== '' ) return $c;
            if ( is_numeric( $c ) ) return (string) $c;
        }
        return '';
    }
}
