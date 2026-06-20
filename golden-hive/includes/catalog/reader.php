<?php
/**
 * Reader — legge dati raw da WooCommerce. Nessun side effect, nessuna trasformazione.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ritorna tutti i prodotti WooCommerce filtrati.
 *
 * @param array $filters Filtri opzionali: status, category, brand, in_stock, per_page.
 * @return WC_Product[] Array di oggetti WC_Product.
 */
function rp_cm_get_all_products( array $filters = [] ): array {

    $args = [
        'limit'  => $filters['per_page'] ?? -1,
        'status' => $filters['status']   ?? 'any',
        'type'   => [ 'simple', 'variable' ],
        'return' => 'objects',
        'orderby' => 'title',
        'order'   => 'ASC',
    ];

    if ( ! empty( $filters['category'] ) ) {
        $args['category'] = [ $filters['category'] ];
    }

    // Include-by-IDs: limita il set iniziale a uno specifico elenco di prodotti.
    // Usato per subset export (roundtrip filtrato) / riuso del reader da moduli.
    if ( ! empty( $filters['include_ids'] ) && is_array( $filters['include_ids'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', $filters['include_ids'] ) ) );
        if ( ! empty( $ids ) ) {
            $args['include'] = $ids;
            $args['limit']   = -1;
        }
    }

    $query    = new WC_Product_Query( $args );
    $products = $query->get_products();

    // Filtro brand: match su categoria di livello "Marca" (depth 1)
    if ( ! empty( $filters['brand'] ) ) {
        $brand = $filters['brand'];
        $products = array_filter( $products, function ( WC_Product $p ) use ( $brand ) {
            $terms = wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'names' ] );
            return in_array( $brand, $terms, true );
        } );
        $products = array_values( $products );
    }

    // Filtro in_stock: almeno una variante (o il prodotto stesso) in stock
    if ( ! empty( $filters['in_stock'] ) ) {
        $products = array_filter( $products, function ( WC_Product $p ) {
            if ( $p->is_type( 'simple' ) ) {
                return $p->get_stock_status() === 'instock';
            }
            foreach ( $p->get_children() as $var_id ) {
                $v = wc_get_product( $var_id );
                if ( $v && $v->get_stock_status() === 'instock' ) return true;
            }
            return false;
        } );
        $products = array_values( $products );
    }

    return $products;
}

/**
 * Ritorna SOLO gli ID prodotto per un set di filtri, nello stesso ordine di
 * rp_cm_get_all_products. Usato per l'export roundtrip a chunk: il client
 * prende prima la lista ID (leggera) e poi scarica i prodotti a batch via
 * include_ids, cosi nessuna singola request costruisce l'intero catalogo
 * (che su 600+ prodotti supera il cap ~100s di Cloudflare → 524).
 *
 * @param array $filters Stessi filtri di rp_cm_get_all_products.
 * @return int[] ID prodotto ordinati.
 */
function rp_cm_get_product_ids( array $filters = [] ): array {

    // Fast path: nessun filtro memory-phase → query SQL pura (return=ids),
    // istantanea anche su migliaia di prodotti.
    if ( empty( $filters['brand'] ) && empty( $filters['in_stock'] ) ) {
        $args = [
            'limit'   => -1,
            'status'  => $filters['status'] ?? 'any',
            'type'    => [ 'simple', 'variable' ],
            'return'  => 'ids',
            'orderby' => 'title',
            'order'   => 'ASC',
        ];
        if ( ! empty( $filters['category'] ) ) {
            $args['category'] = [ $filters['category'] ];
        }
        $ids = ( new WC_Product_Query( $args ) )->get_products();
        return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
    }

    // Slow path: i filtri brand/in_stock richiedono hydration → riusa il
    // reader completo e mappa gli ID (comunque molto piu leggero del full export).
    return array_map(
        static fn( WC_Product $p ): int => $p->get_id(),
        rp_cm_get_all_products( $filters )
    );
}

