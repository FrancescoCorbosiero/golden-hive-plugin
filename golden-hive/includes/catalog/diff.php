<?php
/**
 * Catalog snapshot diff — compare two snapshots and produce a structured
 * change list grouped by added / removed / changed.
 *
 * Diff strategy: hash-compare per product (cheap), then for each changed
 * product walk the canonical field list and emit per-field diffs. Field
 * groups are tagged so the UI can filter by "product data" / "taxonomy" /
 * "meta" / "variations".
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the canonical diffable field map. Key = field name in the payload,
 * value = { label, group }. Groups: product | taxonomy | meta | seo |
 * provenance | variations.
 */
function gh_history_diff_fields(): array {
    return [
        // product data
        'name'                    => [ 'label' => 'Nome',                'group' => 'product' ],
        'slug'                    => [ 'label' => 'Slug',                'group' => 'product' ],
        'sku'                     => [ 'label' => 'SKU',                 'group' => 'product' ],
        'type'                    => [ 'label' => 'Tipo',                'group' => 'product' ],
        'status'                  => [ 'label' => 'Stato',               'group' => 'product' ],
        'description'             => [ 'label' => 'Descrizione',         'group' => 'product' ],
        'short_description'       => [ 'label' => 'Descrizione breve',   'group' => 'product' ],
        'regular_price'           => [ 'label' => 'Prezzo regolare',     'group' => 'product' ],
        'sale_price'              => [ 'label' => 'Prezzo scontato',     'group' => 'product' ],
        'manage_stock'            => [ 'label' => 'Gestisci stock',      'group' => 'product' ],
        'stock_quantity'          => [ 'label' => 'Quantita stock',      'group' => 'product' ],
        'stock_status'            => [ 'label' => 'Stato stock',         'group' => 'product' ],
        'weight'                  => [ 'label' => 'Peso',                'group' => 'product' ],
        'menu_order'              => [ 'label' => 'Ordine menu',         'group' => 'product' ],

        // taxonomies
        'category_names'          => [ 'label' => 'Categorie',           'group' => 'taxonomy' ],
        'brand_names'             => [ 'label' => 'Brand',               'group' => 'taxonomy' ],
        'tag_names'               => [ 'label' => 'Tag',                 'group' => 'taxonomy' ],
        'attributes'              => [ 'label' => 'Attributi',           'group' => 'taxonomy' ],

        // SEO
        'meta_title'              => [ 'label' => 'SEO: Title',          'group' => 'seo' ],
        'meta_description'        => [ 'label' => 'SEO: Description',    'group' => 'seo' ],
        'focus_keyword'           => [ 'label' => 'SEO: Focus keyword',  'group' => 'seo' ],

        // provenance / source meta
        'primary_source'          => [ 'label' => 'Source primaria',     'group' => 'provenance' ],
        'field_sources'           => [ 'label' => 'Source per slice',    'group' => 'provenance' ],
        'import_source'           => [ 'label' => 'Import source (legacy)', 'group' => 'provenance' ],
        'kicksdb_tracked'         => [ 'label' => 'KicksDB tracked',     'group' => 'provenance' ],
        'kicksdb_last_sync'       => [ 'label' => 'KicksDB last sync',   'group' => 'provenance' ],
        'kicksdb_last_price_sync' => [ 'label' => 'KicksDB last price sync', 'group' => 'provenance' ],

        // variations: handled specially below (count + per-variant diff).
    ];
}

/**
 * Polyfill for array_is_list() (PHP 8.1+) — plugin targets 8.0.
 */
function gh_history_is_list( array $a ): bool {
    if ( function_exists( 'array_is_list' ) ) return array_is_list( $a );
    if ( $a === [] ) return true;
    return array_keys( $a ) === range( 0, count( $a ) - 1 );
}

/**
 * Compares two values for diffing. Arrays are compared after sorting (order
 * doesn't matter for taxonomies / source lists).
 */
function gh_history_value_equal( $a, $b ): bool {
    if ( is_array( $a ) && is_array( $b ) ) {
        $a2 = $a; $b2 = $b;
        if ( gh_history_is_list( $a2 ) && gh_history_is_list( $b2 ) ) {
            sort( $a2 ); sort( $b2 );
        } else {
            ksort( $a2 ); ksort( $b2 );
        }
        return wp_json_encode( $a2 ) === wp_json_encode( $b2 );
    }
    if ( is_bool( $a ) || is_bool( $b ) ) {
        return (bool) $a === (bool) $b;
    }
    if ( is_numeric( $a ) && is_numeric( $b ) ) {
        return (string) $a === (string) $b;
    }
    return (string) ( $a ?? '' ) === (string) ( $b ?? '' );
}

/**
 * Renders a value into a short, human-readable string for the UI table cell.
 */
