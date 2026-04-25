<?php
/**
 * KicksDB — feed orchestrator.
 *
 * Pipeline: fetch (parallel) → normalize → diff → apply (con conflict engine).
 *
 * A differenza dei feed push (GS/SF) l'input NON e un URL bulk ma una lista di
 * SKU. Tre punti d'ingresso:
 * - gh_kicksdb_fetch_skus()   — full enrichment in parallelo per N SKU
 * - gh_kicksdb_diff()          — confronto con WC (stesso shape dei feed esistenti)
 * - gh_kicksdb_apply()         — crea/aggiorna con provenance + conflict rules
 *
 * Il pricing-only refresh ha il suo percorso dedicato (non passa da qui) —
 * vedi gh_kicksdb_refresh_pricing().
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_kicksdb_fetch_skus' ) ) return;

/**
 * Fetch full product data per N SKU in parallelo.
 * Usa la cache per skip dei fetch gia recenti (salvo force).
 *
 * @return array {
 *   @type array $woo_products Array di record WC (output di normalize).
 *   @type array $errors       sku => reason per i SKU falliti/not-found.
 *   @type array $stats        { total, fetched, cached, failed, duration_ms }.
 * }
 */
function gh_kicksdb_fetch_skus( array $skus, array $opts = [] ): array {

    $force        = (bool) ( $opts['force'] ?? false );
    $apply_markup = (bool) ( $opts['apply_markup'] ?? true );
    $price_mode   = (string) ( $opts['price_mode'] ?? 'direct' );
    $concurrency  = (int)    ( $opts['concurrency'] ?? gh_kicksdb_get_settings()['concurrency'] );

    $skus = array_values( array_filter( array_unique( array_map( 'strval', $skus ) ) ) );
    if ( empty( $skus ) ) {
        return [ 'woo_products' => [], 'errors' => [], 'stats' => [
            'total' => 0, 'fetched' => 0, 'cached' => 0, 'failed' => 0, 'duration_ms' => 0,
        ] ];
    }

    $t0 = microtime( true );

    // 1. Split cached vs to-fetch
    $responses = [];
    $to_fetch  = [];
    if ( ! $force ) {
        foreach ( $skus as $sku ) {
            $hit = gh_kicksdb_cache_get( $sku );
            if ( $hit !== null ) {
                $responses[ $sku ] = $hit;
            } else {
                $to_fetch[] = $sku;
            }
        }
    } else {
        $to_fetch = $skus;
    }

    $cached_count = count( $responses );

    // 2. Parallel fetch dei missing (curl_multi sliding-window)
    if ( ! empty( $to_fetch ) ) {
        $fresh = gh_kicksdb_get_products_multi( $to_fetch, '', $concurrency );
        foreach ( $fresh as $sku => $resp ) {
            $responses[ $sku ] = $resp;
            // Cachea solo success
            if ( empty( $resp['error'] ) && ( $resp['status'] ?? 0 ) === 200 && ! empty( $resp['body']['data'] ) ) {
                gh_kicksdb_cache_set( $sku, $resp );
            }
        }
    }

    // 3. Normalize
    $woo_products = [];
    $errors       = [];

    foreach ( $skus as $sku ) {
        $resp = $responses[ $sku ] ?? null;

        if ( ! is_array( $resp ) || ! empty( $resp['error'] ) ) {
            $errors[ $sku ] = $resp['error'] ?? 'no response';
            continue;
        }
        if ( ( $resp['status'] ?? 0 ) === 404 ) {
            $errors[ $sku ] = 'not found on KicksDB';
            continue;
        }
        if ( empty( $resp['body']['data'] ) ) {
            $errors[ $sku ] = 'response vuota';
            continue;
        }

        $normalized = gh_kicksdb_normalize( $resp, [
            'apply_markup' => $apply_markup,
            'price_mode'   => $price_mode,
        ] );

        if ( is_wp_error( $normalized ) ) {
            $errors[ $sku ] = $normalized->get_error_message();
            continue;
        }

        $woo_products[] = $normalized;
    }

    return [
        'woo_products' => $woo_products,
        'errors'       => $errors,
        'stats' => [
            'total'       => count( $skus ),
            'fetched'     => count( $to_fetch ) - count( $errors ),
            'cached'      => $cached_count,
            'failed'      => count( $errors ),
            'duration_ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
        ],
    ];
}

