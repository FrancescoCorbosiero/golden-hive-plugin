<?php
/**
 * CSV Feed Pipeline — fetch, parse, map, diff, apply from external CSV sources.
 *
 * Uses the existing building blocks:
 * - rp_rc_request()          → HTTP client for remote CSV fetch
 * - rp_rc_parse_csv()        → CSV parser with separator auto-detect
 * - gh_mapper_apply_rule_bulk() → field mapping with transforms
 * - gh_create_simple/variable_product() → product factory
 *
 * Feed configs are stored in wp_options under GH_CSV_FEEDS_KEY.
 * Each feed has: id, name, source (url/upload), mapping_rule_id, schedule, options, last_run.
 */

defined( 'ABSPATH' ) || exit;

/** wp_options key for CSV feed configs. */
define( 'GH_CSV_FEEDS_KEY', 'gh_csv_feeds' );

/** WP Cron hook name. */
define( 'GH_CSV_CRON_HOOK', 'gh_csv_cron_run_feed' );

// ── Feed Config CRUD ───────────────────────────────────────

/**
 * Gets all saved CSV feed configs.
 *
 * @return array[]
 */
function gh_csv_get_feeds(): array {
    $feeds = get_option( GH_CSV_FEEDS_KEY, [] );
    return is_array( $feeds ) ? $feeds : [];
}

/**
 * Gets a single feed by ID.
 *
 * @param string $feed_id
 * @return array|null
 */
function gh_csv_get_feed( string $feed_id ): ?array {
    foreach ( gh_csv_get_feeds() as $feed ) {
        if ( ( $feed['id'] ?? '' ) === $feed_id ) {
            return $feed;
        }
    }
    return null;
}

/**
 * Saves (creates or updates) a CSV feed config.
 *
 * @param array $feed Feed data. Empty 'id' creates new.
 * @return array The saved feed with ID.
 */
