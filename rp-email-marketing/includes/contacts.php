<?php
/**
 * Contacts — sorgenti contatti per campagne email.
 *
 * Supporta:
 * - Hustle optin modules (singolo modulo o tutti)
 * - CSV raw (upload o stringa)
 * - Merge e deduplicazione cross-source
 *
 * Nessun hook WordPress qui — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

// Prevent double-loading when both golden-hive and rp-email-marketing are active.
if ( function_exists( 'rp_em_get_hustle_modules' ) ) return;

/**
 * Ritorna i moduli Hustle di tipo optin.
 *
 * @return array Lista di oggetti con: module_id, module_name, module_type
 *
 * Esempio:
 *   $modules = rp_em_get_hustle_modules();
 *   // [ { module_id: 1, module_name: "Newsletter", module_type: "popup" }, ... ]
 */
function rp_em_get_hustle_modules(): array {

    global $wpdb;
    $table = $wpdb->prefix . 'hustle_modules';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return [];
    }

    $results = $wpdb->get_results(
        "SELECT module_id, module_name, module_type
         FROM {$table}
         WHERE module_mode = 'optin'
         ORDER BY module_name ASC"
    );

    return $results ?: [];
}

/**
 * Ritorna gli iscritti da Hustle. Se $module_ids vuoto → tutti i moduli.
 * Supporta merge di moduli multipli con deduplicazione automatica.
 *
 * @param int[] $module_ids Array di ID moduli. Vuoto = tutti.
 * @return array Lista di contatti: [ { email, display_name, module_id, date_created } ]
 *
 * Esempio:
 *   $subs = rp_em_get_hustle_subscribers( [1, 3] );
 *   // Mergia iscritti dai moduli 1 e 3, deduplica per email
 */
function rp_em_get_hustle_subscribers( array $module_ids = [] ): array {

    global $wpdb;
    $et = $wpdb->prefix . 'hustle_entries';
    $mt = $wpdb->prefix . 'hustle_entries_meta';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$et}'" ) !== $et ) {
        return [];
    }

    $where = '';
    if ( ! empty( $module_ids ) ) {
        $ids    = array_map( 'intval', $module_ids );
        $in     = implode( ',', $ids );
        $where  = "WHERE e.module_id IN ({$in})";
    }

    $results = $wpdb->get_results( "
        SELECT
            e.entry_id,
            MAX( CASE WHEN em.meta_key = 'email'      THEN em.meta_value END ) AS email,
            COALESCE(
                MAX( CASE WHEN em.meta_key = 'last_name'  THEN em.meta_value END ),
                MAX( CASE WHEN em.meta_key = 'first_name' THEN em.meta_value END )
            ) AS display_name,
            e.module_id,
            e.date_created
        FROM {$et} e
        INNER JOIN {$mt} em ON e.entry_id = em.entry_id
        {$where}
        GROUP BY e.entry_id
        HAVING email IS NOT NULL AND email != ''
        ORDER BY e.date_created ASC
    " );

    if ( ! $results ) return [];

    return rp_em_deduplicate_contacts( $results );
}

/**
 * Parsa una stringa CSV e ritorna contatti normalizzati.
 * Accetta colonne: email (obbligatorio), name/nome/first_name (opzionale).
 * Auto-detect del separatore (, ; \t).
 *
 * @param string $csv_content Contenuto CSV raw.
 * @return array Lista di contatti: [ { email, display_name, source: 'csv' } ]
 *
 * Esempio:
 *   $contacts = rp_em_parse_csv_contacts( "email,name\njohn@test.com,John" );
 */
function rp_em_parse_csv_contacts( string $csv_content ): array {

    $csv_content = trim( $csv_content );
    if ( empty( $csv_content ) ) return [];

    // Rimuove BOM UTF-8
    $csv_content = preg_replace( '/^\xEF\xBB\xBF/', '', $csv_content );

    $lines = preg_split( '/\r\n|\r|\n/', $csv_content );
    if ( count( $lines ) < 2 ) return []; // Serve almeno header + 1 riga

    // Auto-detect separator
    $header_line = $lines[0];
    $sep = ',';
    foreach ( [ "\t", ';', ',' ] as $candidate ) {
        if ( str_contains( $header_line, $candidate ) ) {
            $sep = $candidate;
            break;
        }
    }

    $headers = array_map( fn( $h ) => strtolower( trim( $h, " \t\n\r\0\x0B\"'" ) ), str_getcsv( $header_line, $sep ) );

    // Trova indici colonne
    $email_col = rp_em_find_column_index( $headers, [ 'email', 'e-mail', 'mail', 'email_address' ] );
    $name_col  = rp_em_find_column_index( $headers, [ 'name', 'nome', 'first_name', 'display_name', 'full_name' ] );

    if ( $email_col === null ) return []; // Nessuna colonna email trovata

    $contacts = [];
    for ( $i = 1; $i < count( $lines ); $i++ ) {
        $line = trim( $lines[ $i ] );
        if ( empty( $line ) ) continue;

        $cols  = str_getcsv( $line, $sep );
        $email = trim( $cols[ $email_col ] ?? '' );

        if ( empty( $email ) || ! is_email( $email ) ) continue;

        $contacts[] = (object) [
            'email'        => strtolower( $email ),
            'display_name' => $name_col !== null ? trim( $cols[ $name_col ] ?? '' ) : '',
            'module_id'    => 0,
            'date_created' => '',
            'source'       => 'csv',
        ];
    }

    return $contacts;
}

