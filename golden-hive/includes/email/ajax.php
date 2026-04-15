<?php
/**
 * AJAX handlers — collegano UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

// Prevent double-loading when both golden-hive and rp-email-marketing are active.
if ( has_action( 'wp_ajax_rp_em_ajax_send_test' ) ) return;

/**
 * Verifica nonce: accetta sia rp_em_nonce (UI standalone) sia gh_nonce (UI golden-hive).
 * Termina con wp_die se entrambi falliscono.
 */
function rp_em_check_nonce(): void {
    $nonce = $_REQUEST['nonce'] ?? '';
    if ( wp_verify_nonce( $nonce, 'rp_em_nonce' ) ) return;
    if ( wp_verify_nonce( $nonce, 'gh_nonce' ) )    return;
    wp_die( 'Invalid nonce', 'Forbidden', [ 'response' => 403 ] );
}

// ── TEST EMAIL ──────────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_send_test', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $to      = sanitize_email( $_POST['to'] ?? '' );
    $subject = sanitize_text_field( $_POST['subject'] ?? '' );
    $body    = wp_kses_post( $_POST['body'] ?? '' );

    $result = rp_em_send_test_email( $to, $subject, $body );

    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result['message'] );
    }
} );

// ── GET HUSTLE MODULES ──────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_get_modules', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_em_get_hustle_modules() );
} );

// ── GET CONTACTS ────────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_get_contacts', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $source_type = sanitize_key( $_POST['source_type'] ?? 'hustle' );
    $module_ids  = [];

    if ( ! empty( $_POST['module_ids'] ) ) {
        $raw = json_decode( stripslashes( $_POST['module_ids'] ), true );
        if ( is_array( $raw ) ) {
            $module_ids = array_map( 'intval', $raw );
        }
    }

    $csv_raw = wp_kses_post( $_POST['csv_raw'] ?? '' );

    $sources = [];

    if ( in_array( $source_type, [ 'hustle', 'mixed' ], true ) ) {
        $sources[] = rp_em_get_hustle_subscribers( $module_ids );
    }

    if ( in_array( $source_type, [ 'csv', 'mixed' ], true ) && ! empty( $csv_raw ) ) {
        $sources[] = rp_em_parse_csv_contacts( $csv_raw );
    }

    $contacts = ! empty( $sources ) ? rp_em_merge_contacts( ...$sources ) : [];
    $counts   = rp_em_count_by_source( $contacts );

    wp_send_json_success( [
        'contacts' => $contacts,
        'counts'   => $counts,
    ] );
} );

// ── UPLOAD CSV ──────────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_upload_csv', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'Nessun file CSV caricato o errore upload.' );
    }

    $file = $_FILES['csv_file'];

    // Valida tipo file
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
        wp_send_json_error( 'Solo file .csv o .txt sono supportati.' );
    }

    $contacts = rp_em_parse_csv_file( $file['tmp_name'] );

    if ( empty( $contacts ) ) {
        wp_send_json_error( 'Nessun contatto valido trovato nel CSV. Assicurati che la colonna "email" esista.' );
    }

    wp_send_json_success( [
        'contacts' => $contacts,
        'count'    => count( $contacts ),
        'filename' => sanitize_file_name( $file['name'] ),
    ] );
} );

// ── EXPORT CONTACTS CSV ─────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_export_csv', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $module_ids = [];
    if ( ! empty( $_GET['module_ids'] ) ) {
        $module_ids = array_map( 'intval', explode( ',', sanitize_text_field( $_GET['module_ids'] ) ) );
    }

    $contacts = rp_em_get_hustle_subscribers( $module_ids );
    rp_em_export_contacts_csv( $contacts );
} );

// ── GET CAMPAIGNS ───────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_get_campaigns', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_em_get_campaigns() );
} );

