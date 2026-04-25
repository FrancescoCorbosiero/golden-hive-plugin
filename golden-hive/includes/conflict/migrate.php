<?php
/**
 * Conflict — migrazione di provenance per prodotti esistenti.
 *
 * Runna UNA VOLTA all'attivazione del plugin (o via AJAX manuale) e
 * backfilla _gh_sources / _gh_field_sources su tutti i prodotti esistenti
 * che NON li hanno ancora.
 *
 * Logic:
 * - Se _gh_import_source meta esiste → source = quel valore.
 *   (es. 'goldensneakers' per GS, 'stockfirmati' per SF, 'feed' per CSV).
 * - Altrimenti → 'manual' (catchall: creato a mano nell'admin o da altri
 *   plugin). Questo e il caso piu sicuro: la rule 'manual_sacred' blocca
 *   ogni feed dal sovrascriverli di default.
 *
 * La migration e idempotente: prodotti che hanno gia _gh_sources sono
 * skippati. Safe da ri-eseguire.
 *
 * Processing batched per non timeout su grandi cataloghi:
 * - default 200 prodotti per tick
 * - salva cursore in option: gh_conflict_migration_cursor
 * - completato quando cursore >= total count
 */

defined( 'ABSPATH' ) || exit;

// Const SOPRA il guard (idempotenti). Internal-only nella pratica, ma
// uniformiamo il pattern per evitare la stessa classe di bug.
defined( 'GH_CONFLICT_MIGRATION_CURSOR' )   || define( 'GH_CONFLICT_MIGRATION_CURSOR',   'gh_conflict_migration_cursor' );
defined( 'GH_CONFLICT_MIGRATION_COMPLETE' ) || define( 'GH_CONFLICT_MIGRATION_COMPLETE', 'gh_conflict_migration_complete' );
defined( 'GH_CONFLICT_MIGRATION_BATCH' )    || define( 'GH_CONFLICT_MIGRATION_BATCH',    200 );

if ( function_exists( 'gh_conflict_migrate_run' ) ) return;

/**
 * Mappa _gh_import_source legacy → source canonico.
 */
function gh_conflict_map_legacy_source( string $legacy ): string {
    $legacy = strtolower( trim( $legacy ) );
    $map = [
        'goldensneakers' => 'goldensneakers',
        'gs'             => 'goldensneakers',
        'stockfirmati'   => 'stockfirmati',
        'sf'             => 'stockfirmati',
        'csv'            => 'csv',
        'feed'           => 'csv',
        'config_feed'    => 'csv',
        'kicksdb'        => 'kicksdb',
    ];
    return $map[ $legacy ] ?? ( $legacy !== '' ? $legacy : 'manual' );
}

/**
 * Esegue un batch di migrazione. Ritorna lo stato progress.
 *
 * @param int $batch_size
 * @return array {
 *   @type int  $processed   Prodotti processati in questo batch.
 *   @type int  $backfilled  Prodotti effettivamente aggiornati (non gia taggati).
 *   @type int  $skipped     Prodotti skippati (gia taggati).
 *   @type int  $cursor_after Posizione del cursore dopo il batch.
 *   @type int  $total       Totale prodotti da migrare.
 *   @type bool $done        True se la migrazione e completata.
 * }
 */
function gh_conflict_migrate_run( int $batch_size = GH_CONFLICT_MIGRATION_BATCH ): array {

    if ( (bool) get_option( GH_CONFLICT_MIGRATION_COMPLETE, false ) ) {
        return [
            'processed' => 0, 'backfilled' => 0, 'skipped' => 0,
            'cursor_after' => 0, 'total' => 0, 'done' => true,
        ];
    }

    $cursor = (int) get_option( GH_CONFLICT_MIGRATION_CURSOR, 0 );
    $batch_size = max( 10, min( 1000, $batch_size ) );

    global $wpdb;

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(ID) FROM {$wpdb->posts}
         WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending')"
    );

    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'product' AND post_status IN ('publish','draft','private','pending')
         ORDER BY ID ASC
         LIMIT %d OFFSET %d",
        $batch_size, $cursor
    ) );

    $backfilled = 0;
    $skipped    = 0;

    foreach ( $ids as $id ) {
        $pid = (int) $id;

        $existing = get_post_meta( $pid, GH_CONFLICT_SOURCES_META, true );
        if ( is_array( $existing ) && ! empty( $existing ) ) {
            $skipped++;
            continue;
        }

        $legacy = (string) get_post_meta( $pid, '_gh_import_source', true );
        $source = gh_conflict_map_legacy_source( $legacy );

        // Imposta field_sources con tutto attribuito al single source
        $slice_owners = array_fill_keys( GH_CONFLICT_SLICES, $source );
        gh_conflict_record_source( $pid, $source, $slice_owners, false );

        $backfilled++;
    }

    $cursor_after = $cursor + count( $ids );
    update_option( GH_CONFLICT_MIGRATION_CURSOR, $cursor_after, false );

    $done = $cursor_after >= $total || count( $ids ) < $batch_size;
    if ( $done ) {
        update_option( GH_CONFLICT_MIGRATION_COMPLETE, true, false );
    }

    return [
        'processed'    => count( $ids ),
        'backfilled'   => $backfilled,
        'skipped'      => $skipped,
        'cursor_after' => $cursor_after,
        'total'        => $total,
        'done'         => $done,
    ];
}

/**
 * Reset cursore (per ri-eseguire la migration). NOTA: non rimuove le meta gia
 * scritte; serve solo per marciare di nuovo dall'inizio (e idempotente).
 */
function gh_conflict_migrate_reset(): void {
    delete_option( GH_CONFLICT_MIGRATION_CURSOR );
    delete_option( GH_CONFLICT_MIGRATION_COMPLETE );
}

/**
 * Install default rules se non gia presenti. Chiamata all'attivazione.
 */
function gh_conflict_install_default_rules(): void {
    $existing = gh_option_list_all( GH_CONFLICT_RULES_KEY );
    if ( ! empty( $existing ) ) return;
    gh_option_list_replace( GH_CONFLICT_RULES_KEY, gh_conflict_default_rules() );
}

/**
 * Hook di attivazione: installa default rules + lancia prima passata di
 * migrazione (il resto gira in batch via AJAX/cron successivamente).
 */
function gh_conflict_on_activate(): void {
    gh_conflict_install_default_rules();
    // Una passata immediata (200 prodotti). Se il sito ne ha di piu,
    // l'UI (phase 2b) permettera di continuare in batch.
    gh_conflict_migrate_run();
}
