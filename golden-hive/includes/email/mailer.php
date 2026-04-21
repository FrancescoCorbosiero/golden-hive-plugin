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
 * L'HTML puo contenere {RECIPIENT_*} letterali: verranno sostituiti dall'ESP.
 *
 * @param array  $contacts   Lista di contatti (oggetti con ->email).
 * @param string $subject    Oggetto email (puo contenere {RECIPIENT_*} letterali).
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

    foreach ( $contacts as $contact ) {
        $email = is_object( $contact ) ? (string) ( $contact->email ?? '' ) : (string) ( $contact['email'] ?? '' );
        if ( $email === '' || ! is_email( $email ) ) {
            $failed++;
            $errors[] = "(skipped) invalid email in contact row";
            continue;
        }

        $ok = wp_mail( $email, $subject, $html, $headers );
        if ( $ok ) {
            $sent++;
        } else {
            $failed++;
            $errors[] = "{$email}: wp_mail returned false";
        }

        if ( function_exists( 'rp_em_log_email_safe' ) ) {
            rp_em_log_email_safe( [
                'to'            => $email,
                'subject'       => $subject,
                'type'          => 'campaign',
                'campaign_id'   => $campaign_id,
                'campaign_name' => $campaign_name,
                'status'        => $ok ? 'sent' : 'failed',
                'error'         => $ok ? '' : 'wp_mail returned false',
            ] );
        }

        if ( $rate_limit > 0 ) usleep( $rate_limit );
    }

    return [ 'sent' => $sent, 'failed' => $failed, 'errors' => $errors ];
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
