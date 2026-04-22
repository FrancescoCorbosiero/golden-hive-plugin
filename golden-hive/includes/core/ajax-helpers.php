<?php
/**
 * AJAX helpers — guard + sanitize input per gli handler golden-hive.
 *
 * Unifica il pattern copy-pasted in ~20 handler AJAX:
 *   check_ajax_referer('gh_nonce') / rp_em_check_nonce()
 *   if ( ! current_user_can('manage_woocommerce') ) wp_die(403)
 *   sanitize_text_field / json_decode(stripslashes) / intval ...
 *
 * Tutti i nuovi endpoint dovrebbero usare questi helper. L'esistente rimane
 * funzionante — migrazione progressiva modulo-per-modulo.
 *
 * Esempio:
 *   add_action( 'wp_ajax_gh_my_action', function () {
 *       gh_ajax_guard();
 *       $id   = gh_ajax_text('id');
 *       $ids  = gh_ajax_int_array('product_ids');
 *       $data = gh_ajax_json('payload');
 *       // ...
 *       wp_send_json_success([ ... ]);
 *   } );
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_ajax_guard' ) ) return;

/**
 * Guard canonico per gli AJAX handler golden-hive.
 *
 * Verifica nonce ('gh_nonce' o, in fallback, 'rp_em_nonce' per co-esistenza
 * con email marketing standalone) + capability. In caso di fail risponde 403
 * e termina la richiesta.
 *
 * @param string $cap Capability richiesta. Default 'manage_woocommerce'.
 */
function gh_ajax_guard( string $cap = 'manage_woocommerce' ): void {
    $nonce = (string) ( $_REQUEST['nonce'] ?? '' );
    $ok    = wp_verify_nonce( $nonce, 'gh_nonce' ) || wp_verify_nonce( $nonce, 'rp_em_nonce' );
    if ( ! $ok ) {
        wp_die( 'Invalid nonce', 'Forbidden', [ 'response' => 403 ] );
    }
    if ( ! current_user_can( $cap ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
}

/**
 * Legge un campo come stringa sanitizzata (text_field).
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function gh_ajax_text( string $key, string $default = '' ): string {
    return sanitize_text_field( (string) ( $_REQUEST[ $key ] ?? $default ) );
}

/**
 * Legge un campo come textarea sanitizzata (preserva newline).
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function gh_ajax_textarea( string $key, string $default = '' ): string {
    $raw = isset( $_REQUEST[ $key ] ) ? (string) wp_unslash( (string) $_REQUEST[ $key ] ) : $default;
    return sanitize_textarea_field( $raw );
}

/**
 * Legge un campo come slug/key minuscolo.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function gh_ajax_key( string $key, string $default = '' ): string {
    return sanitize_key( (string) ( $_REQUEST[ $key ] ?? $default ) );
}

/**
 * Legge un campo come email sanitizzata. Ritorna '' se non valida.
 *
 * @param string $key
 * @param string $default
 * @return string
 */
function gh_ajax_email( string $key, string $default = '' ): string {
    $v = sanitize_email( (string) ( $_REQUEST[ $key ] ?? $default ) );
    return is_email( $v ) ? $v : '';
}

/**
 * Legge un campo come intero.
 *
 * @param string $key
 * @param int    $default
 * @return int
 */
function gh_ajax_int( string $key, int $default = 0 ): int {
    return isset( $_REQUEST[ $key ] ) ? (int) $_REQUEST[ $key ] : $default;
}

/**
 * Legge un campo come bool (truthy: '1', 'true', 'on', non-empty).
 *
 * @param string $key
 * @return bool
 */
function gh_ajax_bool( string $key ): bool {
    $v = $_REQUEST[ $key ] ?? false;
    if ( is_bool( $v ) ) return $v;
    $s = strtolower( (string) $v );
    return $s !== '' && $s !== '0' && $s !== 'false' && $s !== 'off' && $s !== 'no';
}

/**
 * Legge un campo come JSON → array associativo. Ritorna $default se malformato.
 *
 * @param string $key
 * @param array  $default
 * @return array
 */
function gh_ajax_json( string $key, array $default = [] ): array {
    $raw = stripslashes( (string) ( $_REQUEST[ $key ] ?? '' ) );
    if ( $raw === '' ) return $default;
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : $default;
}

/**
 * Legge un array di ID interi (positivi, dedupe, preserva ordine).
 * Accetta JSON (`[1,2,3]`), CSV (`'1,2,3'`), o array nativo.
 *
 * @param string $key
 * @return int[]
 */
function gh_ajax_int_array( string $key ): array {
    $raw = $_REQUEST[ $key ] ?? [];
    if ( is_string( $raw ) && $raw !== '' ) {
        $decoded = json_decode( stripslashes( $raw ), true );
        if ( is_array( $decoded ) ) {
            $raw = $decoded;
        } else {
            $raw = array_map( 'trim', explode( ',', $raw ) );
        }
    }
    if ( ! is_array( $raw ) ) return [];

    $out = [];
    foreach ( $raw as $v ) {
        $id = (int) $v;
        if ( $id > 0 && ! in_array( $id, $out, true ) ) $out[] = $id;
    }
    return $out;
}