function gh_csv_save_feed( array $feed ): array {
    $feeds = gh_csv_get_feeds();
    $now   = wp_date( 'c' );

    if ( empty( $feed['id'] ) ) {
        $feed['id']         = 'cf_' . bin2hex( random_bytes( 6 ) );
        $feed['created_at'] = $now;
        $feed['updated_at'] = $now;
        $feeds[]            = $feed;
    } else {
        $found = false;
        foreach ( $feeds as $i => $existing ) {
            if ( ( $existing['id'] ?? '' ) === $feed['id'] ) {
                $feed['created_at'] = $existing['created_at'] ?? $now;
                $feed['updated_at'] = $now;
                // Preserve runtime fields not sent by UI
                $feed['last_run']    = $feed['last_run'] ?? $existing['last_run'] ?? null;
                $feed['last_result'] = $feed['last_result'] ?? $existing['last_result'] ?? null;
                $feeds[ $i ] = $feed;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $feed['created_at'] = $now;
            $feed['updated_at'] = $now;
            $feeds[] = $feed;
        }
    }

    update_option( GH_CSV_FEEDS_KEY, $feeds, false );

    // Sync cron schedule
    gh_csv_sync_cron( $feed );

    return $feed;
}

/**
 * Deletes a CSV feed by ID.
 *
 * @param string $feed_id
 * @return bool
 */
function gh_csv_delete_feed( string $feed_id ): bool {
    $feeds   = gh_csv_get_feeds();
    $initial = count( $feeds );

    $feeds = array_values( array_filter( $feeds, fn( $f ) => ( $f['id'] ?? '' ) !== $feed_id ) );

    if ( count( $feeds ) === $initial ) {
        return false;
    }

    update_option( GH_CSV_FEEDS_KEY, $feeds, false );

    // Remove cron
    gh_csv_unschedule( $feed_id );

    return true;
}

// ── CSV Source Reader ──────────────────────────────────────

/**
 * Reads CSV data from a feed's configured source.
 *
 * @param array $feed Feed config.
 * @return array|WP_Error Parsed CSV rows (array of assoc arrays).
 */
function gh_csv_read_source( array $feed ): array|WP_Error {
    $source_type = $feed['source_type'] ?? 'url';

    if ( $source_type === 'url' ) {
        return gh_csv_fetch_url( $feed['source_url'] ?? '', $feed['source_headers'] ?? [] );
    }

    if ( $source_type === 'file' ) {
        return gh_csv_read_file( $feed['source_path'] ?? '' );
    }

    return new WP_Error( 'invalid_source', 'Tipo sorgente non valido: ' . $source_type );
}

/**
 * Fetches a CSV from a remote URL.
 *
 * @param string $url     Remote CSV URL.
 * @param array  $headers Optional HTTP headers.
 * @return array|WP_Error
 */
function gh_csv_fetch_url( string $url, array $headers = [] ): array|WP_Error {
    if ( ! $url ) {
        return new WP_Error( 'missing_url', 'URL sorgente mancante.' );
    }

    $response = rp_rc_request( [
        'url'     => $url,
        'method'  => 'GET',
        'headers' => $headers,
        'timeout' => 120,
    ] );

    if ( ! empty( $response['error'] ) ) {
        return new WP_Error( 'fetch_error', $response['error'] );
    }
    if ( $response['status'] < 200 || $response['status'] >= 300 ) {
        return new WP_Error( 'http_error', "HTTP {$response['status']}: risposta non valida." );
    }

    return rp_rc_parse_csv( $response['body'] );
}

/**
 * Reads a local CSV file (uploaded to wp-content/uploads).
 *
 * @param string $path File path relative to uploads dir, or absolute.
 * @return array|WP_Error
 */
function gh_csv_read_file( string $path ): array|WP_Error {
    if ( ! $path ) {
        return new WP_Error( 'missing_path', 'Percorso file mancante.' );
    }

    // Resolve relative paths against uploads dir
    if ( ! str_starts_with( $path, '/' ) ) {
        $upload_dir = wp_upload_dir();
        $path = trailingslashit( $upload_dir['basedir'] ) . $path;
    }

    // Security: ensure path is within uploads or wp-content
    $real = realpath( $path );
    if ( ! $real || ! str_contains( $real, 'wp-content' ) ) {
        return new WP_Error( 'invalid_path', 'Percorso file non valido o fuori da wp-content.' );
    }

    if ( ! file_exists( $real ) || ! is_readable( $real ) ) {
        return new WP_Error( 'file_not_found', 'File non trovato: ' . basename( $path ) );
    }

    $body = file_get_contents( $real );
    if ( $body === false ) {
        return new WP_Error( 'read_error', 'Impossibile leggere il file.' );
    }

    return rp_rc_parse_csv( $body );
}

// ── Generic Diff Engine ───────────────────────────────────

/**
 * Compares mapped product data against existing WooCommerce products.
 * Matches by SKU. Detects price, stock, and name changes.
 *
 * @param array $products WooCommerce-format product arrays (output of mapper).
 * @return array { new[], update[], unchanged[], summary{} }
 */
function gh_csv_diff( array $products ): array {
    $new       = [];
    $update    = [];
    $unchanged = [];

    foreach ( $products as $product ) {
        $sku = $product['sku'] ?? '';
        if ( ! $sku ) {
            $new[] = $product;
            continue;
        }

        $existing_id = wc_get_product_id_by_sku( $sku );
        if ( ! $existing_id ) {
            $new[] = $product;
            continue;
        }

        $existing = wc_get_product( $existing_id );
        if ( ! $existing ) {
            $new[] = $product;
            continue;
        }

        $changes = gh_csv_detect_changes( $existing, $product );
        if ( $changes ) {
            $product['_existing_id'] = $existing_id;
            $product['_changes']     = $changes;
            $update[] = $product;
        } else {
            $product['_existing_id'] = $existing_id;
            $unchanged[] = $product;
        }
    }

    return [
        'new'       => $new,
        'update'    => $update,
        'unchanged' => $unchanged,
        'summary'   => [
            'total'     => count( $products ),
            'new'       => count( $new ),
            'update'    => count( $update ),
            'unchanged' => count( $unchanged ),
        ],
    ];
}

/**
 * Detects differences between an existing WC product and new data.
 *
 * @param WC_Product $existing
 * @param array      $new_data
 * @return array List of changed fields, or empty if identical.
 */
function gh_csv_detect_changes( WC_Product $existing, array $new_data ): array {
    $changes = [];

    if ( isset( $new_data['name'] ) && $existing->get_name() !== $new_data['name'] ) {
        $changes[] = 'name';
    }

    if ( isset( $new_data['regular_price'] ) && (string) $existing->get_regular_price() !== (string) $new_data['regular_price'] ) {
        $changes[] = 'regular_price';
    }

    if ( isset( $new_data['sale_price'] ) && (string) $existing->get_sale_price() !== (string) $new_data['sale_price'] ) {
        $changes[] = 'sale_price';
    }

    if ( isset( $new_data['stock_quantity'] ) && (int) $existing->get_stock_quantity() !== (int) $new_data['stock_quantity'] ) {
        $changes[] = 'stock_quantity';
    }

    if ( isset( $new_data['stock_status'] ) && $existing->get_stock_status() !== $new_data['stock_status'] ) {
        $changes[] = 'stock_status';
    }

    if ( isset( $new_data['description'] ) && $existing->get_description() !== $new_data['description'] ) {
        $changes[] = 'description';
    }

    if ( isset( $new_data['short_description'] ) && $existing->get_short_description() !== $new_data['short_description'] ) {
        $changes[] = 'short_description';
    }

    if ( isset( $new_data['weight'] ) && (string) $existing->get_weight() !== (string) $new_data['weight'] ) {
        $changes[] = 'weight';
    }

    if ( isset( $new_data['status'] ) && $existing->get_status() !== $new_data['status'] ) {
        $changes[] = 'status';
    }

    return $changes;
}

// ── Apply (Create / Update) ───────────────────────────────

/**
 * Applies diff results: creates new products, updates existing ones.
 *
 * @param array $diff    Output of gh_csv_diff().
 * @param array $options { create_new, update_existing }
 * @return array { summary{created,updated,errors}, details[] }
 */
function gh_csv_apply( array $diff, array $options = [] ): array {
    $create_new      = $options['create_new'] ?? true;
    $update_existing = $options['update_existing'] ?? true;

    $results = [];

    if ( $create_new ) {
        foreach ( $diff['new'] as $product ) {
            $results[] = gh_csv_create_product( $product );
        }
    }

    if ( $update_existing ) {
        foreach ( $diff['update'] as $product ) {
            $results[] = gh_csv_update_product( $product );
        }
    }

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    return [
        'summary' => compact( 'created', 'updated', 'errors' ),
        'details' => $results,
    ];
}

/**
 * Creates a new WC product from mapped data.
 */
function gh_csv_create_product( array $data ): array {
    try {
        $type       = $data['type'] ?? 'simple';
        $product_id = $type === 'variable'
            ? gh_create_variable_product( $data )
            : gh_create_simple_product( $data );

        return [
            'action' => 'created',
            'id'     => $product_id,
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'] ?? '',
        ];
    } catch ( \Throwable $e ) {
        return [
            'action' => 'error',
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'] ?? '',
            'reason' => $e->getMessage(),
        ];
    }
}

/**
 * Updates an existing WC product with new data.
 */
function gh_csv_update_product( array $data ): array {
    $product_id = $data['_existing_id'] ?? 0;
    if ( ! $product_id ) {
        return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => 'ID mancante' ];
    }

    try {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => 'Prodotto non trovato' ];
        }

        if ( isset( $data['name'] ) )              $product->set_name( $data['name'] );
        if ( isset( $data['regular_price'] ) )     $product->set_regular_price( $data['regular_price'] );
        if ( isset( $data['sale_price'] ) )        $product->set_sale_price( $data['sale_price'] );
        if ( isset( $data['description'] ) )       $product->set_description( $data['description'] );
        if ( isset( $data['short_description'] ) ) $product->set_short_description( $data['short_description'] );
        if ( isset( $data['weight'] ) )            $product->set_weight( $data['weight'] );
        if ( isset( $data['status'] ) )            $product->set_status( $data['status'] );

        if ( isset( $data['stock_quantity'] ) ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( (int) $data['stock_quantity'] );
            $product->set_stock_status( (int) $data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
        }
        if ( isset( $data['stock_status'] ) && ! isset( $data['stock_quantity'] ) ) {
            $product->set_stock_status( $data['stock_status'] );
        }

        $product->save();
        gh_apply_product_meta( $product_id, $data );

        return [
            'action'  => 'updated',
            'id'      => $product_id,
            'sku'     => $data['sku'] ?? '',
            'name'    => $data['name'] ?? '',
            'changes' => $data['_changes'] ?? [],
        ];
    } catch ( \Throwable $e ) {
        return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => $e->getMessage() ];
    }
}

