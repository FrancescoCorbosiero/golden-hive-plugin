<?php
/**
 * Media Pre-Import — scarica le immagini PRIMA dell'import prodotti.
 *
 * Architettura ispirata a woo-importer/MediaUploader:
 * - Sliding window curl_multi (non aspetta che tutti finiscano — appena
 *   un handle completa, ne aggiunge uno nuovo dalla coda)
 * - Thumbnail generation disabilitata durante il batch (aggiunge solo il
 *   file originale, senza generare 5+ sub-sizes per immagine). Questo e
 *   il singolo speed-up piu grande: da ~3s/img a ~0.3s/img.
 * - Mappa persistente source_url → attachment_id per skip/resume
 */

defined( 'ABSPATH' ) || exit;

const GH_MEDIA_PREIMPORT_MAP_KEY = 'gh_media_preimport_map';

function gh_preimport_get_map(): array {
    $map = get_option( GH_MEDIA_PREIMPORT_MAP_KEY, [] );
    return is_array( $map ) ? $map : [];
}

/**
 * Downloads a batch of image URLs using a sliding-window curl_multi and
 * imports each into WP media library with thumbnail generation DISABLED.
 *
 * @param array $urls       Array of { url, sku }.
 * @param int   $concurrency Max simultaneous connections (default 10).
 * @return array { downloaded, skipped, errors, error_urls, map_size }
 */
function gh_preimport_download_batch( array $urls, int $concurrency = 10 ): array {

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

    // 1. Build the download queue — skip already-mapped URLs
    $queue = []; // [ { url, sku, tmp } ]
    foreach ( $urls as $item ) {
        $url = is_array( $item ) ? ( $item['url'] ?? '' ) : (string) $item;
        $sku = is_array( $item ) ? ( $item['sku'] ?? '' ) : '';
        if ( ! $url ) continue;

        if ( isset( $map[ $url ] ) && wp_get_attachment_url( $map[ $url ] ) ) {
            $skipped++;
            continue;
        }
        unset( $map[ $url ] );
        $queue[] = [ 'url' => $url, 'sku' => $sku ];
    }

    if ( empty( $queue ) ) {
        update_option( GH_MEDIA_PREIMPORT_MAP_KEY, $map, false );
        return compact( 'downloaded', 'skipped', 'errors', 'error_urls' ) + [ 'map_size' => count( $map ) ];
    }

    // 2. DISABLE THUMBNAIL GENERATION for speed.
    //    media_handle_sideload → wp_generate_attachment_metadata → creates
    //    5+ sub-sizes per image. This is the #1 bottleneck. Disabling it
    //    means only the original file is stored. Thumbnails can be
    //    regenerated later via "Regenerate Thumbnails" or WP-CLI.
    add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );

    // 3. Sliding-window curl_multi: keep $concurrency connections active.
    //    As each handle completes → immediately sideload the file into WP
    //    and start the next download from the queue. This keeps the pipe
    //    full instead of waiting for the slowest download in the batch.
    $mh       = curl_multi_init();
    $active   = []; // key(ch) → { ch, fp, tmp, url, sku }
    $qi       = 0;  // queue index

    // Fill initial window
    while ( count( $active ) < $concurrency && $qi < count( $queue ) ) {
        $active = gh_preimport_add_handle( $mh, $active, $queue[ $qi ] );
        $qi++;
    }

    // Process
    while ( ! empty( $active ) ) {
        $status = curl_multi_exec( $mh, $running );
        if ( $running ) {
            curl_multi_select( $mh, 0.5 );
        }

        // Check completed handles
        while ( $info = curl_multi_info_read( $mh ) ) {
            $ch  = $info['handle'];
            $key = (int) $ch;

            if ( ! isset( $active[ $key ] ) ) continue;

            $h = $active[ $key ];
            fclose( $h['fp'] );

            $http = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $cerr = curl_error( $ch );
            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );
            unset( $active[ $key ] );

            // Validate download
            if ( $http < 200 || $http >= 400 || $cerr || ! file_exists( $h['tmp'] ) || filesize( $h['tmp'] ) < 100 ) {
                @unlink( $h['tmp'] );
                $errors++;
                $error_urls[] = $h['url'];
            } else {
                // Import into WP (fast — no thumbnails)
                $ext      = pathinfo( parse_url( $h['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
                $basename = $h['sku'] ? sanitize_file_name( $h['sku'] ) : md5( $h['url'] );
                $filename = $basename . '-' . substr( md5( $h['url'] ), 0, 6 ) . '.' . $ext;

                $att_id = media_handle_sideload( [
                    'name'     => $filename,
                    'tmp_name' => $h['tmp'],
                ], 0 );

                if ( is_wp_error( $att_id ) ) {
                    @unlink( $h['tmp'] );
                    $errors++;
                    $error_urls[] = $h['url'];
                } else {
                    $map[ $h['url'] ] = (int) $att_id;
                    $downloaded++;
                }
            }

            // Refill: add next URL from queue
            if ( $qi < count( $queue ) ) {
                $active = gh_preimport_add_handle( $mh, $active, $queue[ $qi ] );
                $qi++;
            }
        }
    }

    curl_multi_close( $mh );

    // 4. Re-enable thumbnail generation
    remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );

    // 5. Persist map
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
 * Adds a curl handle to the multi pool.
 */
function gh_preimport_add_handle( $mh, array $active, array $item ): array {

    $tmp = wp_tempnam( $item['url'] );
    $ch  = curl_init( $item['url'] );
    $fp  = fopen( $tmp, 'wb' );

    curl_setopt_array( $ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 GoldenHive/1.0',
    ] );

    curl_multi_add_handle( $mh, $ch );
    $active[ (int) $ch ] = [
        'ch'  => $ch,
        'fp'  => $fp,
        'tmp' => $tmp,
        'url' => $item['url'],
        'sku' => $item['sku'],
    ];

    return $active;
}

