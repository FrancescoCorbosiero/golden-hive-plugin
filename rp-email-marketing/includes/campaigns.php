<?php
/**
 * Campaigns — CRUD campagne email + scheduling via WP-Cron.
 *
 * Le campagne sono persistite in wp_options come array serializzato.
 * La schedulazione usa wp_schedule_single_event() per esecuzione differita.
 *
 * Nessun hook WordPress qui tranne il cron handler (necessario per il dispatch).
 */

defined( 'ABSPATH' ) || exit;

// Prevent double-loading when both golden-hive and rp-email-marketing are active.
if ( defined( 'RP_EM_CAMPAIGNS_KEY' ) ) return;

const RP_EM_CAMPAIGNS_KEY  = 'rp_em_campaigns';
const RP_EM_CRON_HOOK      = 'rp_em_cron_send_campaign';

/**
 * Stati possibili di una campagna.
 */
const RP_EM_STATUS_DRAFT     = 'draft';
const RP_EM_STATUS_SCHEDULED = 'scheduled';
const RP_EM_STATUS_SENDING   = 'sending';
const RP_EM_STATUS_SENT      = 'sent';
const RP_EM_STATUS_FAILED    = 'failed';

/**
 * Ritorna tutte le campagne salvate.
 *
 * @return array Lista di campagne ordinate per created_at DESC.
 */
function rp_em_get_campaigns(): array {

    $campaigns = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $campaigns ) ) return [];

    usort( $campaigns, fn( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ) );
    return $campaigns;
}

/**
 * Ritorna una singola campagna per ID.
 *
 * @param string $id ID univoco della campagna.
 * @return array|null Campagna o null se non trovata.
 */
function rp_em_get_campaign( string $id ): ?array {

    $campaigns = rp_em_get_campaigns();
    foreach ( $campaigns as $c ) {
        if ( ( $c['id'] ?? '' ) === $id ) return $c;
    }
    return null;
}

/**
 * Crea o aggiorna una campagna.
 *
 * Struttura campagna:
 * - id:            string   ID univoco
 * - name:          string   Nome campagna (per riferimento interno)
 * - subject:       string   Oggetto email
 * - body:          string   Corpo HTML con placeholder
 * - source_type:   string   'hustle' | 'csv' | 'mixed'
 * - module_ids:    int[]    ID moduli Hustle (vuoto = tutti)
 * - csv_contacts:  string   Contenuto CSV raw (opzionale)
 * - rate_limit:    int      Microsecondi tra invii
 * - scheduled_at:  string   Datetime ISO 8601 per invio programmato (vuoto = invio immediato)
 * - status:        string   draft | scheduled | sending | sent | failed
 * - stats:         array    { sent: int, failed: int, errors: string[] }
 * - created_at:    string   Datetime di creazione
 * - updated_at:    string   Datetime ultimo aggiornamento
 *
 * @param array $data Dati campagna.
 * @return string ID della campagna salvata.
 *
 * Esempio:
 *   $id = rp_em_save_campaign([
 *       'name'    => 'Lancio Jordan 4',
 *       'subject' => 'Nuovi arrivi!',
 *       'body'    => '<h1>Ciao {{first_name}}</h1>',
 *       'source_type' => 'hustle',
 *       'module_ids'  => [1, 3],
 *   ]);
 */
function rp_em_save_campaign( array $data ): string {

    $campaigns = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $campaigns ) ) $campaigns = [];

    $id = $data['id'] ?? substr( md5( uniqid( '', true ) ), 0, 8 );
    $now = current_time( 'mysql' );

    $data['id']         = $id;
    $data['updated_at'] = $now;

    if ( empty( $data['created_at'] ) ) {
        $data['created_at'] = $now;
    }
    if ( empty( $data['status'] ) ) {
        $data['status'] = RP_EM_STATUS_DRAFT;
    }
    if ( ! isset( $data['stats'] ) ) {
        $data['stats'] = [ 'sent' => 0, 'failed' => 0, 'errors' => [] ];
    }

    // Aggiorna se esiste, altrimenti aggiungi
    $found = false;
    foreach ( $campaigns as $i => $c ) {
        if ( ( $c['id'] ?? '' ) === $id ) {
            $campaigns[ $i ] = array_merge( $c, $data );
            $found = true;
            break;
        }
    }
    if ( ! $found ) {
        $campaigns[] = $data;
    }

    update_option( RP_EM_CAMPAIGNS_KEY, $campaigns, false );
    return $id;
}

/**
 * Elimina una campagna. Rimuove anche il cron event se schedulato.
 *
 * @param string $id ID della campagna.
 * @return bool True se eliminata.
 */
function rp_em_delete_campaign( string $id ): bool {

    $campaigns = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $campaigns ) ) return false;

    // Rimuovi cron se schedulata
    $campaign = rp_em_get_campaign( $id );
    if ( $campaign && $campaign['status'] === RP_EM_STATUS_SCHEDULED ) {
        rp_em_unschedule_campaign( $id );
    }

    $campaigns = array_values( array_filter( $campaigns, fn( $c ) => ( $c['id'] ?? '' ) !== $id ) );
    return update_option( RP_EM_CAMPAIGNS_KEY, $campaigns, false );
}

