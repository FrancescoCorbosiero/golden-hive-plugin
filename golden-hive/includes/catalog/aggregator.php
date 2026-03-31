<?php
/**
 * Aggregator — calcola metadati aggregati dalle varianti per la modalita CATALOG.
 * Nessun accesso al DB — lavora solo sui dati gia passati.
 */

defined( 'ABSPATH' ) || exit;

/** Regex condivisa per identificare l'attributo taglia. */
const RP_CM_SIZE_REGEX = '/(taglia|size|misura|eu|uk|us|fr|cm)/i';

/**
 * Aggrega un prodotto + varianti in una catalog entry.
 *
 * @param WC_Product                $product  Prodotto padre.
 * @param WC_Product_Variation[]    $variants Varianti (vuoto se simple).
 * @return array Catalog entry con sizes, pricing, stock, seo, dates.
 */
function rp_cm_aggregate_product( WC_Product $product, array $variants ): array {

    $id = $product->get_id();

    return [
        'id'        => $id,
        'name'      => $product->get_name(),
        'sku'       => $product->get_sku(),
        'slug'      => $product->get_slug(),
        'status'    => $product->get_status(),
        'permalink' => get_permalink( $id ),
        'sizes'     => rp_cm_extract_sizes( $variants ),
        'pricing'   => rp_cm_calculate_pricing( $variants, $product ),
        'stock'     => rp_cm_calculate_stock( $variants, $product ),
        'seo'       => rp_cm_extract_seo( $product ),
        'dates'     => [
            'created'  => $product->get_date_created()?->date( 'Y-m-d' ),
            'modified' => $product->get_date_modified()?->date( 'Y-m-d' ),
        ],
    ];
}

/**
 * Estrae e ordina le taglie dalle varianti.
 *
 * @param WC_Product_Variation[] $variants
 * @return array Con range, available, in_stock, out_of_stock, count_total, count_in_stock.
 */
function rp_cm_extract_sizes( array $variants ): array {

    if ( empty( $variants ) ) {
        return [
            'range'         => null,
            'available'     => [],
            'in_stock'      => [],
            'out_of_stock'  => [],
            'count_total'   => 0,
            'count_in_stock' => 0,
        ];
    }

    $available    = [];
    $in_stock     = [];
    $out_of_stock = [];

    foreach ( $variants as $v ) {
        $size = rp_cm_get_variant_size( $v );
        if ( $size === null ) continue;

        $available[] = $size;
        if ( $v->get_stock_status() === 'instock' ) {
            $in_stock[] = $size;
        } else {
            $out_of_stock[] = $size;
        }
    }

    // Ordina numericamente se possibile
    $sort = function ( array &$arr ) {
        usort( $arr, function ( $a, $b ) {
            $an = is_numeric( $a );
            $bn = is_numeric( $b );
            if ( $an && $bn ) return (float) $a <=> (float) $b;
            return strcmp( $a, $b );
        } );
    };

    $sort( $available );
    $sort( $in_stock );
    $sort( $out_of_stock );

    $range = count( $available ) > 1
        ? $available[0] . ' – ' . $available[ count( $available ) - 1 ]
        : ( $available[0] ?? null );

    return [
        'range'          => $range,
        'available'      => $available,
        'in_stock'       => $in_stock,
        'out_of_stock'   => $out_of_stock,
        'count_total'    => count( $available ),
        'count_in_stock' => count( $in_stock ),
    ];
}

/**
 * Calcola pricing aggregato (min/max/avg) sulle varianti.
 *
 * @param WC_Product_Variation[] $variants
 * @param WC_Product             $product Fallback per prodotto simple.
 * @return array Con regular_min, regular_max, regular_avg, has_sale, sale_min, sale_max, currency.
 */