/**
 * Diff contro WC — stesso shape di rp_rc_gs_diff().
 * Classifica ciascun record come new/update/unchanged.
 *
 * @return array { new[], update[], unchanged[], summary }
 */
function gh_kicksdb_diff( array $woo_products ): array {

    $new = $update = $unchanged = [];

    foreach ( $woo_products as $p ) {
        $sku = (string) ( $p['sku'] ?? '' );
        if ( $sku === '' ) { $new[] = $p; continue; }

        $existing_id = wc_get_product_id_by_sku( $sku );
        if ( ! $existing_id ) { $new[] = $p; continue; }

        $existing = wc_get_product( $existing_id );
        if ( ! $existing ) { $new[] = $p; continue; }

        $p['_existing_id'] = $existing_id;

        // Heuristic: cambia se nome o pricing parent differiscono
        $changed = false;
        if ( isset( $p['name'] ) && $existing->get_name() !== $p['name'] ) $changed = true;
        if ( $existing->is_type( 'simple' ) && isset( $p['regular_price'] )
             && (string) $existing->get_regular_price() !== (string) $p['regular_price'] ) {
            $changed = true;
        }

        if ( $changed ) $update[] = $p;
        else            $unchanged[] = $p;
    }

    return [
        'new'       => $new,
        'update'    => $update,
        'unchanged' => $unchanged,
        'summary'   => [
            'total'     => count( $woo_products ),
            'new'       => count( $new ),
            'update'    => count( $update ),
            'unchanged' => count( $unchanged ),
        ],
    ];
}

/**
 * Apply: crea nuovi e aggiorna esistenti. Scrive provenance + rispetta conflict
 * rules se il prodotto esisteva gia con un'altra source.
 *
 * SICUREZZA: se un prodotto esistente ha una source diversa (es. 'manual' o
 * 'goldensneakers') e le conflict rules dicono di NON sovrascrivere quella
 * slice, lo skip e silenzioso (loggato in details[].reason='blocked_by_rule').
 *
 * @param array $diff Output di gh_kicksdb_diff().
 * @param array $options {
 *   @type bool $create_new        Default true.
 *   @type bool $update_existing   Default true.
 *   @type bool $sideload_images   Default true.
 *   @type bool $overwrite_manual  Default false. Se true, sovrascrive anche
 *                                  prodotti 'manual' ignorando le rules.
 * }
 */
