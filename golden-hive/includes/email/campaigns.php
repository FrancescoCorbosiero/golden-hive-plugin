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

// Costanti PRIMA del guard — `const` non sopravvive a early-return del file
// mentre le function declarations si (PHP hoisting). Vedi nota in validator.php.
defined( 'RP_EM_CAMPAIGNS_KEY' )     || define( 'RP_EM_CAMPAIGNS_KEY',     'rp_em_campaigns' );
defined( 'RP_EM_CRON_HOOK' )         || define( 'RP_EM_CRON_HOOK',         'rp_em_cron_send_campaign' );
defined( 'RP_EM_STATUS_DRAFT' )      || define( 'RP_EM_STATUS_DRAFT',      'draft' );
defined( 'RP_EM_STATUS_SCHEDULED' )  || define( 'RP_EM_STATUS_SCHEDULED',  'scheduled' );
defined( 'RP_EM_STATUS_SENDING' )    || define( 'RP_EM_STATUS_SENDING',    'sending' );
defined( 'RP_EM_STATUS_SENT' )       || define( 'RP_EM_STATUS_SENT',       'sent' );
defined( 'RP_EM_STATUS_FAILED' )     || define( 'RP_EM_STATUS_FAILED',     'failed' );
defined( 'RP_EM_CAMPAIGN_LOCK_TTL' ) || define( 'RP_EM_CAMPAIGN_LOCK_TTL', 3600 ); // 1h

// Guard: se le funzioni sono gia state dichiarate (es. da rp-email-marketing
// standalone), non le ri-dichiariamo. Usiamo function_exists invece di
// defined(constant) per non accoppiare le due cose.
if ( function_exists( 'rp_em_save_campaign' ) ) return;

/**
 * Ritorna tutte le campagne ordinate per created_at DESC.
 *
 * @return array[]
 */