function rp_cm_calculate_pricing( array $variants, WC_Product $product ): array {

    if ( empty( $variants ) ) {
        $reg  = (float) $product->get_regular_price();
        $sale = $product->is_on_sale() ? (float) $product->get_sale_price() : null;
        return [
            'regular_min' => $reg ?: null,
            'regular_max' => $reg ?: null,
            'regular_avg' => $reg ?: null,
            'has_sale'    => $product->is_on_sale(),
            'sale_min'    => $sale,
            'sale_max'    => $sale,
            'currency'    => get_woocommerce_currency(),
        ];
    }

    $regular_prices = array_filter(
        array_map( fn( $v ) => (float) $v->get_regular_price(), $variants ),
        fn( $p ) => $p > 0
    );
    $sale_prices = array_filter(
        array_map( fn( $v ) => (float) $v->get_sale_price(), $variants ),
        fn( $p ) => $p > 0
    );

    return [
        'regular_min' => $regular_prices ? min( $regular_prices ) : null,
        'regular_max' => $regular_prices ? max( $regular_prices ) : null,
        'regular_avg' => $regular_prices
            ? round( array_sum( $regular_prices ) / count( $regular_prices ), 2 )
            : null,
        'has_sale'    => count( $sale_prices ) > 0,
        'sale_min'    => $sale_prices ? min( $sale_prices ) : null,
        'sale_max'    => $sale_prices ? max( $sale_prices ) : null,
        'currency'    => get_woocommerce_currency(),
    ];
}

/**
 * Calcola lo stato stock aggregato.
 *
 * @param WC_Product_Variation[] $variants
 * @param WC_Product             $product Fallback per prodotto simple.
 * @return array Con variant_count, in_stock_count, out_of_stock_count, stock_status.
 */
function rp_cm_calculate_stock( array $variants, WC_Product $product ): array {

    if ( empty( $variants ) ) {
        return [
            'variant_count'      => 0,
            'in_stock_count'     => 0,
            'out_of_stock_count' => 0,
            'stock_status'       => rp_cm_stock_status_label( $product ),
        ];
    }

    $in  = 0;
    $out = 0;
    foreach ( $variants as $v ) {
        if ( $v->get_stock_status() === 'instock' ) {
            $in++;
        } else {
            $out++;
        }
    }

    $total = count( $variants );
    if ( $in === $total )      $status = 'full';
    elseif ( $in > 0 )        $status = 'partial';
    else                       $status = 'out';

    return [
        'variant_count'      => $total,
        'in_stock_count'     => $in,
        'out_of_stock_count' => $out,
        'stock_status'       => $status,
    ];
}

/**
 * Estrae dati SEO (Rank Math) da un prodotto.
 *
 * @param WC_Product $product
 * @return array Con focus_keyword, meta_title, has_description, has_short_description, seo_complete.
 */
function rp_cm_extract_seo( WC_Product $product ): array {

    $id           = $product->get_id();
    $keyword      = get_post_meta( $id, 'rank_math_focus_keyword', true );
    $meta_title   = get_post_meta( $id, 'rank_math_title', true );
    $has_desc     = (bool) $product->get_description();
    $has_short    = (bool) $product->get_short_description();
    $seo_complete = (bool) $keyword && (bool) $meta_title && $has_desc && $has_short;

    return [
        'focus_keyword'        => $keyword ?: null,
        'meta_title'           => $meta_title ?: null,
        'has_description'      => $has_desc,
        'has_short_description' => $has_short,
        'seo_complete'         => $seo_complete,
    ];
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Estrae la taglia da una variante WooCommerce.
 *
 * @param WC_Product_Variation $variant
 * @return string|null Taglia o null se non trovata.
 */
function rp_cm_get_variant_size( WC_Product_Variation $variant ): ?string {

    $attrs = $variant->get_variation_attributes();

    foreach ( $attrs as $key => $val ) {
        if ( preg_match( RP_CM_SIZE_REGEX, $key ) ) {
            return $val ?: null;
        }
    }

    // Fallback: primo attributo non vuoto
    foreach ( $attrs as $val ) {
        if ( $val ) return $val;
    }

    return null;
}

/**
 * Determina stock_status per un prodotto simple (senza varianti).
 *
 * @param WC_Product $product
 * @return string 'full' | 'out' | 'unmanaged'
 */
function rp_cm_stock_status_label( WC_Product $product ): string {

    if ( ! $product->get_manage_stock() && $product->get_stock_status() === 'instock' ) {
        return 'unmanaged';
    }

    return $product->get_stock_status() === 'instock' ? 'full' : 'out';
}
