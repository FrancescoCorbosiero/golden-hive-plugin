<?php
/**
 * Library — browse, search e product-media mapping.
 * Solo lettura tranne per l'assegnazione immagini ai prodotti.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cerca attachment nella media library.
 *
 * @param string $query Testo di ricerca (filename, title).
 * @param string $mime  Filtro mime: 'all' | 'image' | 'video' | 'application'
 * @param int    $limit Max risultati.
 * @return array Array di attachment data.
 */
function rp_mm_search_attachments( string $query = '', string $mime = 'all', int $limit = 50 ): array {

    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ( $query ) {
        $args['s'] = $query;
    }
    if ( $mime !== 'all' ) {
        $args['post_mime_type'] = $mime;
    }

    $ids    = get_posts( $args );
    $result = [];

    foreach ( $ids as $id ) {
        $result[] = rp_mm_build_attachment_data( $id );
    }

    return $result;
}

/**
 * Ritorna il mapping completo prodotto → immagini.
 *
 * @param array $filters Filtri opzionali: status, per_page.
 * @return array [ [ product_id, name, sku, featured_image, gallery_images ] ]
 */
function rp_mm_get_product_media_map( array $filters = [] ): array {

    $args = [
        'limit'   => $filters['per_page'] ?? -1,
        'status'  => $filters['status'] ?? 'any',
        'type'    => [ 'simple', 'variable' ],
        'return'  => 'objects',
        'orderby' => 'title',
        'order'   => 'ASC',
    ];

    $query    = new WC_Product_Query( $args );
    $products = $query->get_products();
    $result   = [];

    foreach ( $products as $product ) {
        $id          = $product->get_id();
        $featured_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids();

        $featured = null;
        if ( $featured_id ) {
            $featured = rp_mm_build_attachment_data( (int) $featured_id );
        }

        $gallery = [];
        foreach ( $gallery_ids as $gid ) {
            $gallery[] = rp_mm_build_attachment_data( (int) $gid );
        }

        $result[] = [
            'product_id'     => $id,
            'name'           => $product->get_name(),
            'sku'            => $product->get_sku(),
            'type'           => $product->get_type(),
            'status'         => $product->get_status(),
            'featured_image' => $featured,
            'gallery_images' => $gallery,
            'total_images'   => ( $featured ? 1 : 0 ) + count( $gallery ),
        ];
    }

    return $result;
}

/**
 * Imposta l'immagine featured di un prodotto.
 *
 * @param int $product_id    ID del prodotto WooCommerce.
 * @param int $attachment_id ID dell'attachment da impostare come featured.
 * @return true|WP_Error
 */
function rp_mm_set_product_featured_image( int $product_id, int $attachment_id ): true|WP_Error {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    if ( ! wp_get_attachment_url( $attachment_id ) ) {
        return new WP_Error( 'invalid_attachment', "Attachment #{$attachment_id} non trovato." );
    }

    $product->set_image_id( $attachment_id );
    $product->save();

    return true;
}

/**
 * Imposta le immagini della gallery di un prodotto.
 *
 * @param int   $product_id    ID del prodotto WooCommerce.
 * @param int[] $attachment_ids Array di attachment ID per la gallery.
 * @return true|WP_Error
 */
function rp_mm_set_product_gallery( int $product_id, array $attachment_ids ): true|WP_Error {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'not_found', "Prodotto #{$product_id} non trovato." );
    }

    // Valida che tutti gli attachment esistano
    foreach ( $attachment_ids as $aid ) {
        if ( ! wp_get_attachment_url( (int) $aid ) ) {
            return new WP_Error( 'invalid_attachment', "Attachment #{$aid} non trovato." );
        }
    }

    $product->set_gallery_image_ids( array_map( 'intval', $attachment_ids ) );
    $product->save();

    return true;
}

/**
 * Ritorna tutti i prodotti che usano un determinato attachment.
 *
 * @param int $attachment_id ID dell'attachment.
 * @return array [ [ product_id, name, usage => 'featured'|'gallery' ] ]
 */
function rp_mm_get_attachment_usage( int $attachment_id ): array {

    $usage = [];

    // Featured image
    $featured_products = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [
            'key'   => '_thumbnail_id',
            'value' => $attachment_id,
        ] ],
    ] );

    foreach ( $featured_products as $pid ) {
        $p = wc_get_product( $pid );
        if ( $p ) {
            $usage[] = [
                'product_id' => $pid,
                'name'       => $p->get_name(),
                'usage'      => 'featured',
            ];
        }
    }

    // Gallery
    $gallery_products = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [
            'key'     => '_product_image_gallery',
            'value'   => (string) $attachment_id,
            'compare' => 'LIKE',
        ] ],
    ] );

    foreach ( $gallery_products as $pid ) {
        // Verifica che sia effettivamente nella gallery (LIKE potrebbe matchare sottostringhe)
        $csv = get_post_meta( $pid, '_product_image_gallery', true );
        $ids = array_filter( explode( ',', $csv ) );
        if ( in_array( (string) $attachment_id, $ids, true ) ) {
            $p = wc_get_product( $pid );
            if ( $p ) {
                $usage[] = [
                    'product_id' => $pid,
                    'name'       => $p->get_name(),
                    'usage'      => 'gallery',
                ];
            }
        }
    }

    // Variation thumbnails
    $var_products = get_posts( [
        'post_type'      => 'product_variation',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [
            'key'   => '_thumbnail_id',
            'value' => $attachment_id,
        ] ],
    ] );

    foreach ( $var_products as $vid ) {
        $v = wc_get_product( $vid );
        if ( $v ) {
            $parent = wc_get_product( $v->get_parent_id() );
            $usage[] = [
                'product_id' => $v->get_parent_id(),
                'name'       => $parent ? $parent->get_name() : "Variante #{$vid}",
                'usage'      => 'variation_thumbnail',
            ];
        }
    }

    return $usage;
}
