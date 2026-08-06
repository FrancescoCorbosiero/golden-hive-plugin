<?php
/**
 * AJAX handlers — collegano la UI al modulo email multi-layer.
 *
 * Prefix: rp_em_ajax_*
 * Nonce:  rp_em_check_nonce() accetta 'rp_em_nonce' o 'gh_nonce' (golden-hive).
 * Cap:    current_user_can('manage_woocommerce').
 *
 * Tutti gli handler sanitizzano l'input, chiamano una funzione della logica
 * PHP, e rispondono con wp_send_json_success / wp_send_json_error. Niente
 * logica business qui — questo file e solo glue.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce check condiviso: accetta sia rp_em_nonce sia gh_nonce.
 * Protetto con function_exists per non ri-definire se rp-email-marketing
 * standalone e attivo.
 */
if ( ! function_exists( 'rp_em_check_nonce' ) ) {
    function rp_em_check_nonce(): void {
        $nonce = (string) ( $_REQUEST['nonce'] ?? '' );
        if ( wp_verify_nonce( $nonce, 'rp_em_nonce' ) ) return;
        if ( wp_verify_nonce( $nonce, 'gh_nonce' ) )    return;
        wp_die( 'Invalid nonce', 'Forbidden', [ 'response' => 403 ] );
    }
}

// Prevent double-loading se rp-email-marketing standalone e attivo.
if ( has_action( 'wp_ajax_rp_em_ajax_brand_get' ) ) return;

/**
 * Fail-safe early bind — registrato UNA VOLTA al load del file, non dentro
 * il guard. Scatta per QUALSIASI richiesta admin-ajax.php con action
 * rp_em_ajax_* o gh_ajax_*, indipendentemente da dove il fatal e avvenuto
 * (plugin load, init hook, admin_init, render del body del handler).
 *
 * Senza questo, un fatal prima che rp_em_ajax_guard() venga chiamato
 * restituisce la pagina HTML critical-error di WP che il client non puo
 * parsare, lasciando il dev senza diagnostica.
 */
if ( ! defined( 'RP_EM_AJAX_FAILSAFE_BOUND' ) ) {
    define( 'RP_EM_AJAX_FAILSAFE_BOUND', true );

    $rp_em_is_our_ajax = (
        ( defined( 'DOING_AJAX' ) && DOING_AJAX )
        || ( isset( $_SERVER['SCRIPT_NAME'] ) && str_ends_with( (string) $_SERVER['SCRIPT_NAME'], '/admin-ajax.php' ) )
    );
    $rp_em_action = (string) ( $_REQUEST['action'] ?? '' );
    if ( $rp_em_is_our_ajax && ( str_starts_with( $rp_em_action, 'rp_em_ajax_' ) || str_starts_with( $rp_em_action, 'gh_ajax_' ) ) ) {
        register_shutdown_function( function () {
            $err = error_get_last();
            if ( ! $err ) return;
            $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
            if ( ! ( $err['type'] & $fatal_types ) ) return;

            // Sempre in error_log — il dev puo trovare il fatal in wp-content/debug.log
            // (se WP_DEBUG_LOG e attivo) anche se headers_sent() blocca la response.
            $diag = sprintf(
                'rp_em AJAX fatal [%s]: %s in %s:%d',
                (string) ( $_REQUEST['action'] ?? '?' ),
                (string) $err['message'],
                (string) $err['file'],
                (int) $err['line']
            );
            error_log( $diag );

            if ( headers_sent() ) return;

            while ( ob_get_level() > 0 ) { @ob_end_clean(); }
            status_header( 500 );
            header( 'Content-Type: application/json; charset=UTF-8' );
            echo wp_json_encode( [
                'success' => false,
                'data'    => $diag,
            ] );
        } );
    }
    unset( $rp_em_is_our_ajax, $rp_em_action );
}

/**
 * Guard interno: nonce + capability. Da chiamare all'inizio di ogni handler.
 */
function rp_em_ajax_guard(): void {
    rp_em_check_nonce();
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
}

/**
 * Legacy shim — manteniamo la firma per backward compatibility col vecchio
 * flusso (pre-binding al load del file). No-op: il failsafe e gia installato.
 */
function rp_em_install_ajax_failsafe(): void {}

