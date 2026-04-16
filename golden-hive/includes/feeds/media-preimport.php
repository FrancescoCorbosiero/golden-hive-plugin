<?php
/**
 * Media Pre-Import — scarica le immagini PRIMA dell'import prodotti.
 *
 * Usa curl_multi per scaricare le immagini in parallelo (fino a 10
 * connessioni simultanee per batch). Su un batch di 10 URL, il tempo
 * totale e quello dell'immagine piu lenta, non la somma di tutte.
 */

defined( 'ABSPATH' ) || exit;

const GH_MEDIA_PREIMPORT_MAP_KEY = 'gh_media_preimport_map';

/**
 * Ritorna la mappa corrente source_url → attachment_id.
 *
 * @return array<string,int>
 */
function gh_preimport_get_map(): array {
    $map = get_option( GH_MEDIA_PREIMPORT_MAP_KEY, [] );
    return is_array( $map ) ? $map : [];
}

/**
 * Scarica un batch di URL in parallelo via curl_multi, poi importa in WP.
 *
 * @param array $urls  Array di { url, sku }.
 * @return array { downloaded, skipped, errors, error_urls, map_size }
 */
function gh_preimport_download_batch( array $urls ): array {

    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $map = gh_preimport_get_map();

    $downloaded = 0;
    $skipped    = 0;
    $errors     = 0;
    $error_urls = [];

    // 1. Filter: skip already-downloaded, collect what needs fetching
    $to_fetch = []; // [ { url, sku, tmp_path } ]
    foreach ( $urls as $item ) {
        $url = is_array( $item ) ? ( $item['url'] ?? '' ) : (string) $item;
        $sku = is_array( $item ) ? ( $item['sku'] ?? '' ) : '';
        if ( ! $url ) continue;

        if ( isset( $map[ $url ] ) && wp_get_attachment_url( $map[ $url ] ) ) {
            $skipped++;
            continue;
        }
        unset( $map[ $url ] );

        $tmp = wp_tempnam( $url );
        $to_fetch[] = [ 'url' => $url, 'sku' => $sku, 'tmp' => $tmp ];
    }

    if ( empty( $to_fetch ) ) {
        update_option( GH_MEDIA_PREIMPORT_MAP_KEY, $map, false );
        return compact( 'downloaded', 'skipped', 'errors', 'error_urls' ) + [ 'map_size' => count( $map ) ];
    }

    // 2. Parallel download via curl_multi
    $mh      = curl_multi_init();
    $handles = [];

    foreach ( $to_fetch as $i => $item ) {
        $ch = curl_init( $item['url'] );
        $fp = fopen( $item['tmp'], 'wb' );

        curl_setopt_array( $ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_USERAGENT       => 'GoldenHive/1.0',
        ] );

        curl_multi_add_handle( $mh, $ch );
        $handles[ $i ] = [ 'ch' => $ch, 'fp' => $fp ];
    }

    // Execute all in parallel
    do {
        $status = curl_multi_exec( $mh, $active );
        if ( $active ) {
            curl_multi_select( $mh, 1.0 );
        }
    } while ( $active && $status === CURLM_OK );

    // 3. Collect results + import each file into WP media library
    foreach ( $to_fetch as $i => $item ) {
        $ch   = $handles[ $i ]['ch'];
        $fp   = $handles[ $i ]['fp'];
        fclose( $fp );

        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err       = curl_error( $ch );

        curl_multi_remove_handle( $mh, $ch );
        curl_close( $ch );

        if ( $http_code < 200 || $http_code >= 400 || $err || ! file_exists( $item['tmp'] ) || filesize( $item['tmp'] ) < 100 ) {
            @unlink( $item['tmp'] );
            $errors++;
            $error_urls[] = $item['url'];
            continue;
        }

        // Build a clean filename
        $ext      = pathinfo( parse_url( $item['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
        $basename = $item['sku'] ? sanitize_file_name( $item['sku'] ) : md5( $item['url'] );
        $filename = $basename . '-' . substr( md5( $item['url'] ), 0, 6 ) . '.' . $ext;

        $att_id = media_handle_sideload( [
            'name'     => $filename,
            'tmp_name' => $item['tmp'],
        ], 0 );

        if ( is_wp_error( $att_id ) ) {
            @unlink( $item['tmp'] );
            $errors++;
            $error_urls[] = $item['url'];
            continue;
        }

        $map[ $item['url'] ] = (int) $att_id;
        $downloaded++;
    }

    curl_multi_close( $mh );

    update_option( GH_MEDIA_PREIMPORT_MAP_KEY, $map, false );

    return [
        'downloaded' => $downloaded,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'error_urls' => $error_urls,
        'map_size'   => count( $map ),
    ];
}

/**
 * Resolve source URLs to local attachment IDs using the pre-import map.
 *
 * @param array $urls
 * @return int[]
 */
function gh_preimport_resolve_urls( array $urls ): array {
    $map = gh_preimport_get_map();
    $ids = [];
    foreach ( $urls as $url ) {
        if ( isset( $map[ $url ] ) ) $ids[] = (int) $map[ $url ];
    }
    return $ids;
}

/**
 * Assign pre-imported images to a product. First = featured, rest = gallery.
 *
 * @param int   $product_id
 * @param array $urls
 * @param array $cfg  { first_is_featured?, rest_is_gallery? }
 */
function gh_preimport_assign_images( int $product_id, array $urls, array $cfg = [] ): void {
    $att_ids = gh_preimport_resolve_urls( $urls );
    if ( empty( $att_ids ) ) return;

    $first_featured = $cfg['first_is_featured'] ?? true;
    $rest_gallery   = $cfg['rest_is_gallery'] ?? true;
    $gallery        = [];

    foreach ( $att_ids as $i => $att_id ) {
        if ( $i === 0 && $first_featured ) {
            set_post_thumbnail( $product_id, $att_id );
        } elseif ( $rest_gallery ) {
            $gallery[] = $att_id;
        }
    }

    if ( $gallery ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_gallery_image_ids( $gallery );
            $product->save();
        }
    }
}

/**
 * Parallel sideload: downloads N image URLs simultaneously via curl_multi
 * and imports them into WP media library. Used by all feed importers as a
 * drop-in replacement for the old sequential download_url() loops.
 *
 * Flow:
 * 1. Check pre-import map for each URL (skip already-downloaded)
 * 2. curl_multi parallel download for the rest
 * 3. media_handle_sideload each successful file into WP
 * 4. Assign: first = featured, rest = gallery (configurable)
 *
 * @param int   $product_id  WC product to attach images to.
 * @param array $urls        Image URLs to download.
 * @param string $sku        SKU for filename generation.
 * @param array  $cfg        { first_is_featured?: bool, rest_is_gallery?: bool }
 */
function gh_parallel_sideload_to_product( int $product_id, array $urls, string $sku = '', array $cfg = [] ): void {

    $urls = array_filter( $urls );
    if ( empty( $urls ) ) return;

    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $first_featured = $cfg['first_is_featured'] ?? true;
    $rest_gallery   = $cfg['rest_is_gallery'] ?? true;

    // 1. Check pre-import map first (instant, no network)
    $map         = gh_preimport_get_map();
    $to_download = [];
    $resolved    = []; // index => attachment_id

    foreach ( array_values( $urls ) as $i => $url ) {
        if ( isset( $map[ $url ] ) && wp_get_attachment_url( $map[ $url ] ) ) {
            $resolved[ $i ] = (int) $map[ $url ];
        } else {
            $to_download[ $i ] = $url;
        }
    }

    // 2. Parallel download missing ones via curl_multi
    if ( ! empty( $to_download ) ) {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ( $to_download as $i => $url ) {
            $tmp = wp_tempnam( $url );
            $ch  = curl_init( $url );
            $fp  = fopen( $tmp, 'wb' );

            curl_setopt_array( $ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'GoldenHive/1.0',
            ] );

            curl_multi_add_handle( $mh, $ch );
            $handles[ $i ] = [ 'ch' => $ch, 'fp' => $fp, 'tmp' => $tmp, 'url' => $url ];
        }

        do {
            $status = curl_multi_exec( $mh, $active );
            if ( $active ) curl_multi_select( $mh, 1.0 );
        } while ( $active && $status === CURLM_OK );

        foreach ( $handles as $i => $h ) {
            fclose( $h['fp'] );
            $http = (int) curl_getinfo( $h['ch'], CURLINFO_HTTP_CODE );
            $cerr = curl_error( $h['ch'] );
            curl_multi_remove_handle( $mh, $h['ch'] );
            curl_close( $h['ch'] );

            if ( $http < 200 || $http >= 400 || $cerr || ! file_exists( $h['tmp'] ) || filesize( $h['tmp'] ) < 100 ) {
                @unlink( $h['tmp'] );
                continue;
            }

            $ext      = pathinfo( parse_url( $h['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
            $basename = $sku ? sanitize_file_name( $sku ) : md5( $h['url'] );
            $filename = $basename . '-' . ( $i + 1 ) . '.' . $ext;

            $att_id = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $h['tmp'] ], $product_id );
            if ( is_wp_error( $att_id ) ) { @unlink( $h['tmp'] ); continue; }

            $resolved[ $i ] = (int) $att_id;
            $map[ $h['url'] ] = (int) $att_id;
        }

        curl_multi_close( $mh );
        update_option( GH_MEDIA_PREIMPORT_MAP_KEY, $map, false );
    }

    // 3. Assign: first = featured, rest = gallery (in original URL order)
    ksort( $resolved );
    $gallery = [];
    $assigned_first = false;

    foreach ( $resolved as $i => $att_id ) {
        if ( ! $assigned_first && $first_featured ) {
            set_post_thumbnail( $product_id, $att_id );
            $assigned_first = true;
        } elseif ( $rest_gallery ) {
            $gallery[] = $att_id;
        }
    }

    if ( $gallery ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_gallery_image_ids( $gallery );
            $product->save();
        }
    }
}

/**
 * Clear the map for a new import cycle.
 */
function gh_preimport_clear_map(): void {
    update_option( GH_MEDIA_PREIMPORT_MAP_KEY, [], false );
}

/**
 * Map stats.
 *
 * @return array { total, valid }
 */
function gh_preimport_map_stats(): array {
    $map   = gh_preimport_get_map();
    $valid = 0;
    foreach ( $map as $url => $att_id ) {
        if ( wp_get_attachment_url( $att_id ) ) $valid++;
    }
    return [ 'total' => count( $map ), 'valid' => $valid ];
}
