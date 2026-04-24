<?php
/**
 * Mailer — wrapper wp_mail() per test e invio campagne multi-layer.
 *
 * wp_mail() viene instradato su AWS SES tramite WP Mail SMTP (trasparente).
 * Questo modulo NON fa sostituzione placeholder: riceve HTML gia renderizzato
 * dal renderer (renderer.php) e si limita a spedirlo.
 *
 * I placeholder {RECIPIENT_*} restano letterali nell'HTML: l'ESP/SES li
 * sostituisce per destinatario al send-time (feature SES template / merge tag).
 *
 * Nessun hook WordPress — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'rp_em_send_test_email' ) ) return;

/**
 * Invia una email di test a un singolo destinatario (smoke test mailer).
 *
 * @param string $to      Email destinatario.
 * @param string $subject Oggetto (default: test standard).
 * @param string $body    Corpo HTML gia renderizzato (no placeholder).
 * @return array { success: bool, message: string }
 */
function rp_em_send_test_email( string $to, string $subject = '', string $body = '' ): array {

    if ( $to === '' || ! is_email( $to ) ) {
        return [ 'success' => false, 'message' => 'Indirizzo email non valido.' ];
    }

    if ( $subject === '' ) {
        $subject = 'Test Email — Golden Hive (' . gmdate( 'H:i:s' ) . ')';
    }
    if ( $body === '' ) {
        $body = rp_em_build_test_template( $to );
    }

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    $sent    = wp_mail( $to, $subject, $body, $headers );

    if ( function_exists( 'rp_em_log_email_safe' ) ) {
        rp_em_log_email_safe( [
            'to'      => $to,
            'subject' => $subject,
            'type'    => 'test',
            'status'  => $sent ? 'sent' : 'failed',
            'error'   => $sent ? '' : 'wp_mail returned false',
        ] );
    }

    return [
        'success' => (bool) $sent,
        'message' => $sent
            ? "Email di test inviata a {$to}"
            : "Invio fallito verso {$to} — controlla WP Mail SMTP log.",
    ];
}

/**
 * Invia una campagna a una lista di contatti usando HTML gia renderizzato.
 *
 * Per ogni destinatario, sostituisce i placeholder {RECIPIENT_*} con i
 * valori reali PRIMA di wp_mail — il vecchio design lasciava quei token
 * letterali assumendo che l'ESP (SES) li sostituisse, ma WP Mail SMTP
 * instrada come SMTP raw, non via SES Template API: i merge tag non
 * vengono toccati e finirebbero letterali nell'inbox del cliente.
 *
 * @param array  $contacts   Lista di contatti (oggetti con ->email).
 * @param string $subject    Oggetto email (puo contenere {RECIPIENT_*}).
 * @param string $html       Corpo HTML gia renderizzato.
 * @param int    $rate_limit Microsecondi di pausa tra invii.
 * @param array  $meta       { campaign_id, campaign_name } per il logging.
 * @return array { sent: int, failed: int, errors: string[] }
 */
