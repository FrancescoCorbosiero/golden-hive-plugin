<?php
/**
 * v2 Workflow tab — credential round-trip AJAX.
 *
 * Thin wrappers over the existing feed-credentials store. Two endpoints:
 *
 *   - gh_v2_workflow_credentials_load
 *       Returns redacted credentials for a source_id (••••XXXX on
 *       secrets). Called when the user picks a source so the config
 *       form pre-fills with stored values.
 *
 *   - gh_v2_workflow_credentials_save
 *       Persists incoming credentials. The legacy save layer already
 *       drops fields not in the schema, redacts secrets in its return
 *       value, and treats values matching ^•+ as "unchanged". So a
 *       round-trip (load → form → save) preserves stored secrets even
 *       when the user only edited non-secret fields.
 *
 * The v2 source_id IS the legacy feed_key for goldensneakers / stockfirmati.
 * Sources whose id is not in the feed-credentials whitelist (e.g.
 * woostore) get a clean 400 — the UI hides the buttons in that case
 * but the server is the canonical gate.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_gh_v2_workflow_credentials_load', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    $source_id = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'source_id' ) : (string) ( $_POST['source_id'] ?? '' );
    if ( $source_id === '' ) {
        wp_send_json_error( [ 'message' => 'source_id mancante' ], 400 );
    }
    if ( ! function_exists( 'gh_feed_credentials_is_valid_key' )
        || ! gh_feed_credentials_is_valid_key( $source_id ) ) {
        // Source has no credentials managed by feed-credentials —
        // empty config is the truthful answer.
        wp_send_json_success( [ 'config' => [] ] );
    }

    $redacted = gh_feed_credentials_get_redacted( $source_id );
    wp_send_json_success( [ 'config' => $redacted ] );
} );

add_action( 'wp_ajax_gh_v2_workflow_credentials_save', function (): void {
    if ( function_exists( 'gh_ajax_guard' ) ) {
        gh_ajax_guard();
    } else {
        check_ajax_referer( 'gh_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    $source_id = function_exists( 'gh_ajax_text' ) ? gh_ajax_text( 'source_id' ) : (string) ( $_POST['source_id'] ?? '' );
    $config    = function_exists( 'gh_ajax_json' ) ? gh_ajax_json( 'config' )    : [];
    if ( $source_id === '' ) {
        wp_send_json_error( [ 'message' => 'source_id mancante' ], 400 );
    }
    if ( ! function_exists( 'gh_feed_credentials_is_valid_key' )
        || ! gh_feed_credentials_is_valid_key( $source_id ) ) {
        wp_send_json_error( [ 'message' => "Sorgente non gestisce credenziali: {$source_id}" ], 400 );
    }

    $result = gh_feed_credentials_save( $source_id, is_array( $config ) ? $config : [] );
    if ( ! empty( $result['errors'] ) ) {
        wp_send_json_error( [
            'message' => 'Errore validazione credenziali',
            'errors'  => $result['errors'],
        ], 422 );
    }

    // Return redacted view so the form can repaint with sanitized values
    // (e.g. URL trimmed, secrets re-redacted with last-4).
    wp_send_json_success( [ 'config' => gh_feed_credentials_get_redacted( $source_id ) ] );
} );

/**
 * Hydrate redacted (^•+) or empty secret fields in a fetch config from
 * the credential store. Used by both preview and run paths so the form's
 * ••••XXXX placeholder never reaches the upstream API.
 *
 * Schema-aware: only fields declared 'secret' in the source's schema
 * are eligible for hydration. Non-secret empty fields are NOT filled
 * (those are intentional clears).
 */
function gh_v2_hydrate_credentials( string $source_id, array $config ): array {
    if ( ! function_exists( 'gh_feed_credentials_is_valid_key' )
        || ! gh_feed_credentials_is_valid_key( $source_id ) ) {
        return $config;
    }
    $schema = gh_feed_credentials_schema()[ $source_id ] ?? [];
    $stored = gh_feed_credentials_get( $source_id );
    foreach ( $schema as $field => $cfg ) {
        $is_secret = ( ( $cfg['type'] ?? '' ) === 'secret' );
        $current   = $config[ $field ] ?? '';
        $is_redacted = is_string( $current ) && preg_match( '/^•+/u', $current );
        if ( $is_secret && ( $current === '' || $is_redacted ) && ! empty( $stored[ $field ] ) ) {
            $config[ $field ] = $stored[ $field ];
        }
    }
    return $config;
}
