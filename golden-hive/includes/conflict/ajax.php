<?php
/**
 * Conflict — AJAX handlers.
 *
 * Endpoint:
 *  - gh_conflict_rules_list       → lista rule ordinate
 *  - gh_conflict_rule_save        → upsert
 *  - gh_conflict_rule_delete      → remove
 *  - gh_conflict_rules_reset      → reinstalla default (danger, richiede conferma UI)
 *  - gh_conflict_migrate_tick     → runna un batch di migration
 *  - gh_conflict_migrate_status   → lettura cursore + totale
 *  - gh_conflict_product_provenance → lettura sources + field_sources di 1 prodotto
 *  - gh_conflict_dry_run          → anteprima "se arriva source X, cosa succede"
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'GH_CONFLICT_AJAX_LOADED' ) ) return;
define( 'GH_CONFLICT_AJAX_LOADED', 1 );

// ── Rules CRUD ──────────────────────────────────────────────

add_action( 'wp_ajax_gh_conflict_rules_list', function () {
    gh_ajax_guard();
    wp_send_json_success( [
        'rules'  => gh_conflict_rules_all(),
        'slices' => GH_CONFLICT_SLICES,
    ] );
} );

add_action( 'wp_ajax_gh_conflict_rule_save', function () {
    gh_ajax_guard();
    $data = gh_ajax_json( 'rule', [] );
    if ( empty( $data ) ) wp_send_json_error( 'Payload rule mancante.', 400 );

    $id = gh_conflict_rules_upsert( $data );
    wp_send_json_success( [ 'rule' => gh_conflict_rules_find( $id ) ] );
} );

add_action( 'wp_ajax_gh_conflict_rule_delete', function () {
    gh_ajax_guard();
    $id = gh_ajax_text( 'id' );
    if ( $id === '' ) wp_send_json_error( 'ID mancante.', 400 );
    $ok = gh_conflict_rules_remove( $id );
    wp_send_json_success( [ 'removed' => $ok ] );
} );

add_action( 'wp_ajax_gh_conflict_rules_reset', function () {
    gh_ajax_guard();
    gh_conflict_rules_reset_to_defaults();
    wp_send_json_success( [ 'rules' => gh_conflict_rules_all() ] );
} );

// ── Migration ───────────────────────────────────────────────

add_action( 'wp_ajax_gh_conflict_migrate_tick', function () {
    gh_ajax_guard();
    $batch = gh_ajax_int( 'batch', GH_CONFLICT_MIGRATION_BATCH );
    @set_time_limit( 60 );
    $progress = gh_conflict_migrate_run( $batch );
    wp_send_json_success( $progress );
} );

add_action( 'wp_ajax_gh_conflict_migrate_status', function () {
    gh_ajax_guard();
    global $wpdb;
    $total = (int) $wpdb->get_var(
        "SELECT COUNT(ID) FROM {$wpdb->posts}
         WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending')"
    );
    wp_send_json_success( [
        'cursor'   => (int) get_option( GH_CONFLICT_MIGRATION_CURSOR, 0 ),
        'complete' => (bool) get_option( GH_CONFLICT_MIGRATION_COMPLETE, false ),
        'total'    => $total,
    ] );
} );

add_action( 'wp_ajax_gh_conflict_migrate_reset', function () {
    gh_ajax_guard();
    gh_conflict_migrate_reset();
    wp_send_json_success( [ 'reset' => true ] );
} );

// ── Provenance lookup ───────────────────────────────────────

add_action( 'wp_ajax_gh_conflict_product_provenance', function () {
    gh_ajax_guard();
    $pid = gh_ajax_int( 'product_id' );
    if ( $pid <= 0 ) wp_send_json_error( 'product_id richiesto.', 400 );

    wp_send_json_success( [
        'product_id'     => $pid,
        'sources'        => gh_conflict_get_sources( $pid ),
        'field_sources'  => gh_conflict_get_field_sources( $pid ),
        'primary_source' => gh_conflict_get_primary_source( $pid ),
    ] );
} );

// ── Dry-run preview ─────────────────────────────────────────

add_action( 'wp_ajax_gh_conflict_dry_run', function () {
    gh_ajax_guard();
    $ids = gh_ajax_int_array( 'product_ids' );
    $src = gh_ajax_text( 'incoming_source' );
    if ( empty( $ids ) || $src === '' ) {
        wp_send_json_error( 'product_ids + incoming_source richiesti.', 400 );
    }

    // Limit safety
    if ( count( $ids ) > 500 ) {
        wp_send_json_error( 'Max 500 prodotti per dry-run.', 413 );
    }

    wp_send_json_success( [
        'preview' => gh_conflict_dry_run( $ids, $src ),
    ] );
} );
