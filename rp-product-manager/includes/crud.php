<?php
/**
 * CRUD base — lettura, creazione, aggiornamento, eliminazione prodotto.
 */

defined( 'ABSPATH' ) || exit;

// ── READ ────────────────────────────────────────────────────

function rp_get_product( int $product_id ): array {

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return [ 'error' => "Prodotto ID {$product_id} non trovato." ];
    }

    return [
        'id'                => $product->get_id(),
        'name'              => $product->get_name(),
        'slug'              => $product->get_slug(),
        'sku'               => $product->get_sku(),
        'type'              => $product->get_type(),
        'status'            => $product->get_status(),
        'regular_price'     => $product->get_regular_price(),
        'sale_price'        => $product->get_sale_price(),
        'price'             => $product->get_price(),
        'short_description' => $product->get_short_description(),
        'description'       => $product->get_description(),
        'manage_stock'      => $product->get_manage_stock(),
        'stock_quantity'    => $product->get_stock_quantity(),
        'stock_status'      => $product->get_stock_status(),
        'weight'            => $product->get_weight(),
        'categories'        => wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] ),
        'tags'              => wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] ),
        'attributes'        => $product->get_attributes(),
        'meta_title'        => get_post_meta( $product_id, 'rank_math_title', true ),
        'meta_description'  => get_post_meta( $product_id, 'rank_math_description', true ),
        'focus_keyword'     => get_post_meta( $product_id, 'rank_math_focus_keyword', true ),
        'permalink'         => get_permalink( $product_id ),
        'date_created'      => $product->get_date_created()?->date( 'Y-m-d H:i:s' ),
        'date_modified'     => $product->get_date_modified()?->date( 'Y-m-d H:i:s' ),
    ];
}

// ── CREATE ───────────────────────────────────────────────────

function rp_create_product( array $data ): int|WP_Error {

    if ( empty( $data['name'] ) ) {
        return new WP_Error( 'missing_field', 'Il campo "name" è obbligatorio.' );
    }
    if ( empty( $data['regular_price'] ) ) {
        return new WP_Error( 'missing_field', 'Il campo "regular_price" è obbligatorio.' );
    }

    $product = new WC_Product_Simple();

    $product->set_name( $data['name'] );
    $product->set_regular_price( $data['regular_price'] );

    if ( isset( $data['sku'] ) )               $product->set_sku( $data['sku'] );
    if ( isset( $data['sale_price'] ) )        $product->set_sale_price( $data['sale_price'] );
    if ( isset( $data['description'] ) )       $product->set_description( $data['description'] );
    if ( isset( $data['short_description'] ) ) $product->set_short_description( $data['short_description'] );
    if ( isset( $data['status'] ) )            $product->set_status( $data['status'] );
    if ( isset( $data['weight'] ) )            $product->set_weight( $data['weight'] );
    if ( isset( $data['slug'] ) )              $product->set_slug( $data['slug'] );

    $manage = $data['manage_stock'] ?? false;
    $product->set_manage_stock( $manage );
    if ( $manage && isset( $data['stock_quantity'] ) ) {
        $product->set_stock_quantity( (int) $data['stock_quantity'] );
        $product->set_stock_status( 'instock' );
    } else {
        $product->set_stock_status( $data['stock_status'] ?? 'instock' );
    }

    $product_id = $product->save();

    if ( ! $product_id ) {
        return new WP_Error( 'save_failed', 'Errore nel salvataggio del prodotto.' );
    }

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

    return $product_id;
}

// ── UPDATE ───────────────────────────────────────────────────

function rp_update_product( int $product_id, array $data ): true|WP_Error {

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto ID {$product_id} non trovato." );
    }

    if ( array_key_exists( 'name', $data ) )              $product->set_name( $data['name'] );
    if ( array_key_exists( 'sku', $data ) )               $product->set_sku( $data['sku'] );
    if ( array_key_exists( 'regular_price', $data ) )     $product->set_regular_price( $data['regular_price'] );
    if ( array_key_exists( 'sale_price', $data ) )        $product->set_sale_price( $data['sale_price'] );
    if ( array_key_exists( 'description', $data ) )       $product->set_description( $data['description'] );
    if ( array_key_exists( 'short_description', $data ) ) $product->set_short_description( $data['short_description'] );
    if ( array_key_exists( 'status', $data ) )            $product->set_status( $data['status'] );
    if ( array_key_exists( 'weight', $data ) )            $product->set_weight( $data['weight'] );
    if ( array_key_exists( 'slug', $data ) )              $product->set_slug( $data['slug'] );

    if ( array_key_exists( 'manage_stock', $data ) ) {
        $product->set_manage_stock( $data['manage_stock'] );
    }
    if ( array_key_exists( 'stock_quantity', $data ) ) {
        $qty = (int) $data['stock_quantity'];
        $product->set_stock_quantity( $qty );
        if ( ! array_key_exists( 'stock_status', $data ) ) {
            $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
        }
    }
    if ( array_key_exists( 'stock_status', $data ) ) {
        $product->set_stock_status( $data['stock_status'] );
    }

    $product->save();

    if ( array_key_exists( 'category_ids', $data ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $data['category_ids'] ), 'product_cat' );
    }
    if ( array_key_exists( 'tag_ids', $data ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $data['tag_ids'] ), 'product_tag' );
    }
    if ( array_key_exists( 'meta_title', $data ) ) {
        update_post_meta( $product_id, 'rank_math_title', sanitize_text_field( $data['meta_title'] ) );
    }
    if ( array_key_exists( 'meta_description', $data ) ) {
        update_post_meta( $product_id, 'rank_math_description', sanitize_text_field( $data['meta_description'] ) );
    }
    if ( array_key_exists( 'focus_keyword', $data ) ) {
        update_post_meta( $product_id, 'rank_math_focus_keyword', sanitize_text_field( $data['focus_keyword'] ) );
    }

    return true;
}

// ── DELETE ───────────────────────────────────────────────────

function rp_delete_product( int $product_id, bool $force_delete = false ): true|WP_Error {

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto ID {$product_id} non trovato." );
    }

    if ( $force_delete ) {
        $result = $product->delete( true );
    } else {
        wp_trash_post( $product_id );
        $result = true;
    }

    if ( ! $result ) {
        return new WP_Error( 'delete_failed', "Errore nell'eliminazione del prodotto ID {$product_id}." );
    }

    return true;
}
