<?php
/**
 * Cleaner — eliminazione sicura degli attachment.
 * L'UNICO file che cancella dati. Sempre previa whitelist check.
 */

defined( 'ABSPATH' ) || exit;

const RP_MM_LOG_KEY     = 'rp_mm_deletion_log';
const RP_MM_LOG_MAX     = 500;

/**
 * Elimina un singolo attachment con tutti i safety check.
 *
 * @param int $attachment_id
 * @return true|WP_Error
 */
function rp_mm_delete_attachment( int $attachment_id ): true|WP_Error {

    // 1. Whitelist check
    if ( rp_mm_is_whitelisted( $attachment_id ) ) {
        return new WP_Error( 'whitelisted', "Attachment #{$attachment_id} e in whitelist." );
    }

    // 2. Double-check in uso
    if ( rp_mm_is_used( $attachment_id ) ) {
        return new WP_Error( 'in_use', "Attachment #{$attachment_id} risulta ancora in uso." );
    }

    // 3. Logga prima di eliminare (in caso di errore dopo, almeno abbiamo il log)
    $data = rp_mm_build_attachment_data( $attachment_id );
    rp_mm_log_deletion( $data );

    // 4. Elimina (force = true per rimuovere anche il file fisico)
    $result = wp_delete_attachment( $attachment_id, true );

    if ( ! $result ) {
        return new WP_Error( 'delete_failed', "Errore nell'eliminazione dell'attachment #{$attachment_id}." );
    }

    return true;
}

/**
 * Elimina N attachment in bulk. Non si ferma agli errori.
 *
 * Batch di quello che è batchabile senza indebolire le safety:
 * - le due whitelist (propria + Hive Sync) vengono lette UNA volta e
 *   consultate come set id/url — il loop legacy riscandiva l'option e
 *   risolveva l'URL per ogni item, DUE volte (qui e dentro
 *   rp_mm_delete_attachment);
 * - le cache post/meta degli attachment vengono primed in blocco, così
 *   get_attached_file / wp_get_attachment_url leggono da cache;
 * - il deletion log accumula in memoria e scrive l'option UNA volta a
 *   fine batch invece di serialize+write di 500 righe per eliminazione.
 *
 * NON viene batchato il check "in uso" (rp_mm_is_used): resta
 * point-in-time per ogni item, come da design del modulo — davanti a
 * una wp_delete_attachment(force=true) una snapshot vecchia di minuti
 * non è un risparmio accettabile.
 *
 * @param int[] $attachment_ids
 * @return array [ 'deleted' => int[], 'errors' => [ id => reason ], 'skipped_whitelist' => int[], 'freed_bytes' => int ]
 */
function rp_mm_bulk_delete( array $attachment_ids ): array {

    $deleted           = [];
    $errors            = [];
    $skipped_whitelist = [];
    $freed_bytes       = 0;
    $log_entries       = [];

    $attachment_ids = array_map( 'intval', $attachment_ids );
    if ( function_exists( 'gh_prime_product_caches' ) ) {
        gh_prime_product_caches( $attachment_ids );
    }

    // Union di enforcement (stessa semantica di rp_mm_is_whitelisted):
    // set id + set url da entrambe le whitelist, costruiti una volta.
    $wl_ids  = [];
    $wl_urls = [];
    $external = get_option( 'hsync_media_whitelist', [] );
    foreach ( array_merge( rp_mm_get_whitelist(), is_array( $external ) ? $external : [] ) as $entry ) {
        $wid = (int) ( $entry['id'] ?? 0 );
        if ( $wid > 0 ) $wl_ids[ $wid ] = true;
        $wurl = (string) ( $entry['url'] ?? '' );
        if ( $wurl !== '' ) $wl_urls[ $wurl ] = true;
    }

    foreach ( $attachment_ids as $id ) {
        if ( $id <= 0 ) continue;

        $url = wp_get_attachment_url( $id );
        if ( isset( $wl_ids[ $id ] ) || ( $url && isset( $wl_urls[ $url ] ) ) ) {
            $skipped_whitelist[] = $id;
            continue;
        }

        // Check puntuale point-in-time, identico al path singolo.
        if ( rp_mm_is_used( $id ) ) {
            $errors[ $id ] = "Attachment #{$id} risulta ancora in uso.";
            continue;
        }

        $file = get_attached_file( $id );
        $size = $file && file_exists( $file ) ? filesize( $file ) : 0;

        // Log PRIMA dell'eliminazione (stessa garanzia del path
        // singolo: se il process muore dopo, l'audit c'è) — ma
        // accumulato e persistito in un'unica scrittura a fine batch.
        $log_entries[] = rp_mm_build_attachment_data( $id );

        if ( ! wp_delete_attachment( $id, true ) ) {
            $errors[ $id ] = "Errore nell'eliminazione dell'attachment #{$id}.";
            continue;
        }

        $deleted[]    = $id;
        $freed_bytes += $size;

        // Ogni 100 eliminazioni persisti il log accumulato: bilancia
        // "una write per item" contro "tutto perso su fatal a metà".
        if ( count( $log_entries ) >= 100 ) {
            rp_mm_log_deletion_batch( $log_entries );
            $log_entries = [];
        }
    }

    if ( $log_entries ) {
        rp_mm_log_deletion_batch( $log_entries );
    }

    return [
        'deleted'           => $deleted,
        'errors'            => $errors,
        'skipped_whitelist' => $skipped_whitelist,
        'freed_bytes'       => $freed_bytes,
        'freed_human'       => size_format( $freed_bytes ),
    ];
}