// ── Feed Runner ───────────────────────────────────────────

/**
 * Runs a complete CSV feed pipeline: read → map → diff → apply.
 *
 * @param string $feed_id Feed config ID.
 * @param array  $options Override options { create_new, update_existing, dry_run }.
 * @return array|WP_Error Result with summary + details.
 */
function gh_csv_run_feed( string $feed_id, array $options = [] ): array|WP_Error {
    $feed = gh_csv_get_feed( $feed_id );
    if ( ! $feed ) {
        return new WP_Error( 'feed_not_found', 'Feed non trovato: ' . $feed_id );
    }

    // 1. Read CSV source
    $rows = gh_csv_read_source( $feed );
    if ( is_wp_error( $rows ) ) {
        gh_csv_update_last_run( $feed_id, [
            'status'  => 'error',
            'error'   => $rows->get_error_message(),
            'ran_at'  => wp_date( 'c' ),
        ] );
        return $rows;
    }

    if ( empty( $rows ) ) {
        $result = [
            'status'  => 'empty',
            'message' => 'CSV vuoto (nessuna riga di dati).',
            'ran_at'  => wp_date( 'c' ),
        ];
        gh_csv_update_last_run( $feed_id, $result );
        return new WP_Error( 'empty_csv', $result['message'] );
    }

    // 2. Resolve mappings (supports 3 modes: preset, auto, rule)
    $mappings = gh_csv_resolve_mappings( $feed, $rows );
    if ( is_wp_error( $mappings ) ) {
        gh_csv_update_last_run( $feed_id, [
            'status' => 'error',
            'error'  => $mappings->get_error_message(),
            'ran_at' => wp_date( 'c' ),
        ] );
        return $mappings;
    }

    $products = gh_mapper_apply_rule_bulk( $rows, $mappings, '' );

    if ( empty( $products ) ) {
        $result = [
            'status'   => 'empty',
            'message'  => 'Mapping non ha prodotto risultati.',
            'rows_read' => count( $rows ),
            'ran_at'   => wp_date( 'c' ),
        ];
        gh_csv_update_last_run( $feed_id, $result );
        return new WP_Error( 'no_products', $result['message'] );
    }

    // 3. Diff against WooCommerce
    $diff = gh_csv_diff( $products );

    // 4. Dry run?
    if ( ! empty( $options['dry_run'] ) ) {
        $result = [
            'status'    => 'preview',
            'rows_read' => count( $rows ),
            'diff'      => $diff,
            'ran_at'    => wp_date( 'c' ),
        ];
        return $result;
    }

    // 5. Apply
    $merge_opts = array_merge(
        [
            'create_new'      => $feed['options']['create_new'] ?? true,
            'update_existing' => $feed['options']['update_existing'] ?? true,
        ],
        $options
    );

    $apply_result = gh_csv_apply( $diff, $merge_opts );

    // 6. Save run result
    $run_result = [
        'status'    => 'completed',
        'rows_read' => count( $rows ),
        'mapped'    => count( $products ),
        'summary'   => $apply_result['summary'],
        'ran_at'    => wp_date( 'c' ),
    ];
    gh_csv_update_last_run( $feed_id, $run_result );

    return array_merge( $apply_result, [ 'rows_read' => count( $rows ) ] );
}

