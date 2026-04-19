<?php
/**
 * Force Re-Import — deep overwrite for selected SKUs from a feed source.
 *
 * Intent: when a handful of products in WooCommerce get corrupted (wrong
 * variants, wrong media, stale data) the user picks them in the feed tab,
 * clicks "Ri-importa forzato" and this module deletes the WC product
 * (optionally wiping its media) and re-creates it from the feed record.
 *
 * Shape of the public surface (called from the job handler):
 *
 *   gh_reimport_fetch( 'gs'|'sf', array $feed_config ): array|WP_Error
 *   gh_reimport_filter_records( array $records, array $skus, string $feed_type ): array
 *   gh_reimport_apply_one( string $feed_type, array $record, array $feed_config, bool $overwrite_media ): array
 *
 * Each function is independent so the handler can fetch once, filter once
 * and apply one-by-one across chunked ticks.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the full feed (GS or SF) returning normalized records.
 *
 * For GS: requires { url, token, cookie?, format? } in $feed_config.
 * For SF: requires { url } in $feed_config — the config_id is hardcoded
 *         to 'stockfirmati', same as the regular import path.
 */
function gh_reimport_fetch( string $feed_type, array $feed_config ): array|WP_Error {

    if ( $feed_type === 'gs' ) {
        if ( empty( $feed_config['url'] ) || empty( $feed_config['token'] ) ) {
            return new WP_Error( 'gh_reimport_config', 'Config GS incompleto (serve url + token).' );
        }
        $cfg = [
            'url'    => (string) $feed_config['url'],
            'token'  => (string) $feed_config['token'],
            'cookie' => (string) ( $feed_config['cookie'] ?? '' ),
            'format' => (string) ( $feed_config['format'] ?? 'hierarchical' ),
        ];
        return rp_rc_gs_fetch( $cfg );
    }

    if ( $feed_type === 'sf' ) {
        if ( empty( $feed_config['url'] ) ) {
            return new WP_Error( 'gh_reimport_config', 'Config SF incompleto (serve url).' );
        }
        $config = gh_fc_load_config( 'stockfirmati' );
        if ( ! $config ) {
            return new WP_Error( 'gh_reimport_config', 'Config stockfirmati non trovato.' );
        }
        $response = rp_rc_request( [ 'url' => (string) $feed_config['url'], 'method' => 'GET', 'timeout' => 120 ] );
        if ( ! empty( $response['error'] ) ) {
            return new WP_Error( 'gh_reimport_http', (string) $response['error'] );
        }
        if ( (int) ( $response['status'] ?? 0 ) !== 200 ) {
            return new WP_Error( 'gh_reimport_http', 'HTTP ' . ( $response['status'] ?? '?' ) );
        }
        $rows = rp_rc_parse_csv( (string) $response['body'] );
        if ( is_wp_error( $rows ) ) return $rows;

        return gh_fc_normalize( $rows, $config );
    }

    return new WP_Error( 'gh_reimport_feed_type', "Feed type sconosciuto: {$feed_type}" );
}

/**
 * Filters normalized records by SKU. Returns a list in the same order as
 * the input SKU list, with 'sku' case-insensitive. SKUs not found in the
 * feed are returned separately.
 *
 * @return array { found: array[], missing_skus: string[] }
 */
function gh_reimport_filter_records( array $records, array $skus, string $feed_type ): array {

    $index = [];
    foreach ( $records as $r ) {
        $key = strtoupper( trim( (string) ( $r['sku'] ?? '' ) ) );
        if ( $key !== '' ) $index[ $key ] = $r;
    }

    $found   = [];
    $missing = [];
    foreach ( $skus as $sku ) {
        $key = strtoupper( trim( (string) $sku ) );
        if ( $key === '' ) continue;
        if ( isset( $index[ $key ] ) ) $found[] = $index[ $key ];
        else                           $missing[] = $sku;
    }

    return [ 'found' => $found, 'missing_skus' => $missing ];
}

/**
 * Applies one record as a force re-import. The existing WC product (if
 * any) is hard-deleted first — optionally after wiping its attachments
 * via the whitelist-aware cleaner — and then re-created from scratch via
 * the feed's normal create path. This is more aggressive than the regular
 * update path (which touches only prices/stock) and matches the "wrong
 * variants / wrong media / full reset" intent.
 *
 * @return array { sku, status: recreated|error|skipped, id?, error? }
 */