function rp_em_send_campaign_rendered(
    array $contacts,
    string $subject,
    string $html,
    int $rate_limit = 200000,
    array $meta = []
): array {

    $sent    = 0;
    $failed  = 0;
    $errors  = [];
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    $campaign_id   = (string) ( $meta['campaign_id']   ?? '' );
    $campaign_name = (string) ( $meta['campaign_name'] ?? '' );
    $total         = count( $contacts );

    // Relax i limiti PHP per le campagne lunghe. wp_raise_memory_limit('admin')
    // parte da 256M di default (filtrabile). set_time_limit(0) chiede al SAPI
    // di non killare — l'hoster puo ignorarlo ma e il meglio che possiamo fare
    // da user-land.
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );
    @set_time_limit( 0 );
    @ignore_user_abort( true );

    // Checkpoint frequenza: ogni N recipient (o almeno ogni 30s) scriviamo
    // stats parziali a DB cosi un timeout/OOM lascia tracce visibili nell'UI
    // invece di far sparire tutto il progresso.
    $checkpoint_every  = 25;
    $checkpoint_seconds = 30;
    $last_checkpoint    = microtime( true );

    foreach ( $contacts as $i => $contact ) {
        $email = is_object( $contact ) ? (string) ( $contact->email ?? '' ) : (string) ( $contact['email'] ?? '' );
        if ( $email === '' || ! is_email( $email ) ) {
            $failed++;
            $errors[] = '(skipped) invalid email in contact row';
            continue;
        }
        $display_name = is_object( $contact )
            ? (string) ( $contact->display_name ?? '' )
            : (string) ( $contact['display_name'] ?? '' );

        // Sostituzione per-destinatario dei {RECIPIENT_*}. Applicata sia al
        // body HTML sia al subject (che puo usare "Ciao {RECIPIENT_FIRST_NAME}").
        $subject_r = rp_em_substitute_recipient( $subject, $email, $display_name );
        $html_r    = rp_em_substitute_recipient( $html,    $email, $display_name );

        // Ogni wp_mail wrapped in try/catch: un bug del mailer SMTP / SES
        // o una eccezione in un plugin di logging NON deve interrompere
        // l'invio agli altri destinatari. La campagna continua.
        $ok      = false;
        $err_msg = '';
        try {
            $ok = (bool) wp_mail( $email, $subject_r, $html_r, $headers );
            if ( ! $ok ) $err_msg = 'wp_mail returned false';
        } catch ( \Throwable $e ) {
            $ok      = false;
            $err_msg = 'exception: ' . $e->getMessage();
            error_log( 'rp_em_send_campaign_rendered: wp_mail threw for ' . $email . ' — ' . $e->getMessage() );
        }

        if ( $ok ) {
            $sent++;
        } else {
            $failed++;
            $errors[] = $email . ': ' . $err_msg;
        }

        if ( function_exists( 'rp_em_log_email_safe' ) ) {
            rp_em_log_email_safe( [
                'to'            => $email,
                'subject'       => $subject,
                'type'          => 'campaign',
                'campaign_id'   => $campaign_id,
                'campaign_name' => $campaign_name,
                'status'        => $ok ? 'sent' : 'failed',
                'error'         => $ok ? '' : $err_msg,
            ] );
        }

        // Checkpoint: scrivi stats parziali al DB cosi l'UI (e un restart
        // dopo timeout) vedono il progresso reale.
        $elapsed = microtime( true ) - $last_checkpoint;
        $is_last = ( $i + 1 ) >= $total;
        if ( $campaign_id !== ''
            && ( ( ( $i + 1 ) % $checkpoint_every ) === 0 || $elapsed >= $checkpoint_seconds || $is_last )
            && function_exists( 'rp_em_save_campaign' )
        ) {
            try {
                rp_em_save_campaign( [
                    'id'    => $campaign_id,
                    'stats' => [
                        'sent'       => $sent,
                        'failed'     => $failed,
                        'errors'     => array_slice( $errors, -25 ), // solo le ultime 25 per non bloatare wp_options
                        'progress'   => $i + 1,
                        'total'      => $total,
                        'checkpoint' => current_time( 'mysql' ),
                    ],
                ] );
            } catch ( \Throwable $e ) {
                error_log( 'rp_em_send_campaign_rendered: checkpoint save failed — ' . $e->getMessage() );
            }
            $last_checkpoint = microtime( true );
        }

        if ( $rate_limit > 0 ) usleep( $rate_limit );
    }

    return [
        'sent'     => $sent,
        'failed'   => $failed,
        'errors'   => $errors,
        'progress' => $total,
        'total'    => $total,
    ];
}