/**
 * Sanitizza ricorsivamente le stringhe dentro un valore per garantire UTF-8
 * valido prima di json_encode. Senza questo, un byte corrotto dentro
 * last_render / csv_contacts / subject fa fallire wp_send_json_success
 * silenziosamente e il client riceve una risposta vuota o un warning PHP.
 *
 * @param mixed $v
 * @return mixed
 */
function rp_em_sanitize_utf8( mixed $v ): mixed {
    if ( is_string( $v ) ) {
        if ( $v === '' ) return $v;
        // Rimuove sequenze di byte non valide senza alterare l'ASCII.
        $clean = @iconv( 'UTF-8', 'UTF-8//IGNORE', $v );
        return $clean === false ? '' : $clean;
    }
    if ( is_array( $v ) ) {
        foreach ( $v as $k => $val ) {
            $v[ $k ] = rp_em_sanitize_utf8( $val );
        }
    }
    return $v;
}

// ═══ BRAND ══════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_brand_get', function () {
    rp_em_ajax_guard();
    wp_send_json_success( [
        'brand'  => rp_em_get_brand(),
        'schema' => rp_em_get_brand_schema(),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_brand_save', function () {
    rp_em_ajax_guard();
    $raw  = stripslashes( (string) ( $_POST['brand'] ?? '{}' ) );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) wp_send_json_error( 'JSON brand non valido.' );

    rp_em_save_brand( $data );
    wp_send_json_success( [ 'brand' => rp_em_get_brand() ] );
} );

add_action( 'wp_ajax_rp_em_ajax_brand_reset', function () {
    rp_em_ajax_guard();
    rp_em_reset_brand();
    wp_send_json_success( [ 'brand' => rp_em_get_brand() ] );
} );

// ═══ TEMPLATES ══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_template_list', function () {
    rp_em_ajax_guard();
    wp_send_json_success( rp_em_list_templates() );
} );

add_action( 'wp_ajax_rp_em_ajax_template_get', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $t  = $id !== '' ? rp_em_get_template( $id ) : null;
    if ( ! $t ) wp_send_json_error( 'Template non trovato.' );
    wp_send_json_success( rp_em_sanitize_utf8( $t ) );
} );

add_action( 'wp_ajax_rp_em_ajax_template_save', function () {
    rp_em_ajax_guard();
    $raw  = stripslashes( (string) ( $_POST['template'] ?? '{}' ) );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) wp_send_json_error( 'JSON template non valido.' );

    $clean = [
        'id'          => isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '',
        'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
        'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
        'html'        => isset( $data['html'] ) ? (string) $data['html'] : '',
    ];
    if ( $clean['name'] === '' ) wp_send_json_error( 'Nome template obbligatorio.' );

    // HTML validation — blocca save se il body e rotto (UTF-8 invalido,
    // table sbilanciate, script, troppo grande). Senza questo il save passa
    // e il bug esplode dopo, quando la campagna tenta il render.
    $html_check = rp_em_validate_template_html( $clean['html'] );
    if ( ! empty( $html_check['errors'] ) ) {
        $msgs = array_map( fn( $e ) => (string) ( $e['message'] ?? '' ), $html_check['errors'] );
        wp_send_json_error( [
            'message' => implode( ' ', $msgs ),
            'errors'  => $html_check['errors'],
        ] );
    }

    $id = rp_em_save_template( $clean );
    wp_send_json_success( rp_em_sanitize_utf8( rp_em_get_template( $id ) ) );
} );

add_action( 'wp_ajax_rp_em_ajax_template_delete', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    if ( ! rp_em_delete_template( $id ) ) wp_send_json_error( 'Template non trovato.' );
    wp_send_json_success( [ 'deleted' => $id ] );
} );

