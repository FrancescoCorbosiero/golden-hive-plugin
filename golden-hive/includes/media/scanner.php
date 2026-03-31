<?php
/**
 * Scanner — identifica attachment orfani nella media library.
 * Nessun side effect, solo lettura.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ritorna tutti gli attachment della media library.
 *
 * @param string $mime_filter 'all' | 'image' | 'video' | 'application'
 * @return array [ [ id, url, filename, filesize, filesize_human, date, mime_type, thumbnail_url ] ]
 */
function rp_mm_get_all_attachments( string $mime_filter = 'all' ): array {

    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    if ( $mime_filter !== 'all' ) {
        $args['post_mime_type'] = $mime_filter;
    }

    $ids    = get_posts( $args );
    $result = [];

    foreach ( $ids as $id ) {
        $result[] = rp_mm_build_attachment_data( $id );
    }

    return $result;
}

/**
 * Ritorna gli ID di tutti gli attachment attualmente in uso.
 *
 * Fonti: featured images, galleries WooCommerce, varianti,
 * post/page thumbnails, immagini inline nel content.
 *
 * @return int[] Array deduplicato di attachment ID.
 */
function rp_mm_get_used_attachment_ids(): array {

    $used = [];

    // 1. Featured images di tutti i prodotti WooCommerce
    $product_ids = get_posts( [
        'post_type'      => [ 'product', 'product_variation' ],
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $product_ids as $pid ) {
        $thumb = get_post_thumbnail_id( $pid );
        if ( $thumb ) $used[] = (int) $thumb;
    }

    // 2. Gallery WooCommerce (CSV in _product_image_gallery)
    $gallery_posts = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [
            'key'     => '_product_image_gallery',
            'value'   => '',
            'compare' => '!=',
        ] ],
    ] );

    foreach ( $gallery_posts as $pid ) {
        $csv = get_post_meta( $pid, '_product_image_gallery', true );
        $ids = array_filter( explode( ',', $csv ), fn( $v ) => $v !== '' );
        foreach ( $ids as $id ) {
            $used[] = (int) $id;
        }
    }

    // 3. Featured images di post e pagine
    $other_posts = get_posts( [
        'post_type'      => [ 'post', 'page' ],
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $other_posts as $pid ) {
        $thumb = get_post_thumbnail_id( $pid );
        if ( $thumb ) $used[] = (int) $thumb;
    }

    // 4. Immagini inline nel content
    $content_posts = get_posts( [
        'post_type'      => [ 'product', 'post', 'page' ],
        'post_status'    => 'any',
        'posts_per_page' => -1,
    ] );

    $url_index = null;
    foreach ( $content_posts as $post ) {
        $content = $post->post_content . ' ' . $post->post_excerpt;
        if ( preg_match_all( '/(?:src|href)=["\']([^"\']+?\.(?:jpe?g|png|gif|webp|svg))["\']?/i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $att_id = attachment_url_to_postid( $url );
                if ( $att_id ) $used[] = $att_id;
            }
        }
    }

    return array_values( array_unique( $used ) );
}

/**
 * Ritorna gli attachment orfani (non in uso e non in whitelist).
 *
 * @return array Array di attachment data con flag is_whitelisted.
 */
function rp_mm_get_orphan_attachments(): array {

    $all  = rp_mm_get_all_attachments( 'image' );
    $used = array_flip( rp_mm_get_used_attachment_ids() );

    $orphans = [];
    foreach ( $all as $att ) {
        if ( isset( $used[ $att['id'] ] ) ) continue;

        $att['is_whitelisted']  = rp_mm_is_whitelisted( $att['id'] );
        $att['whitelist_reason'] = $att['is_whitelisted']
            ? rp_mm_get_whitelist_reason( $att['id'] )
            : null;

        $orphans[] = $att;
    }

    return $orphans;
}

/**
 * Stima la dimensione totale degli orfani eliminabili.
 *
 * @return array [ 'count' => int, 'total_bytes' => int, 'total_human' => string ]
 */
function rp_mm_estimate_orphan_size(): array {

    $orphans     = rp_mm_get_orphan_attachments();
    $total_bytes = 0;
    $deletable   = 0;

    foreach ( $orphans as $att ) {
        if ( $att['is_whitelisted'] ) continue;
        $total_bytes += $att['filesize'] ?? 0;
        $deletable++;
    }

    return [
        'count'       => $deletable,
        'total_bytes' => $total_bytes,
        'total_human' => size_format( $total_bytes ),
    ];
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Costruisce l'oggetto dati per un singolo attachment.
 *
 * @param int $id Attachment ID.
 * @return array
 */
function rp_mm_build_attachment_data( int $id ): array {

    $url      = wp_get_attachment_url( $id );
    $file     = get_attached_file( $id );
    $filesize = $file && file_exists( $file ) ? filesize( $file ) : 0;
    $post     = get_post( $id );
    $thumb    = wp_get_attachment_image_src( $id, 'thumbnail' );

    return [
        'id'             => $id,
        'url'            => $url ?: '',
        'filename'       => $file ? basename( $file ) : '',
        'filesize'       => $filesize,
        'filesize_human' => size_format( $filesize ),
        'date'           => $post?->post_date ?? '',
        'mime_type'      => $post?->post_mime_type ?? '',
        'thumbnail_url'  => $thumb[0] ?? $url,
    ];
}