function rp_em_get_campaigns(): array {
    $all = get_option( RP_EM_CAMPAIGNS_KEY, [] );
    if ( ! is_array( $all ) ) return [];

    // Difensivo: se wp_options fosse corrotto e contenesse un valore
    // non-array, usort/foreach andrebbero in TypeError su PHP 8. Filtriamo
    // qui una volta per tutti i caller a valle.
    $all = array_values( array_filter( $all, 'is_array' ) );

    usort( $all, fn( $a, $b ) => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
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
 * Protetto da un lock basato su transient per evitare doppio-send:
 * - AJAX click + cron che scatta nello stesso istante
 * - User che clicca "Invia ora" due volte
 * - Due richieste AJAX in race su hoster a bassa concorrenza
 *
 * Il lock ha TTL finito: se una run precedente muore senza rilasciare il
 * lock (fatal PHP, timeout del SAPI), dopo RP_EM_CAMPAIGN_LOCK_TTL la
 * campagna e di nuovo eseguibile.
 *
 * @param string $campaign_id
 * @return array { sent, failed, errors, progress?, total?, skipped_reason? }
 */
function rp_em_execute_campaign( string $campaign_id ): array {
    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) {
        return [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Campagna non trovata.' ] ];
    }

    // ── Concurrency lock — acquisizione atomica via transient.
    $lock_key = 'rp_em_camp_lock_' . md5( $campaign_id );
    $lock_ttl = (int) apply_filters( 'rp_em_campaign_lock_ttl', RP_EM_CAMPAIGN_LOCK_TTL );
    if ( get_transient( $lock_key ) ) {
        return [
            'sent'           => 0,
            'failed'         => 0,
            'errors'         => [ 'Invio gia in corso per questa campagna. Aspetta il completamento o ritenta tra ' . $lock_ttl . 's.' ],
            'skipped_reason' => 'locked',
        ];
    }
    set_transient( $lock_key, (string) time(), $lock_ttl );

    // Garantiamo rilascio del lock anche in caso di fatal PHP: register_shutdown.
    register_shutdown_function( function () use ( $lock_key ) {
        delete_transient( $lock_key );
    } );

    // Wrap tutto il resto in try/finally cosi il lock viene rilasciato
    // anche su eccezione e lo status della campagna non resta 'sending'.
    try {
        return rp_em_execute_campaign_internal( $campaign_id, $campaign );
    } catch ( \Throwable $e ) {
        error_log( 'rp_em_execute_campaign: exception for ' . $campaign_id . ' — ' . $e->getMessage() );
        rp_em_save_campaign( [
            'id'     => $campaign_id,
            'status' => RP_EM_STATUS_FAILED,
            'stats'  => [
                'sent'   => 0,
                'failed' => 0,
                'errors' => [ 'Fatal interno: ' . $e->getMessage() ],
            ],
        ] );
        return [
            'sent'   => 0,
            'failed' => 0,
            'errors' => [ 'Fatal interno: ' . $e->getMessage() ],
        ];
    } finally {
        delete_transient( $lock_key );
    }
}

/**
 * Implementazione interna di execute_campaign. Separata da rp_em_execute_campaign
 * per tenere chiaro il flusso lock/unlock.
 *
 * @param string $campaign_id
 * @param array  $campaign
 * @return array
 */
function rp_em_execute_campaign_internal( string $campaign_id, array $campaign ): array {
    // Validator BLOCCANTE: solo fatali (template mancante, HTML rotto,
    // subject vuoto). Placeholder mancanti / brand incompleto NON bloccano
    // piu il send — il renderer li sostituisce con stringa vuota.
    $blocking = rp_em_validate_campaign_blocking( $campaign_id );
    // Compute anche la validazione strict in modo da salvarla come
    // last_validation (cosi l'UI continua a mostrare warning/quality issues)
    // ma non la usiamo per decidere se bloccare.
    $full = rp_em_validate_campaign( $campaign_id );

    if ( ! $blocking['ok'] ) {
        rp_em_save_campaign( [
            'id'              => $campaign_id,
            'status'          => RP_EM_STATUS_FAILED,
            'last_validation' => $full,
            'stats'           => [ 'sent' => 0, 'failed' => 0, 'errors' => array_map( fn( $e ) => $e['message'], $blocking['errors'] ) ],
        ] );
        return [ 'sent' => 0, 'failed' => 0, 'errors' => array_map( fn( $e ) => $e['message'], $blocking['errors'] ) ];
    }

    rp_em_save_campaign( [
        'id'              => $campaign_id,
        'status'          => RP_EM_STATUS_SENDING,
        'started_at'      => current_time( 'mysql' ),
        'last_validation' => $full,
    ] );

    // Render: isolato in try/catch cosi qualunque eccezione del layer
    // placeholder/template/WC resolver viene catturata e segnalata senza
    // far 500 la response.
    try {
        $html = rp_em_render_campaign( $campaign_id );
    } catch ( \Throwable $e ) {
        error_log( 'rp_em_render_campaign threw for ' . $campaign_id . ' — ' . $e->getMessage() );
        rp_em_save_campaign( [
            'id'     => $campaign_id,
            'status' => RP_EM_STATUS_FAILED,
            'stats'  => [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Render exception: ' . $e->getMessage() ] ],
        ] );
        return [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Render exception: ' . $e->getMessage() ] ];
    }

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
        'id'           => $campaign_id,
        'status'       => $status,
        'stats'        => $result,
        'last_render'  => $html,
        'completed_at' => current_time( 'mysql' ),
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
    // Il cron handler NON deve mai propagare exception: una risolleva qui
    // ucciderebbe il runner cron per tutte le altre campagne in coda.
    try {
        rp_em_execute_campaign( $campaign_id );
    } catch ( \Throwable $e ) {
        error_log( 'rp_em_cron: execute_campaign threw for ' . $campaign_id . ' — ' . $e->getMessage() );
        if ( function_exists( 'rp_em_save_campaign' ) ) {
            rp_em_save_campaign( [
                'id'     => $campaign_id,
                'status' => RP_EM_STATUS_FAILED,
                'stats'  => [ 'sent' => 0, 'failed' => 0, 'errors' => [ 'Cron fatal: ' . $e->getMessage() ] ],
            ] );
        }
    }
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
