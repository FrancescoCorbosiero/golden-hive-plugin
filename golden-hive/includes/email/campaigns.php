<?php
/**
 * Campaigns — CRUD campagne email + scheduling via WP-Cron.
 *
 * Una campagna lega un template (brand implicito = config del sito) a un
 * payload di valori {CAMPAIGN_*} + una lista ordinata di product_ids
 * WooCommerce che popola i {PRODUCT_N_*}.
 *
 * Shape campagna:
 *   - id:              string   ID univoco
 *   - name:            string   Nome interno
 *   - subject:         string   Oggetto email
 *   - preheader:       string   Preheader (prima riga di preview nel client)
 *   - template_id:     string   ID template (rp_em_get_template)
 *   - payload:         array    Map { CAMPAIGN_* => string, META_* => string override }
 *   - product_ids:     int[]    ID WooCommerce ordinati → PRODUCT_1_*, PRODUCT_2_*, ...
 *   - source_type:     string   'hustle' | 'csv' | 'mixed'
 *   - module_ids:      int[]    ID moduli Hustle
 *   - csv_contacts:    string   CSV raw
 *   - rate_limit:      int      Microsecondi tra invii
 *   - scheduled_at:    string   Datetime ISO per invio programmato
 *   - status:          string   draft | scheduled | sending | sent | failed
 *   - stats:           array    { sent, failed, errors }
 *   - last_render:     string   HTML cached dell'ultimo render (per debug/audit)
 *   - last_validation: array    { errors, warnings, validated_at }
 *   - created_at:      string
 *   - updated_at:      string
 *
 * Storage: wp_option 'rp_em_campaigns' (array serializzato).
 * Nessun hook WordPress tranne il cron handler.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RP_EM_CAMPAIGNS_KEY' ) ) return;

const RP_EM_CAMPAIGNS_KEY = 'rp_em_campaigns';
const RP_EM_CRON_HOOK     = 'rp_em_cron_send_campaign';

const RP_EM_STATUS_DRAFT     = 'draft';
const RP_EM_STATUS_SCHEDULED = 'scheduled';
const RP_EM_STATUS_SENDING   = 'sending';
const RP_EM_STATUS_SENT      = 'sent';
const RP_EM_STATUS_FAILED    = 'failed';

/**
 * Ritorna tutte le campagne ordinate per created_at DESC.
 *
 * @return array[]
 */
function rp_em_get_campaigns(): array {
    $all = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $all ) ) return [];

    usort( $all, fn( $a, $b ) => strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ) );
    return $all;
}

/**
 * Ritorna una campagna per ID, o null.
 *
 * @param string $id
 * @return array|null
 */
function rp_em_get_campaign( string $id ): ?array {
    foreach ( rp_em_get_campaigns() as $c ) {
        if ( ( $c['id'] ?? '' ) === $id ) return $c;
    }
    return null;
}

/**
 * Crea o aggiorna una campagna. Se $data['id'] non esiste, ne genera uno.
 *
 * @param array $data
 * @return string ID della campagna salvata.
 */
function rp_em_save_campaign( array $data ): string {
    $all = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $all ) ) $all = [];

    $now = current_time( 'mysql' );
    $id  = (string) ( $data['id'] ?? '' );
    if ( $id === '' ) $id = 'cmp_' . substr( md5( uniqid( '', true ) ), 0, 8 );

    $data['id']         = $id;
    $data['updated_at'] = $now;

    $found = false;
    foreach ( $all as $i => $existing ) {
        if ( ( $existing['id'] ?? '' ) === $id ) {
            // Update selettivo: mergia i campi passati sopra quelli esistenti.
            // Non forzare default status/stats qui — solo in creazione.
            $data['created_at'] = $existing['created_at'] ?? $now;
            $all[ $i ] = array_merge( $existing, $data );
            $found = true;
            break;
        }
    }
    if ( ! $found ) {
        // Creazione: applica i default mancanti.
        $data['created_at'] = $now;
        if ( empty( $data['status'] ) )  $data['status']  = RP_EM_STATUS_DRAFT;
        if ( ! isset( $data['stats'] ) ) $data['stats']   = [ 'sent' => 0, 'failed' => 0, 'errors' => [] ];
        $all[] = $data;
    }

    update_option( RP_EM_CAMPAIGNS_KEY, $all, false );
    return $id;
}

/**
 * Elimina una campagna. Rimuove anche il cron schedulato se presente.
 *
 * @param string $id
 * @return bool
 */
function rp_em_delete_campaign( string $id ): bool {
    $campaign = rp_em_get_campaign( $id );
    if ( $campaign && ( $campaign['status'] ?? '' ) === RP_EM_STATUS_SCHEDULED ) {
        rp_em_unschedule_campaign( $id );
    }

    $all = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $all ) ) return false;
    $filtered = array_values( array_filter( $all, fn( $c ) => ( $c['id'] ?? '' ) !== $id ) );
    if ( count( $filtered ) === count( $all ) ) return false;
    update_option( RP_EM_CAMPAIGNS_KEY, $filtered, false );
    return true;
}

/**
 * Costruisce il payload completo di una campagna per il renderer:
 * - Merge del payload custom (CAMPAIGN_* + eventuali META_* override)
 * - Risoluzione dei product_ids via WooCommerce → PRODUCT_N_* fields
 *
 * @param string $campaign_id
 * @return array Map KEY => value pronta per rp_em_merge_layers.
 */