/**
 * Parsa contatti da file CSV uploadato.
 *
 * @param string $file_path Path assoluto al file CSV temporaneo.
 * @return array Lista di contatti normalizzati.
 */
function rp_em_parse_csv_file( string $file_path ): array {

    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return [];
    }

    $content = file_get_contents( $file_path );
    return rp_em_parse_csv_contacts( $content );
}

/**
 * Mergia contatti da sorgenti multiple e deduplica per email.
 * La prima occorrenza di ogni email viene mantenuta.
 *
 * @param array ...$sources Array multipli di contatti.
 * @return array Lista unificata e deduplicata.
 *
 * Esempio:
 *   $all = rp_em_merge_contacts( $hustle_subs, $csv_contacts );
 */
function rp_em_merge_contacts( array ...$sources ): array {

    $all = array_merge( ...$sources );
    return rp_em_deduplicate_contacts( $all );
}

/**
 * Deduplica una lista di contatti per email (case-insensitive).
 * Mantiene la prima occorrenza.
 *
 * @param array $contacts Lista di oggetti con proprietà ->email.
 * @return array Lista deduplicata.
 */
function rp_em_deduplicate_contacts( array $contacts ): array {

    $seen  = [];
    $clean = [];

    foreach ( $contacts as $row ) {
        $key = strtolower( trim( $row->email ) );
        if ( ! isset( $seen[ $key ] ) ) {
            $seen[ $key ] = true;
            $clean[]      = $row;
        }
    }

    return $clean;
}

/**
 * Esporta contatti in formato CSV.
 *
 * @param array  $contacts Lista di contatti.
 * @param string $filename Nome file per il download.
 * @return void Outputs CSV e termina.
 */
function rp_em_export_contacts_csv( array $contacts, string $filename = '' ): void {

    if ( empty( $filename ) ) {
        $filename = 'contacts-' . gmdate( 'Ymd-Hi' ) . '.csv';
    }

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
    header( 'Pragma: no-cache' );

    $out = fopen( 'php://output', 'w' );
    // UTF-8 BOM per Excel
    fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
    fputcsv( $out, [ 'email', 'nome', 'modulo', 'data_iscrizione', 'sorgente' ] );

    foreach ( $contacts as $c ) {
        fputcsv( $out, [
            $c->email,
            $c->display_name ?? '',
            $c->module_id ?? '',
            $c->date_created ?? '',
            $c->source ?? 'hustle',
        ] );
    }

    fclose( $out );
    exit;
}

/**
 * Conta contatti per sorgente (helper UI).
 *
 * @param array $contacts
 * @return array [ 'hustle' => int, 'csv' => int, 'total' => int ]
 */
function rp_em_count_by_source( array $contacts ): array {

    $hustle = 0;
    $csv    = 0;

    foreach ( $contacts as $c ) {
        $source = $c->source ?? 'hustle';
        if ( $source === 'csv' ) {
            $csv++;
        } else {
            $hustle++;
        }
    }

    return [
        'hustle' => $hustle,
        'csv'    => $csv,
        'total'  => $hustle + $csv,
    ];
}

// ── INTERNAL HELPERS ──────────────────────────────────────────────────────────

/**
 * Trova l'indice di una colonna CSV cercando tra nomi alternativi.
 *
 * @param array $headers   Headers normalizzati (lowercase).
 * @param array $candidates Nomi possibili da cercare.
 * @return int|null Indice della colonna trovata, o null.
 */
function rp_em_find_column_index( array $headers, array $candidates ): ?int {

    foreach ( $candidates as $name ) {
        $idx = array_search( $name, $headers, true );
        if ( $idx !== false ) return $idx;
    }
    return null;
}
