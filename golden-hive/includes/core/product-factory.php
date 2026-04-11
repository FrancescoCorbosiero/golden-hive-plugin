<?php
/**
 * Product Factory — creazione/aggiornamento unificato prodotti WooCommerce.
 * Usato da: bulk importer, roundtrip importer, GS feed, qualsiasi modulo che crea prodotti.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crea un prodotto simple da un array di dati.
 *
 * @param array $data Dati del prodotto.
 * @return int Product ID.
 */
function gh_create_simple_product( array $data ): int {

    $product = new WC_Product_Simple();
    gh_apply_product_fields( $product, $data );
    $product_id = $product->save();

    gh_apply_product_meta( $product_id, $data );

    return $product_id;
}

/**
 * Crea un prodotto variable con attributi e varianti.
 *
 * @param array $data Dati del prodotto con 'attributes' e 'variations'.
 * @return int Product ID.
 */
function gh_create_variable_product( array $data ): int {

    $product = new WC_Product_Variable();
    gh_apply_product_fields( $product, $data );

    if ( ! empty( $data['attributes'] ) ) {
        $product->set_attributes( gh_build_wc_attributes( $data['attributes'] ) );
    }

    $product_id = $product->save();
    gh_apply_product_meta( $product_id, $data );

    // Crea varianti
    foreach ( $data['variations'] ?? [] as $var_data ) {
        gh_create_variation( $product_id, $var_data );
    }

    WC_Product_Variable::sync( $product_id );

    return $product_id;
}

/**
 * Crea una singola variante sotto un prodotto padre.
 *
 * @param int   $parent_id ID del prodotto padre.
 * @param array $data      Dati della variante.
 * @return int Variation ID.
 */
function gh_create_variation( int $parent_id, array $data ): int {

    $v = new WC_Product_Variation();
    $v->set_parent_id( $parent_id );

    // Attributi — for taxonomy attributes, WooCommerce expects the term slug
    $attrs = [];
    foreach ( $data['attributes'] ?? [] as $key => $val ) {
        $attr_key = str_starts_with( $key, 'attribute_' ) ? $key : 'attribute_' . $key;
        $taxonomy = str_replace( 'attribute_', '', $attr_key );

        if ( taxonomy_exists( $taxonomy ) ) {
            // Ensure term exists and use its slug
            $term = get_term_by( 'name', $val, $taxonomy );
            if ( ! $term ) {
                $term = get_term_by( 'slug', sanitize_title( $val ), $taxonomy );
            }
            if ( ! $term ) {
                $inserted = wp_insert_term( $val, $taxonomy );
                if ( ! is_wp_error( $inserted ) ) {
                    $term = get_term( $inserted['term_id'], $taxonomy );
                }
            }
            $attrs[ $attr_key ] = $term ? $term->slug : sanitize_title( $val );
        } else {
            $attrs[ $attr_key ] = $val;
        }
    }
    $v->set_attributes( $attrs );

    if ( ! empty( $data['sku'] ) )            $v->set_sku( $data['sku'] );
    if ( isset( $data['regular_price'] ) )     $v->set_regular_price( $data['regular_price'] );
    if ( isset( $data['sale_price'] ) )        $v->set_sale_price( $data['sale_price'] );
    if ( isset( $data['weight'] ) )            $v->set_weight( $data['weight'] );

    $v->set_status( $data['status'] ?? 'publish' );

    $manage = $data['manage_stock'] ?? false;
    $v->set_manage_stock( $manage );
    if ( $manage && isset( $data['stock_quantity'] ) ) {
        $v->set_stock_quantity( (int) $data['stock_quantity'] );
    }
    $v->set_stock_status( $data['stock_status'] ?? 'instock' );

    return $v->save();
}

/**
 * Applica i campi comuni a un oggetto WC_Product.
 *
 * @param WC_Product $product
 * @param array      $data
 */