/**
 * Sostituisce i placeholder {RECIPIENT_*} nel testo (subject o body) con i
 * valori del destinatario. Usato da rp_em_send_campaign_rendered e dal test
 * email della campagna.
 *
 * Chiavi risolte:
 *   {RECIPIENT_EMAIL}       → email del destinatario
 *   {RECIPIENT_FIRST_NAME}  → prima parola di display_name, oppure local-part dell'email
 *   {RECIPIENT_LAST_NAME}   → resto di display_name dopo il primo spazio (o '')
 *   {RECIPIENT_FULL_NAME}   → display_name, oppure local-part dell'email
 *
 * Fallback: se display_name e vuoto, usiamo la local-part dell'email come
 * nome — meglio "Ciao mario" che "Ciao ,". I valori vengono escapati con
 * esc_html perche iniettati in HTML.
 *
 * @param string $text         Testo con placeholder.
 * @param string $email        Email destinatario.
 * @param string $display_name Nome completo (da Hustle/CSV), puo essere vuoto.
 * @return string              Testo con {RECIPIENT_*} sostituiti.
 */
function rp_em_substitute_recipient( string $text, string $email, string $display_name = '' ): string {
    if ( $text === '' ) return $text;

    $name = trim( $display_name );
    if ( $name === '' ) {
        // Fallback: local-part dell'email ("mario.rossi@example.com" → "mario.rossi")
        $at   = strpos( $email, '@' );
        $name = $at !== false ? substr( $email, 0, $at ) : $email;
    }

    $parts = preg_split( '/\s+/', $name, 2 );
    $first = (string) ( $parts[0] ?? $name );
    $last  = (string) ( $parts[1] ?? '' );

    return strtr( $text, [
        '{RECIPIENT_EMAIL}'       => esc_html( $email ),
        '{RECIPIENT_FIRST_NAME}'  => esc_html( $first ),
        '{RECIPIENT_LAST_NAME}'   => esc_html( $last ),
        '{RECIPIENT_FULL_NAME}'   => esc_html( $name ),
    ] );
}

/**
 * Rate limit presets per SES.
 *
 * @return array [ key => { label, usec, description } ]
 */
function rp_em_rate_limit_presets(): array {
    return [
        'fast'   => [ 'label' => 'Veloce — ~20/sec',   'usec' => 50000,   'description' => 'SES produzione, alto volume.' ],
        'normal' => [ 'label' => 'Normale — ~5/sec',   'usec' => 200000,  'description' => 'Compromesso sicuro (consigliato).' ],
        'slow'   => [ 'label' => 'Lento — 1/sec',      'usec' => 1000000, 'description' => 'Debug o SES sandbox.' ],
    ];
}

// ── INTERNAL HELPERS ──────────────────────────────────────────────────────────

/**
 * Template HTML minimale per smoke test del mailer.
 *
 * @param string $to
 * @return string
 */
function rp_em_build_test_template( string $to ): string {
    $site_name = get_bloginfo( 'name' );
    $time      = current_time( 'H:i:s' );
    $date      = current_time( 'j M Y' );

    return <<<HTML
<div style="max-width:600px;margin:0 auto;font-family:'Helvetica Neue',Arial,sans-serif;color:#333;">
    <div style="background:#0c0d10;padding:24px 32px;border-radius:8px 8px 0 0;">
        <h1 style="color:#3d7fff;font-size:20px;margin:0;">{$site_name}</h1>
        <p style="color:#5f6480;font-size:12px;margin:4px 0 0;">Email Marketing — Test</p>
    </div>
    <div style="background:#ffffff;padding:32px;border:1px solid #e5e7eb;border-top:none;">
        <h2 style="color:#111;font-size:18px;margin:0 0 16px;">Email di test riuscita</h2>
        <p>Routing <strong>wp_mail() → WP Mail SMTP → AWS SES</strong> funziona.</p>
        <table style="width:100%;margin:20px 0;font-size:13px;border-collapse:collapse;">
            <tr><td style="padding:8px 0;color:#666;">Destinatario</td><td style="padding:8px 0;"><strong>{$to}</strong></td></tr>
            <tr><td style="padding:8px 0;color:#666;">Data</td><td style="padding:8px 0;">{$date}</td></tr>
            <tr><td style="padding:8px 0;color:#666;">Ora</td><td style="padding:8px 0;">{$time}</td></tr>
        </table>
    </div>
</div>
HTML;
}
