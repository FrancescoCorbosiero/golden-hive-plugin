<?php
/**
 * AJAX handlers — collegano UI e layer PHP.
 * Tutti richiedono: utente autenticato + manage_woocommerce + nonce valido.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifica nonce: accetta sia rp_em_nonce (UI standalone) sia gh_nonce (UI golden-hive).
 * Termina con wp_die se entrambi falliscono.
 *
 * Definita PRIMA della guard di double-loading e protetta da function_exists,
 * cosi e sempre disponibile indipendentemente dall'ordine di load dei due plugin.
 */
if ( ! function_exists( 'rp_em_check_nonce' ) ) {
    function rp_em_check_nonce(): void {
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( wp_verify_nonce( $nonce, 'rp_em_nonce' ) ) return;
        if ( wp_verify_nonce( $nonce, 'gh_nonce' ) )    return;
        wp_die( 'Invalid nonce', 'Forbidden', [ 'response' => 403 ] );
    }
}

// Prevent double-loading when both golden-hive and rp-email-marketing are active.
if ( has_action( 'wp_ajax_rp_em_ajax_send_test' ) ) return;

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

// ═══ TEMPLATES ═════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_get_templates', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_em_get_templates() );
} );

add_action( 'wp_ajax_rp_em_ajax_save_template', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw  = stripslashes( $_POST['template'] ?? '' );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) { wp_send_json_error( 'JSON non valido.' ); }

    $clean = [
        'id'       => sanitize_text_field( $data['id'] ?? '' ),
        'name'     => sanitize_text_field( $data['name'] ?? '' ),
        'subject'  => sanitize_text_field( $data['subject'] ?? '' ),
        'body'     => wp_kses_post( $data['body'] ?? '' ),
        'category' => sanitize_key( $data['category'] ?? 'general' ),
    ];

    if ( ! $clean['name'] ) { wp_send_json_error( 'Nome obbligatorio.' ); }

    $id = rp_em_save_template( $clean );
    wp_send_json_success( [ 'id' => $id, 'template' => rp_em_get_template( $id ) ] );
} );

add_action( 'wp_ajax_rp_em_ajax_delete_template', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = sanitize_text_field( $_POST['template_id'] ?? '' );
    if ( ! rp_em_delete_template( $id ) ) { wp_send_json_error( 'Non trovato.' ); }
    wp_send_json_success( 'Eliminato.' );
} );

add_action( 'wp_ajax_rp_em_ajax_get_placeholders', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    wp_send_json_success( rp_em_get_placeholder_registry() );
} );

add_action( 'wp_ajax_rp_em_ajax_render_template', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $template_id = sanitize_text_field( $_POST['template_id'] ?? '' );
    $raw_ctx     = stripslashes( $_POST['context'] ?? '{}' );
    $context     = json_decode( $raw_ctx, true ) ?: [];

    $tpl = rp_em_get_template( $template_id );
    if ( ! $tpl ) { wp_send_json_error( 'Template non trovato.' ); }

    $ctx = [];
    if ( ! empty( $context['order_id'] ) )    $ctx['order_id']    = (int) $context['order_id'];
    if ( ! empty( $context['customer_id'] ) ) $ctx['customer_id'] = (int) $context['customer_id'];
    if ( ! empty( $context['product_id'] ) )  $ctx['product_id']  = (int) $context['product_id'];
    if ( ! empty( $context['custom'] ) )      $ctx['custom']      = array_map( 'sanitize_text_field', (array) $context['custom'] );

    $ctx['contact'] = (object) [
        'email'        => sanitize_email( $context['email'] ?? 'test@example.com' ),
        'display_name' => sanitize_text_field( $context['first_name'] ?? 'Test' ),
    ];

    $rendered_body    = rp_em_render_template( $tpl['body'] ?? '', $ctx );
    $rendered_subject = rp_em_render_template( $tpl['subject'] ?? '', $ctx );
    $placeholders     = rp_em_extract_placeholders( ( $tpl['body'] ?? '' ) . ' ' . ( $tpl['subject'] ?? '' ) );

    wp_send_json_success( [
        'subject'      => $rendered_subject,
        'body'         => $rendered_body,
        'placeholders' => $placeholders,
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_send_template', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $template_id = sanitize_text_field( $_POST['template_id'] ?? '' );
    $to          = sanitize_email( $_POST['to'] ?? '' );
    $raw_ctx     = stripslashes( $_POST['context'] ?? '{}' );
    $context     = json_decode( $raw_ctx, true ) ?: [];

    if ( ! $to || ! is_email( $to ) ) { wp_send_json_error( 'Email destinatario non valida.' ); }

    $tpl = rp_em_get_template( $template_id );
    if ( ! $tpl ) { wp_send_json_error( 'Template non trovato.' ); }

    $ctx = [];
    if ( ! empty( $context['order_id'] ) )    $ctx['order_id']    = (int) $context['order_id'];
    if ( ! empty( $context['customer_id'] ) ) $ctx['customer_id'] = (int) $context['customer_id'];
    if ( ! empty( $context['product_id'] ) )  $ctx['product_id']  = (int) $context['product_id'];
    if ( ! empty( $context['custom'] ) )      $ctx['custom']      = array_map( 'sanitize_text_field', (array) $context['custom'] );

    $ctx['contact'] = (object) [
        'email'        => $to,
        'display_name' => sanitize_text_field( $context['first_name'] ?? '' ),
    ];

    $subject = rp_em_render_template( $tpl['subject'] ?? '', $ctx );
    $body    = rp_em_render_template( $tpl['body'] ?? '', $ctx );

    $result = rp_em_send_test_email( $to, $subject, $body );
    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_rp_em_ajax_search_orders', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $query = sanitize_text_field( $_POST['query'] ?? '' );
    wp_send_json_success( rp_em_search_orders( $query, 10 ) );
} );

add_action( 'wp_ajax_rp_em_ajax_search_customers', function () {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $query = sanitize_text_field( $_POST['query'] ?? '' );
    wp_send_json_success( rp_em_search_customers( $query, 10 ) );
} );
