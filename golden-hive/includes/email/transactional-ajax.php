<?php
/**
 * Transactional AJAX — handlers per la tab "Transazionali".
 *
 * Endpoints:
 *   rp_em_ajax_trx_list       — lista eventi + bindings correnti + templates disponibili
 *   rp_em_ajax_trx_save       — salva binding per un evento (slug, binding JSON)
 *   rp_em_ajax_trx_test_fire  — scatta un evento per un ordine reale (QA tool)
 *
 * Tutti sotto manage_woocommerce + nonce gh_nonce/rp_em_nonce (stesso
 * contratto degli altri handler email). Riusa rp_em_ajax_guard().
 */

defined( 'ABSPATH' ) || exit;

if ( has_action( 'wp_ajax_rp_em_ajax_trx_list' ) ) return;

add_action( 'wp_ajax_rp_em_ajax_trx_list', function () {
    rp_em_ajax_guard();

    $events = [];
    foreach ( rp_em_transactional_events() as $ev ) {
        $events[] = [
            'slug'  => (string) $ev['slug'],
            'label' => (string) $ev['label'],
            'desc'  => (string) $ev['desc'],
            'hook'  => (string) $ev['hook'],
        ];
    }

    wp_send_json_success( [
        'events'    => $events,
        'bindings'  => rp_em_get_transactional_bindings(),
        'templates' => rp_em_list_templates(),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_trx_save', function () {
    rp_em_ajax_guard();

    $slug = sanitize_key( (string) ( $_POST['slug'] ?? '' ) );
    if ( $slug === '' || ! rp_em_transactional_event( $slug ) ) {
        wp_send_json_error( 'Evento sconosciuto.' );
    }

    $raw = stripslashes( (string) ( $_POST['binding'] ?? '{}' ) );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) wp_send_json_error( 'JSON binding non valido.' );

    $ok = rp_em_save_transactional_binding( $slug, [
        'enabled'     => ! empty( $data['enabled'] ),
        'template_id' => (string) ( $data['template_id'] ?? '' ),
        'subject'     => (string) ( $data['subject'] ?? '' ),
        'preheader'   => (string) ( $data['preheader'] ?? '' ),
    ] );
    if ( ! $ok ) wp_send_json_error( 'Salvataggio fallito.' );

    wp_send_json_success( [
        'binding' => rp_em_get_transactional_binding( $slug ),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_trx_test_fire', function () {
    rp_em_ajax_guard();

    $event    = sanitize_key( (string) ( $_POST['event'] ?? '' ) );
    $order_id = (int) ( $_POST['order_id'] ?? 0 );
    if ( $event === '' )   wp_send_json_error( 'Evento mancante.' );
    if ( $order_id <= 0 )  wp_send_json_error( 'Order ID mancante.' );

    $result = rp_em_fire_transactional( $event, $order_id );
    wp_send_json_success( $result );
} );
