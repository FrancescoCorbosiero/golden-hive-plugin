<?php
/**
 * AJAX bridge for the Catalog History (snapshot/diff) tab.
 *
 * Endpoints (all protected by gh_ajax_guard()):
 *   gh_history_list      → list snapshots (newest first)
 *   gh_history_capture   → capture a snapshot now (manual trigger)
 *   gh_history_diff      → diff two snapshots by id
 *   gh_history_delete    → delete a snapshot by id
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_gh_history_list', function () {
    gh_ajax_guard();
    gh_history_install_tables();
    wp_send_json_success( [
        'snapshots'      => gh_history_list_snapshots(),
        'retention_days' => GH_HISTORY_RETENTION_DAYS,
    ] );
} );

add_action( 'wp_ajax_gh_history_capture', function () {
    gh_ajax_guard();
    $result = gh_history_capture( 'manual' );
    if ( isset( $result['error'] ) ) {
        wp_send_json_error( $result['error'] );
    }
    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_gh_history_diff', function () {
    gh_ajax_guard();
    $a = gh_ajax_int( 'snapshot_a' );
    $b = gh_ajax_int( 'snapshot_b' );
    if ( $a <= 0 || $b <= 0 ) {
        wp_send_json_error( 'snapshot_a / snapshot_b mancanti.' );
    }
    if ( $a === $b ) {
        wp_send_json_error( 'I due snapshot devono essere diversi.' );
    }

    // Always order so A is older than B (so "before -> after" reads chronologically).
    $meta_a = gh_history_get_snapshot( $a );
    $meta_b = gh_history_get_snapshot( $b );
    if ( ! $meta_a || ! $meta_b ) {
        wp_send_json_error( 'Snapshot non trovato.' );
    }
    if ( strcmp( $meta_a['snapshot_date'], $meta_b['snapshot_date'] ) > 0 ) {
        [ $a, $b ] = [ $b, $a ];
    }

    $diff = gh_history_diff_snapshots( $a, $b );
    wp_send_json_success( $diff );
} );

add_action( 'wp_ajax_gh_history_delete', function () {
    gh_ajax_guard();
    $id = gh_ajax_int( 'snapshot_id' );
    if ( $id <= 0 ) wp_send_json_error( 'snapshot_id mancante.' );

    global $wpdb;
    $wpdb->delete( gh_history_table_items(),     [ 'snapshot_id' => $id ], [ '%d' ] );
    $deleted = $wpdb->delete( gh_history_table_snapshots(), [ 'id' => $id ], [ '%d' ] );

    wp_send_json_success( [ 'deleted' => (int) $deleted ] );
} );
