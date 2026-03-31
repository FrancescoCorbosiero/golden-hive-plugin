<?php
/**
 * Saved Endpoints — persiste configurazioni di endpoint in wp_options.
 */

defined( 'ABSPATH' ) || exit;

const RP_RC_ENDPOINTS_KEY = 'rp_rc_endpoints';

/**
 * Ritorna tutti gli endpoint salvati.
 *
 * @return array
 */
function rp_rc_get_saved_endpoints(): array {

    $endpoints = get_option( RP_RC_ENDPOINTS_KEY, [] );
    return is_array( $endpoints ) ? $endpoints : [];
}

/**
 * Ritorna un singolo endpoint per ID.
 *
 * @param string $id
 * @return array|null
 */
function rp_rc_get_endpoint( string $id ): ?array {

    $endpoints = rp_rc_get_saved_endpoints();
    foreach ( $endpoints as $ep ) {
        if ( ( $ep['id'] ?? '' ) === $id ) return $ep;
    }
    return null;
}

/**
 * Salva un endpoint (crea o aggiorna).
 *
 * @param array $config Configurazione dell'endpoint.
 * @return string ID dell'endpoint.
 */
function rp_rc_save_endpoint( array $config ): string {

    $endpoints = rp_rc_get_saved_endpoints();
    $id        = $config['id'] ?? substr( md5( uniqid( '', true ) ), 0, 8 );

    $config['id'] = $id;
    if ( empty( $config['created_at'] ) ) {
        $config['created_at'] = current_time( 'mysql' );
    }

    // Aggiorna se esiste, altrimenti aggiungi
    $found = false;
    foreach ( $endpoints as $i => $ep ) {
        if ( ( $ep['id'] ?? '' ) === $id ) {
            $endpoints[ $i ] = $config;
            $found = true;
            break;
        }
    }
    if ( ! $found ) {
        $endpoints[] = $config;
    }

    update_option( RP_RC_ENDPOINTS_KEY, $endpoints, false );
    return $id;
}

/**
 * Elimina un endpoint.
 *
 * @param string $id
 * @return bool
 */
function rp_rc_delete_endpoint( string $id ): bool {

    $endpoints = rp_rc_get_saved_endpoints();
    $endpoints = array_values( array_filter( $endpoints, fn( $ep ) => ( $ep['id'] ?? '' ) !== $id ) );
    return update_option( RP_RC_ENDPOINTS_KEY, $endpoints, false );
}
