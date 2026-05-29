<?php
/**
 * GS Labeling — backfill provenance + the super-sale tag onto EXISTING
 * WooCommerce products that came from Golden Sneakers, matched by an
 * operator-supplied SKU list.
 *
 * This is NOT an import: it never creates, updates, or overwrites product
 * data (name, price, stock, variations, media). It only writes provenance
 * meta and assigns the GS product tag, so products imported long ago —
 * before provenance tracking existed — become distinguishable again:
 *   - filterable via the `import_source` / `provenance_source` conditions
 *   - correctly owned by 'goldensneakers' in the conflict engine instead
 *     of being defaulted to 'manual' by the backfill migration
 *
 * Identification is operator-driven (a pasted SKU list) on purpose: the
 * legacy GS importer left no persisted marker, so the only authoritative
 * "is this GS?" signal is the GS feed's own SKU set, which the operator
 * supplies here. Going forward, hive-sync stamps provenance itself (via
 * the host conflict/record bridge) — this tool only backfills the past.
 */

defined( 'ABSPATH' ) || exit;

defined( 'GH_GS_LABEL_SOURCE' )   || define( 'GH_GS_LABEL_SOURCE',   'goldensneakers' );
defined( 'GH_GS_LABEL_TAG_SLUG' ) || define( 'GH_GS_LABEL_TAG_SLUG', 'super-sale' );

/**
 * Parses a raw SKU blob (newline and/or comma separated) into a unique,
 * trimmed, non-empty list. Original case is preserved — WC SKU lookups are
 * collation-dependent, so we don't force-case.
 *
 * @return string[]
 */
function gh_gs_label_parse_skus( string $raw ): array {
    $parts = preg_split( '/[\r\n,]+/', $raw ) ?: [];
    $skus  = [];
    foreach ( $parts as $p ) {
        $p = trim( (string) $p );
        if ( $p !== '' ) $skus[ $p ] = true; // dedupe, preserve case
    }
    return array_keys( $skus );
}

/**
 * Resolves a SKU list to WC product IDs without writing anything.
 *
 * @param string[] $skus
 * @return array{ matched: array<int, array{id:int, sku:string, name:string}>, not_found: string[], total:int }
 */
function gh_gs_label_resolve( array $skus ): array {
    $matched   = [];
    $not_found = [];

    foreach ( $skus as $sku ) {
        $pid = function_exists( 'wc_get_product_id_by_sku' )
            ? (int) wc_get_product_id_by_sku( $sku )
            : 0;
        if ( $pid > 0 ) {
            $matched[] = [
                'id'   => $pid,
                'sku'  => (string) $sku,
                'name' => (string) get_the_title( $pid ),
            ];
        } else {
            $not_found[] = (string) $sku;
        }
    }

    return [
        'matched'   => $matched,
        'not_found' => $not_found,
        'total'     => count( $skus ),
    ];
}

/**
 * Preview: how many supplied SKUs map to existing products. Read-only.
 *
 * @param string[] $skus
 */
function gh_gs_label_preview( array $skus ): array {
    $r = gh_gs_label_resolve( $skus );
    return [
        'total'          => $r['total'],
        'matched'        => count( $r['matched'] ),
        'not_found'      => count( $r['not_found'] ),
        'sample'         => array_slice( $r['matched'], 0, 25 ),
        'not_found_skus' => array_slice( $r['not_found'], 0, 50 ),
    ];
}

/**
 * Apply labels: stamp provenance ('goldensneakers') + the super-sale tag
 * onto every existing product matched by the SKU list. Idempotent.
 *
 * - `_gh_import_source` set to 'goldensneakers' (legacy provenance meta;
 *   read by the `import_source` filter condition + the conflict migration).
 * - `gh_conflict_record_source()` records the source in `_gh_sources`,
 *   sets `_gh_primary_source` if empty, and fills slice ownership with
 *   merge_only=true so any existing (e.g. KicksDB-owned) slice is preserved.
 * - super-sale product tag appended (does not replace existing tags).
 *
 * No product field (name/price/stock/variations/media) is touched.
 *
 * @param string[] $skus
 */
function gh_gs_label_apply( array $skus ): array {
    $r = gh_gs_label_resolve( $skus );

    $labeled = 0;
    $tagged  = 0;
    $already = 0;

    foreach ( $r['matched'] as $row ) {
        $pid = (int) $row['id'];

        if ( (string) get_post_meta( $pid, '_gh_import_source', true ) === GH_GS_LABEL_SOURCE ) {
            $already++;
        }

        update_post_meta( $pid, '_gh_import_source', GH_GS_LABEL_SOURCE );

        if ( function_exists( 'gh_conflict_record_source' ) ) {
            // merge_only=true: record GS as a source and fill only the
            // slices that have no owner yet — never yank a slice another
            // source (KicksDB) already owns.
            gh_conflict_record_source( $pid, GH_GS_LABEL_SOURCE, [
                'catalog' => GH_GS_LABEL_SOURCE,
                'pricing' => GH_GS_LABEL_SOURCE,
                'stock'   => GH_GS_LABEL_SOURCE,
                'media'   => GH_GS_LABEL_SOURCE,
            ], true );
        }

        $res = wp_set_object_terms( $pid, [ GH_GS_LABEL_TAG_SLUG ], 'product_tag', true );
        if ( ! is_wp_error( $res ) ) $tagged++;

        $labeled++;
    }

    if ( function_exists( 'wc_delete_product_transients' ) ) {
        wc_delete_product_transients();
    }

    return [
        'total'     => $r['total'],
        'labeled'   => $labeled,
        'tagged'    => $tagged,
        'already'   => $already,
        'not_found' => count( $r['not_found'] ),
    ];
}