// ── SHARED PARALLEL SIDELOAD ────────────────────────────────────────────────

/**
 * Parallel sideload to a specific product. Checks pre-import map first,
 * then downloads missing images via sliding-window curl_multi with
 * thumbnails disabled.
 *
 * @param int    $product_id
 * @param array  $urls
 * @param string $sku
 * @param array  $cfg  { first_is_featured?, rest_is_gallery? }
 */
function gh_parallel_sideload_to_product( int $product_id, array $urls, string $sku = '', array $cfg = [] ): void {

    $urls = array_values( array_filter( $urls ) );
    if ( empty( $urls ) ) return;

    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $first_featured = $cfg['first_is_featured'] ?? true;
    $rest_gallery   = $cfg['rest_is_gallery'] ?? true;

    // 1. Check pre-import map
    $map         = gh_preimport_get_map();
    $to_download = [];
    $resolved    = []; // index => att_id

    foreach ( $urls as $i => $url ) {
        if ( isset( $map[ $url ] ) && wp_get_attachment_url( $map[ $url ] ) ) {
            $resolved[ $i ] = (int) $map[ $url ];
        } else {
            $to_download[ $i ] = $url;
        }
    }

    // 2. Download missing via curl_multi (with thumbnail skip)
    if ( ! empty( $to_download ) ) {
        add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );

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
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 GoldenHive/1.0',
            ] );
            curl_multi_add_handle( $mh, $ch );
            $handles[ $i ] = [ 'ch' => $ch, 'fp' => $fp, 'tmp' => $tmp, 'url' => $url ];
        }

        do {
            $status = curl_multi_exec( $mh, $active );
            if ( $active ) curl_multi_select( $mh, 0.5 );
        } while ( $active && $status === CURLM_OK );

        foreach ( $handles as $i => $h ) {
            fclose( $h['fp'] );
            $http = (int) curl_getinfo( $h['ch'], CURLINFO_HTTP_CODE );
            curl_multi_remove_handle( $mh, $h['ch'] );
            curl_close( $h['ch'] );

            if ( $http < 200 || $http >= 400 || ! file_exists( $h['tmp'] ) || filesize( $h['tmp'] ) < 100 ) {
                @unlink( $h['tmp'] );
                continue;
            }

            $ext      = pathinfo( parse_url( $h['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
            $filename = ( $sku ? sanitize_file_name( $sku ) : md5( $h['url'] ) ) . '-' . ( $i + 1 ) . '.' . $ext;

            $att_id = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $h['tmp'] ], $product_id );
            if ( is_wp_error( $att_id ) ) { @unlink( $h['tmp'] ); continue; }

            $resolved[ $i ] = (int) $att_id;
            $map[ $h['url'] ] = (int) $att_id;
        }

        curl_multi_close( $mh );
        remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 999 );
        update_option( GH_MEDIA_PREIMPORT_MAP_KEY, $map, false );
    }

    // 3. Assign in original URL order
    ksort( $resolved );
    $gallery        = [];
    $assigned_first = false;

    foreach ( $resolved as $att_id ) {
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

// ── MAP UTILS ────────────────────────────────────────────────────────────────

function gh_preimport_resolve_urls( array $urls ): array {
    $map = gh_preimport_get_map();
    $ids = [];
    foreach ( $urls as $url ) {
        if ( isset( $map[ $url ] ) ) $ids[] = (int) $map[ $url ];
    }
    return $ids;
}

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

function gh_preimport_clear_map(): void {
    update_option( GH_MEDIA_PREIMPORT_MAP_KEY, [], false );
}

function gh_preimport_map_stats(): array {
    $map   = gh_preimport_get_map();
    $valid = 0;
    foreach ( $map as $att_id ) {
        if ( wp_get_attachment_url( $att_id ) ) $valid++;
    }
    return [ 'total' => count( $map ), 'valid' => $valid ];
}