/**
 * Updates the last_run field on a feed config.
 */
function gh_csv_update_last_run( string $feed_id, array $run_result ): void {
    $feeds = gh_csv_get_feeds();
    foreach ( $feeds as $i => $f ) {
        if ( ( $f['id'] ?? '' ) === $feed_id ) {
            $feeds[ $i ]['last_run']    = $run_result['ran_at'] ?? wp_date( 'c' );
            $feeds[ $i ]['last_result'] = $run_result;
            break;
        }
    }
    update_option( GH_CSV_FEEDS_KEY, $feeds, false );
}

// ── Mapping Resolution ────────────────────────────────────

/**
 * Resolves the mappings array for a feed, supporting 3 modes:
 *
 * - "auto"   → auto-detect from CSV column names
 * - "preset" → use a built-in preset config (resolved against actual columns)
 * - "rule"   → use a saved mapper rule (from Mapper UI)
 *
 * @param array $feed Feed config.
 * @param array $rows Parsed CSV rows (to extract column headers).
 * @return array|WP_Error Mappings array ready for gh_mapper_apply_rule_bulk().
 */
function gh_csv_resolve_mappings( array $feed, array $rows ): array|WP_Error {
    $mode      = $feed['mapping_mode'] ?? 'rule';
    $columns   = ! empty( $rows ) ? array_keys( $rows[0] ) : [];

    if ( $mode === 'auto' ) {
        if ( empty( $columns ) ) {
            return new WP_Error( 'no_columns', 'CSV senza colonne: impossibile auto-mappare.' );
        }
        $auto = gh_csv_auto_map( $columns );
        if ( empty( $auto['mappings'] ) ) {
            return new WP_Error( 'no_match', 'Nessuna colonna CSV corrisponde a campi WooCommerce.' );
        }
        return $auto['mappings'];
    }

    if ( $mode === 'preset' ) {
        $preset_id = $feed['preset_id'] ?? '';
        if ( ! $preset_id ) {
            return new WP_Error( 'no_preset', 'Nessun preset selezionato.' );
        }
        $preset = gh_csv_get_preset( $preset_id );
        if ( ! $preset ) {
            return new WP_Error( 'preset_not_found', 'Preset non trovato: ' . $preset_id );
        }
        // Resolve preset aliases against actual CSV columns
        return gh_csv_resolve_preset( $preset, $columns );
    }

    // mode === 'rule' (original behavior)
    $rule_id = $feed['mapping_rule_id'] ?? '';
    if ( ! $rule_id ) {
        return new WP_Error( 'no_mapping', 'Nessuna regola di mapping configurata.' );
    }

    $rule = gh_mapper_get_rule( $rule_id );
    if ( ! $rule ) {
        return new WP_Error( 'rule_not_found', 'Regola di mapping non trovata: ' . $rule_id );
    }

    return $rule['mappings'] ?? [];
}

