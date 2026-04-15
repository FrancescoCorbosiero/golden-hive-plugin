<?php
/**
 * Mailer — wrapper wp_mail() per test e invio campagne.
 *
 * wp_mail() viene instradato su AWS SES tramite WP Mail SMTP (trasparente).
 * Nessun hook WordPress qui — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

// Prevent double-loading when both golden-hive and rp-email-marketing are active.
if ( function_exists( 'rp_em_send_test_email' ) ) return;

/**
 * Option key per il log delle email inviate (history lightweight).
 * Capped a RP_EM_LOG_MAX entries per evitare bloat di wp_options.
 */
const RP_EM_LOG_KEY = 'rp_em_email_log';
const RP_EM_LOG_MAX = 500;

/**
 * Invia una email di test a un singolo destinatario.
 * Simile al test email di WooCommerce ma con template personalizzabile.
 *
 * @param string $to      Email destinatario.
 * @param string $subject Oggetto (default: test standard).
 * @param string $body    Corpo HTML (default: template test).
 * @return array { success: bool, message: string }
 *
 * Esempio:
 *   $result = rp_em_send_test_email( 'admin@example.com' );
 *   // { success: true, message: 'Email di test inviata a admin@example.com' }
 */
function rp_em_send_test_email( string $to, string $subject = '', string $body = '' ): array {

    if ( empty( $to ) || ! is_email( $to ) ) {
        return [ 'success' => false, 'message' => 'Indirizzo email non valido.' ];
    }

    if ( empty( $subject ) ) {
        $subject = 'Test Email — Golden Hive (' . gmdate( 'H:i:s' ) . ')';
    }

    if ( empty( $body ) ) {
        $site_name = get_bloginfo( 'name' );
        $body = rp_em_build_test_template( $site_name, $to );
    }

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    $sent    = wp_mail( $to, $subject, $body, $headers );

    rp_em_log_email( [
        'to'      => $to,
        'subject' => $subject,
        'type'    => 'test',
        'status'  => $sent ? 'sent' : 'failed',
        'error'   => $sent ? '' : 'wp_mail returned false',
    ] );

    return [
        'success' => $sent,
        'message' => $sent
            ? "Email di test inviata a {$to}"
            : "Invio fallito verso {$to} — controlla WP Mail SMTP log.",
    ];
}

/**
 * Invia una campagna a una lista di contatti.
 *
 * @param array  $contacts   Lista di contatti (oggetti con ->email, ->display_name).
 * @param string $subject    Oggetto email.
 * @param string $body       Corpo HTML con placeholder {{first_name}}.
 * @param int    $rate_limit Microsecondi di pausa tra ogni invio (default: 200000 = 5/sec).
 * @return array { sent: int, failed: int, errors: string[] }
 *
 * Esempio:
 *   $result = rp_em_send_campaign( $contacts, 'Nuovi arrivi!', '<h1>Ciao {{first_name}}</h1>' );
 *   // { sent: 48, failed: 2, errors: ['john@bad.com: wp_mail failed'] }
 */
function rp_em_send_campaign( array $contacts, string $subject, string $body, int $rate_limit = 200000, array $meta = [] ): array {

    $sent    = 0;
    $failed  = 0;
    $errors  = [];
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    $campaign_id   = $meta['campaign_id']   ?? '';
    $campaign_name = $meta['campaign_name'] ?? '';

    foreach ( $contacts as $contact ) {
        $personalized = rp_em_personalize( $body, $contact );
        $ok = wp_mail( $contact->email, $subject, $personalized, $headers );

        if ( $ok ) {
            $sent++;
        } else {
            $failed++;
            $errors[] = "{$contact->email}: wp_mail failed";
        }

        rp_em_log_email( [
            'to'            => $contact->email,
            'subject'       => $subject,
            'type'          => 'campaign',
            'campaign_id'   => $campaign_id,
            'campaign_name' => $campaign_name,
            'status'        => $ok ? 'sent' : 'failed',
            'error'         => $ok ? '' : 'wp_mail returned false',
        ] );

        if ( $rate_limit > 0 ) {
            usleep( $rate_limit );
        }
    }

    return [
        'sent'   => $sent,
        'failed' => $failed,
        'errors' => $errors,
    ];
}