/**
 * Schedula una campagna per invio differito via WP-Cron.
 *
 * @param string $campaign_id ID campagna.
 * @param string $datetime    Datetime in formato 'Y-m-d H:i' (timezone del sito).
 * @return bool True se schedulata con successo.
 *
 * Esempio:
 *   rp_em_schedule_campaign( 'abc12345', '2025-06-15 10:00' );
 */
function rp_em_schedule_campaign( string $campaign_id, string $datetime ): bool {

    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) return false;

    // Converte datetime locale in timestamp UTC per WP-Cron
    $timestamp = rp_em_local_to_timestamp( $datetime );
    if ( ! $timestamp || $timestamp <= time() ) {
        return false;
    }

    // Rimuovi eventuali schedule precedenti
    rp_em_unschedule_campaign( $campaign_id );

    $scheduled = wp_schedule_single_event( $timestamp, RP_EM_CRON_HOOK, [ $campaign_id ] );

    if ( $scheduled !== false ) {
        rp_em_save_campaign( [
            'id'           => $campaign_id,
            'status'       => RP_EM_STATUS_SCHEDULED,
            'scheduled_at' => $datetime,
        ] );
        return true;
    }

    return false;
}

/**
 * Rimuove la schedulazione cron di una campagna.
 *
 * @param string $campaign_id
 * @return void
 */
function rp_em_unschedule_campaign( string $campaign_id ): void {

    $timestamp = wp_next_scheduled( RP_EM_CRON_HOOK, [ $campaign_id ] );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, RP_EM_CRON_HOOK, [ $campaign_id ] );
    }
}

/**
 * Esegue una campagna (invio immediato o chiamato dal cron).
 * Raccoglie i contatti dalla sorgente configurata, invia e aggiorna le stats.
 *
 * @param string $campaign_id ID campagna.
 * @return array { sent: int, failed: int, errors: string[] }
 */
function rp_em_execute_campaign( string $campaign_id ): array {

    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) {
        return [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Campagna non trovata.' ] ];
    }

    // Segna come in invio
    rp_em_save_campaign( [
        'id'     => $campaign_id,
        'status' => RP_EM_STATUS_SENDING,
    ] );

    // Raccogli contatti dalla sorgente
    $contacts = rp_em_resolve_campaign_contacts( $campaign );

    if ( empty( $contacts ) ) {
        rp_em_save_campaign( [
            'id'     => $campaign_id,
            'status' => RP_EM_STATUS_FAILED,
            'stats'  => [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Nessun contatto trovato.' ] ],
        ] );
        return [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Nessun contatto trovato.' ] ];
    }

    $rate_limit = intval( $campaign['rate_limit'] ?? 200000 );
    $result     = rp_em_send_campaign( $contacts, $campaign['subject'], $campaign['body'], $rate_limit );

    // Aggiorna campagna con risultati
    $status = $result['failed'] > 0 && $result['sent'] === 0
        ? RP_EM_STATUS_FAILED
        : RP_EM_STATUS_SENT;

    rp_em_save_campaign( [
        'id'     => $campaign_id,
        'status' => $status,
        'stats'  => $result,
    ] );

    return $result;
}

/**
 * Risolve i contatti per una campagna in base alla sorgente configurata.
 *
 * @param array $campaign Dati campagna.
 * @return array Lista contatti deduplicata.
 */
function rp_em_resolve_campaign_contacts( array $campaign ): array {

    $source_type = $campaign['source_type'] ?? 'hustle';
    $sources     = [];

    // Hustle subscribers
    if ( in_array( $source_type, [ 'hustle', 'mixed' ], true ) ) {
        $module_ids = $campaign['module_ids'] ?? [];
        $sources[]  = rp_em_get_hustle_subscribers( $module_ids );
    }

    // CSV contacts
    if ( in_array( $source_type, [ 'csv', 'mixed' ], true ) ) {
        $csv_raw = $campaign['csv_contacts'] ?? '';
        if ( ! empty( $csv_raw ) ) {
            $sources[] = rp_em_parse_csv_contacts( $csv_raw );
        }
    }

    if ( empty( $sources ) ) return [];

    return rp_em_merge_contacts( ...$sources );
}

// ── WP-CRON HANDLER ───────────────────────────────────────────────────────────

add_action( RP_EM_CRON_HOOK, function ( string $campaign_id ) {
    rp_em_execute_campaign( $campaign_id );
} );

// ── INTERNAL HELPERS ──────────────────────────────────────────────────────────

/**
 * Converte un datetime locale del sito in timestamp Unix.
 *
 * @param string $datetime Formato 'Y-m-d H:i' o 'Y-m-d H:i:s'.
 * @return int|false Timestamp o false se parsing fallisce.
 */
function rp_em_local_to_timestamp( string $datetime ): int|false {

    $tz_string = get_option( 'timezone_string' );
    if ( empty( $tz_string ) ) {
        $offset    = (float) get_option( 'gmt_offset', 0 );
        $sign      = $offset >= 0 ? '+' : '-';
        $abs       = abs( $offset );
        $hours     = (int) $abs;
        $minutes   = (int) ( ( $abs - $hours ) * 60 );
        $tz_string = sprintf( '%s%02d:%02d', $sign, $hours, $minutes );
    }

    try {
        $tz = new \DateTimeZone( $tz_string );
        $dt = new \DateTime( $datetime, $tz );
        return $dt->getTimestamp();
    } catch ( \Exception ) {
        return false;
    }
}