// ── SAVE CAMPAIGN ───────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_save_campaign', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['campaign'] ?? '{}' );
    $data = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }

    // Sanitizza campi
    $sanitized = [
        'name'         => sanitize_text_field( $data['name'] ?? '' ),
        'subject'      => sanitize_text_field( $data['subject'] ?? '' ),
        'body'         => wp_kses_post( $data['body'] ?? '' ),
        'source_type'  => sanitize_key( $data['source_type'] ?? 'hustle' ),
        'module_ids'   => array_map( 'intval', $data['module_ids'] ?? [] ),
        'csv_contacts' => wp_kses_post( $data['csv_contacts'] ?? '' ),
        'rate_limit'   => intval( $data['rate_limit'] ?? 200000 ),
        'scheduled_at' => sanitize_text_field( $data['scheduled_at'] ?? '' ),
    ];

    if ( ! empty( $data['id'] ) ) {
        $sanitized['id'] = sanitize_key( $data['id'] );
    }

    if ( empty( $sanitized['name'] ) || empty( $sanitized['subject'] ) || empty( $sanitized['body'] ) ) {
        wp_send_json_error( 'Nome, oggetto e corpo email sono obbligatori.' );
    }

    $id       = rp_em_save_campaign( $sanitized );
    $campaign = rp_em_get_campaign( $id );

    wp_send_json_success( [ 'id' => $id, 'campaign' => $campaign ] );
} );

// ── DELETE CAMPAIGN ─────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_delete_campaign', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_key( $_POST['campaign_id'] ?? '' );
    if ( empty( $id ) ) { wp_send_json_error( 'ID campagna mancante.' ); }

    rp_em_delete_campaign( $id );
    wp_send_json_success( [ 'message' => "Campagna {$id} eliminata." ] );
} );

// ── SEND CAMPAIGN (immediate) ───────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_send_campaign', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_key( $_POST['campaign_id'] ?? '' );
    if ( empty( $id ) ) { wp_send_json_error( 'ID campagna mancante.' ); }

    $campaign = rp_em_get_campaign( $id );
    if ( ! $campaign ) { wp_send_json_error( 'Campagna non trovata.' ); }

    if ( $campaign['status'] === RP_EM_STATUS_SENDING ) {
        wp_send_json_error( 'Campagna gia in invio.' );
    }

    $result = rp_em_execute_campaign( $id );

    wp_send_json_success( $result );
} );

// ── SCHEDULE CAMPAIGN ───────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_schedule_campaign', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id       = sanitize_key( $_POST['campaign_id'] ?? '' );
    $datetime = sanitize_text_field( $_POST['scheduled_at'] ?? '' );

    if ( empty( $id ) ) { wp_send_json_error( 'ID campagna mancante.' ); }
    if ( empty( $datetime ) ) { wp_send_json_error( 'Data/ora di schedulazione mancante.' ); }

    $ok = rp_em_schedule_campaign( $id, $datetime );

    if ( $ok ) {
        wp_send_json_success( [
            'message'  => "Campagna schedulata per {$datetime}.",
            'campaign' => rp_em_get_campaign( $id ),
        ] );
    } else {
        wp_send_json_error( 'Schedulazione fallita. Verifica che la data sia nel futuro.' );
    }
} );

// ── PREVIEW CAMPAIGN ────────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_preview_campaign', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_key( $_POST['campaign_id'] ?? '' );
    if ( empty( $id ) ) { wp_send_json_error( 'ID campagna mancante.' ); }

    $campaign = rp_em_get_campaign( $id );
    if ( ! $campaign ) { wp_send_json_error( 'Campagna non trovata.' ); }

    $contacts = rp_em_resolve_campaign_contacts( $campaign );
    $first    = $contacts[0] ?? (object) [ 'email' => 'test@example.com', 'display_name' => 'Utente Test' ];
    $html     = rp_em_preview_campaign( $campaign['body'], $first );

    wp_send_json_success( [
        'html'           => $html,
        'subject'        => $campaign['subject'],
        'contact_count'  => count( $contacts ),
    ] );
} );

// ── EMAIL LOG (HISTORY) ─────────────────────────────────────────
add_action( 'wp_ajax_rp_em_ajax_get_log', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $args = [
        'limit'  => intval( $_POST['limit']  ?? 200 ),
        'type'   => sanitize_key( $_POST['type']   ?? '' ),
        'status' => sanitize_key( $_POST['status'] ?? '' ),
        'search' => sanitize_text_field( $_POST['search'] ?? '' ),
    ];

    wp_send_json_success( [
        'entries' => rp_em_get_email_log( $args ),
        'stats'   => rp_em_email_log_stats(),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_clear_log', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    rp_em_clear_email_log();
    wp_send_json_success( [ 'message' => 'Storico email svuotato.' ] );
} );
