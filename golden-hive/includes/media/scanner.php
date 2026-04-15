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
 * Wrapper di comodo che appiattisce l'output di rp_mm_build_usage_map().
 * Mantenuto per compatibilita con i chiamanti esistenti (cleaner, scanner).
 *
 * @return int[] Array deduplicato di attachment ID.
 */
function rp_mm_get_used_attachment_ids(): array {

    $map = rp_mm_build_usage_map();
    return $map['all_used'];
}

/**
 * Costruisce la mappa "media in uso" con breakdown per sorgente.
 *
 * Questa e la primitiva del "safe cleanup": prima di marcare qualcosa come
 * orfano, dobbiamo avere evidenza completa di tutti gli attachment
 * effettivamente referenziati dal sito. Ogni sorgente ritorna la sua lista
 * di ID, cosi l'UI puo mostrare un breakdown ispezionabile ("featured
 * products: 217, gallery: 890, variation thumbs: 120, inline content: 42").
 *
 * Il set `all_used` e l'unione deduplicata: il diff con tutti gli attachment
 * immagine restituisce gli orfani "100% sicuri".
 *
 * @return array {
 *     featured_products:  int[] — featured image dei prodotti simple/variable
 *     featured_variations:int[] — thumbnail delle varianti
 *     gallery_products:   int[] — ID in _product_image_gallery (dedup)
 *     featured_posts:     int[] — featured di post/page
 *     inline_content:     int[] — riferiti via <img src> o <a href> nel content
 *     all_used:           int[] — unione deduplicata di tutti i precedenti
 * }
 */
function rp_mm_build_usage_map(): array {

    $featured_products   = [];
    $featured_variations = [];
    $gallery_products    = [];
    $featured_posts      = [];
    $inline_content      = [];

    // 1. Featured images di prodotti simple/variable + variazioni
    $product_ids = get_posts( [
        'post_type'      => [ 'product', 'product_variation' ],
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $product_ids as $pid ) {
        $thumb = get_post_thumbnail_id( $pid );
        if ( ! $thumb ) continue;
        if ( get_post_type( $pid ) === 'product_variation' ) {
            $featured_variations[] = (int) $thumb;
        } else {
            $featured_products[] = (int) $thumb;
        }
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
            $gallery_products[] = (int) $id;
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
        if ( $thumb ) $featured_posts[] = (int) $thumb;
    }

    // 4. Immagini inline referenziate nel content (src/href)
    $content_posts = get_posts( [
        'post_type'      => [ 'product', 'post', 'page' ],
        'post_status'    => 'any',
        'posts_per_page' => -1,
    ] );

    foreach ( $content_posts as $post ) {
        $content = $post->post_content . ' ' . $post->post_excerpt;
        if ( preg_match_all( '/(?:src|href)=["\']([^"\']+?\.(?:jpe?g|png|gif|webp|svg))["\']?/i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $att_id = attachment_url_to_postid( $url );
                if ( $att_id ) $inline_content[] = (int) $att_id;
            }
        }
    }

    // Dedup locale (ogni sorgente) + unione globale
    $featured_products   = array_values( array_unique( $featured_products ) );
    $featured_variations = array_values( array_unique( $featured_variations ) );
    $gallery_products    = array_values( array_unique( $gallery_products ) );
    $featured_posts      = array_values( array_unique( $featured_posts ) );
    $inline_content      = array_values( array_unique( $inline_content ) );

    $all_used = array_values( array_unique( array_merge(
        $featured_products,
        $featured_variations,
        $gallery_products,
        $featured_posts,
        $inline_content
    ) ) );

    return [
        'featured_products'   => $featured_products,
        'featured_variations' => $featured_variations,
        'gallery_products'    => $gallery_products,
        'featured_posts'      => $featured_posts,
        'inline_content'      => $inline_content,
        'all_used'            => $all_used,
    ];
}

/**
 * Ritorna gli attachment orfani (non in uso e non in whitelist).
 *
 * @param array|null $usage_map Map precomputata da rp_mm_build_usage_map().
 *                              Passarla evita di riscansionare il sito due volte
 *                              quando anche il breakdown serve all'UI.
 * @return array Array di attachment data con flag is_whitelisted.
 */
function rp_mm_get_orphan_attachments( ?array $usage_map = null ): array {

    $all  = rp_mm_get_all_attachments( 'image' );
    $ids  = $usage_map['all_used'] ?? rp_mm_get_used_attachment_ids();
    $used = array_flip( $ids );

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
 * @param array|null $orphans Lista orfani precalcolata (evita doppia scansione).
 * @return array [ 'count' => int, 'total_bytes' => int, 'total_human' => string ]
 */
function rp_mm_estimate_orphan_size( ?array $orphans = null ): array {

    $orphans     = $orphans ?? rp_mm_get_orphan_attachments();
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