function gh_history_render_value( $v ): string {
    if ( is_bool( $v ) )   return $v ? 'true' : 'false';
    if ( is_null( $v ) )   return '∅';
    if ( is_array( $v ) )  return wp_json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $s = (string) $v;
    if ( $s === '' ) return '∅';
    return $s;
}

/**
 * Computes the diff between two product payloads. Returns the list of changed
 * fields (each entry: field, label, group, before, after).
 *
 * @return array<int, array{field:string,label:string,group:string,before:mixed,after:mixed}>
 */
function gh_history_diff_product( array $before, array $after ): array {
    $changes = [];
    foreach ( gh_history_diff_fields() as $field => $meta ) {
        $b = $before[ $field ] ?? null;
        $a = $after[ $field ]  ?? null;
        if ( ! gh_history_value_equal( $b, $a ) ) {
            $changes[] = [
                'field'  => $field,
                'label'  => $meta['label'],
                'group'  => $meta['group'],
                'before' => $b,
                'after'  => $a,
            ];
        }
    }

    // Variations summary diff: count changed + per-variant SKU/price/stock.
    $vb = $before['variations'] ?? [];
    $va = $after['variations']  ?? [];
    if ( count( $vb ) !== count( $va ) ) {
        $changes[] = [
            'field'  => 'variations_count',
            'label'  => 'Numero varianti',
            'group'  => 'variations',
            'before' => count( $vb ),
            'after'  => count( $va ),
        ];
    }
    // Per-variant diff keyed by SKU (variants without SKU fall back to id).
    $vb_map = [];
    foreach ( $vb as $v ) {
        $key = $v['sku'] !== '' ? 'sku:' . $v['sku'] : 'id:' . $v['id'];
        $vb_map[ $key ] = $v;
    }
    foreach ( $va as $v ) {
        $key = $v['sku'] !== '' ? 'sku:' . $v['sku'] : 'id:' . $v['id'];
        $bv  = $vb_map[ $key ] ?? null;
        if ( ! $bv ) {
            $changes[] = [
                'field'  => 'variation_added',
                'label'  => 'Variante aggiunta (' . $key . ')',
                'group'  => 'variations',
                'before' => null,
                'after'  => $v,
            ];
            continue;
        }
        unset( $vb_map[ $key ] );
        foreach ( [ 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'status' ] as $vf ) {
            if ( ! gh_history_value_equal( $bv[ $vf ] ?? null, $v[ $vf ] ?? null ) ) {
                $changes[] = [
                    'field'  => 'variation.' . $vf,
                    'label'  => 'Variante ' . $key . ' — ' . $vf,
                    'group'  => 'variations',
                    'before' => $bv[ $vf ] ?? null,
                    'after'  => $v[ $vf ]  ?? null,
                ];
            }
        }
    }
    foreach ( $vb_map as $key => $bv ) {
        $changes[] = [
            'field'  => 'variation_removed',
            'label'  => 'Variante rimossa (' . $key . ')',
            'group'  => 'variations',
            'before' => $bv,
            'after'  => null,
        ];
    }

    return $changes;
}

/**
 * Diff two snapshots end-to-end.
 *
 * @return array{
 *   summary: array{added:int, removed:int, changed:int, unchanged:int, total_changes:int},
 *   added:   array<int, array>,
 *   removed: array<int, array>,
 *   changed: array<int, array>,
 *   meta:    array{snapshot_a: array, snapshot_b: array}
 * }
 */
