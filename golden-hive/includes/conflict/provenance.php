<?php
/**
 * Provenance — tiene traccia di quale source "possiede" quali slice di un
 * prodotto WC. Read/write helpers sulla post meta.
 *
 * Meta usati (nuovi):
 * - _gh_sources        → array di { source, first_seen, last_seen }
 * - _gh_field_sources  → map slice → source_id ('catalog'|'pricing'|'stock'|'media')
 * - _gh_primary_source → tiebreaker (di solito first-in)
 *
 * Meta legacy (preservati):
 * - _gh_import_source  → scritto da feed esistenti; letto dalla migration.
 *
 * Sorgenti canoniche: 'manual' | 'kicksdb' | 'goldensneakers' | 'stockfirmati' | 'csv'
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_conflict_get_sources' ) ) return;

const GH_CONFLICT_SOURCES_META       = '_gh_sources';
const GH_CONFLICT_FIELD_SOURCES_META = '_gh_field_sources';
const GH_CONFLICT_PRIMARY_META       = '_gh_primary_source';

const GH_CONFLICT_SLICES = [ 'catalog', 'pricing', 'stock', 'media' ];

/**
 * Ritorna la lista di source che hanno "toccato" il prodotto.
 *
 * @return array [ { source, first_seen, last_seen }, ... ]
 */
function gh_conflict_get_sources( int $product_id ): array {
    $v = get_post_meta( $product_id, GH_CONFLICT_SOURCES_META, true );
    return is_array( $v ) ? $v : [];
}

/**
 * Ritorna solo i nomi dei source (deduplicati).
 *
 * @return string[]
 */
function gh_conflict_get_source_names( int $product_id ): array {
    $rows = gh_conflict_get_sources( $product_id );
    $out  = [];
    foreach ( $rows as $r ) {
        if ( ! empty( $r['source'] ) ) $out[] = (string) $r['source'];
    }
    return array_values( array_unique( $out ) );
}

/**
 * Ritorna la mappa slice → source.
 *
 * @return array Mappa, fallback a empty se mancante.
 */
function gh_conflict_get_field_sources( int $product_id ): array {
    $v = get_post_meta( $product_id, GH_CONFLICT_FIELD_SOURCES_META, true );
    return is_array( $v ) ? $v : [];
}

/**
 * Ritorna il primary source (tiebreaker). Fallback al first-in se mancante.
 */
function gh_conflict_get_primary_source( int $product_id ): string {
    $v = (string) get_post_meta( $product_id, GH_CONFLICT_PRIMARY_META, true );
    if ( $v !== '' ) return $v;

    $rows = gh_conflict_get_sources( $product_id );
    return $rows[0]['source'] ?? '';
}

/**
 * Registra (o aggiorna) una source sul prodotto. Non distruttivo:
 * - se la source non e presente, la aggiunge con first_seen=now.
 * - se presente, aggiorna solo last_seen.
 * - opzionalmente setta/aggiorna le field_sources per le slice toccate.
 * - imposta _gh_primary_source solo se non esiste gia.
 *
 * @param int    $product_id
 * @param string $source        Identificatore canonico.
 * @param array  $slice_owners  Mappa slice => source. Se vuota, non tocca field_sources.
 * @param bool   $merge_only    Se true, NON modifica field_sources sulle slice
 *                              che hanno gia un owner diverso. Usato da
 *                              update-path per preservare le scelte dell'utente.
 */
function gh_conflict_record_source( int $product_id, string $source, array $slice_owners = [], bool $merge_only = false ): void {

    if ( $product_id <= 0 || $source === '' ) return;

    $now  = current_time( 'mysql' );
    $rows = gh_conflict_get_sources( $product_id );

    $found = false;
    foreach ( $rows as &$r ) {
        if ( ( $r['source'] ?? '' ) === $source ) {
            $r['last_seen'] = $now;
            $found = true;
            break;
        }
    }
    unset( $r );

    if ( ! $found ) {
        $rows[] = [
            'source'     => $source,
            'first_seen' => $now,
            'last_seen'  => $now,
        ];
    }

    update_post_meta( $product_id, GH_CONFLICT_SOURCES_META, $rows );

    // Primary: set solo se vuoto (sticky al first-in)
    $primary = (string) get_post_meta( $product_id, GH_CONFLICT_PRIMARY_META, true );
    if ( $primary === '' ) {
        update_post_meta( $product_id, GH_CONFLICT_PRIMARY_META, $source );
    }

    // Field sources
    if ( ! empty( $slice_owners ) ) {
        $existing = gh_conflict_get_field_sources( $product_id );
        foreach ( $slice_owners as $slice => $src ) {
            if ( ! in_array( $slice, GH_CONFLICT_SLICES, true ) ) continue;
            if ( $merge_only && ! empty( $existing[ $slice ] ) ) continue;
            $existing[ $slice ] = (string) $src;
        }
        update_post_meta( $product_id, GH_CONFLICT_FIELD_SOURCES_META, $existing );
    }
}

/**
 * Setta esplicitamente il primary source (override). Usato dall'UI delle rules.
 */
function gh_conflict_set_primary_source( int $product_id, string $source ): void {
    if ( $product_id <= 0 || $source === '' ) return;
    update_post_meta( $product_id, GH_CONFLICT_PRIMARY_META, $source );
}

/**
 * Override diretto delle field sources (usato dall'UI; non chiama la logica
 * di record, presume che i sources siano gia tracciati).
 */
function gh_conflict_set_field_sources( int $product_id, array $map ): void {
    $clean = [];
    foreach ( $map as $slice => $src ) {
        if ( ! in_array( $slice, GH_CONFLICT_SLICES, true ) ) continue;
        $clean[ $slice ] = (string) $src;
    }
    update_post_meta( $product_id, GH_CONFLICT_FIELD_SOURCES_META, $clean );
}
