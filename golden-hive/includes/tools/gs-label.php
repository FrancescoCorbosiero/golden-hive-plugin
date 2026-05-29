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
 * Stamps 'goldensneakers' provenance on a product AND neutralizes the
 * conflict-migration's default 'manual' source.
 *
 * Why neutralize 'manual': the backfill migration tags any product with no
 * legacy _gh_import_source as 'manual'. If that 'manual' entry survives,
 * the "Manual is sacred" rule (priority 10) blocks EVERY future write —
 * so hive-sync would silently refuse to touch the product. For a SKU the
 * operator asserts is GS, that 'manual' is a wrong default, so we drop it.
 *
 * Slices already owned by another real source (e.g. 'kicksdb' on
 * catalog/media) are left untouched — only empty/'manual' slices flip to
 * 'goldensneakers'. Idempotent.
 */
function gh_gs_label_stamp_provenance( int $pid ): void {
    if ( ! function_exists( 'gh_conflict_get_sources' ) ) {
        return; // conflict engine absent — legacy _gh_import_source is enough for filtering
    }

    $now = current_time( 'mysql' );

    // 1) _gh_sources: drop 'manual', ensure 'goldensneakers' present.
    $rows   = gh_conflict_get_sources( $pid );
    $kept   = [];
    $has_gs = false;
    foreach ( $rows as $r ) {
        $src = (string) ( $r['source'] ?? '' );
        if ( $src === '' || $src === 'manual' ) continue; // drop migration default
        if ( $src === GH_GS_LABEL_SOURCE ) {
            $has_gs        = true;
            $r['last_seen'] = $now;
        }
        $kept[] = $r;
    }
    if ( ! $has_gs ) {
        $kept[] = [ 'source' => GH_GS_LABEL_SOURCE, 'first_seen' => $now, 'last_seen' => $now ];
    }
    update_post_meta( $pid, GH_CONFLICT_SOURCES_META, array_values( $kept ) );

    // 2) _gh_primary_source: if empty or 'manual', make it goldensneakers.
    $primary = (string) get_post_meta( $pid, GH_CONFLICT_PRIMARY_META, true );
    if ( $primary === '' || $primary === 'manual' ) {
        update_post_meta( $pid, GH_CONFLICT_PRIMARY_META, GH_GS_LABEL_SOURCE );
    }

    // 3) _gh_field_sources: flip empty/'manual' slices to goldensneakers,
    //    leave slices owned by another real source alone.
    $fields = function_exists( 'gh_conflict_get_field_sources' )
        ? gh_conflict_get_field_sources( $pid )
        : [];
    foreach ( GH_CONFLICT_SLICES as $slice ) {
        $owner = (string) ( $fields[ $slice ] ?? '' );
        if ( $owner === '' || $owner === 'manual' ) {
            $fields[ $slice ] = GH_GS_LABEL_SOURCE;
        }
    }
    update_post_meta( $pid, GH_CONFLICT_FIELD_SOURCES_META, $fields );
}

/**
 * Apply labels: stamp provenance ('goldensneakers') + the super-sale tag
 * onto every existing product matched by the SKU list. Idempotent.
 *
 * - `_gh_import_source` set to 'goldensneakers' (legacy provenance meta;
 *   read by the `import_source` filter condition + the conflict migration).
 * - `gh_gs_label_stamp_provenance()` records GS in `_gh_sources`, sets
 *   `_gh_primary_source`, fills empty/'manual' slices, and drops the
 *   migration's 'manual' default (which would otherwise block hive-sync).
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

        gh_gs_label_stamp_provenance( $pid );

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