function gh_reimport_apply_one( string $feed_type, array $record, array $feed_config, bool $overwrite_media ): array {

    $sku = (string) ( $record['sku'] ?? '' );
    if ( $sku === '' ) {
        return [ 'sku' => '', 'status' => 'error', 'error' => 'Record senza SKU.' ];
    }

    // 1. Find the existing product by SKU and wipe it.
    $existing_id = (int) wc_get_product_id_by_sku( $sku );
    $media_report = [ 'deleted' => 0, 'kept' => 0 ];

    if ( $existing_id > 0 ) {
        $product = wc_get_product( $existing_id );
        if ( $product ) {
            if ( $overwrite_media ) {
                $media_report = gh_reimport_wipe_product_media( $product );
            }
            // Hard delete (trash=false). For variable products WC cascades
            // to children; we also nuke via rp_delete_product which uses
            // the Woo CRUD delete(true) path.
            $res = rp_delete_product( $existing_id, true );
            if ( is_wp_error( $res ) ) {
                return [ 'sku' => $sku, 'status' => 'error', 'error' => 'delete: ' . $res->get_error_message() ];
            }
        }
    }

    // 2. Transform the feed record to the WC shape and create fresh.
    try {
        if ( $feed_type === 'gs' ) {
            $price_mode = (string) ( $feed_config['price_mode'] ?? 'direct' );
            $sale_mult  = (float) ( $feed_config['sale_mult']  ?? 1.3 );
            $woo        = rp_rc_gs_transform_to_woo( $record, $price_mode, $sale_mult );
            $result     = rp_rc_gs_create_product( $woo, true ); // sideload = true
        } elseif ( $feed_type === 'sf' ) {
            $woo    = gh_sf_transform_to_woo( $record );
            $result = gh_sf_create_product( $woo, true ); // sideload = true
        } else {
            return [ 'sku' => $sku, 'status' => 'error', 'error' => "Feed type sconosciuto: {$feed_type}" ];
        }
    } catch ( \Throwable $e ) {
        return [ 'sku' => $sku, 'status' => 'error', 'error' => 'create: ' . $e->getMessage() ];
    }

    $status = ( ( $result['action'] ?? '' ) === 'created' ) ? 'recreated' : 'error';
    return array_filter( [
        'sku'           => $sku,
        'status'        => $status,
        'id'            => $result['id']    ?? null,
        'error'         => $status === 'error' ? ( $result['error'] ?? 'create fallito' ) : null,
        'media_deleted' => $media_report['deleted'],
        'media_kept'    => $media_report['kept'],
    ], fn( $v ) => $v !== null );
}

/**
 * Deletes featured + gallery + variation thumbnails for a WC product,
 * routing each attachment through rp_mm_delete_attachment() so the
 * whitelist + "still in use elsewhere" safety nets apply. Also purges
 * the matching entries from the preimport URL→attachment map so the
 * next sideload redownloads fresh bytes.
 *
 * @return array { deleted: int, kept: int } kept = skipped (whitelist/used).
 */
function gh_reimport_wipe_product_media( WC_Product $product ): array {

    $ids = gh_collect_product_attachment_ids( $product );
    $deleted = 0;
    $kept    = 0;
    $actually_deleted = [];

    foreach ( $ids as $att_id ) {
        $res = rp_mm_delete_attachment( (int) $att_id );
        if ( is_wp_error( $res ) ) {
            $kept++;
        } else {
            $deleted++;
            $actually_deleted[] = (int) $att_id;
        }
    }

    if ( $actually_deleted ) {
        gh_preimport_purge_by_attachment_ids( $actually_deleted );
    }

    return [ 'deleted' => $deleted, 'kept' => $kept ];
}

/**
 * Top-level orchestrator usable from a one-shot context (e.g. ajax
 * fallback or tests). The job handler does its own chunking; this is
 * a convenience for small runs.
 *
 * @return array { total, found, recreated, errors, missing_skus, details }
 */
function gh_reimport_run( string $feed_type, array $skus, array $feed_config, bool $overwrite_media ): array {

    $records = gh_reimport_fetch( $feed_type, $feed_config );
    if ( is_wp_error( $records ) ) {
        return [
            'total'        => count( $skus ),
            'found'        => 0,
            'recreated'    => 0,
            'errors'       => count( $skus ),
            'missing_skus' => $skus,
            'fetch_error'  => $records->get_error_message(),
            'details'      => [],
        ];
    }

    $filtered = gh_reimport_filter_records( $records, $skus, $feed_type );
    $details  = [];
    $ok       = 0;
    $err      = 0;

    foreach ( $filtered['found'] as $record ) {
        $res = gh_reimport_apply_one( $feed_type, $record, $feed_config, $overwrite_media );
        $details[] = $res;
        if ( $res['status'] === 'recreated' ) $ok++;
        else                                  $err++;
    }

    return [
        'total'        => count( $skus ),
        'found'        => count( $filtered['found'] ),
        'recreated'    => $ok,
        'errors'       => $err + count( $filtered['missing_skus'] ),
        'missing_skus' => $filtered['missing_skus'],
        'details'      => $details,
    ];
}
