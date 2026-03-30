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
 * @param int[] $attachment_ids
 * @return array [ 'deleted' => int[], 'errors' => [ id => reason ], 'skipped_whitelist' => int[], 'freed_bytes' => int ]
 */
function rp_mm_bulk_delete( array $attachment_ids ): array {

    $deleted           = [];
    $errors            = [];
    $skipped_whitelist = [];
    $freed_bytes       = 0;

    foreach ( $attachment_ids as $id ) {
        $id = (int) $id;

        if ( rp_mm_is_whitelisted( $id ) ) {
            $skipped_whitelist[] = $id;
            continue;
        }

        $file = get_attached_file( $id );
        $size = $file && file_exists( $file ) ? filesize( $file ) : 0;

        $result = rp_mm_delete_attachment( $id );
        if ( is_wp_error( $result ) ) {
            $errors[ $id ] = $result->get_error_message();
        } else {
            $deleted[]    = $id;
            $freed_bytes += $size;
        }
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

    // Gallery WooCommerce
    $as_gallery = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => 1,
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

    $log = get_option( RP_MM_LOG_KEY, [] );
    if ( ! is_array( $log ) ) $log = [];

    $current_user = wp_get_current_user();

    array_unshift( $log, [
        'attachment_id' => $attachment_data['id'],
        'filename'      => $attachment_data['filename'],
        'url'           => $attachment_data['url'],
        'filesize'      => $attachment_data['filesize'],
        'deleted_at'    => current_time( 'mysql' ),
        'deleted_by'    => $current_user->user_login ?? 'system',
    ] );

    // FIFO: mantieni solo gli ultimi N
    if ( count( $log ) > RP_MM_LOG_MAX ) {
        $log = array_slice( $log, 0, RP_MM_LOG_MAX );
    }

    update_option( RP_MM_LOG_KEY, $log, false );
}