function gh_kicksdb_apply( array $diff, array $options = [] ): array {

    $create_new      = (bool) ( $options['create_new'] ?? true );
    $update_existing = (bool) ( $options['update_existing'] ?? true );
    $sideload        = (bool) ( $options['sideload_images'] ?? true );

    $results = [];

    if ( $create_new && ! empty( $diff['new'] ) ) {
        foreach ( $diff['new'] as $p ) {
            $results[] = gh_kicksdb_create_product( $p, $sideload );
        }
    }

    if ( $update_existing && ! empty( $diff['update'] ) ) {
        foreach ( $diff['update'] as $p ) {
            $results[] = gh_kicksdb_update_product( $p, $options );
        }
    }

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $skipped = count( array_filter( $results, fn( $r ) => $r['action'] === 'skipped' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    return [
        'summary' => compact( 'created', 'updated', 'skipped', 'errors' ),
        'details' => $results,
    ];
}

/**
 * Crea un nuovo prodotto KicksDB.
 */
function gh_kicksdb_create_product( array $data, bool $sideload = true ): array {
    try {
        $type = $data['type'] ?? 'simple';
        $pid  = $type === 'variable'
            ? gh_create_variable_product( $data )
            : gh_create_simple_product( $data );

        gh_kicksdb_post_process( $pid, $data, $sideload );

        // Provenance (Phase 2)
        if ( function_exists( 'gh_conflict_record_source' ) ) {
            gh_conflict_record_source( $pid, 'kicksdb', [
                'catalog' => 'kicksdb',
                'pricing' => 'kicksdb',
                'stock'   => 'kicksdb',
                'media'   => 'kicksdb',
            ] );
        }
        // Flag tracked per refresh pricing futuri
        update_post_meta( $pid, '_gh_kicksdb_tracked', '1' );

        return [
            'action' => 'created',
            'id'     => $pid,
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'] ?? '',
        ];
    } catch ( \Throwable $e ) {
        if ( function_exists( 'gh_is_duplicate_sku_error' ) && gh_is_duplicate_sku_error( $e ) && ! empty( $data['sku'] ) ) {
            $eid = wc_get_product_id_by_sku( (string) $data['sku'] );
            if ( $eid ) {
                $data['_existing_id'] = $eid;
                return gh_kicksdb_update_product( $data );
            }
        }
        return [
            'action' => 'error',
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'] ?? '?',
            'reason' => $e->getMessage(),
        ];
    }
}

/**
 * Aggiorna un prodotto esistente, rispettando le conflict rules.
 * Se il prodotto e 'manual' o di un'altra source che vince su KicksDB per una
 * slice, quella slice viene SKIPPATA. Log dettagliato in result.changes_applied
 * / result.changes_blocked.
 */
function gh_kicksdb_update_product( array $data, array $options = [] ): array {

    $pid = (int) ( $data['_existing_id'] ?? 0 );
    if ( ! $pid ) {
        return [
            'action' => 'error',
            'sku'    => $data['sku'] ?? '',
            'reason' => 'ID mancante',
        ];
    }

    try {
        $product = wc_get_product( $pid );
        if ( ! $product ) {
            return [
                'action' => 'error',
                'sku'    => $data['sku'] ?? '',
                'reason' => 'Prodotto non trovato',
            ];
        }

        // Conflict check (Phase 2). Di default la rule #1 blocca 'manual'.
        $resolved = function_exists( 'gh_conflict_resolve' )
            ? gh_conflict_resolve( $pid, $data, 'kicksdb', $options )
            : [ 'allowed_slices' => [ 'catalog' => true, 'pricing' => true, 'stock' => true, 'media' => true ], 'blocked' => [] ];

        $allowed = $resolved['allowed_slices'];
        $blocked = $resolved['blocked'];

        // Se TUTTE le slice sono bloccate → skip intero update
        if ( empty( array_filter( $allowed ) ) ) {
            return [
                'action' => 'skipped',
                'id'     => $pid,
                'sku'    => $data['sku'] ?? '',
                'reason' => 'blocked_by_rule',
                'blocked' => $blocked,
            ];
        }

        $applied = [];

        // Catalog slice: name, description, meta KicksDB
        if ( ! empty( $allowed['catalog'] ) ) {
            if ( isset( $data['name'] ) )        $product->set_name( (string) $data['name'] );
            if ( isset( $data['description'] ) ) $product->set_description( (string) $data['description'] );
            $applied[] = 'catalog';
        }

        // Pricing slice: prezzi parent (simple) + varianti
        if ( ! empty( $allowed['pricing'] ) && $product->is_type( 'simple' ) ) {
            if ( isset( $data['regular_price'] ) ) $product->set_regular_price( (string) $data['regular_price'] );
            if ( isset( $data['sale_price'] ) )    $product->set_sale_price( (string) $data['sale_price'] );
            $applied[] = 'pricing';
        }

        // Stock slice (simple)
        if ( ! empty( $allowed['stock'] ) && $product->is_type( 'simple' ) && isset( $data['stock_quantity'] ) ) {
            $qty = (int) $data['stock_quantity'];
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $qty );
            $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            $applied[] = 'stock';
        }

        $product->save();

        // Varianti (variable) — rispetta pricing/stock allow
        if ( $product->is_type( 'variable' ) && ! empty( $data['variations'] ) ) {
            gh_kicksdb_apply_variations( $pid, $data['variations'], $allowed );
            WC_Product_Variable::sync( $pid );
        }

        // Media slice: sideload solo se consentito e se non ha gia una featured
        if ( ! empty( $allowed['media'] ) && ! has_post_thumbnail( $pid ) && ! empty( $data['_kdb_image'] ) && function_exists( 'gh_parallel_sideload_to_product' ) ) {
            gh_parallel_sideload_to_product( $pid, [ (string) $data['_kdb_image'] ], (string) ( $data['sku'] ?? '' ), [
                'first_is_featured' => true,
                'rest_is_gallery'   => true,
            ] );
            $applied[] = 'media';
        }

        update_post_meta( $pid, '_gh_kicksdb_last_sync', current_time( 'mysql' ) );

        // Registra la source come "touched" (senza sovrascrivere primary)
        if ( function_exists( 'gh_conflict_record_source' ) ) {
            gh_conflict_record_source( $pid, 'kicksdb', array_fill_keys( $applied, 'kicksdb' ), true );
        }

        return [
            'action'          => 'updated',
            'id'              => $pid,
            'sku'             => $data['sku'] ?? '',
            'name'            => $data['name'] ?? '',
            'changes_applied' => $applied,
            'changes_blocked' => $blocked,
        ];
    } catch ( \Throwable $e ) {
        return [
            'action' => 'error',
            'id'     => $pid,
            'sku'    => $data['sku'] ?? '',
            'reason' => $e->getMessage(),
        ];
    }
}

/**
 * Aggiorna / crea varianti rispettando le slice permesse.
 */
function gh_kicksdb_apply_variations( int $parent_id, array $variations, array $allowed ): void {

    foreach ( $variations as $var_data ) {
        $var_sku = (string) ( $var_data['sku'] ?? '' );
        if ( $var_sku === '' ) continue;

        $var_id = wc_get_product_id_by_sku( $var_sku );

        if ( $var_id ) {
            $v = wc_get_product( $var_id );
            if ( ! $v || ! $v->is_type( 'variation' ) ) continue;

            if ( ! empty( $allowed['pricing'] ) ) {
                if ( isset( $var_data['regular_price'] ) ) $v->set_regular_price( (string) $var_data['regular_price'] );
                if ( isset( $var_data['sale_price'] ) )    $v->set_sale_price( (string) $var_data['sale_price'] );
            }
            if ( ! empty( $allowed['stock'] ) && isset( $var_data['stock_quantity'] ) ) {
                $qty = (int) $var_data['stock_quantity'];
                $v->set_manage_stock( true );
                $v->set_stock_quantity( $qty );
                $v->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            }
            $v->save();
        } else {
            // Nuova variante — SOLO se catalog+pricing+stock tutte consentite
            if ( ! ( $allowed['catalog'] && $allowed['pricing'] && $allowed['stock'] ) ) continue;
            if ( function_exists( 'gh_create_variation' ) ) {
                gh_create_variation( $parent_id, $var_data );
            }
        }
    }
}

// ── Pricing refresh path (batch endpoint) ──────────────────

/**
 * Refresh pricing-only per N SKU tramite l'endpoint batch /stockx/prices.
 *
 * Path dedicato (non passa da fetch/normalize/diff). Molto piu veloce del
 * full-refresh perche un chunk di 50 SKU = 1 call invece di 50.
 *
 * SICUREZZA: aggiorna SOLO prodotti con _gh_kicksdb_tracked=1. Se una SKU
 * non ha mai avuto enrollment KicksDB → no-op, loggato come skipped.
 *
 * @return array { updated, skipped, errors, details[] }
 */
function gh_kicksdb_refresh_pricing( array $skus, array $options = [] ): array {

    $skus   = array_values( array_filter( array_unique( array_map( 'strval', $skus ) ) ) );
    if ( empty( $skus ) ) {
        return [ 'summary' => [ 'updated' => 0, 'skipped' => 0, 'errors' => 0 ], 'details' => [] ];
    }

    // 1. Filter su tracked=1
    $tracked = [];
    foreach ( $skus as $sku ) {
        $pid = wc_get_product_id_by_sku( $sku );
        if ( $pid && get_post_meta( $pid, '_gh_kicksdb_tracked', true ) === '1' ) {
            $tracked[ $sku ] = $pid;
        }
    }

    $details = [];
    foreach ( $skus as $sku ) {
        if ( ! isset( $tracked[ $sku ] ) ) {
            $details[] = [ 'action' => 'skipped', 'sku' => $sku, 'reason' => 'not_tracked' ];
        }
    }

    if ( empty( $tracked ) ) {
        return [
            'summary' => [ 'updated' => 0, 'skipped' => count( $details ), 'errors' => 0 ],
            'details' => $details,
        ];
    }

    // 2. Batch fetch pricing
    $resp = gh_kicksdb_get_prices_batch( array_keys( $tracked ) );

    // 3. Costruisci remap size_us → size_eu da variations esistenti WC
    $size_remap = [];
    foreach ( $tracked as $sku => $pid ) {
        $variations = wc_get_product( $pid )?->get_children() ?? [];
        foreach ( $variations as $vid ) {
            $v = wc_get_product( $vid );
            if ( ! $v ) continue;
            $eu = $v->get_attribute( 'pa_taglia' );
            $us = get_post_meta( $vid, '_gh_kicksdb_size_us', true );
            if ( $eu && $us ) {
                $size_remap[ $sku . '|' . $us ] = $eu;
            }
        }
    }

    // 4. Estrai lowest_ask standard per taglia
    $prices = gh_kicksdb_extract_standard_prices( $resp, $size_remap );
    $priced = gh_kicksdb_apply_markup_to_map( $prices );

    // 5. Applica per ciascun SKU tracked
    $updated = 0;
    $errors  = 0;

    foreach ( $tracked as $sku => $pid ) {
        if ( empty( $priced[ $sku ] ) ) {
            $details[] = [ 'action' => 'skipped', 'id' => $pid, 'sku' => $sku, 'reason' => 'no_prices_returned' ];
            continue;
        }

        // Conflict check sulla slice pricing
        $resolved = function_exists( 'gh_conflict_resolve' )
            ? gh_conflict_resolve( $pid, [ 'regular_price' => 0 ], 'kicksdb', $options )
            : [ 'allowed_slices' => [ 'pricing' => true ], 'blocked' => [] ];

        if ( empty( $resolved['allowed_slices']['pricing'] ) ) {
            $details[] = [ 'action' => 'skipped', 'id' => $pid, 'sku' => $sku, 'reason' => 'blocked_by_rule' ];
            continue;
        }

        $applied_to = [];
        foreach ( $priced[ $sku ] as $size_eu => $info ) {
            $var_sku = $sku . '-EU' . $size_eu;
            $var_id  = wc_get_product_id_by_sku( $var_sku );
            if ( ! $var_id ) continue;
            $v = wc_get_product( $var_id );
            if ( ! $v || ! $v->is_type( 'variation' ) ) continue;

            $v->set_regular_price( (string) $info['selling'] );
            $v->save();
            $applied_to[] = $size_eu;
        }

        if ( ! empty( $applied_to ) ) {
            WC_Product_Variable::sync( $pid );
            update_post_meta( $pid, '_gh_kicksdb_last_price_sync', current_time( 'mysql' ) );
            $updated++;
            $details[] = [ 'action' => 'updated', 'id' => $pid, 'sku' => $sku, 'sizes' => $applied_to ];
        } else {
            $details[] = [ 'action' => 'skipped', 'id' => $pid, 'sku' => $sku, 'reason' => 'no_matching_variations' ];
        }
    }

    return [
        'summary' => [
            'updated' => $updated,
            'skipped' => count( $details ) - $updated - $errors,
            'errors'  => $errors,
            'chunks'  => $resp['chunks'] ?? 0,
        ],
        'details' => $details,
    ];
}
