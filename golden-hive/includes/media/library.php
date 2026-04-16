<?php
/**
 * Library — operazioni su prodotti lato media (assegna featured/gallery,
 * reverse lookup "quali prodotti usano questo attachment").
 *
 * Solo lettura tranne rp_mm_set_product_featured_image() / _set_product_gallery(),
 * usate da UI inline (Media Library → row actions) e feed importers.
 *
 * La ricerca paginaged della media library e il product-media mapping
 * vivono ora in media/browser.php (indice inverso cached per il Media
 * Library panel). Le funzioni legacy di browse/mapping sono state rimosse.
 */

defined( 'ABSPATH' ) || exit;

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