/**
 * Ritorna le varianti raw di un prodotto variabile.
 *
 * @param int $product_id ID del prodotto padre.
 * @return WC_Product_Variation[] Array vuoto se prodotto simple.
 */
function rp_cm_get_product_variants( int $product_id ): array {

    $product = wc_get_product( $product_id );
    if ( ! $product || ! $product->is_type( 'variable' ) ) {
        return [];
    }

    $variants = [];
    foreach ( $product->get_children() as $var_id ) {
        $v = wc_get_product( $var_id );
        if ( $v ) $variants[] = $v;
    }

    return $variants;
}

/**
 * Ritorna la gerarchia completa delle categorie prodotto.
 *
 * @return array [ term_id => [ 'name', 'slug', 'parent_id', 'count' ] ]
 */
function rp_cm_get_product_categories(): array {

    $terms = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
    ] );

    if ( is_wp_error( $terms ) ) return [];

    $result = [];
    foreach ( $terms as $term ) {
        $result[ $term->term_id ] = [
            'name'      => $term->name,
            'slug'      => $term->slug,
            'parent_id' => $term->parent,
            'count'     => $term->count,
        ];
    }

    return $result;
}

/**
 * Ritorna URL immagine featured e gallery di un prodotto.
 *
 * @param int $product_id ID del prodotto.
 * @return array [ 'featured_image_url' => string|null, 'gallery_urls' => string[] ]
 */
function rp_cm_get_product_images( int $product_id ): array {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return [ 'featured_image_url' => null, 'gallery_urls' => [] ];
    }

    $featured_id  = $product->get_image_id();
    $featured_url = $featured_id ? wp_get_attachment_url( $featured_id ) : null;

    $gallery_ids  = $product->get_gallery_image_ids();
    $gallery_urls = array_filter( array_map( 'wp_get_attachment_url', $gallery_ids ) );

    return [
        'featured_image_url' => $featured_url ?: null,
        'gallery_urls'       => array_values( $gallery_urls ),
    ];
}

/**
 * Ritorna gli ID delle categorie prodotto.
 *
 * @param int $product_id ID del prodotto.
 * @return int[] Array di term_id.
 */
function rp_cm_get_product_category_ids( int $product_id ): array {

    $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
    return is_wp_error( $terms ) ? [] : $terms;
}

/**
 * Ritorna i nomi delle categorie prodotto.
 *
 * @param int $product_id ID del prodotto.
 * @return string[] Array di nomi.
 */
function rp_cm_get_product_category_names( int $product_id ): array {

    $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
    return is_wp_error( $terms ) ? [] : $terms;
}

/**
 * Ritorna gli ID dei tag prodotto.
 *
 * @param int $product_id ID del prodotto.
 * @return int[] Array di term_id.
 */
function rp_cm_get_product_tag_ids( int $product_id ): array {

    $terms = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'ids' ] );
    return is_wp_error( $terms ) ? [] : $terms;
}

/**
 * Ritorna i nomi dei tag prodotto.
 *
 * @param int $product_id ID del prodotto.
 * @return string[] Array di nomi.
 */
function rp_cm_get_product_tag_names( int $product_id ): array {

    $terms = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] );
    return is_wp_error( $terms ) ? [] : $terms;
}

/**
 * Ritorna gli attributi raw di un prodotto nel formato roundtrip.
 *
 * @param WC_Product $product
 * @return array [ attr_key => [ 'options' => [...], 'visible' => bool, 'variation' => bool ] ]
 */
function rp_cm_get_product_attributes_raw( WC_Product $product ): array {

    $result = [];

    foreach ( $product->get_attributes() as $key => $attr ) {
        if ( ! is_object( $attr ) || ! method_exists( $attr, 'get_options' ) ) continue;

        $result[ $key ] = [
            'options'   => $attr->get_options(),
            'visible'   => $attr->get_visible(),
            'variation' => $attr->get_variation(),
        ];
    }

    return $result;
}