add_action( 'wp_ajax_rp_em_ajax_template_extract_placeholders', function () {
    rp_em_ajax_guard();
    $html = isset( $_POST['html'] ) ? wp_unslash( (string) $_POST['html'] ) : '';
    $keys = rp_em_extract_placeholders( $html );
    wp_send_json_success( [
        'all'     => $keys,
        'grouped' => rp_em_group_placeholders( $keys ),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_template_render_demo', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    if ( $id === '' ) wp_send_json_error( 'ID template mancante.' );

    $result = rp_em_render_template_with_demo( $id );
    if ( $result['html'] === '' ) wp_send_json_error( 'Template non trovato o render vuoto.' );
    wp_send_json_success( $result );
} );

// Render template con uno specifico prodotto nello slot PRODUCT_1_*.
// Se template_id non specificato, usa il primo template che referenzia PRODUCT_1_*.
// Cross-module: Inline Editor → Email preview.
add_action( 'wp_ajax_rp_em_ajax_preview_product_in_email', function () {
    rp_em_ajax_guard();
    $product_id  = (int) ( $_POST['product_id'] ?? 0 );
    $template_id = sanitize_text_field( (string) ( $_POST['template_id'] ?? '' ) );

    if ( $product_id <= 0 ) wp_send_json_error( 'Product ID mancante.' );

    if ( $template_id === '' ) {
        foreach ( rp_em_get_templates() as $t ) {
            $keys = $t['placeholders_cache'] ?? rp_em_extract_placeholders( (string) ( $t['html'] ?? '' ) );
            foreach ( $keys as $k ) {
                if ( rp_em_product_index( $k ) === 1 ) {
                    $template_id = (string) $t['id'];
                    break 2;
                }
            }
        }
    }
    if ( $template_id === '' ) {
        wp_send_json_error( 'Nessun template con slot PRODUCT_1_*. Crea o seleziona un template che usi {PRODUCT_1_*}.' );
    }

    $template = rp_em_get_template( $template_id );
    if ( ! $template ) wp_send_json_error( 'Template non trovato.' );

    $html = (string) ( $template['html'] ?? '' );
    $keys = $template['placeholders_cache'] ?? rp_em_extract_placeholders( $html );

    [ $values, $unresolved ] = rp_em_build_demo_values( $keys );
    // Override PRODUCT_1_* col prodotto reale.
    $values = array_merge( $values, rp_em_resolve_product_fields( $product_id, 1 ) );

    $rendered = rp_em_render_raw( $html, $values, preserve_recipient: false );
    wp_send_json_success( [
        'html'            => $rendered,
        'subject'         => '[Preview] ' . (string) ( $template['name'] ?? $template_id ),
        'template_id'     => $template_id,
        'template_name'   => (string) ( $template['name'] ?? '' ),
        'unresolved_keys' => $unresolved,
    ] );
} );

// ═══ CAMPAIGNS ══════════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_campaign_list', function () {
    rp_em_ajax_guard();
    wp_send_json_success( rp_em_sanitize_utf8( rp_em_get_campaigns() ) );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_get', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $c  = $id !== '' ? rp_em_get_campaign( $id ) : null;
    if ( ! $c ) wp_send_json_error( 'Campagna non trovata.' );
    wp_send_json_success( rp_em_sanitize_utf8( $c ) );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_save', function () {
    rp_em_ajax_guard();
    $raw  = stripslashes( (string) ( $_POST['campaign'] ?? '{}' ) );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) wp_send_json_error( 'JSON campagna non valido.' );

    $clean = [
        'id'           => isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '',
        'name'         => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
        'subject'      => sanitize_text_field( (string) ( $data['subject'] ?? '' ) ),
        'preheader'    => sanitize_text_field( (string) ( $data['preheader'] ?? '' ) ),
        'template_id'  => sanitize_text_field( (string) ( $data['template_id'] ?? '' ) ),
        'payload'      => rp_em_sanitize_payload_map( $data['payload'] ?? [] ),
        'product_ids'  => rp_em_sanitize_product_ids( $data['product_ids'] ?? [] ),
        'source_type'  => sanitize_key( (string) ( $data['source_type'] ?? 'hustle' ) ),
        'module_ids'   => array_values( array_map( 'intval', (array) ( $data['module_ids'] ?? [] ) ) ),
        'csv_contacts' => (string) ( $data['csv_contacts'] ?? '' ),
        'rate_limit'   => max( 0, intval( $data['rate_limit'] ?? 200000 ) ),
        'scheduled_at' => sanitize_text_field( (string) ( $data['scheduled_at'] ?? '' ) ),
    ];
    if ( $clean['name'] === '' )        wp_send_json_error( 'Nome campagna obbligatorio.' );
    if ( $clean['template_id'] === '' ) wp_send_json_error( 'Template obbligatorio.' );

    $id = rp_em_save_campaign( $clean );
    wp_send_json_success( rp_em_get_campaign( $id ) );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_delete', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    if ( ! rp_em_delete_campaign( $id ) ) wp_send_json_error( 'Campagna non trovata.' );
    wp_send_json_success( [ 'deleted' => $id ] );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_schedule', function () {
    rp_em_ajax_guard();
    $id       = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $datetime = sanitize_text_field( (string) ( $_POST['scheduled_at'] ?? '' ) );
    if ( $id === '' )       wp_send_json_error( 'ID mancante.' );
    if ( $datetime === '' ) wp_send_json_error( 'Data/ora mancante.' );

    $ok = rp_em_schedule_campaign( $id, $datetime );
    if ( ! $ok ) wp_send_json_error( 'Schedulazione fallita (data nel passato?).' );
    wp_send_json_success( rp_em_get_campaign( $id ) );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_send', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $c  = $id !== '' ? rp_em_get_campaign( $id ) : null;
    if ( ! $c ) wp_send_json_error( 'Campagna non trovata.' );

    // Se lo status dice 'sending' ma il lock e scaduto (TTL), la run
    // precedente e morta: permettiamo un retry. Senza questa logica la
    // campagna restava bloccata in 'sending' per sempre dopo un PHP crash.
    if ( ( $c['status'] ?? '' ) === RP_EM_STATUS_SENDING ) {
        if ( rp_em_campaign_lock_active( $id ) ) {
            wp_send_json_error( 'Campagna gia in invio (lock attivo). Attendi il completamento.' );
        }
        // Lock scaduto / mai acquisito → run abbandonata, procediamo.
    }

    // Path preferito: dispatch del job chunked in background. La response
    // torna in millisecondi e l'invio prosegue via wp-cron a tick — niente
    // piu richiesta AJAX aperta per minuti che Cloudflare tronca a ~100s.
    // La UI polla rp_em_ajax_campaign_status per il progresso.
    $dispatch = rp_em_dispatch_campaign_send( $id, 'manual' );
    if ( ! empty( $dispatch['ok'] ) ) {
        wp_send_json_success( [
            'dispatched' => true,
            'job_id'     => (string) ( $dispatch['job_id'] ?? '' ),
        ] );
    }
    if ( ( $dispatch['reason'] ?? '' ) === 'already_sending' ) {
        wp_send_json_error( 'Campagna gia in invio (lock attivo). Attendi il completamento.' );
    }

    // Fallback legacy: job runner non disponibile → invio sincrono.
    $result = rp_em_execute_campaign( $id );
    wp_send_json_success( rp_em_sanitize_utf8( $result ) );
} );