// ── WP Cron ───────────────────────────────────────────────

/**
 * Syncs a feed's WP Cron schedule based on its config.
 */
function gh_csv_sync_cron( array $feed ): void {
    $feed_id  = $feed['id'] ?? '';
    $schedule = $feed['schedule'] ?? 'manual';
    $status   = $feed['status'] ?? 'active';

    // Remove existing cron first
    gh_csv_unschedule( $feed_id );

    // Re-schedule if not manual and active
    if ( $schedule !== 'manual' && $status === 'active' ) {
        wp_schedule_event( time() + 60, $schedule, GH_CSV_CRON_HOOK, [ $feed_id ] );
    }
}

/**
 * Removes all scheduled cron events for a feed.
 */
function gh_csv_unschedule( string $feed_id ): void {
    $ts = wp_next_scheduled( GH_CSV_CRON_HOOK, [ $feed_id ] );
    if ( $ts ) {
        wp_unschedule_event( $ts, GH_CSV_CRON_HOOK, [ $feed_id ] );
    }
}

/**
 * Cron callback — runs a feed by ID.
 */
add_action( GH_CSV_CRON_HOOK, function ( string $feed_id ) {
    gh_csv_run_feed( $feed_id );
}, 10, 1 );

// ── CSV Upload Cleanup ────────────────────────────────────

/**
 * Schedules a one-time cleanup of orphaned CSV uploads (older than 24h).
 */
function gh_csv_schedule_cleanup(): void {
    if ( ! wp_next_scheduled( 'gh_csv_cleanup_uploads' ) ) {
        wp_schedule_single_event( time() + DAY_IN_SECONDS, 'gh_csv_cleanup_uploads' );
    }
}

/**
 * Deletes CSV files in uploads/golden-hive/csv/ that are older than 24h
 * and not referenced by any active CSV feed config.
 */
add_action( 'gh_csv_cleanup_uploads', function () {
    $upload_dir = wp_upload_dir();
    $csv_dir    = trailingslashit( $upload_dir['basedir'] ) . 'golden-hive/csv';

    if ( ! is_dir( $csv_dir ) ) return;

    // Collect paths referenced by active feeds
    $active_paths = [];
    foreach ( gh_csv_get_feeds() as $feed ) {
        if ( ! empty( $feed['source_path'] ) ) {
            $active_paths[] = basename( $feed['source_path'] );
        }
    }

    $cutoff = time() - DAY_IN_SECONDS;

    foreach ( glob( $csv_dir . '/*' ) as $file ) {
        if ( ! is_file( $file ) ) continue;
        if ( in_array( basename( $file ), $active_paths, true ) ) continue;
        if ( filemtime( $file ) < $cutoff ) {
            @unlink( $file );
        }
    }
} );
