<?php
/**
 * Catalog snapshot storage — daily snapshots of every WC product for diffing.
 *
 * Two tables, per-product rows so a diff between two days is a JOIN on
 * product_id with a hash compare (fast) — payloads are only inflated for
 * rows that actually changed.
 *
 *   {prefix}gh_catalog_snapshots
 *     id, snapshot_date (UNIQUE), created_at, trigger_type, product_count
 *
 *   {prefix}gh_catalog_snapshot_items
 *     snapshot_id, product_id, sku, primary_source, sources_csv,
 *     payload_hash, payload (gzipped JSON)
 *
 * Retention: 30 days, pruned on every successful capture.
 */

defined( 'ABSPATH' ) || exit;

const GH_HISTORY_RETENTION_DAYS = 30;
const GH_HISTORY_DB_VERSION     = '1';
const GH_HISTORY_DB_VERSION_KEY = 'gh_history_db_version';

/** Returns the snapshots table name. */
function gh_history_table_snapshots(): string {
    global $wpdb;
    return $wpdb->prefix . 'gh_catalog_snapshots';
}

/** Returns the snapshot items table name. */
function gh_history_table_items(): string {
    global $wpdb;
    return $wpdb->prefix . 'gh_catalog_snapshot_items';
}

/**
 * Installs (or upgrades) the two history tables. Idempotent.
 */
function gh_history_install_tables(): void {
    if ( get_option( GH_HISTORY_DB_VERSION_KEY ) === GH_HISTORY_DB_VERSION ) return;

    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $t_snap  = gh_history_table_snapshots();
    $t_item  = gh_history_table_items();

    $sql_snap = "CREATE TABLE {$t_snap} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        snapshot_date DATE NOT NULL,
        created_at DATETIME NOT NULL,
        trigger_type VARCHAR(32) NOT NULL DEFAULT 'cron',
        product_count INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_date (snapshot_date),
        KEY idx_created (created_at)
    ) {$charset};";

    $sql_item = "CREATE TABLE {$t_item} (
        snapshot_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        sku VARCHAR(100) NOT NULL DEFAULT '',
        primary_source VARCHAR(64) NOT NULL DEFAULT '',
        sources_csv VARCHAR(255) NOT NULL DEFAULT '',
        payload_hash CHAR(40) NOT NULL,
        payload LONGBLOB NOT NULL,
        PRIMARY KEY (snapshot_id, product_id),
        KEY idx_sku (sku),
        KEY idx_hash (payload_hash),
        KEY idx_primary_source (primary_source)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_snap );
    dbDelta( $sql_item );

    update_option( GH_HISTORY_DB_VERSION_KEY, GH_HISTORY_DB_VERSION, false );
}

/**
 * Builds the per-product payload captured in a snapshot. This is the canonical
 * shape used by the diff engine — extending it (new fields) is safe; renaming
 * existing keys breaks diffs across the schema change boundary.
 */