// Status leggero per il polling della UI durante l'invio in background:
// niente last_render/payload (pesanti), solo status + stats.
add_action( 'wp_ajax_rp_em_ajax_campaign_status', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $c  = $id !== '' ? rp_em_get_campaign( $id ) : null;
    if ( ! $c ) wp_send_json_error( 'Campagna non trovata.' );

    wp_send_json_success( rp_em_sanitize_utf8( [
        'id'           => $c['id'],
        'status'       => (string) ( $c['status'] ?? '' ),
        'stats'        => (array) ( $c['stats'] ?? [] ),
        'started_at'   => (string) ( $c['started_at'] ?? '' ),
        'completed_at' => (string) ( $c['completed_at'] ?? '' ),
    ] ) );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_preview', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $c  = $id !== '' ? rp_em_get_campaign( $id ) : null;
    if ( ! $c ) wp_send_json_error( 'Campagna non trovata.' );

    $html = rp_em_render_campaign( $id );
    wp_send_json_success( [
        'html'      => $html,
        'subject'   => (string) ( $c['subject'] ?? '' ),
        'preheader' => (string) ( $c['preheader'] ?? '' ),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_validate', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $result = rp_em_validate_campaign( $id );
    rp_em_save_campaign( [ 'id' => $id, 'last_validation' => $result ] );
    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_rp_em_ajax_campaign_send_test', function () {
    rp_em_ajax_guard();
    $id = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
    $to = sanitize_email( (string) ( $_POST['to'] ?? '' ) );
    $c  = $id !== '' ? rp_em_get_campaign( $id ) : null;
    if ( ! $c ) wp_send_json_error( 'Campagna non trovata.' );
    if ( ! is_email( $to ) ) wp_send_json_error( 'Indirizzo email non valido.' );

    // Usa il display_name dell'utente WP corrispondente all'email se esiste,
    // altrimenti fallback alla local-part dell'email dentro substitute_recipient.
    $wp_user      = get_user_by( 'email', $to );
    $display_name = $wp_user ? (string) $wp_user->display_name : '';

    $html    = rp_em_render_campaign( $id );
    $subject = (string) ( $c['subject'] ?? '' );

    // Sostituisce {RECIPIENT_*} col destinatario di test — stesso path del
    // send reale, cosi il test riflette davvero cosa arrivera all'inbox.
    $subject = rp_em_substitute_recipient( $subject, $to, $display_name );
    $html    = rp_em_substitute_recipient( $html,    $to, $display_name );

    $result  = rp_em_send_test_email( $to, $subject, $html );
    wp_send_json_success( $result );
} );

// ═══ PRODUCT PICKER (per wizard campagna) ══════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_product_picker', function () {
    rp_em_ajax_guard();
    $query = sanitize_text_field( (string) ( $_POST['query'] ?? '' ) );
    $limit = min( 20, max( 1, intval( $_POST['limit'] ?? 10 ) ) );
    wp_send_json_success( rp_em_product_picker_search( $query, $limit ) );
} );