function rp_em_build_campaign_payload( string $campaign_id ): array {
    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) return [];

    $payload = is_array( $campaign['payload'] ?? null ) ? $campaign['payload'] : [];

    $product_ids = is_array( $campaign['product_ids'] ?? null )
        ? array_values( array_filter( array_map( 'intval', $campaign['product_ids'] ) ) )
        : [];

    $products_map = [];
    foreach ( $product_ids as $i => $pid ) {
        $products_map = array_merge( $products_map, rp_em_resolve_product_fields( $pid, $i + 1 ) );
    }

    return array_merge( $payload, $products_map );
}

/**
 * Schedula una campagna per invio differito via WP-Cron.
 *
 * @param string $campaign_id
 * @param string $datetime     Formato 'Y-m-d H:i' o 'Y-m-d\TH:i' (timezone sito).
 * @return bool
 */
function rp_em_schedule_campaign( string $campaign_id, string $datetime ): bool {
    if ( ! rp_em_get_campaign( $campaign_id ) ) return false;

    $datetime  = str_replace( 'T', ' ', $datetime );
    $timestamp = rp_em_local_to_timestamp( $datetime );
    if ( ! $timestamp || $timestamp <= time() ) return false;

    rp_em_unschedule_campaign( $campaign_id );

    $ok = wp_schedule_single_event( $timestamp, RP_EM_CRON_HOOK, [ $campaign_id ] );
    if ( $ok === false ) return false;

    rp_em_save_campaign( [
        'id'           => $campaign_id,
        'status'       => RP_EM_STATUS_SCHEDULED,
        'scheduled_at' => $datetime,
    ] );
    return true;
}

/**
 * Rimuove la schedulazione cron di una campagna (se presente).
 *
 * @param string $campaign_id
 * @return void
 */
function rp_em_unschedule_campaign( string $campaign_id ): void {
    $ts = wp_next_scheduled( RP_EM_CRON_HOOK, [ $campaign_id ] );
    if ( $ts ) wp_unschedule_event( $ts, RP_EM_CRON_HOOK, [ $campaign_id ] );
}

/**
 * Esegue una campagna: valida, renderizza, risolve contatti, invia.
 *
 * @param string $campaign_id
 * @return array { sent, failed, errors }
 */
function rp_em_execute_campaign( string $campaign_id ): array {
    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) {
        return [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Campagna non trovata.' ] ];
    }

    // Validate first — se errori bloccanti, abort.
    $validation = rp_em_validate_campaign( $campaign_id );
    if ( ! $validation['ok'] ) {
        rp_em_save_campaign( [
            'id'              => $campaign_id,
            'status'          => RP_EM_STATUS_FAILED,
            'last_validation' => $validation,
            'stats'           => [ 'sent' => 0, 'failed' => 0, 'errors' => array_map( fn( $e ) => $e['message'], $validation['errors'] ) ],
        ] );
        return [ 'sent' => 0, 'failed' => 0, 'errors' => array_map( fn( $e ) => $e['message'], $validation['errors'] ) ];
    }

    rp_em_save_campaign( [ 'id' => $campaign_id, 'status' => RP_EM_STATUS_SENDING ] );

    $html = rp_em_render_campaign( $campaign_id );
    if ( $html === '' ) {
        rp_em_save_campaign( [
            'id'     => $campaign_id,
            'status' => RP_EM_STATUS_FAILED,
            'stats'  => [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Render vuoto.' ] ],
        ] );
        return [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Render vuoto.' ] ];
    }

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
    $subject    = (string) ( $campaign['subject'] ?? '' );

    $result = rp_em_send_campaign_rendered(
        $contacts,
        $subject,
        $html,
        $rate_limit,
        [
            'campaign_id'   => $campaign['id']   ?? '',
            'campaign_name' => $campaign['name'] ?? '',
        ]
    );

    $status = ( $result['failed'] > 0 && $result['sent'] === 0 )
        ? RP_EM_STATUS_FAILED
        : RP_EM_STATUS_SENT;

    rp_em_save_campaign( [
        'id'          => $campaign_id,
        'status'      => $status,
        'stats'       => $result,
        'last_render' => $html,
    ] );

    return $result;
}

/**
 * Risolve i contatti della campagna in base alla sorgente configurata.
 *
 * @param array $campaign
 * @return array
 */
function rp_em_resolve_campaign_contacts( array $campaign ): array {
    $source_type = (string) ( $campaign['source_type'] ?? 'hustle' );
    $sources     = [];

    if ( in_array( $source_type, [ 'hustle', 'mixed' ], true ) ) {
        $module_ids = array_map( 'intval', (array) ( $campaign['module_ids'] ?? [] ) );
        $sources[]  = rp_em_get_hustle_subscribers( $module_ids );
    }
    if ( in_array( $source_type, [ 'csv', 'mixed' ], true ) ) {
        $csv_raw = (string) ( $campaign['csv_contacts'] ?? '' );
        if ( $csv_raw !== '' ) {
            $sources[] = rp_em_parse_csv_contacts( $csv_raw );
        }
    }

    return empty( $sources ) ? [] : rp_em_merge_contacts( ...$sources );
}

// ── WP-CRON HANDLER ───────────────────────────────────────────────────────────

add_action( RP_EM_CRON_HOOK, function ( string $campaign_id ) {
    rp_em_execute_campaign( $campaign_id );
} );

// ── INTERNAL HELPERS ──────────────────────────────────────────────────────────

/**
 * Converte un datetime locale del sito in timestamp Unix.
 *
 * @param string $datetime 'Y-m-d H:i' o 'Y-m-d H:i:s'.
 * @return int|false
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
