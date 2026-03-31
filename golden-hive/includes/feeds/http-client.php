<?php
/**
 * HTTP Client — wrapper attorno a wp_remote_request().
 * Mai usare curl direttamente.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Esegue una richiesta HTTP.
 *
 * @param array $config [
 *   'url'     => string (required),
 *   'method'  => 'GET'|'POST'|'PUT'|'PATCH'|'DELETE' (default: GET),
 *   'headers' => array (optional),
 *   'body'    => string|array (optional),
 *   'timeout' => int seconds (default: 30),
 * ]
 * @return array [ 'status', 'headers', 'body', 'duration_ms' ] | [ 'error' => string ]
 */
function rp_rc_request( array $config ): array {

    $url     = $config['url'] ?? '';
    $method  = strtoupper( $config['method'] ?? 'GET' );
    $headers = $config['headers'] ?? [];
    $body    = $config['body'] ?? '';
    $timeout = $config['timeout'] ?? 30;

    if ( ! $url ) {
        return [ 'error' => 'URL mancante.' ];
    }

    $args = [
        'method'  => $method,
        'headers' => $headers,
        'timeout' => min( $timeout, 120 ),
    ];

    if ( $body && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
        $args['body'] = $body;
    }

    $start    = microtime( true );
    $response = wp_remote_request( $url, $args );
    $duration = round( ( microtime( true ) - $start ) * 1000 );

    if ( is_wp_error( $response ) ) {
        return [ 'error' => $response->get_error_message(), 'duration_ms' => $duration ];
    }

    return [
        'status'      => wp_remote_retrieve_response_code( $response ),
        'headers'     => wp_remote_retrieve_headers( $response )->getAll(),
        'body'        => wp_remote_retrieve_body( $response ),
        'duration_ms' => $duration,
    ];
}

/**
 * Redatta header sensibili per la risposta AJAX.
 *
 * @param array $headers
 * @return array Headers con valori sensibili mascherati.
 */
function rp_rc_redact_sensitive_headers( array $headers ): array {

    $sensitive = [ 'authorization', 'cookie', 'x-api-key', 'x-auth-token' ];
    $redacted  = [];

    foreach ( $headers as $key => $value ) {
        $k = strtolower( $key );
        if ( in_array( $k, $sensitive, true ) ) {
            $redacted[ $key ] = '••••••••' . substr( $value, -4 );
        } else {
            $redacted[ $key ] = $value;
        }
    }

    return $redacted;
}