add_action( 'wp_ajax_rp_em_ajax_product_resolve', function () {
    rp_em_ajax_guard();
    $raw = stripslashes( (string) ( $_POST['product_ids'] ?? '[]' ) );
    $ids = array_values( array_filter( array_map( 'intval', (array) json_decode( $raw, true ) ) ) );
    $out = [];
    foreach ( $ids as $i => $pid ) {
        $out[] = [
            'slot'       => $i + 1,
            'product_id' => $pid,
            'resolved'   => rp_em_resolve_product_fields( $pid, $i + 1 ),
        ];
    }
    wp_send_json_success( $out );
} );

// ═══ CONTACTS (intatto) ════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_get_modules', function () {
    rp_em_ajax_guard();
    wp_send_json_success( rp_em_get_hustle_modules() );
} );

add_action( 'wp_ajax_rp_em_ajax_get_contacts', function () {
    rp_em_ajax_guard();
    $source_type = sanitize_key( (string) ( $_POST['source_type'] ?? 'hustle' ) );
    $module_ids  = [];
    if ( ! empty( $_POST['module_ids'] ) ) {
        $raw = json_decode( stripslashes( (string) $_POST['module_ids'] ), true );
        if ( is_array( $raw ) ) $module_ids = array_map( 'intval', $raw );
    }
    $csv_raw = (string) ( $_POST['csv_raw'] ?? '' );

    $sources = [];
    if ( in_array( $source_type, [ 'hustle', 'mixed' ], true ) ) $sources[] = rp_em_get_hustle_subscribers( $module_ids );
    if ( in_array( $source_type, [ 'csv', 'mixed' ], true ) && $csv_raw !== '' ) $sources[] = rp_em_parse_csv_contacts( $csv_raw );

    $contacts = empty( $sources ) ? [] : rp_em_merge_contacts( ...$sources );
    wp_send_json_success( [ 'contacts' => $contacts, 'counts' => rp_em_count_by_source( $contacts ) ] );
} );

add_action( 'wp_ajax_rp_em_ajax_upload_csv', function () {
    rp_em_ajax_guard();
    if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'Nessun file CSV caricato.' );
    }
    $file = $_FILES['csv_file'];
    $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) wp_send_json_error( 'Solo .csv o .txt.' );

    $contacts = rp_em_parse_csv_file( $file['tmp_name'] );
    if ( empty( $contacts ) ) wp_send_json_error( 'Nessun contatto valido (colonna "email" richiesta).' );

    wp_send_json_success( [
        'contacts' => $contacts,
        'count'    => count( $contacts ),
        'filename' => sanitize_file_name( $file['name'] ),
    ] );
} );

add_action( 'wp_ajax_rp_em_ajax_export_csv', function () {
    rp_em_ajax_guard();
    $module_ids = [];
    if ( ! empty( $_GET['module_ids'] ) ) {
        $module_ids = array_map( 'intval', explode( ',', sanitize_text_field( (string) $_GET['module_ids'] ) ) );
    }
    $contacts = rp_em_get_hustle_subscribers( $module_ids );
    rp_em_export_contacts_csv( $contacts );
} );

