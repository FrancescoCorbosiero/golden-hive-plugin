<?php
/**
 * Option-list store — CRUD generico per wp_options che contengono array di record.
 *
 * Unifica il pattern ripetuto in 7+ moduli:
 *   get_option($key, []) → is_array? → find-by-id / array_filter / foreach
 *   → merge / append → update_option($key, $new, false)
 *
 * Composable: moduli nuovi persistono una lista con 4 chiamate invece di 30
 * righe di glue code. Esistenti (email/brand, email/templates, email/campaigns,
 * email/transactional, jobs/storage, mapper/storage, feeds/saved-endpoints,
 * media/whitelist) possono essere migrati in modo progressivo — le loro
 * funzioni attuali restano funzionanti, questo modulo e una nuova utility.
 *
 * Ogni record e una map associativa con $id_key (default 'id'). I timestamp
 * created_at / updated_at sono gestiti automaticamente da upsert se $timestamps.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_option_list_all' ) ) return;

/**
 * Ritorna tutti i record. Garantito array (anche se wp_option non esiste o
 * contiene scalare).
 *
 * @param string $key wp_option key.
 * @return array[]
 */
function gh_option_list_all( string $key ): array {
    $all = get_option( $key, [] );
    return is_array( $all ) ? $all : [];
}

/**
 * Trova un record per ID. Ritorna null se non trovato o ID vuoto.
 *
 * @param string $key
 * @param string $id
 * @param string $id_key Default 'id'.
 * @return array|null
 */
function gh_option_list_find( string $key, string $id, string $id_key = 'id' ): ?array {
    if ( $id === '' ) return null;
    foreach ( gh_option_list_all( $key ) as $item ) {
        if ( is_array( $item ) && (string) ( $item[ $id_key ] ?? '' ) === $id ) return $item;
    }
    return null;
}

/**
 * Crea o aggiorna un record. Genera un ID se mancante. Merge sopra il record
 * esistente (update parziale), preserva created_at.
 *
 * @param string     $key        wp_option key.
 * @param array      $data       Record. Se $data[$id_key] e vuoto, generato.
 * @param string     $id_key     Default 'id'.
 * @param string     $id_prefix  Prefix per ID generato ('tpl_', 'cmp_', ...).
 * @param ?callable  $sanitize   Callable(array $data): array. Opzionale.
 * @param bool       $timestamps Se true, gestisce created_at/updated_at.
 * @return string                ID finale del record.
 */
function gh_option_list_upsert(
    string $key,
    array $data,
    string $id_key = 'id',
    string $id_prefix = '',
    ?callable $sanitize = null,
    bool $timestamps = true
): string {
    $all = gh_option_list_all( $key );
    $now = current_time( 'mysql' );

    $id = (string) ( $data[ $id_key ] ?? '' );
    if ( $id === '' ) {
        $id = $id_prefix . substr( md5( uniqid( '', true ) ), 0, 8 );
    }
    $data[ $id_key ] = $id;

    if ( $sanitize !== null ) {
        $data = (array) $sanitize( $data );
        $data[ $id_key ] = $id;
    }

    if ( $timestamps ) $data['updated_at'] = $now;

    $found = false;
    foreach ( $all as $i => $existing ) {
        if ( is_array( $existing ) && (string) ( $existing[ $id_key ] ?? '' ) === $id ) {
            if ( $timestamps ) {
                $data['created_at'] = (string) ( $existing['created_at'] ?? $now );
            }
            $all[ $i ] = array_merge( $existing, $data );
            $found = true;
            break;
        }
    }
    if ( ! $found ) {
        if ( $timestamps ) $data['created_at'] = $now;
        $all[] = $data;
    }

    update_option( $key, $all, false );
    return $id;
}

/**
 * Elimina un record per ID.
 *
 * @param string $key
 * @param string $id
 * @param string $id_key
 * @return bool True se eliminato, false se non trovato.
 */
function gh_option_list_remove( string $key, string $id, string $id_key = 'id' ): bool {
    $all = gh_option_list_all( $key );
    $filtered = array_values( array_filter(
        $all,
        fn( $it ) => ! is_array( $it ) || (string) ( $it[ $id_key ] ?? '' ) !== $id
    ) );
    if ( count( $filtered ) === count( $all ) ) return false;
    update_option( $key, $filtered, false );
    return true;
}

/**
 * Sostituisce l'intera lista (riscrive la option). Utile per batch import
 * o reset.
 *
 * @param string  $key
 * @param array[] $items
 * @return bool
 */
function gh_option_list_replace( string $key, array $items ): bool {
    return update_option( $key, array_values( $items ), false );
}
