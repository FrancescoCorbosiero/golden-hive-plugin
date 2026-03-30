<?php
/**
 * Exporter — assembla i formati JSON (CATALOG aggregato + ROUNDTRIP re-importabile).
 * Orchestra reader, aggregator e tree-builder.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Genera l'export in modalita CATALOG (aggregato, senza dettaglio varianti).
 *
 * @param array $filters Filtri opzionali (status, category, brand, in_stock).
 * @return array Struttura completa con generated_at, mode, summary, tree.
 */
function rp_cm_export_catalog( array $filters = [] ): array {

    $start    = microtime( true );
    $products = rp_cm_get_all_products( $filters );
    $entries  = [];

    foreach ( $products as $product ) {
        $variants  = rp_cm_get_product_variants( $product->get_id() );
        $entries[] = rp_cm_aggregate_product( $product, $variants );
    }

    $tree    = rp_cm_build_tree( $entries );
    $summary = rp_cm_build_summary( $tree );
    $summary['generated_in_seconds'] = round( microtime( true ) - $start, 2 );

    return [
        'generated_at' => wp_date( 'c' ),
        'mode'         => 'catalog',
        'summary'      => $summary,
        'tree'         => $tree,
    ];
}

/**
 * Genera l'export ROUNDTRIP — flat, raw, re-importabile.
 *
 * Ogni campo e preservato esattamente come WooCommerce lo restituisce.
 * Il formato e progettato per: export → modifica → re-import.
 *
 * @param array $filters Filtri opzionali (status, category, brand, in_stock).
 * @return array Struttura con format, version, site_url, products[].
 */
function rp_cm_export_roundtrip( array $filters = [] ): array {

    $start    = microtime( true );
    $products = rp_cm_get_all_products( $filters );
    $entries  = [];

    foreach ( $products as $product ) {
        $id       = $product->get_id();
        $variants = rp_cm_get_product_variants( $id );
        $images   = rp_cm_get_product_images( $id );

        // Varianti raw
        $variants_data = [];
        foreach ( $variants as $v ) {
            $variants_data[] = [
                'id'             => $v->get_id(),
                'sku'            => $v->get_sku(),
                'status'         => $v->get_status(),
                'regular_price'  => $v->get_regular_price(),
                'sale_price'     => $v->get_sale_price(),
                'manage_stock'   => $v->get_manage_stock(),
                'stock_quantity' => $v->get_stock_quantity(),
                'stock_status'   => $v->get_stock_status(),
                'weight'         => $v->get_weight(),
                'attributes'     => $v->get_variation_attributes(),
            ];
        }

        $entries[] = [
            'id'                => $id,
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'sku'               => $product->get_sku(),
            'type'              => $product->get_type(),
            'status'            => $product->get_status(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'manage_stock'      => $product->get_manage_stock(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'stock_status'      => $product->get_stock_status(),
            'weight'            => $product->get_weight(),
            'category_ids'      => rp_cm_get_product_category_ids( $id ),
            'category_names'    => rp_cm_get_product_category_names( $id ),
            'tag_ids'           => rp_cm_get_product_tag_ids( $id ),
            'tag_names'         => rp_cm_get_product_tag_names( $id ),
            'attributes'        => rp_cm_get_product_attributes_raw( $product ),
            'featured_image_url'  => $images['featured_image_url'],
            'gallery_image_urls'  => $images['gallery_urls'],
            'meta_title'        => get_post_meta( $id, 'rank_math_title', true ) ?: null,
            'meta_description'  => get_post_meta( $id, 'rank_math_description', true ) ?: null,
            'focus_keyword'     => get_post_meta( $id, 'rank_math_focus_keyword', true ) ?: null,
            'date_created'      => $product->get_date_created()?->date( 'c' ),
            'date_modified'     => $product->get_date_modified()?->date( 'c' ),
            'variations'        => $variants_data,
        ];
    }

    return [
        'format'        => 'rp_cm_roundtrip',
        'version'       => 1,
        'generated_at'  => wp_date( 'c' ),
        'site_url'      => home_url(),
        'product_count' => count( $entries ),
        'generated_in_seconds' => round( microtime( true ) - $start, 2 ),
        'products'      => $entries,
    ];
}

/**
 * Calcola il blocco summary dall'albero gia costruito.
 *
 * @param array $tree Albero gerarchico.
 * @return array Con total_products, total_in_stock, total_variants, total_variants_in_stock, categories, brands.
 */
function rp_cm_build_summary( array $tree ): array {

    $total_products          = 0;
    $total_in_stock          = 0;
    $total_variants          = 0;
    $total_variants_in_stock = 0;
    $brands_set              = [];
    $categories_count        = 0;

    foreach ( $tree as $section ) {
        foreach ( $section as $brand_name => $subcats ) {
            $brands_set[ $brand_name ] = true;
            foreach ( $subcats as $products ) {
                $categories_count++;
                foreach ( $products as $entry ) {
                    $total_products++;

                    $stock = $entry['stock'] ?? [];

                    if ( isset( $stock['stock_status'] ) && is_string( $stock['stock_status'] ) ) {
                        if ( $stock['stock_status'] !== 'out' ) $total_in_stock++;
                        $total_variants          += $stock['variant_count'] ?? 0;
                        $total_variants_in_stock += $stock['in_stock_count'] ?? 0;
                    }
                }
            }
        }
    }

    return [
        'total_products'          => $total_products,
        'total_in_stock'          => $total_in_stock,
        'total_variants'          => $total_variants,
        'total_variants_in_stock' => $total_variants_in_stock,
        'categories'              => $categories_count,
        'brands'                  => count( $brands_set ),
    ];
}
