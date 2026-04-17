<?php
/**
 * AJAX handlers — Product module (Inline Editor + Filtra bridge).
 *
 * Endpoint attivi (tutti con prefix gh_ajax_*, nonce gh_nonce,
 * manage_woocommerce):
 *   gh_ajax_product_search    — typeahead per l'Inline Editor
 *   gh_ajax_product_load      — fetch full product + variations + brands + categories
 *   gh_ajax_product_save      — batch update di piu campi in un colpo solo
 *   gh_ajax_product_variations_save — batch update varianti
 *
 * La business logic vive in product/crud.php e product/variations.php.
 * Qui: sanitize → call → json response. Niente altro.
 *
 * Coesistenza con rp-product-manager: quel plugin espone gli stessi ENDPOINT
 * con prefix rp_ajax_* e nonce rp_crud_nonce. Nessuna collisione — sono
 * handler distinti sulle action hook diverse.
 */

defined( 'ABSPATH' ) || exit;

// ── SEARCH (typeahead) ─────────────────────────────────────────────────────

add_action( 'wp_ajax_gh_ajax_product_search', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $query = sanitize_text_field( $_POST['query'] ?? '' );
    $limit = max( 1, min( 30, (int) ( $_POST['limit'] ?? 8 ) ) );

    if ( $query === '' ) { wp_send_json_success( [] ); }

    wp_send_json_success( rp_search_products( $query, $limit ) );
} );

// ── LOAD (full product payload per l'Inline Editor) ───────────────────────

add_action( 'wp_ajax_gh_ajax_product_load', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['product_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'product_id mancante.' ); }

    $product = wc_get_product( $id );
    if ( ! $product ) { wp_send_json_error( "Prodotto #{$id} non trovato." ); }

    $data = rp_get_product( $id );
    if ( isset( $data['error'] ) ) { wp_send_json_error( $data['error'] ); }

    // Aggiunge brand (product_brand) e IDs di categorie/tag per editing programmatico
    $data['brands']       = taxonomy_exists( 'product_brand' )
        ? wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] )
        : [];
    $data['category_ids'] = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'ids' ] );
    $data['tag_ids']      = wp_get_post_terms( $id, 'product_tag', [ 'fields' => 'ids' ] );
    $data['brand_ids']    = taxonomy_exists( 'product_brand' )
        ? wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] )
        : [];

    // Gallery con URL per l'UI
    $gallery = [];
    foreach ( $product->get_gallery_image_ids() as $gid ) {
        $url = wp_get_attachment_image_src( (int) $gid, 'thumbnail' );
        $gallery[] = [
            'id'            => (int) $gid,
            'thumbnail_url' => $url[0] ?? wp_get_attachment_url( (int) $gid ),
        ];
    }
    $data['gallery'] = $gallery;

    // Featured image
    $featured_id = $product->get_image_id();
    if ( $featured_id ) {
        $f = wp_get_attachment_image_src( (int) $featured_id, 'thumbnail' );
        $data['featured_image'] = [
            'id'            => (int) $featured_id,
            'thumbnail_url' => $f[0] ?? wp_get_attachment_url( (int) $featured_id ),
        ];
    } else {
        $data['featured_image'] = null;
    }

    // Variazioni (solo se variable)
    $variations = [];
    if ( $product->is_type( 'variable' ) ) {
        $variations = rp_get_product_variations( $id );
    }

    wp_send_json_success( [
        'product'    => $data,
        'variations' => $variations,
    ] );
} );

