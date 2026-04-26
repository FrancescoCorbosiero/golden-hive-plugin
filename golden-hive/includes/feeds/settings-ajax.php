<?php
/**
 * Unified AJAX endpoints for the new settings IO contract.
 *
 *   gh_settings_get   → returns flat field map (secrets as fingerprint structs)
 *   gh_settings_save  → saves partial, returns per-field status (verify-after-write)
 *   gh_settings_dump  → WP_DEBUG-only diagnostic dump of the actual stored option
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_gh_settings_get', function () {
    gh_ajax_guard();
    $service = sanitize_key( $_REQUEST['service'] ?? '' );
    if ( ! gh_settings_service_exists( $service ) ) {
        wp_send_json_error( [ 'error' => "Servizio sconosciuto: {$service}" ], 400 );
    }
    wp_send_json_success( [
        'service' => $service,
        'fields'  => gh_settings_get( $service, true ),
    ] );
} );

add_action( 'wp_ajax_gh_settings_save', function () {
    gh_ajax_guard();
    $service = sanitize_key( $_REQUEST['service'] ?? '' );
    if ( ! gh_settings_service_exists( $service ) ) {
        wp_send_json_error( [ 'error' => "Servizio sconosciuto: {$service}" ], 400 );
    }

    $payload = gh_ajax_json( 'fields', [] );

    $result = gh_settings_save( $service, $payload );

    if ( ! ( $result['ok'] ?? false ) ) {
        // Send the same per-field detail back so the UI can render rejected fields.
        wp_send_json_error( $result, 422 );
    }

    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_gh_settings_dump', function () {
    gh_ajax_guard();
    $service = sanitize_key( $_REQUEST['service'] ?? '' );
    if ( ! gh_settings_service_exists( $service ) ) {
        wp_send_json_error( [ 'error' => "Servizio sconosciuto: {$service}" ], 400 );
    }
    wp_send_json_success( gh_settings_dump_debug( $service ) );
} );
