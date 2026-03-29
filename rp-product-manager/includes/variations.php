<?php
/**
 * Ricerca prodotti e gestione varianti.
 */

defined( 'ABSPATH' ) || exit;

// ── SEARCH ──────────────────────────────────────────────────

function rp_search_products( string $query, int $limit = 8 ): array {

    $query   = trim( $query );
    $results = [];
    $seen    = [];

    $add = function ( $product ) use ( &$results, &$seen ) {
        $id = $product->get_id();
        if ( isset( $seen[ $id ] ) ) return;
        $seen[ $id ] = true;
        $results[]   = [
            'id'     => $id,
            'name'   => $product->get_name(),
            'sku'    => $product->get_sku(),
            'type'   => $product->get_type(),
            'price'  => $product->get_price(),
            'status' => $product->get_status(),
        ];
    };

    // 1. ID diretto
    if ( is_numeric( $query ) ) {
        $p = wc_get_product( (int) $query );
        if ( $p ) $add( $p );
    }

    // 2. SKU esatto
    $sku_id = wc_get_product_id_by_sku( $query );
    if ( $sku_id ) {
        $p = wc_get_product( $sku_id );
        if ( $p ) $add( $p );
    }

    // 3. Fulltext titolo
    if ( count( $results ) < $limit ) {
        $posts = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            's'              => $query,
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'relevance',
        ] );
        foreach ( $posts as $id ) {
            if ( count( $results ) >= $limit ) break;
            $p = wc_get_product( $id );
            if ( $p ) $add( $p );
        }
    }

    // 4. SKU parziale
    if ( count( $results ) < $limit ) {
        $meta_posts = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'meta_query'     => [ [
                'key'     => '_sku',
                'value'   => $query,
                'compare' => 'LIKE',
            ] ],
        ] );
        foreach ( $meta_posts as $id ) {
            if ( count( $results ) >= $limit ) break;
            $p = wc_get_product( $id );
            if ( $p ) $add( $p );
        }
    }

    return array_slice( $results, 0, $limit );
}

// ── GET VARIATIONS ───────────────────────────────────────────

function rp_get_product_variations( int $product_id ): array {

    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return [ 'error' => "Prodotto #{$product_id} non trovato." ];
    }
    if ( ! $product->is_type( 'variable' ) ) {
        return [ 'error' => "Il prodotto #{$product_id} non è variabile (tipo: {$product->get_type()})." ];
    }

    $variations = [];

    foreach ( $product->get_children() as $var_id ) {
        $v = wc_get_product( $var_id );
        if ( ! $v ) continue;

        $attrs = $v->get_variation_attributes();
        $size  = '';
        foreach ( $attrs as $key => $val ) {
            if ( preg_match( '/(taglia|size|misura|eu|uk|us|fr|cm)/i', $key ) ) {
                $size = $val;
                break;
            }
        }
        if ( ! $size ) $size = implode( ' / ', array_filter( array_values( $attrs ) ) );

        $variations[] = [
            'variation_id'   => $var_id,
            'size'           => $size ?: "Var #{$var_id}",
            'sku'            => $v->get_sku(),
            'regular_price'  => $v->get_regular_price(),
            'sale_price'     => $v->get_sale_price(),
            'price'          => $v->get_price(),
            'manage_stock'   => $v->get_manage_stock(),
            'stock_quantity' => $v->get_stock_quantity(),
            'stock_status'   => $v->get_stock_status(),
            'status'         => $v->get_status(),
            'attributes'     => $attrs,
        ];
    }

    usort( $variations, function ( $a, $b ) {
        $an = is_numeric( $a['size'] );
        $bn = is_numeric( $b['size'] );
        if ( $an && $bn ) return (float) $a['size'] <=> (float) $b['size'];
        return strcmp( $a['size'], $b['size'] );
    } );

    return $variations;
}

// ── UPDATE SINGLE VARIATION ──────────────────────────────────

function rp_update_variation( int $variation_id, array $data ): true|WP_Error {

    $v = wc_get_product( $variation_id );

    if ( ! $v || ! $v->is_type( 'variation' ) ) {
        return new WP_Error( 'not_found', "Variante #{$variation_id} non trovata." );
    }

    if ( array_key_exists( 'regular_price', $data ) )  $v->set_regular_price( $data['regular_price'] );
    if ( array_key_exists( 'sale_price', $data ) )     $v->set_sale_price( $data['sale_price'] );
    if ( array_key_exists( 'sku', $data ) )            $v->set_sku( $data['sku'] );
    if ( array_key_exists( 'status', $data ) )         $v->set_status( $data['status'] );

    if ( array_key_exists( 'stock_quantity', $data ) ) {
        $qty = (int) $data['stock_quantity'];
        $v->set_manage_stock( true );
        $v->set_stock_quantity( $qty );
        if ( ! array_key_exists( 'stock_status', $data ) ) {
            $v->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
        }
    }
    if ( array_key_exists( 'stock_status', $data ) ) {
        $v->set_stock_status( $data['stock_status'] );
    }

    $v->save();
    WC_Product_Variable::sync( $v->get_parent_id() );

    return true;
}

// ── BULK UPDATE VARIATIONS ───────────────────────────────────

function rp_bulk_update_variations( array $updates ): array {

    $results = [];

    foreach ( $updates as $update ) {
        $var_id = (int) ( $update['variation_id'] ?? 0 );
        if ( ! $var_id ) continue;
        $fields              = $update;
        unset( $fields['variation_id'] );
        $result              = rp_update_variation( $var_id, $fields );
        $results[ $var_id ]  = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
    }

    return $results;
}