function gh_history_capture_product( WC_Product $product, ?array $children_ids = null ): array {
    $id = $product->get_id();

    // Variations (compact: only fields that meaningfully change day to day)
    $variations = [];
    if ( function_exists( 'rp_cm_get_product_variants' ) ) {
        foreach ( rp_cm_get_product_variants( $id, $children_ids ) as $v ) {
            $variations[] = [
                'id'             => $v->get_id(),
                'sku'            => (string) $v->get_sku(),
                'status'         => (string) $v->get_status(),
                'regular_price'  => (string) $v->get_regular_price(),
                'sale_price'     => (string) $v->get_sale_price(),
                'manage_stock'   => (bool) $v->get_manage_stock(),
                'stock_quantity' => $v->get_stock_quantity(),
                'stock_status'   => (string) $v->get_stock_status(),
                'attributes'     => $v->get_variation_attributes(),
            ];
        }
    }

    $brand_ids   = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] );
    $brand_names = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
    if ( is_wp_error( $brand_ids ) )   $brand_ids = [];
    if ( is_wp_error( $brand_names ) ) $brand_names = [];

    $cat_ids   = function_exists( 'rp_cm_get_product_category_ids' )   ? rp_cm_get_product_category_ids( $id )   : [];
    $cat_names = function_exists( 'rp_cm_get_product_category_names' ) ? rp_cm_get_product_category_names( $id ) : [];
    $tag_ids   = function_exists( 'rp_cm_get_product_tag_ids' )        ? rp_cm_get_product_tag_ids( $id )        : [];
    $tag_names = function_exists( 'rp_cm_get_product_tag_names' )      ? rp_cm_get_product_tag_names( $id )      : [];
    $attrs     = function_exists( 'rp_cm_get_product_attributes_raw' ) ? rp_cm_get_product_attributes_raw( $product ) : [];

    // Provenance — both the canonical sources list and per-slice owner map.
    $sources       = function_exists( 'gh_conflict_get_sources' )        ? gh_conflict_get_sources( $id )        : [];
    $field_sources = function_exists( 'gh_conflict_get_field_sources' )  ? gh_conflict_get_field_sources( $id )  : [];
    $primary_src   = function_exists( 'gh_conflict_get_primary_source' ) ? gh_conflict_get_primary_source( $id ) : '';

    return [
        // Product data (scalars)
        'id'                => $id,
        'name'              => $product->get_name(),
        'slug'              => $product->get_slug(),
        'sku'               => (string) $product->get_sku(),
        'type'              => $product->get_type(),
        'status'            => $product->get_status(),
        'description'       => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'regular_price'     => (string) $product->get_regular_price(),
        'sale_price'        => (string) $product->get_sale_price(),
        'manage_stock'      => (bool) $product->get_manage_stock(),
        'stock_quantity'    => $product->get_stock_quantity(),
        'stock_status'      => (string) $product->get_stock_status(),
        'weight'            => (string) $product->get_weight(),
        'menu_order'        => (int) $product->get_menu_order(),
        'date_created'      => $product->get_date_created()?->date( 'c' ),
        'date_modified'     => $product->get_date_modified()?->date( 'c' ),

        // Taxonomies
        'category_ids'      => array_map( 'intval', (array) $cat_ids ),
        'category_names'    => array_values( (array) $cat_names ),
        'brand_ids'         => array_map( 'intval', (array) $brand_ids ),
        'brand_names'       => array_values( (array) $brand_names ),
        'tag_ids'           => array_map( 'intval', (array) $tag_ids ),
        'tag_names'         => array_values( (array) $tag_names ),
        'attributes'        => $attrs,

        // SEO meta
        'meta_title'        => (string) get_post_meta( $id, 'rank_math_title', true ),
        'meta_description'  => (string) get_post_meta( $id, 'rank_math_description', true ),
        'focus_keyword'     => (string) get_post_meta( $id, 'rank_math_focus_keyword', true ),

        // Provenance
        'sources'           => $sources,
        'field_sources'     => $field_sources,
        'primary_source'    => $primary_src,
        'import_source'     => (string) get_post_meta( $id, '_gh_import_source', true ),

        // KicksDB tracking meta
        'kicksdb_tracked'   => (string) get_post_meta( $id, '_gh_kicksdb_tracked', true ),
        'kicksdb_last_sync' => (string) get_post_meta( $id, '_gh_kicksdb_last_sync', true ),
        'kicksdb_last_price_sync' => (string) get_post_meta( $id, '_gh_kicksdb_last_price_sync', true ),

        // Variations
        'variations'        => $variations,
    ];
}

/**
 * Captures a snapshot of every WC product into a single dated row.
 *
 * One snapshot per calendar date — re-running the same day overwrites the
 * existing row (so manual + cron on the same day end up with the latest).
 *
 * @param string $trigger 'cron' | 'manual' | 'import' (audit only).
 * @return array { snapshot_id, snapshot_date, product_count, duration_ms }
 */
function gh_history_capture( string $trigger = 'manual' ): array {
    gh_history_install_tables();

    global $wpdb;
    $start = microtime( true );

    $today = wp_date( 'Y-m-d' );
    $now   = current_time( 'mysql' );

    // Replace any existing row for today (manual re-capture allowed).
    $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
        'SELECT id FROM ' . gh_history_table_snapshots() . ' WHERE snapshot_date = %s',
        $today
    ) );
    if ( $existing_id > 0 ) {
        $wpdb->delete( gh_history_table_items(),     [ 'snapshot_id' => $existing_id ], [ '%d' ] );
        $wpdb->delete( gh_history_table_snapshots(), [ 'id' => $existing_id ],          [ '%d' ] );
    }

    $wpdb->insert(
        gh_history_table_snapshots(),
        [
            'snapshot_date' => $today,
            'created_at'    => $now,
            'trigger_type'  => substr( $trigger, 0, 32 ),
            'product_count' => 0,
        ],
        [ '%s', '%s', '%s', '%d' ]
    );
    $snapshot_id = (int) $wpdb->insert_id;
    if ( $snapshot_id <= 0 ) {
        return [ 'error' => 'insert snapshots row fallita: ' . $wpdb->last_error ];
    }

    $count = 0;
    if ( function_exists( 'rp_cm_get_all_products' ) ) {
        $products = rp_cm_get_all_products( [] );

        // Figli risolti + cache primed per l'intero catalogo in ~4 query
        // (il capture giornaliero pagava ~2 query per variante).
        $children_map = function_exists( 'rp_cm_prime_variant_caches' )
            ? rp_cm_prime_variant_caches( array_map( static fn( WC_Product $p ): int => $p->get_id(), $products ) )
            : [];

        foreach ( $products as $product ) {
            $payload      = gh_history_capture_product( $product, $children_map[ $product->get_id() ] ?? [] );
            $payload_json = wp_json_encode( $payload );
            $payload_gz   = function_exists( 'gzencode' )
                ? gzencode( $payload_json, 6 )
                : $payload_json;
            $hash    = sha1( $payload_json );
            $sources = $payload['sources'] ?? [];
            $names   = [];
            foreach ( $sources as $row ) {
                if ( ! empty( $row['source'] ) ) $names[] = (string) $row['source'];
            }
            $names      = array_values( array_unique( $names ) );
            $sources_csv = implode( ',', $names );

            $wpdb->insert(
                gh_history_table_items(),
                [
                    'snapshot_id'    => $snapshot_id,
                    'product_id'     => (int) $payload['id'],
                    'sku'            => substr( (string) $payload['sku'], 0, 100 ),
                    'primary_source' => substr( (string) $payload['primary_source'], 0, 64 ),
                    'sources_csv'    => substr( $sources_csv, 0, 255 ),
                    'payload_hash'   => $hash,
                    'payload'        => $payload_gz,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
            $count++;
        }
    }

    $wpdb->update(
        gh_history_table_snapshots(),
        [ 'product_count' => $count ],
        [ 'id' => $snapshot_id ],
        [ '%d' ],
        [ '%d' ]
    );

    gh_history_prune();

    return [
        'snapshot_id'   => $snapshot_id,
        'snapshot_date' => $today,
        'product_count' => $count,
        'duration_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
    ];
}

/**
 * Drops snapshots older than the retention window. Idempotent.
 */
function gh_history_prune(): int {
    global $wpdb;
    $cutoff = wp_date( 'Y-m-d', strtotime( '-' . GH_HISTORY_RETENTION_DAYS . ' days' ) );

    $old_ids = $wpdb->get_col( $wpdb->prepare(
        'SELECT id FROM ' . gh_history_table_snapshots() . ' WHERE snapshot_date < %s',
        $cutoff
    ) );
    if ( ! $old_ids ) return 0;

    $placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );
    $wpdb->query( $wpdb->prepare(
        'DELETE FROM ' . gh_history_table_items()     . ' WHERE snapshot_id IN (' . $placeholders . ')',
        ...array_map( 'intval', $old_ids )
    ) );
    $wpdb->query( $wpdb->prepare(
        'DELETE FROM ' . gh_history_table_snapshots() . ' WHERE id IN (' . $placeholders . ')',
        ...array_map( 'intval', $old_ids )
    ) );

    return count( $old_ids );
}