function gh_history_diff_snapshots( int $snapshot_id_a, int $snapshot_id_b ): array {
    $meta_a = gh_history_get_snapshot( $snapshot_id_a );
    $meta_b = gh_history_get_snapshot( $snapshot_id_b );
    if ( ! $meta_a || ! $meta_b ) {
        return [
            'summary' => [ 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'total_changes' => 0 ],
            'added' => [], 'removed' => [], 'changed' => [],
            'meta'  => [ 'snapshot_a' => $meta_a, 'snapshot_b' => $meta_b ],
        ];
    }

    // First pass: compare hashes WITHOUT decoding payloads — fast path for
    // unchanged products.
    global $wpdb;
    $rows_a = $wpdb->get_results( $wpdb->prepare(
        'SELECT product_id, sku, primary_source, sources_csv, payload_hash
         FROM ' . gh_history_table_items() . ' WHERE snapshot_id = %d',
        $snapshot_id_a
    ), ARRAY_A );
    $rows_b = $wpdb->get_results( $wpdb->prepare(
        'SELECT product_id, sku, primary_source, sources_csv, payload_hash
         FROM ' . gh_history_table_items() . ' WHERE snapshot_id = %d',
        $snapshot_id_b
    ), ARRAY_A );

    $by_a = [];
    foreach ( (array) $rows_a as $r ) $by_a[ (int) $r['product_id'] ] = $r;
    $by_b = [];
    foreach ( (array) $rows_b as $r ) $by_b[ (int) $r['product_id'] ] = $r;

    $added_ids   = array_diff( array_keys( $by_b ), array_keys( $by_a ) );
    $removed_ids = array_diff( array_keys( $by_a ), array_keys( $by_b ) );
    $common_ids  = array_intersect( array_keys( $by_a ), array_keys( $by_b ) );

    $changed_ids   = [];
    $unchanged_cnt = 0;
    foreach ( $common_ids as $pid ) {
        if ( ( $by_a[ $pid ]['payload_hash'] ?? '' ) === ( $by_b[ $pid ]['payload_hash'] ?? '' ) ) {
            $unchanged_cnt++;
        } else {
            $changed_ids[] = $pid;
        }
    }

    // Inflate payloads only for the rows we actually need (added/removed/changed).
    $needed_a = array_unique( array_merge( $removed_ids, $changed_ids ) );
    $needed_b = array_unique( array_merge( $added_ids,   $changed_ids ) );

    $payloads_a = gh_history_load_items_subset( $snapshot_id_a, $needed_a );
    $payloads_b = gh_history_load_items_subset( $snapshot_id_b, $needed_b );

    $added = [];
    foreach ( $added_ids as $pid ) {
        $row = $by_b[ $pid ];
        $p   = $payloads_b[ $pid ]['payload'] ?? null;
        $added[] = [
            'product_id'     => $pid,
            'sku'            => (string) $row['sku'],
            'name'           => (string) ( $p['name'] ?? '' ),
            'primary_source' => (string) $row['primary_source'],
            'sources'        => array_filter( explode( ',', (string) $row['sources_csv'] ) ),
        ];
    }

    $removed = [];
    foreach ( $removed_ids as $pid ) {
        $row = $by_a[ $pid ];
        $p   = $payloads_a[ $pid ]['payload'] ?? null;
        $removed[] = [
            'product_id'     => $pid,
            'sku'            => (string) $row['sku'],
            'name'           => (string) ( $p['name'] ?? '' ),
            'primary_source' => (string) $row['primary_source'],
            'sources'        => array_filter( explode( ',', (string) $row['sources_csv'] ) ),
        ];
    }

    $changed = [];
    $total_changes = 0;
    foreach ( $changed_ids as $pid ) {
        $pa = $payloads_a[ $pid ]['payload'] ?? null;
        $pb = $payloads_b[ $pid ]['payload'] ?? null;
        if ( ! $pa || ! $pb ) continue;
        $field_changes = gh_history_diff_product( $pa, $pb );
        if ( ! $field_changes ) continue; // hash mismatch but no canonical-field change (e.g. only ignored fields)

        $row = $by_b[ $pid ];
        $changed[] = [
            'product_id'     => $pid,
            'sku'            => (string) $row['sku'],
            'name'           => (string) ( $pb['name'] ?? $pa['name'] ?? '' ),
            'primary_source' => (string) $row['primary_source'],
            'sources'        => array_filter( explode( ',', (string) $row['sources_csv'] ) ),
            'changes'        => $field_changes,
        ];
        $total_changes += count( $field_changes );
    }

    return [
        'summary' => [
            'added'         => count( $added ),
            'removed'       => count( $removed ),
            'changed'       => count( $changed ),
            'unchanged'     => $unchanged_cnt,
            'total_changes' => $total_changes,
        ],
        'added'   => $added,
        'removed' => $removed,
        'changed' => $changed,
        'meta'    => [ 'snapshot_a' => $meta_a, 'snapshot_b' => $meta_b ],
    ];
}

/**
 * Loads a subset of items for a snapshot, decoded.
 *
 * @param int        $snapshot_id
 * @param array<int> $product_ids
 * @return array<int, array{product_id:int, payload:?array}>
 */
function gh_history_load_items_subset( int $snapshot_id, array $product_ids ): array {
    if ( ! $product_ids ) return [];
    global $wpdb;
    $product_ids  = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
    $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
    $sql          = 'SELECT product_id, payload FROM ' . gh_history_table_items()
                  . ' WHERE snapshot_id = %d AND product_id IN (' . $placeholders . ')';
    $params       = array_merge( [ $snapshot_id ], $product_ids );
    $rows         = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
    if ( ! is_array( $rows ) ) return [];
    $out = [];
    foreach ( $rows as $r ) {
        $pid = (int) $r['product_id'];
        $out[ $pid ] = [
            'product_id' => $pid,
            'payload'    => gh_history_decode_item_payload( $r['payload'] ),
        ];
    }
    return $out;
}
