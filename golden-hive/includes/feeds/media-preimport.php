<?php
/**
 * Media Pre-Import — scarica le immagini PRIMA dell'import prodotti.
 *
 * Flusso: il client raccoglie tutti gli URL immagine dal feed preview,
 * li invia in batch al server, che li scarica nella media library e
 * costruisce una mappa `source_url → attachment_id`. Questa mappa viene
 * poi usata dal product create per assegnare featured/gallery senza
 * nessun download in-line (istantaneo).
 *
 * La mappa e persistita in wp_options con TTL implicito (si sovrascrive
 * ad ogni pre-import). Il product create la legge e fa un semplice
 * array lookup.
 *
 * Vantaggi vs sideload on-the-fly:
 * - Product creation diventa istantanea (0 network I/O)
 * - Le immagini si scaricano in batch da 10, resumable, con progress
 * - Se crasha al download #500, riprendi e salta i 500 gia scaricati
 * - La mappa e ispezionabile: sai quante immagini sono state scaricate
 *   prima di impegnarti con i prodotti
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
 * Scarica un batch di URL immagine nella media library.
 *
 * Per ogni URL:
 * 1. Controlla se gia nella mappa (skip se presente — resumable)
 * 2. download_url() con timeout 30s
 * 3. media_handle_sideload() → attachment_id
 * 4. Aggiunge alla mappa
 *
 * Ritorna i risultati per questo batch + lo stato aggiornato della mappa.
 *
 * @param array $urls  Array di { url, sku } da scaricare.
 * @return array {
 *     downloaded: int,
 *     skipped: int,
 *     errors: int,
 *     error_urls: string[],
 *     map_size: int,
 * }
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

    foreach ( $urls as $item ) {
        $url = is_array( $item ) ? ( $item['url'] ?? '' ) : (string) $item;
        $sku = is_array( $item ) ? ( $item['sku'] ?? '' ) : '';

        if ( ! $url ) continue;

        // Gia scaricata? Skip (resumable)
        if ( isset( $map[ $url ] ) ) {
            // Verifica che l'attachment esista ancora
            if ( wp_get_attachment_url( $map[ $url ] ) ) {
                $skipped++;
                continue;
            }
            // Attachment sparito — ri-scarica
            unset( $map[ $url ] );
        }

        // Download
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            $errors++;
            $error_urls[] = $url;
            continue;
        }

        // Filename: sku-N.ext o hash dell'URL
        $ext      = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'jpg';
        $basename = $sku ? sanitize_file_name( $sku ) : md5( $url );
        // Aggiungi un suffisso unico per evitare collisioni
        $filename = $basename . '-' . substr( md5( $url ), 0, 6 ) . '.' . $ext;

        $att_id = media_handle_sideload( [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ], 0 ); // parent=0: non associato a nessun post

        if ( is_wp_error( $att_id ) ) {
            @unlink( $tmp );
            $errors++;
            $error_urls[] = $url;
            continue;
        }

        $map[ $url ] = (int) $att_id;
        $downloaded++;
    }

    // Persisti la mappa aggiornata
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
 * Dato un array di URL, risolvi i corrispondenti attachment_id dalla mappa.
 *
 * Usata dal product create: invece di download_url(), fa un array lookup.
 *
 * @param array $urls Array di URL sorgente.
 * @return int[] Array di attachment_id (solo quelli trovati nella mappa).
 */
function gh_preimport_resolve_urls( array $urls ): array {

    $map = gh_preimport_get_map();
    $ids = [];

    foreach ( $urls as $url ) {
        if ( isset( $map[ $url ] ) ) {
            $ids[] = (int) $map[ $url ];
        }
    }

    return $ids;
}

/**
 * Assegna le immagini pre-importate a un prodotto usando la mappa.
 *
 * Prima immagine → featured, resto → gallery. Stessa logica di
 * gh_fc_sideload_images() ma senza nessun download: solo assegnazioni.
 *
 * @param int   $product_id
 * @param array $urls        URL sorgente delle immagini.
 * @param array $cfg         { first_is_featured?, rest_is_gallery? }
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
 * Resetta la mappa (per un nuovo ciclo di pre-import).
 */
function gh_preimport_clear_map(): void {
    update_option( GH_MEDIA_PREIMPORT_MAP_KEY, [], false );
}

/**
 * Ritorna statistiche sulla mappa corrente.
 *
 * @return array { total, valid }
 */
function gh_preimport_map_stats(): array {
    $map = gh_preimport_get_map();
    $valid = 0;
    foreach ( $map as $url => $att_id ) {
        if ( wp_get_attachment_url( $att_id ) ) $valid++;
    }
    return [
        'total' => count( $map ),
        'valid' => $valid,
    ];
}
