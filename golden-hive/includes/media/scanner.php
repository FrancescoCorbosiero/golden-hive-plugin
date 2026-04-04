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
 * Ritorna SOLO gli ID delle immagini usate dai prodotti WooCommerce.
 * Più restrittivo di rp_mm_get_used_attachment_ids() — ignora post/page/content inline.
 * Questo è il "safe set" che non deve MAI essere toccato dal cleanup.
 *
 * Fonti:
 * 1. Featured image di ogni prodotto (tutti gli stati)
 * 2. Gallery di ogni prodotto (_product_image_gallery)
 * 3. Featured image di ogni variazione
 *
 * @return int[] Array deduplicato di attachment ID.
 */
function rp_mm_get_product_image_ids(): array {

    $ids = [];

    // 1. Featured images di tutti i prodotti
    $products = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $products as $pid ) {
        $thumb = get_post_thumbnail_id( $pid );
        if ( $thumb ) $ids[] = (int) $thumb;
    }

    // 2. Gallery WooCommerce
    foreach ( $products as $pid ) {
        $csv = get_post_meta( $pid, '_product_image_gallery', true );
        if ( ! empty( $csv ) ) {
            foreach ( explode( ',', $csv ) as $id ) {
                $id = (int) trim( $id );
                if ( $id > 0 ) $ids[] = $id;
            }
        }
    }

    // 3. Featured images di tutte le variazioni
    $variations = get_posts( [
        'post_type'      => 'product_variation',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $variations as $vid ) {
        $thumb = get_post_thumbnail_id( $vid );
        if ( $thumb ) $ids[] = (int) $thumb;
    }

    return array_values( array_unique( $ids ) );
}

/**
 * Esegue un safe scan: confronta TUTTE le immagini in media library con quelle
 * effettivamente usate dai prodotti WooCommerce + whitelist.
 *
 * Restituisce un report dettagliato con 4 categorie:
 * - protected_product: immagini usate da almeno un prodotto (MAI eliminabili)
 * - protected_whitelist: immagini in whitelist (MAI eliminabili)
 * - protected_other: immagini usate in post/pagine/content ma non da prodotti
 * - safe_to_delete: immagini non usate da nessuna parte e non in whitelist
 *
 * @return array {
 *     all_media_count: int,
 *     product_images: int[],
 *     report: [
 *         protected_product: array,
 *         protected_whitelist: array,
 *         protected_other: array,
 *         safe_to_delete: array,
 *     ],
 *     summary: {
 *         total: int,
 *         protected_product: int,
 *         protected_whitelist: int,
 *         protected_other: int,
 *         safe_to_delete: int,
 *         reclaimable_bytes: int,
 *         reclaimable_human: string,
 *     }
 * }
 */
function rp_mm_safe_orphan_scan(): array {

    // Step 1: raccoglie TUTTI i media (immagini)
    $all_media = rp_mm_get_all_attachments( 'image' );

    // Step 2: raccoglie le 3 fonti di protezione
    $product_ids   = array_flip( rp_mm_get_product_image_ids() );
    $all_used_ids  = array_flip( rp_mm_get_used_attachment_ids() );
    $whitelist_ids = [];
    foreach ( rp_mm_get_whitelist() as $wl ) {
        if ( ! empty( $wl['id'] ) ) $whitelist_ids[ (int) $wl['id'] ] = $wl['reason'] ?? '';
    }

    // Step 3: classifica ogni immagine
    $protected_product   = [];
    $protected_whitelist = [];
    $protected_other     = [];
    $safe_to_delete      = [];
    $reclaimable_bytes   = 0;

    foreach ( $all_media as $att ) {
        $id = $att['id'];

        if ( isset( $product_ids[ $id ] ) ) {
            $att['protection'] = 'product';
            $protected_product[] = $att;
        } elseif ( isset( $whitelist_ids[ $id ] ) ) {
            $att['protection']       = 'whitelist';
            $att['whitelist_reason'] = $whitelist_ids[ $id ];
            $protected_whitelist[] = $att;
        } elseif ( isset( $all_used_ids[ $id ] ) ) {
            $att['protection'] = 'other_content';
            $protected_other[] = $att;
        } else {
            $att['protection'] = 'none';
            $safe_to_delete[] = $att;
            $reclaimable_bytes += $att['filesize'] ?? 0;
        }
    }

    return [
        'all_media_count' => count( $all_media ),
        'product_images'  => array_keys( $product_ids ),
        'report'          => [
            'protected_product'   => $protected_product,
            'protected_whitelist' => $protected_whitelist,
            'protected_other'     => $protected_other,
            'safe_to_delete'      => $safe_to_delete,
        ],
        'summary' => [
            'total'               => count( $all_media ),
            'protected_product'   => count( $protected_product ),
            'protected_whitelist' => count( $protected_whitelist ),
            'protected_other'     => count( $protected_other ),
            'safe_to_delete'      => count( $safe_to_delete ),
            'reclaimable_bytes'   => $reclaimable_bytes,
            'reclaimable_human'   => size_format( $reclaimable_bytes ),
        ],
    ];
}

// ── Data Helpers ────────────────────────────────────────────

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