// ── SAVE (batch update via JSON payload) ──────────────────────────────────
//
// Accetta un payload arbitrario (JSON) di campi da aggiornare.
// rp_update_product usa array_key_exists, quindi passare "" cancella un
// campo (e.g. "sale_price": "" rimuove il sale price).
//
// Campi riconosciuti: name, sku, regular_price, sale_price, description,
// short_description, status, weight, slug, manage_stock, stock_quantity,
// stock_status, category_ids, tag_ids, meta_title, meta_description,
// focus_keyword, brand_ids.
add_action( 'wp_ajax_gh_ajax_product_save', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $id = intval( $_POST['product_id'] ?? 0 );
    if ( ! $id ) { wp_send_json_error( 'product_id mancante.' ); }

    $raw     = stripslashes( $_POST['payload'] ?? '{}' );
    $payload = json_decode( $raw, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON payload non valido: ' . json_last_error_msg() );
    }
    if ( ! is_array( $payload ) ) {
        wp_send_json_error( 'Payload deve essere un oggetto JSON.' );
    }

    // Protezione campi read-only: se il client ha mandato id/type/price/date_*
    // come "modifiche" (es. copiati dal JSON view) li scartiamo in silenzio.
    unset(
        $payload['id'], $payload['type'], $payload['price'],
        $payload['date_created'], $payload['date_modified'],
        $payload['permalink'], $payload['categories'], $payload['tags'],
        $payload['brands'], $payload['attributes'], $payload['gallery'],
        $payload['featured_image']
    );

    // brand_ids → product_brand (gestito manualmente qui perche rp_update_product
    // non conosce la tassonomia product_brand)
    $brand_ids = null;
    if ( array_key_exists( 'brand_ids', $payload ) ) {
        $brand_ids = array_map( 'intval', (array) $payload['brand_ids'] );
        unset( $payload['brand_ids'] );
    }

    try {
        if ( ! empty( $payload ) ) {
            $result = rp_update_product( $id, $payload );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
        }

        if ( $brand_ids !== null && taxonomy_exists( 'product_brand' ) ) {
            wp_set_object_terms( $id, $brand_ids, 'product_brand' );
        }

        // Invalida cache dell'indice media usage (il salvataggio di un prodotto
        // puo aver cambiato featured / gallery references)
        if ( function_exists( 'gh_media_invalidate_usage_index' ) ) {
            gh_media_invalidate_usage_index();
        }

        // Ritorna il prodotto aggiornato per refresh in-place
        $updated = rp_get_product( $id );
        wp_send_json_success( [ 'product' => $updated ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Save fallita: ' . $e->getMessage() );
    }
} );

// ── VARIATIONS BATCH SAVE ─────────────────────────────────────────────────

add_action( 'wp_ajax_gh_ajax_product_variations_save', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $product_id = intval( $_POST['product_id'] ?? 0 );
    $raw        = stripslashes( $_POST['updates'] ?? '[]' );
    $updates    = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON updates non valido: ' . json_last_error_msg() );
    }
    if ( ! is_array( $updates ) || empty( $updates ) ) {
        wp_send_json_error( 'Nessun update fornito.' );
    }

    try {
        $results = rp_bulk_update_variations( $updates );

        $errors = array_filter( $results, fn( $v ) => $v !== 'ok' );
        $ok     = count( $results ) - count( $errors );

        // Re-fetch varianti aggiornate per refresh in-place
        $variations = $product_id ? rp_get_product_variations( $product_id ) : [];

        wp_send_json_success( [
            'results'    => $results,
            'ok'         => $ok,
            'errors'     => count( $errors ),
            'variations' => $variations,
        ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( 'Save fallita: ' . $e->getMessage() );
    }
} );

// ── BULK JSON LOAD — fetch full payloads for multiple products ─────────────

add_action( 'wp_ajax_gh_ajax_product_bulk_load', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    $raw = stripslashes( $_POST['product_ids'] ?? '[]' );
    $ids = json_decode( $raw, true ) ?: [];

    if ( ! is_array( $ids ) || empty( $ids ) ) {
        wp_send_json_error( 'Nessun ID fornito.' );
    }

    $products = [];
    foreach ( $ids as $id ) {
        $id   = (int) $id;
        $data = rp_get_product( $id );
        if ( isset( $data['error'] ) ) continue;

        $data['brands']       = taxonomy_exists( 'product_brand' )
            ? wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] ) : [];
        $data['category_ids'] = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'ids' ] );
        $data['tag_ids']      = wp_get_post_terms( $id, 'product_tag', [ 'fields' => 'ids' ] );
        $data['brand_ids']    = taxonomy_exists( 'product_brand' )
            ? wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] ) : [];

        if ( wc_get_product( $id )?->is_type( 'variable' ) ) {
            $data['variations'] = rp_get_product_variations( $id );
        }

        $products[] = $data;
    }

    wp_send_json_success( $products );
} );