/**
 * Genera anteprima HTML della prima email di una campagna.
 *
 * @param string $body    Corpo HTML con placeholder.
 * @param object $contact Primo contatto per personalizzazione.
 * @return string HTML personalizzato.
 */
function rp_em_preview_campaign( string $body, object $contact ): string {

    return rp_em_personalize( $body, $contact );
}

/**
 * Sostituisce i placeholder nel corpo email.
 *
 * Placeholder supportati:
 * - {{first_name}} → display_name del contatto o "Amico" come fallback
 * - {{email}}      → email del contatto
 * - {{site_name}}  → nome del sito WordPress
 *
 * @param string $body    Corpo HTML.
 * @param object $contact Contatto con ->email e ->display_name.
 * @return string Corpo personalizzato.
 */
function rp_em_personalize( string $body, object $contact ): string {

    $name = ! empty( $contact->display_name )
        ? esc_html( $contact->display_name )
        : 'Amico';

    $replacements = [
        '{{first_name}}' => $name,
        '{{email}}'      => esc_html( $contact->email ),
        '{{site_name}}'  => esc_html( get_bloginfo( 'name' ) ),
    ];

    return str_replace(
        array_keys( $replacements ),
        array_values( $replacements ),
        $body
    );
}

/**
 * Rate limit presets per SES.
 *
 * @return array [ key => { label: string, usec: int, description: string } ]
 */
function rp_em_rate_limit_presets(): array {

    return [
        'fast'   => [
            'label'       => 'Veloce — ~20/sec',
            'usec'        => 50000,
            'description' => 'SES produzione, alto volume.',
        ],
        'normal' => [
            'label'       => 'Normale — ~5/sec',
            'usec'        => 200000,
            'description' => 'Compromesso sicuro (consigliato).',
        ],
        'slow'   => [
            'label'       => 'Lento — 1/sec',
            'usec'        => 1000000,
            'description' => 'Debug o SES sandbox.',
        ],
    ];
}

// ── INTERNAL HELPERS ──────────────────────────────────────────────────────────

/**
 * Template HTML per email di test.
 *
 * @param string $site_name Nome del sito.
 * @param string $to        Email destinatario.
 * @return string HTML.
 */
function rp_em_build_test_template( string $site_name, string $to ): string {

    $time = current_time( 'H:i:s' );
    $date = current_time( 'j M Y' );

    return <<<HTML
    <div style="max-width:600px;margin:0 auto;font-family:'Helvetica Neue',Arial,sans-serif;color:#333;">
        <div style="background:#0c0d10;padding:24px 32px;border-radius:8px 8px 0 0;">
            <h1 style="color:#3d7fff;font-size:20px;margin:0;">{$site_name}</h1>
            <p style="color:#5f6480;font-size:12px;margin:4px 0 0;">Email Marketing — Test</p>
        </div>
        <div style="background:#ffffff;padding:32px;border:1px solid #e5e7eb;border-top:none;">
            <h2 style="color:#111;font-size:18px;margin:0 0 16px;">Email di test riuscita</h2>
            <p>Se stai leggendo questa email, il routing <strong>wp_mail() &rarr; WP Mail SMTP &rarr; AWS SES</strong> funziona correttamente.</p>
            <table style="width:100%;margin:20px 0;font-size:13px;border-collapse:collapse;">
                <tr><td style="padding:8px 0;color:#666;">Destinatario</td><td style="padding:8px 0;"><strong>{$to}</strong></td></tr>
                <tr><td style="padding:8px 0;color:#666;">Data</td><td style="padding:8px 0;">{$date}</td></tr>
                <tr><td style="padding:8px 0;color:#666;">Ora</td><td style="padding:8px 0;">{$time}</td></tr>
                <tr><td style="padding:8px 0;color:#666;">Sito</td><td style="padding:8px 0;">{$site_name}</td></tr>
            </table>
            <p style="font-size:12px;color:#999;margin-top:24px;">Questa email e stata generata automaticamente da RP Email Marketing.</p>
        </div>
    </div>
    HTML;
}

// ── EMAIL LOG (HISTORY) ───────────────────────────────────────────────────────

