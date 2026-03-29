<?php
/**
 * Exporter — assembla i formati JSON finali (CATALOG e FULL EXPORT).
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
 * Genera l'export in modalita FULL (ogni variante con tutti i valori).
 *
 * @param array $filters Filtri opzionali (status, category, brand, in_stock).
 * @return array Struttura completa con generated_at, mode, summary, tree.
 */
function rp_cm_export_full( array $filters = [] ): array {

    $start    = microtime( true );
    $products = rp_cm_get_all_products( $filters );
    $entries  = [];

    foreach ( $products as $product ) {
        $id       = $product->get_id();
        $variants = rp_cm_get_product_variants( $id );
        $images   = rp_cm_get_product_images( $id );

        $variants_data = [];
        foreach ( $variants as $v ) {
            $variants_data[] = [
                'variation_id'   => $v->get_id(),
                'size'           => rp_cm_get_variant_size( $v ),
                'sku'            => $v->get_sku(),
                'regular_price'  => $v->get_regular_price(),
                'sale_price'     => $v->get_sale_price(),
                'stock_quantity' => $v->get_stock_quantity(),
                'stock_status'   => $v->get_stock_status(),
                'status'         => $v->get_status(),
            ];
        }

        // Attributi del prodotto padre
        $attributes = [];
        foreach ( $product->get_attributes() as $attr_key => $attr ) {
            if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
                $attributes[ $attr_key ] = $attr->get_options();
            }
        }

        $entries[] = [
            'id'        => $id,
            'name'      => $product->get_name(),
            'sku'       => $product->get_sku(),
            'slug'      => $product->get_slug(),
            'status'    => $product->get_status(),
            'type'      => $product->get_type(),
            'permalink' => get_permalink( $id ),
            'pricing'   => [
                'regular_price' => $product->get_regular_price(),
                'sale_price'    => $product->get_sale_price(),
                'price'         => $product->get_price(),
            ],
            'stock' => [
                'manage_stock'   => $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status'   => $product->get_stock_status(),
            ],
            'content' => [
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
            ],
            'seo' => [
                'focus_keyword'    => get_post_meta( $id, 'rank_math_focus_keyword', true ) ?: null,
                'meta_title'       => get_post_meta( $id, 'rank_math_title', true ) ?: null,
                'meta_description' => get_post_meta( $id, 'rank_math_description', true ) ?: null,
                'slug'             => $product->get_slug(),
            ],
            'media'      => $images,
            'attributes' => $attributes,
            'variants'   => $variants_data,
            'dates'      => [
                'created'  => $product->get_date_created()?->date( 'c' ),
                'modified' => $product->get_date_modified()?->date( 'c' ),
            ],
        ];
    }

    $tree    = rp_cm_build_tree( $entries );
    $summary = rp_cm_build_summary( $tree );
    $summary['generated_in_seconds'] = round( microtime( true ) - $start, 2 );

    return [
        'generated_at' => wp_date( 'c' ),
        'mode'         => 'full_export',
        'summary'      => $summary,
        'tree'         => $tree,
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

                    // Compatibilita con entrambi i formati (catalog e full)
                    $stock = $entry['stock'] ?? [];

                    if ( isset( $stock['stock_status'] ) && is_string( $stock['stock_status'] ) ) {
                        // CATALOG mode: stock_status e una stringa calcolata
                        if ( $stock['stock_status'] !== 'out' ) $total_in_stock++;
                        $total_variants          += $stock['variant_count'] ?? 0;
                        $total_variants_in_stock += $stock['in_stock_count'] ?? 0;
                    } elseif ( isset( $entry['variants'] ) ) {
                        // FULL mode: contiamo dalle varianti effettive
                        $var_count = count( $entry['variants'] );
                        $var_in    = 0;
                        foreach ( $entry['variants'] as $v ) {
                            if ( ( $v['stock_status'] ?? '' ) === 'instock' ) $var_in++;
                        }
                        $total_variants          += $var_count;
                        $total_variants_in_stock += $var_in;
                        if ( $var_in > 0 || ( $stock['stock_status'] ?? '' ) === 'instock' ) {
                            $total_in_stock++;
                        }
                    } else {
                        // Simple product senza varianti (full mode)
                        if ( ( $stock['stock_status'] ?? '' ) === 'instock' ) {
                            $total_in_stock++;
                        }
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