/**
 * Lists snapshots, newest first. Returns lightweight rows (no payloads).
 *
 * @return array<int, array{id:int, snapshot_date:string, created_at:string, trigger_type:string, product_count:int}>
 */
function gh_history_list_snapshots(): array {
    gh_history_install_tables();
    global $wpdb;
    $rows = $wpdb->get_results(
        'SELECT id, snapshot_date, created_at, trigger_type, product_count
         FROM ' . gh_history_table_snapshots() . '
         ORDER BY snapshot_date DESC',
        ARRAY_A
    );
    if ( ! is_array( $rows ) ) return [];

    return array_map( static function ( $r ) {
        return [
            'id'            => (int) $r['id'],
            'snapshot_date' => (string) $r['snapshot_date'],
            'created_at'    => (string) $r['created_at'],
            'trigger_type'  => (string) $r['trigger_type'],
            'product_count' => (int) $r['product_count'],
        ];
    }, $rows );
}

/** Loads a single snapshot row by id (no items). */
function gh_history_get_snapshot( int $id ): ?array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        'SELECT id, snapshot_date, created_at, trigger_type, product_count
         FROM ' . gh_history_table_snapshots() . ' WHERE id = %d',
        $id
    ), ARRAY_A );
    if ( ! $row ) return null;
    return [
        'id'            => (int) $row['id'],
        'snapshot_date' => (string) $row['snapshot_date'],
        'created_at'    => (string) $row['created_at'],
        'trigger_type'  => (string) $row['trigger_type'],
        'product_count' => (int) $row['product_count'],
    ];
}

/** Decodes a single snapshot item's payload back to its array form. */
function gh_history_decode_item_payload( $blob ): ?array {
    if ( $blob === null || $blob === '' ) return null;
    $json = $blob;
    if ( function_exists( 'gzdecode' ) ) {
        $decoded = @gzdecode( (string) $blob );
        if ( $decoded !== false ) $json = $decoded;
    }
    $arr = json_decode( (string) $json, true );
    return is_array( $arr ) ? $arr : null;
}

/**
 * Loads all items for a given snapshot, keyed by product_id.
 * Each value is the decoded payload array (no inflation cost amortised).
 *
 * @return array<int, array>
 */
function gh_history_load_items( int $snapshot_id, bool $decode = true ): array {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        'SELECT product_id, sku, primary_source, sources_csv, payload_hash, payload
         FROM ' . gh_history_table_items() . ' WHERE snapshot_id = %d',
        $snapshot_id
    ), ARRAY_A );
    if ( ! is_array( $rows ) ) return [];

    $out = [];
    foreach ( $rows as $r ) {
        $pid = (int) $r['product_id'];
        $out[ $pid ] = [
            'product_id'     => $pid,
            'sku'            => (string) $r['sku'],
            'primary_source' => (string) $r['primary_source'],
            'sources_csv'    => (string) $r['sources_csv'],
            'payload_hash'   => (string) $r['payload_hash'],
            'payload'        => $decode ? gh_history_decode_item_payload( $r['payload'] ) : $r['payload'],
        ];
    }
    return $out;
}
