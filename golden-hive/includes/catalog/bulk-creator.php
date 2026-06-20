<?php
/**
 * Bulk Creator — crea prodotti WooCommerce in massa da JSON.
 * Usa il product factory condiviso (core/product-factory.php).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valida e normalizza il JSON di bulk import.
 *
 * @param mixed $data JSON decodificato.
 * @return array|WP_Error
 */
function rp_cm_validate_bulk_json( mixed $data ): array|WP_Error {

    if ( is_array( $data ) && isset( $data[0] ) ) {
        $data = [ 'products' => $data ];
    }

    if ( empty( $data['products'] ) || ! is_array( $data['products'] ) ) {
        return new WP_Error( 'no_products', 'Array "products" mancante o vuoto.' );
    }

    foreach ( $data['products'] as $i => $p ) {
        if ( empty( $p['name'] ) ) {
            return new WP_Error( 'missing_name', "Prodotto indice {$i}: campo 'name' obbligatorio." );
        }
        $type = $p['type'] ?? 'simple';
        if ( $type === 'simple' && empty( $p['regular_price'] ) ) {
            return new WP_Error( 'missing_price', "Prodotto '{$p['name']}': 'regular_price' obbligatorio per simple." );
        }
        if ( $type === 'variable' && empty( $p['variations'] ) ) {
            return new WP_Error( 'missing_variations', "Prodotto '{$p['name']}': 'variations' obbligatorio per variable." );
        }
    }

    return $data;
}

/**
 * Preview del bulk import.
 *
 * @param array  $data Dati validati.
 * @param string $mode 'create' | 'create_or_update'
 * @return array
 */
function rp_cm_bulk_preview( array $data, string $mode = 'create' ): array {

    $details = [];

    foreach ( $data['products'] as $entry ) {
        $sku      = $entry['sku'] ?? null;
        $action   = 'create';
        $existing = null;

        if ( $mode === 'create_or_update' && $sku ) {
            $existing_id = wc_get_product_id_by_sku( $sku );
            if ( $existing_id ) { $action = 'update'; $existing = $existing_id; }
        }

        $details[] = [
            'name'            => $entry['name'],
            'sku'             => $sku,
            'type'            => $entry['type'] ?? 'simple',
            'status'          => $entry['status'] ?? 'publish',
            'action'          => $action,
            'existing_id'     => $existing,
            'variation_count' => count( $entry['variations'] ?? [] ),
        ];
    }

    return [
        'summary' => [
            'total'     => count( $details ),
            'to_create' => count( array_filter( $details, fn( $d ) => $d['action'] === 'create' ) ),
            'to_update' => count( array_filter( $details, fn( $d ) => $d['action'] === 'update' ) ),
        ],
        'details' => $details,
    ];
}

/**
 * Applica il bulk import. Usa gh_create_* per la creazione.
 *
 * @param array  $data Dati validati.
 * @param string $mode 'create' | 'create_or_update'
 * @return array
 */
function rp_cm_bulk_apply( array $data, string $mode = 'create' ): array {

    $details = [];

    foreach ( $data['products'] as $entry ) {
        $sku = $entry['sku'] ?? null;

        // Check per aggiornamento
        $existing_id = null;
        if ( $mode === 'create_or_update' && $sku ) {
            $existing_id = wc_get_product_id_by_sku( $sku );
        }

        try {
            if ( $existing_id ) {
                $result = rp_cm_bulk_update_existing( $existing_id, $entry );
            } else {
                $type       = $entry['type'] ?? 'simple';
                $product_id = $type === 'variable'
                    ? gh_create_variable_product( $entry )
                    : gh_create_simple_product( $entry );

                $result = [
                    'name'            => $entry['name'],
                    'sku'             => $sku,
                    'type'            => $type,
                    'status'          => 'created',
                    'id'              => $product_id,
                    'variation_count' => count( $entry['variations'] ?? [] ),
                ];
            }
            $details[] = $result;
        } catch ( \Exception $e ) {
            $details[] = [
                'name'   => $entry['name'],
                'sku'    => $sku,
                'type'   => $entry['type'] ?? '?',
                'status' => 'error',
                'reason' => $e->getMessage(),
                'id'     => null,
            ];
        }
    }

    return [
        'summary' => [
            'total'   => count( $details ),
            'created' => count( array_filter( $details, fn( $d ) => $d['status'] === 'created' ) ),
            'updated' => count( array_filter( $details, fn( $d ) => $d['status'] === 'updated' ) ),
            'errors'  => count( array_filter( $details, fn( $d ) => $d['status'] === 'error' ) ),
        ],
        'details' => $details,
    ];
}

/**
 * Aggiorna un prodotto esistente con i dati dal JSON.
 */