// ═══ TEST EMAIL (standalone mailer smoke test) ═════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_send_test', function () {
    rp_em_ajax_guard();
    $to      = sanitize_email( (string) ( $_POST['to'] ?? '' ) );
    $subject = sanitize_text_field( (string) ( $_POST['subject'] ?? '' ) );
    $body    = wp_kses_post( (string) ( $_POST['body'] ?? '' ) );

    $result = rp_em_send_test_email( $to, $subject, $body );
    if ( $result['success'] ) wp_send_json_success( $result );
    wp_send_json_error( $result['message'] );
} );

// ═══ SEEDER (smoke test one-click) ═════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_seed_demo', function () {
    rp_em_ajax_guard();
    $reset_brand = ! empty( $_POST['reset_brand'] );
    wp_send_json_success( rp_em_seed_demo( $reset_brand ) );
} );

// ═══ LOG (intatto) ═════════════════════════════════════════════════════════

add_action( 'wp_ajax_rp_em_ajax_get_log', function () {
    rp_em_ajax_guard();
    $args = [
        'limit'  => intval( $_POST['limit']  ?? 200 ),
        'type'   => sanitize_key( (string) ( $_POST['type']   ?? '' ) ),
        'status' => sanitize_key( (string) ( $_POST['status'] ?? '' ) ),
        'search' => sanitize_text_field( (string) ( $_POST['search'] ?? '' ) ),
    ];
    wp_send_json_success( rp_em_sanitize_utf8( [
        'entries' => rp_em_get_email_log( $args ),
        'stats'   => rp_em_email_log_stats(),
    ] ) );
} );

add_action( 'wp_ajax_rp_em_ajax_clear_log', function () {
    rp_em_ajax_guard();
    rp_em_clear_email_log();
    wp_send_json_success( [ 'message' => 'Storico email svuotato.' ] );
} );

// ── HELPERS LOCALI ─────────────────────────────────────────────────────────

/**
 * Sanitize del payload campagna. Accetta solo chiavi UPPERCASE_UNDERSCORE.
 *
 * @param mixed $raw
 * @return array
 */
function rp_em_sanitize_payload_map( mixed $raw ): array {
    if ( ! is_array( $raw ) ) return [];
    $out = [];
    foreach ( $raw as $k => $v ) {
        if ( ! is_string( $k ) ) continue;
        if ( ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $k ) ) continue;
        $out[ $k ] = sanitize_textarea_field( (string) $v );
    }
    return $out;
}

/**
 * Sanitize array di product_ids — solo int positivi, no duplicati, mantiene l'ordine.
 *
 * @param mixed $raw
 * @return int[]
 */
function rp_em_sanitize_product_ids( mixed $raw ): array {
    if ( ! is_array( $raw ) ) return [];
    $out = [];
    foreach ( $raw as $v ) {
        $id = (int) $v;
        if ( $id > 0 && ! in_array( $id, $out, true ) ) $out[] = $id;
    }
    return $out;
}

/**
 * Ricerca prodotti WooCommerce per il product picker del wizard campagna.
 * Match su titolo / SKU / ID. Ritorna campi pronti per l'UI.
 *
 * @param string $query
 * @param int    $limit
 * @return array[]
 */
function rp_em_product_picker_search( string $query, int $limit = 10 ): array {
    if ( ! function_exists( 'wc_get_products' ) ) return [];

    $args = [
        'limit'   => $limit,
        'status'  => [ 'publish' ],
        'orderby' => 'date',
        'order'   => 'DESC',
    ];

    if ( $query !== '' ) {
        if ( is_numeric( $query ) ) {
            $args['include'] = [ (int) $query ];
        } else {
            $args['s'] = $query;
        }
    }

    $products = wc_get_products( $args );
    if ( ! is_array( $products ) ) return [];

    $out = [];
    foreach ( $products as $p ) {
        $img_id = $p->get_image_id();
        $out[] = [
            'id'        => $p->get_id(),
            'name'      => $p->get_name(),
            'sku'       => $p->get_sku(),
            'price'     => html_entity_decode( wp_strip_all_tags( (string) $p->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
            'image_url' => $img_id ? (string) wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
            'permalink' => (string) $p->get_permalink(),
        ];
    }
    return $out;
}