// ── BULK JSON UPSERT — update existing by ID/SKU, create new ──────────────

add_action( 'wp_ajax_gh_ajax_product_bulk_upsert', function () {
    check_ajax_referer( 'gh_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

    @set_time_limit( 300 );
    if ( function_exists( 'wp_raise_memory_limit' ) ) wp_raise_memory_limit( 'admin' );

    $raw      = stripslashes( $_POST['products'] ?? '[]' );
    $products = json_decode( $raw, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error( 'JSON non valido: ' . json_last_error_msg() );
    }
    if ( ! is_array( $products ) || empty( $products ) ) {
        wp_send_json_error( 'Array prodotti vuoto.' );
    }

    $read_only = [ 'type', 'price', 'date_created', 'date_modified', 'permalink', 'categories', 'tags', 'brands', 'attributes', 'gallery', 'featured_image' ];

    $results = [];
    $updated = 0;
    $created = 0;
    $errors  = 0;

    foreach ( $products as $p ) {
        if ( ! is_array( $p ) ) { $errors++; continue; }

        $payload = $p;
        foreach ( $read_only as $field ) unset( $payload[ $field ] );

        $product_id = (int) ( $payload['id'] ?? 0 );
        $sku        = $payload['sku'] ?? '';

        if ( $product_id && wc_get_product( $product_id ) ) {
            // found by ID
        } elseif ( $sku ) {
            $found = wc_get_product_id_by_sku( $sku );
            if ( $found ) $product_id = $found;
        }

        try {
            if ( $product_id ) {
                unset( $payload['id'] );

                $brand_ids = null;
                if ( array_key_exists( 'brand_ids', $payload ) ) {
                    $brand_ids = array_map( 'intval', (array) $payload['brand_ids'] );
                    unset( $payload['brand_ids'] );
                }

                $variations = null;
                if ( array_key_exists( 'variations', $payload ) ) {
                    $variations = $payload['variations'];
                    unset( $payload['variations'] );
                }

                if ( ! empty( $payload ) ) {
                    $r = rp_update_product( $product_id, $payload );
                    if ( is_wp_error( $r ) ) {
                        $results[] = [ 'action' => 'error', 'id' => $product_id, 'sku' => $sku, 'reason' => $r->get_error_message() ];
                        $errors++;
                        continue;
                    }
                }

                if ( $brand_ids !== null && taxonomy_exists( 'product_brand' ) ) {
                    wp_set_object_terms( $product_id, $brand_ids, 'product_brand' );
                }

                if ( $variations !== null ) {
                    rp_bulk_update_variations( $product_id, $variations );
                }

                $results[] = [ 'action' => 'updated', 'id' => $product_id, 'sku' => $sku, 'name' => $p['name'] ?? '' ];
                $updated++;
            } else {
                unset( $payload['id'] );
                $variations = $payload['variations'] ?? null;
                unset( $payload['variations'] );
                $brand_ids = $payload['brand_ids'] ?? null;
                unset( $payload['brand_ids'] );

                $type = $p['type'] ?? 'simple';
                if ( $type === 'variable' && $variations ) {
                    $payload['variations'] = $variations;
                    $new_id = gh_create_variable_product( $payload );
                } else {
                    $new_id = gh_create_simple_product( $payload );
                }

                if ( $brand_ids && taxonomy_exists( 'product_brand' ) ) {
                    wp_set_object_terms( $new_id, array_map( 'intval', (array) $brand_ids ), 'product_brand' );
                }

                $results[] = [ 'action' => 'created', 'id' => $new_id, 'sku' => $sku, 'name' => $p['name'] ?? '' ];
                $created++;
            }
        } catch ( \Throwable $e ) {
            $results[] = [ 'action' => 'error', 'id' => $product_id, 'sku' => $sku, 'reason' => $e->getMessage() ];
            $errors++;
        }
    }

    wp_send_json_success( [
        'summary' => compact( 'updated', 'created', 'errors' ),
        'details' => $results,
    ] );
} );