/**
 * Registra una email inviata nel log lightweight.
 * Capped a RP_EM_LOG_MAX entries — le piu vecchie vengono troncate.
 *
 * Struttura entry:
 * - id:            string  ID univoco
 * - to:            string  Destinatario
 * - subject:       string  Oggetto
 * - type:          string  'test' | 'campaign'
 * - campaign_id:   string  ID campagna (vuoto per test)
 * - campaign_name: string  Nome campagna (vuoto per test)
 * - status:        string  'sent' | 'failed'
 * - error:         string  Messaggio d'errore (vuoto se sent)
 * - sent_at:       string  Datetime mysql
 *
 * @param array $entry Dati dell'email inviata.
 * @return void
 */
function rp_em_log_email( array $entry ): void {

    $log = get_option( RP_EM_LOG_KEY, [] );
    if ( ! is_array( $log ) ) $log = [];

    $log[] = [
        'id'            => substr( md5( uniqid( '', true ) ), 0, 10 ),
        'to'            => sanitize_email( $entry['to'] ?? '' ),
        'subject'       => sanitize_text_field( $entry['subject'] ?? '' ),
        'type'          => sanitize_key( $entry['type'] ?? 'test' ),
        'campaign_id'   => sanitize_text_field( $entry['campaign_id'] ?? '' ),
        'campaign_name' => sanitize_text_field( $entry['campaign_name'] ?? '' ),
        'status'        => sanitize_key( $entry['status'] ?? 'sent' ),
        'error'         => sanitize_text_field( $entry['error'] ?? '' ),
        'sent_at'       => current_time( 'mysql' ),
    ];

    // Cap log size: tieni solo le ultime RP_EM_LOG_MAX entries.
    if ( count( $log ) > RP_EM_LOG_MAX ) {
        $log = array_slice( $log, -RP_EM_LOG_MAX );
    }

    update_option( RP_EM_LOG_KEY, $log, false );
}

/**
 * Ritorna lo storico delle email, ordinato dal piu recente.
 *
 * @param array $args Filtri opzionali:
 *   - limit:  int    Massimo numero di entries (default 200)
 *   - type:   string Filtra per type ('test' | 'campaign')
 *   - status: string Filtra per status ('sent' | 'failed')
 *   - search: string Cerca in to / subject / campaign_name
 * @return array Lista di entries.
 */
function rp_em_get_email_log( array $args = [] ): array {

    $log = get_option( RP_EM_LOG_KEY, [] );
    if ( ! is_array( $log ) ) return [];

    $type   = $args['type']   ?? '';
    $status = $args['status'] ?? '';
    $search = strtolower( trim( $args['search'] ?? '' ) );
    $limit  = max( 1, intval( $args['limit'] ?? 200 ) );

    if ( $type !== '' || $status !== '' || $search !== '' ) {
        $log = array_filter( $log, function ( $e ) use ( $type, $status, $search ) {
            if ( $type   !== '' && ( $e['type']   ?? '' ) !== $type )   return false;
            if ( $status !== '' && ( $e['status'] ?? '' ) !== $status ) return false;
            if ( $search !== '' ) {
                $hay = strtolower( ( $e['to'] ?? '' ) . ' ' . ( $e['subject'] ?? '' ) . ' ' . ( $e['campaign_name'] ?? '' ) );
                if ( strpos( $hay, $search ) === false ) return false;
            }
            return true;
        } );
    }

    // Ordine: piu recenti prima.
    $log = array_reverse( array_values( $log ) );

    return array_slice( $log, 0, $limit );
}

/**
 * Conta totale, sent, failed nel log corrente.
 *
 * @return array { total: int, sent: int, failed: int }
 */
function rp_em_email_log_stats(): array {

    $log = get_option( RP_EM_LOG_KEY, [] );
    if ( ! is_array( $log ) ) $log = [];

    $sent   = 0;
    $failed = 0;
    foreach ( $log as $e ) {
        if ( ( $e['status'] ?? '' ) === 'sent' )   $sent++;
        if ( ( $e['status'] ?? '' ) === 'failed' ) $failed++;
    }

    return [
        'total'  => count( $log ),
        'sent'   => $sent,
        'failed' => $failed,
    ];
}

/**
 * Svuota completamente il log delle email.
 *
 * @return bool
 */
function rp_em_clear_email_log(): bool {

    return update_option( RP_EM_LOG_KEY, [], false );
}