/**
 * Controlla se un attachment e attualmente in uso (check puntuale).
 *
 * @param int $attachment_id
 * @return bool
 */
function rp_mm_is_used( int $attachment_id ): bool {

    // Preimport-pending orphan — a media-only pre-stage downloaded
    // this attachment expecting a later products-pass to claim it.
    // Treat as "in use" so the cleaner doesn't nuke the pre-staged
    // pool. The marker is auto-cleared when the attach path runs.
    if ( get_post_meta( $attachment_id, '_gh_preimport_pending', true ) === '1' ) {
        return true;
    }

    // Featured image
    $as_thumb = get_posts( [
        'post_type'      => [ 'product', 'product_variation', 'post', 'page' ],
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [
            'key'   => '_thumbnail_id',
            'value' => $attachment_id,
        ] ],
    ] );
    if ( $as_thumb ) return true;

    // Gallery WooCommerce. posts_per_page DEVE essere -1: LIKE '%23%'
    // matcha anche gallery che contengono 123/230/1023. Con un solo
    // candidato, un falso positivo del LIKE maschera il prodotto che
    // usa davvero l'attachment e il check ritorna false — via libera
    // a una wp_delete_attachment(force=true) irreversibile.
    $as_gallery = get_posts( [
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
    if ( $as_gallery ) {
        // Verifica LIKE non sia falso positivo
        foreach ( $as_gallery as $pid ) {
            $csv = get_post_meta( $pid, '_product_image_gallery', true );
            if ( in_array( (string) $attachment_id, explode( ',', $csv ), true ) ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Ritorna il log delle eliminazioni.
 *
 * @param int $limit Max entry da ritornare.
 * @return array
 */
function rp_mm_get_deletion_log( int $limit = 100 ): array {

    $log = get_option( RP_MM_LOG_KEY, [] );
    if ( ! is_array( $log ) ) $log = [];

    return array_slice( $log, 0, $limit );
}

// ── Helpers ─────────────────────────────────────────────────

/**
 * Aggiunge un evento al deletion log.
 *
 * @param array $attachment_data Dati dell'attachment eliminato.
 */
function rp_mm_log_deletion( array $attachment_data ): void {
    rp_mm_log_deletion_batch( [ $attachment_data ] );
}

/**
 * Aggiunge N eventi al deletion log con UNA sola lettura+scrittura
 * dell'option. Il bulk delete accumula e chiama questa; il path
 * singolo passa da rp_mm_log_deletion (batch da 1).
 *
 * @param array[] $attachments_data Output di rp_mm_build_attachment_data.
 */
function rp_mm_log_deletion_batch( array $attachments_data ): void {

    if ( ! $attachments_data ) return;

    $log = get_option( RP_MM_LOG_KEY, [] );
    if ( ! is_array( $log ) ) $log = [];

    $current_user = wp_get_current_user();
    $now          = current_time( 'mysql' );
    $by           = $current_user->user_login ?? 'system';

    $entries = [];
    foreach ( $attachments_data as $attachment_data ) {
        $entries[] = [
            'attachment_id' => $attachment_data['id'] ?? 0,
            'filename'      => $attachment_data['filename'] ?? '',
            'url'           => $attachment_data['url'] ?? '',
            'filesize'      => $attachment_data['filesize'] ?? 0,
            'deleted_at'    => $now,
            'deleted_by'    => $by,
        ];
    }

    $log = array_merge( $entries, $log );

    // FIFO: mantieni solo gli ultimi N
    if ( count( $log ) > RP_MM_LOG_MAX ) {
        $log = array_slice( $log, 0, RP_MM_LOG_MAX );
    }

    update_option( RP_MM_LOG_KEY, $log, false );
}