function rp_cm_bulk_update_existing( int $product_id, array $entry ): array {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return [ 'name' => $entry['name'], 'sku' => $entry['sku'] ?? null, 'type' => '?', 'status' => 'error', 'reason' => 'Non trovato', 'id' => null ];
    }

    gh_apply_product_fields( $product, $entry );

    if ( $product->is_type( 'variable' ) && ! empty( $entry['attributes'] ) ) {
        $product->set_attributes( gh_build_wc_attributes( $entry['attributes'] ) );
    }

    $product->save();
    gh_apply_product_meta( $product_id, $entry );

    // Varianti
    $var_count = 0;
    if ( $product->is_type( 'variable' ) && ! empty( $entry['variations'] ) ) {
        foreach ( $entry['variations'] as $var_data ) {
            $var_id = null;
            if ( ! empty( $var_data['sku'] ) ) {
                $var_id = wc_get_product_id_by_sku( $var_data['sku'] );
            }
            if ( $var_id ) {
                $v = wc_get_product( $var_id );
                if ( $v && $v->is_type( 'variation' ) ) {
                    gh_apply_product_fields( $v, $var_data );
                    $v->save();
                }
            } else {
                gh_create_variation( $product_id, $var_data );
            }
            $var_count++;
        }
        WC_Product_Variable::sync( $product_id );
    }

    return [
        'name'            => $product->get_name(),
        'sku'             => $product->get_sku(),
        'type'            => $product->get_type(),
        'status'          => 'updated',
        'id'              => $product_id,
        'variation_count' => $var_count,
    ];
}

/**
 * Dispatches a bulk import as a background job (kind bulk_import).
 *
 * Writes the products to a token-named file under uploads/gh-jobs/imports/ and
 * fires a one-shot job that walks it in WP-Cron ticks. The dispatch returns in
 * milliseconds, so the upload request never holds long enough to hit
 * Cloudflare's ~100s cap; the actual import runs server-side and survives the
 * user closing the tab.
 *
 * @param array  $data { products: [...] } (or a bare array).
 * @param string $mode 'create' | 'create_or_update'
 * @return array{job_id:string,total:int}|WP_Error
 */
function gh_cm_dispatch_bulk_import( array $data, string $mode = 'create' ): array|WP_Error {

    if ( ! in_array( $mode, [ 'create', 'create_or_update' ], true ) ) {
        $mode = 'create';
    }
    if ( ! function_exists( 'gh_jobs_save' ) || ! function_exists( 'gh_jobs_get_kind' ) || ! gh_jobs_get_kind( 'bulk_import' ) ) {
        return new WP_Error( 'jobs_unavailable', 'Job runner non disponibile.' );
    }

    $valid = rp_cm_validate_bulk_json( $data );
    if ( is_wp_error( $valid ) ) {
        return $valid;
    }
    $products = $valid['products'];
    $total    = count( $products );

    // Persist the payload to disk (job params can't carry MBs of JSON).
    $uploads = wp_get_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return new WP_Error( 'upload_dir', 'Upload dir non accessibile: ' . $uploads['error'] );
    }
    $dir = trailingslashit( $uploads['basedir'] ) . 'gh-jobs/imports';
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'mkdir', "Impossibile creare {$dir}" );
    }
    // Best-effort directory hardening (Apache; nginx ignores .htaccess, but the
    // random token filename keeps the path unguessable).
    if ( ! file_exists( $dir . '/.htaccess' ) ) {
        @file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
    }
    if ( ! file_exists( $dir . '/index.php' ) ) {
        @file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
    }

    $token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 16, false ) : bin2hex( random_bytes( 8 ) );
    $path  = trailingslashit( $dir ) . 'import-' . wp_date( 'Ymd-His' ) . '-' . $token . '.json';
    $json  = wp_json_encode( [ 'products' => $products ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( false === file_put_contents( $path, $json ) ) {
        return new WP_Error( 'write', "Scrittura fallita: {$path}" );
    }

    $saved = gh_jobs_save( [
        'kind'        => 'bulk_import',
        'label'       => sprintf( 'Bulk Import · %d prodotti', $total ),
        // Expression valida ma enabled:false → il cron non la schedula mai;
        // facciamo un fire singolo qui sotto.
        'cron'        => '0 0 1 1 *',
        'enabled'     => false,
        'max_runtime' => 3600,
        'tick_budget' => 25,
        'params'      => [ 'file' => $path, 'mode' => $mode, 'weight_budget' => '80' ],
    ] );

    if ( is_wp_error( $saved ) ) {
        @unlink( $path );
        return $saved;
    }

    // Seed progress so the first status poll has something to show.
    gh_jobs_update_fields( $saved['id'], [
        'last_status'  => 'continue',
        'last_summary' => [ 'processed' => 0, 'total' => $total, 'created' => 0, 'updated' => 0, 'errors' => 0 ],
    ] );

    wp_schedule_single_event( time(), GH_JOBS_TICK_HOOK, [ $saved['id'] ] );
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron( time() );
    }

    return [ 'job_id' => (string) $saved['id'], 'total' => $total ];
}
