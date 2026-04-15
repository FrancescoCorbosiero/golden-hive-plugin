<?php
/**
 * Email Log — history lightweight delle email inviate.
 *
 * Persistito in wp_options come array serializzato, capped a RP_EM_LOG_MAX
 * entries (FIFO) per non far esplodere l'option.
 *
 * Questo file ha una guard indipendente da mailer.php: se il plugin standalone
 * rp-email-marketing e gia attivo e definisce rp_em_send_test_email(),
 * mailer.php di golden-hive early-returnerebbe e le funzioni di logging non
 * sarebbero mai disponibili. Tenendo il log in un file separato con la propria
 * guard (keyed su rp_em_log_email), le funzioni di log sono sempre caricate
 * una volta sola, indipendentemente dall'ordine di load dei due plugin.
 */

defined( 'ABSPATH' ) || exit;

// Guard indipendente: se qualcun altro ha gia definito il log module, usciamo.
if ( function_exists( 'rp_em_log_email' ) ) return;

/**
 * Option key per il log delle email inviate (history lightweight).
 * Capped a RP_EM_LOG_MAX entries per evitare bloat di wp_options.
 */
if ( ! defined( 'RP_EM_LOG_KEY' ) ) define( 'RP_EM_LOG_KEY', 'rp_em_email_log' );
if ( ! defined( 'RP_EM_LOG_MAX' ) ) define( 'RP_EM_LOG_MAX', 500 );

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
        'to'            => sanitize_email( (string) ( $entry['to'] ?? '' ) ),
        'subject'       => sanitize_text_field( (string) ( $entry['subject'] ?? '' ) ),
        'type'          => sanitize_key( (string) ( $entry['type'] ?? 'test' ) ),
        'campaign_id'   => sanitize_text_field( (string) ( $entry['campaign_id'] ?? '' ) ),
        'campaign_name' => sanitize_text_field( (string) ( $entry['campaign_name'] ?? '' ) ),
        'status'        => sanitize_key( (string) ( $entry['status'] ?? 'sent' ) ),
        'error'         => sanitize_text_field( (string) ( $entry['error'] ?? '' ) ),
        'sent_at'       => (string) current_time( 'mysql' ),
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

    $type   = (string) ( $args['type']   ?? '' );
    $status = (string) ( $args['status'] ?? '' );
    $search = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
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

/**
 * Wrapper difensivo per chiamare rp_em_log_email senza mai rompere il flusso
 * chiamante. Se logging fallisce (per qualsiasi motivo, incluso fatal Error
 * in PHP 8), l'eccezione viene catturata e scritta in error_log ma NON
 * propagata.
 *
 * Usalo dai mailer invece di chiamare rp_em_log_email direttamente: un bug
 * nel logging non deve MAI impedire la risposta AJAX di un invio andato a
 * buon fine.
 *
 * @param array $entry
 * @return void
 */
function rp_em_log_email_safe( array $entry ): void {

    try {
        rp_em_log_email( $entry );
    } catch ( \Throwable $e ) {
        error_log( 'rp_em_log_email_safe: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
    }
}
