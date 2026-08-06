<?php
/**
 * Whitelist — protegge attachment dall'eliminazione.
 * Salvata in wp_options con chiave rp_mm_whitelist.
 */

defined( 'ABSPATH' ) || exit;

const RP_MM_WHITELIST_KEY = 'rp_mm_whitelist';

/**
 * Ritorna la whitelist corrente.
 *
 * @return array [ [ 'id', 'url', 'reason', 'added_at', 'added_by' ] ]
 */
function rp_mm_get_whitelist(): array {

    $list = get_option( RP_MM_WHITELIST_KEY, [] );
    return is_array( $list ) ? $list : [];
}

/**
 * Aggiunge un attachment alla whitelist.
 *
 * @param int|null    $id     Attachment ID (almeno uno tra id e url richiesto).
 * @param string|null $url    URL dell'attachment.
 * @param string      $reason Motivo della protezione.
 * @return bool
 */
function rp_mm_add_to_whitelist( ?int $id, ?string $url = null, string $reason = '' ): bool {

    if ( ! $id && ! $url ) return false;

    $list = rp_mm_get_whitelist();

    // Controlla se gia presente
    foreach ( $list as &$entry ) {
        if ( ( $id && ( $entry['id'] ?? null ) === $id ) || ( $url && ( $entry['url'] ?? null ) === $url ) ) {
            $entry['reason'] = $reason;
            return update_option( RP_MM_WHITELIST_KEY, $list, false );
        }
    }
    unset( $entry );

    // Risolvi URL da ID se mancante
    if ( $id && ! $url ) {
        $url = wp_get_attachment_url( $id ) ?: null;
    }

    $list[] = [
        'id'       => $id,
        'url'      => $url,
        'reason'   => $reason,
        'added_at' => current_time( 'mysql' ),
        'added_by' => get_current_user_id(),
    ];

    return update_option( RP_MM_WHITELIST_KEY, $list, false );
}

/**
 * Rimuove un attachment dalla whitelist.
 *
 * @param int $id Attachment ID.
 * @return bool
 */
function rp_mm_remove_from_whitelist( int $id ): bool {

    $list = rp_mm_get_whitelist();
    $list = array_values( array_filter( $list, fn( $e ) => ( $e['id'] ?? null ) !== $id ) );

    return update_option( RP_MM_WHITELIST_KEY, $list, false );
}

/**
 * Controlla se un attachment e in whitelist.
 *
 * Enforcement-only union: oltre alla whitelist propria (rp_mm_whitelist)
 * viene rispettata anche quella di Hive Sync (hsync_media_whitelist),
 * letta direttamente dall'option cosi la protezione vale anche quando
 * l'altro plugin non e attivo ma i suoi dati sono ancora nel DB. Le
 * funzioni CRUD/list restano scoped alla sola whitelist propria: ogni
 * UI gestisce la sua lista, ma nessun cleaner cancella un attachment
 * protetto dall'altra.
 *
 * @param int $attachment_id
 * @return bool
 */
function rp_mm_is_whitelisted( int $attachment_id ): bool {

    $url = wp_get_attachment_url( $attachment_id );

    foreach ( rp_mm_get_whitelist() as $entry ) {
        if ( ( $entry['id'] ?? null ) === $attachment_id ) return true;
        if ( $url && ( $entry['url'] ?? null ) === $url ) return true;
    }

    $external = get_option( 'hsync_media_whitelist', [] );
    if ( is_array( $external ) ) {
        foreach ( $external as $entry ) {
            if ( (int) ( $entry['id'] ?? 0 ) === $attachment_id ) return true;
            if ( $url && ( $entry['url'] ?? null ) === $url ) return true;
        }
    }

    return false;
}

/**
 * Ritorna il motivo della whitelist per un attachment.
 *
 * @param int $attachment_id
 * @return string|null
 */
function rp_mm_get_whitelist_reason( int $attachment_id ): ?string {

    $list = rp_mm_get_whitelist();
    $url  = wp_get_attachment_url( $attachment_id );

    foreach ( $list as $entry ) {
        if ( ( $entry['id'] ?? null ) === $attachment_id ) return $entry['reason'] ?? null;
        if ( $url && ( $entry['url'] ?? null ) === $url ) return $entry['reason'] ?? null;
    }

    return null;
}

/**
 * Svuota tutta la whitelist.
 *
 * @return bool
 */
function rp_mm_clear_whitelist(): bool {

    return update_option( RP_MM_WHITELIST_KEY, [], false );
}