function gh_apply_product_fields( WC_Product $product, array $data ): void {

    if ( isset( $data['name'] ) )              $product->set_name( $data['name'] );
    if ( isset( $data['sku'] ) )               $product->set_sku( $data['sku'] );
    if ( isset( $data['slug'] ) )              $product->set_slug( $data['slug'] );
    if ( isset( $data['regular_price'] ) )     $product->set_regular_price( $data['regular_price'] );
    if ( isset( $data['sale_price'] ) )        $product->set_sale_price( $data['sale_price'] );
    if ( isset( $data['description'] ) )       $product->set_description( $data['description'] );
    if ( isset( $data['short_description'] ) ) $product->set_short_description( $data['short_description'] );
    if ( isset( $data['weight'] ) )            $product->set_weight( $data['weight'] );

    $product->set_status( $data['status'] ?? 'publish' );

    $manage = $data['manage_stock'] ?? false;
    $product->set_manage_stock( $manage );
    if ( $manage && isset( $data['stock_quantity'] ) ) {
        $product->set_stock_quantity( (int) $data['stock_quantity'] );
    }
    $product->set_stock_status( $data['stock_status'] ?? 'instock' );
}

/**
 * Applica categorie, tag e meta SEO dopo il save.
 *
 * @param int   $product_id
 * @param array $data
 */
function gh_apply_product_meta( int $product_id, array $data ): void {

    if ( ! empty( $data['category_ids'] ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $data['category_ids'] ), 'product_cat' );
    }
    if ( ! empty( $data['tag_ids'] ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $data['tag_ids'] ), 'product_tag' );
    }
    if ( ! empty( $data['meta_title'] ) ) {
        update_post_meta( $product_id, 'rank_math_title', sanitize_text_field( $data['meta_title'] ) );
    }
    if ( ! empty( $data['meta_description'] ) ) {
        update_post_meta( $product_id, 'rank_math_description', sanitize_text_field( $data['meta_description'] ) );
    }
    if ( ! empty( $data['focus_keyword'] ) ) {
        update_post_meta( $product_id, 'rank_math_focus_keyword', sanitize_text_field( $data['focus_keyword'] ) );
    }
}

/**
 * Costruisce oggetti WC_Product_Attribute dal formato JSON.
 *
 * @param array $attrs_json { "pa_taglia": { "options": [...], "visible": true, "variation": true } }
 * @return WC_Product_Attribute[]
 */
function gh_build_wc_attributes( array $attrs_json ): array {

    $wc_attrs = [];
    $position = 0;

    foreach ( $attrs_json as $name => $config ) {
        $attr = new WC_Product_Attribute();

        $tax_id = wc_attribute_taxonomy_id_by_name( $name );

        // Auto-register attribute taxonomy if it doesn't exist
        if ( ! $tax_id && str_starts_with( $name, 'pa_' ) ) {
            $tax_id = gh_ensure_attribute_taxonomy( $name );
        }

        if ( $tax_id ) {
            $attr->set_id( $tax_id );
            $attr->set_name( $name );
            foreach ( $config['options'] ?? [] as $term_name ) {
                if ( ! term_exists( $term_name, $name ) ) {
                    wp_insert_term( $term_name, $name );
                }
            }
        } else {
            $attr->set_id( 0 );
            $attr->set_name( $name );
        }

        $attr->set_options( $config['options'] ?? [] );
        $attr->set_visible( $config['visible'] ?? true );
        $attr->set_variation( $config['variation'] ?? true );
        $attr->set_position( $position++ );

        $wc_attrs[] = $attr;
    }

    return $wc_attrs;
}

/**
 * Ensures a WooCommerce attribute taxonomy exists, creating it if needed.
 *
 * @param string $taxonomy_name Taxonomy name (e.g. "pa_taglia").
 * @return int Attribute taxonomy ID, or 0 on failure.
 */
function gh_ensure_attribute_taxonomy( string $taxonomy_name ): int {
    // Already registered?
    $existing_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
    if ( $existing_id ) return $existing_id;

    // Derive label from slug: "pa_taglia" → "Taglia"
    $slug  = str_replace( 'pa_', '', $taxonomy_name );
    $label = ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );

    $id = wc_create_attribute( [
        'name'         => $label,
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ] );

    if ( is_wp_error( $id ) ) return 0;

    // Register the taxonomy immediately so it's available in the same request
    register_taxonomy( $taxonomy_name, 'product', [
        'labels'       => [ 'name' => $label ],
        'hierarchical' => false,
        'show_ui'      => false,
        'query_var'    => true,
        'rewrite'      => [ 'slug' => $slug ],
    ] );

    return $id;
}
