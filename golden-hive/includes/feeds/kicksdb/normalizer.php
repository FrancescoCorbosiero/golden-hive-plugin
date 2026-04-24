<?php
/**
 * KicksDB — normalizzatore response → shape WooCommerce.
 *
 * Prende la response "full product" (GET /stockx/products/{sku}?display=variants,traits,identifiers)
 * e produce un record compatibile con gh_create_variable_product() / gh_create_simple_product().
 *
 * Decisioni di mapping:
 * - size: UNICAMENTE EU. Ignoriamo size_us/size_uk. Schema piu pulito, meno
 *   churn se KicksDB cambia payload, coerente con pa_taglia esistente.
 * - brand: va sia su pa_brand (attribute) sia su product_brand (taxonomy
 *   gerarchica, model come child di brand) — coerente con feed GS.
 * - category: heuristic da product_type / category KicksDB: Shoes → sneakers,
 *   Clothing → abbigliamento. Se vuoto → sneakers (fallback).
 * - pricing: da variant.lowest_ask (usato per preview). Per refresh periodico
 *   si usa invece il batch endpoint (vedi pricing.php).
 * - immagini: main image come featured; gallery 360° solo se abilitato in
 *   settings (opt-in, perche il 360° spesso non e disponibile).
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_kicksdb_normalize' ) ) return;

/**
 * Normalizza una response "full product" in un record WC pronto per il factory.
 *
 * @param array  $full_response Response di gh_kicksdb_get_product_full (o _cached).
 * @param array  $opts {
 *   @type bool   $apply_markup  Se true, converte lowest_ask con markup formula
 *                                (default true per preview, false per batch
 *                                pricing flows che aggiornano in seguito).
 *   @type string $price_mode    'direct' o 'sale'. Default 'direct'. In 'sale'
 *                                il selling diventa sale_price e regular_price
 *                                e un markup sopra (analogo a GS).
 *   @type float  $sale_mult     Moltiplicatore per fake regular in 'sale' mode.
 * }
 * @return array|WP_Error Shape compatibile gh_create_variable_product() oppure
 *                        WP_Error se la response e vuota/malformata.
 */
function gh_kicksdb_normalize( array $full_response, array $opts = [] ): array|WP_Error {

    $data = $full_response['body']['data'] ?? $full_response['data'] ?? null;
    if ( ! is_array( $data ) || empty( $data['sku'] ) ) {
        return new WP_Error( 'kdb_malformed', 'Response KicksDB priva di data.sku.' );
    }

    $apply_markup = (bool) ( $opts['apply_markup'] ?? true );
    $price_mode   = (string) ( $opts['price_mode'] ?? 'direct' );
    $sale_mult    = (float)  ( $opts['sale_mult'] ?? 1.3 );

    $sku         = (string) $data['sku'];
    $name        = (string) ( $data['title'] ?? $sku );
    $brand       = (string) ( $data['brand'] ?? '' );
    $model       = (string) ( $data['model'] ?? '' );
    $gender      = (string) ( $data['gender'] ?? '' );
    $colorway    = (string) ( $data['colorway'] ?? '' );
    $description = (string) ( $data['description'] ?? '' );
    $main_image  = (string) ( $data['image'] ?? '' );
    $release     = (string) ( $data['release_date'] ?? '' );
    $kicksdb_id  = (string) ( $data['id'] ?? '' );
    $kicksdb_slug= (string) ( $data['slug'] ?? '' );

    // Category: Shoes → sneakers, Clothing → abbigliamento, altro → sneakers.
    $product_type = strtolower( (string) ( $data['product_type'] ?? '' ) );
    $gs_category  = str_contains( $product_type, 'cloth' ) || str_contains( $product_type, 'apparel' )
        ? 'abbigliamento'
        : 'sneakers';

    // Varianti: solo EU. Se non ci sono varianti → simple product.
    $variants_in = $data['variants'] ?? [];
    if ( ! is_array( $variants_in ) ) $variants_in = [];

    $sizes_eu = [];
    $variants_out = [];
    $formula = gh_kicksdb_get_pricing_formula();

    foreach ( $variants_in as $v ) {
        $eu = trim( (string) ( $v['size_eu'] ?? '' ) );
        if ( $eu === '' ) continue;

        $market_price = (float) ( $v['lowest_ask'] ?? 0 );
        $selling      = $apply_markup
            ? gh_kicksdb_apply_markup( $market_price, $formula )
            : $market_price;

        if ( $selling <= 0 ) {
            // Taglia senza prezzo: la includiamo come variation fuori stock
            // cosi resta visibile nel catalogo ma non acquistabile.
            $variants_out[] = [
                'attributes'     => [ 'pa_taglia' => $eu ],
                'sku'            => $sku . '-EU' . $eu,
                'manage_stock'   => true,
                'stock_quantity' => 0,
                'stock_status'   => 'outofstock',
                'regular_price'  => '',
                'sale_price'     => '',
                'status'         => 'publish',
            ];
            if ( ! in_array( $eu, $sizes_eu, true ) ) $sizes_eu[] = $eu;
            continue;
        }

        $var = [
            'attributes'     => [ 'pa_taglia' => $eu ],
            'sku'            => $sku . '-EU' . $eu,
            'manage_stock'   => true,
            // Nota stock: KicksDB non espone stock reale (e un marketplace).
            // Si assume "available === true → disponibile", quantita simbolica 1.
            'stock_quantity' => ( $v['available'] ?? true ) ? 1 : 0,
            'stock_status'   => ( $v['available'] ?? true ) ? 'instock' : 'outofstock',
            'status'         => 'publish',
        ];

        if ( $price_mode === 'sale' ) {
            $var['sale_price']    = (string) $selling;
            $var['regular_price'] = (string) round( $selling * $sale_mult, 2 );
        } else {
            $var['regular_price'] = (string) $selling;
            $var['sale_price']    = '';
        }

        $variants_out[] = $var;
        if ( ! in_array( $eu, $sizes_eu, true ) ) $sizes_eu[] = $eu;
    }

    $type = count( $variants_out ) > 0 ? 'variable' : 'simple';

    $woo = [
        'name'        => $name,
        'sku'         => $sku,
        'type'        => $type,
        'status'      => 'publish',
        'description' => $description,

        // Markers interni letti da gh_kicksdb_post_process()
        '_kdb_brand'    => $brand,
        '_kdb_model'    => $model,
        '_kdb_gender'   => $gender,
        '_kdb_colorway' => $colorway,
        '_kdb_release'  => $release,
        '_kdb_id'       => $kicksdb_id,
        '_kdb_slug'     => $kicksdb_slug,
        '_kdb_image'    => $main_image,
        '_kdb_category' => $gs_category,
    ];

    $attrs = [];
    if ( $brand !== '' ) {
        $attrs['pa_brand'] = [
            'options'   => [ $brand ],
            'visible'   => true,
            'variation' => false,
        ];
    }

    if ( $type === 'variable' ) {
        $attrs['pa_taglia'] = [
            'options'   => $sizes_eu,
            'visible'   => true,
            'variation' => true,
        ];
        $woo['attributes'] = $attrs;
        $woo['variations'] = $variants_out;
    } else {
        // Simple fallback — nessuna variante: prezzo base da "lowest_ask" top-level
        // se presente nella response (alcuni endpoint lo espongono).
        $top_ask = (float) ( $data['lowest_ask'] ?? 0 );
        $selling = $apply_markup ? gh_kicksdb_apply_markup( $top_ask, $formula ) : $top_ask;

        if ( $selling > 0 ) {
            if ( $price_mode === 'sale' ) {
                $woo['sale_price']    = (string) $selling;
                $woo['regular_price'] = (string) round( $selling * $sale_mult, 2 );
            } else {
                $woo['regular_price'] = (string) $selling;
                $woo['sale_price']    = '';
            }
        }
        $woo['manage_stock']   = true;
        $woo['stock_quantity'] = 1;
        $woo['stock_status']   = 'instock';

        if ( ! empty( $attrs ) ) $woo['attributes'] = $attrs;
    }

    return $woo;
}

/**
 * Normalizza un batch di response in parallelo.
 *
 * @param array $responses Mappa sku → response (output di gh_kicksdb_get_products_multi).
 * @param array $opts      Opts passati a gh_kicksdb_normalize.
 * @return array Mappa sku → woo record (o null se errore).
 */
function gh_kicksdb_normalize_many( array $responses, array $opts = [] ): array {
    $out = [];
    foreach ( $responses as $sku => $resp ) {
        if ( ! is_array( $resp ) || ! empty( $resp['error'] ) ) {
            $out[ $sku ] = null;
            continue;
        }
        $normalized = gh_kicksdb_normalize( $resp, $opts );
        $out[ $sku ] = is_wp_error( $normalized ) ? null : $normalized;
    }
    return $out;
}

/**
 * Post-process dopo creazione prodotto: brand taxonomy (gerarchica), category,
 * meta KicksDB (id, slug, updated_at), featured image.
 *
 * Chiamato dal feed orchestrator DOPO gh_create_{simple,variable}_product.
 */
function gh_kicksdb_post_process( int $product_id, array $data, bool $sideload = true ): void {

    // Brand taxonomy (product_brand, con model child di brand)
    if ( ! empty( $data['_kdb_brand'] ) && taxonomy_exists( 'product_brand' ) ) {
        gh_kicksdb_assign_brand_term( $product_id, (string) $data['_kdb_brand'], (string) ( $data['_kdb_model'] ?? '' ) );
    }

    // Category
    if ( ! empty( $data['_kdb_category'] ) ) {
        gh_kicksdb_assign_category_term( $product_id, (string) $data['_kdb_category'] );
    }

    // Meta KicksDB (opaco, non editabile da UI)
    if ( ! empty( $data['_kdb_id'] ) )       update_post_meta( $product_id, '_gh_kicksdb_id', sanitize_text_field( $data['_kdb_id'] ) );
    if ( ! empty( $data['_kdb_slug'] ) )     update_post_meta( $product_id, '_gh_kicksdb_slug', sanitize_text_field( $data['_kdb_slug'] ) );
    if ( ! empty( $data['_kdb_gender'] ) )   update_post_meta( $product_id, '_gh_kicksdb_gender', sanitize_text_field( $data['_kdb_gender'] ) );
    if ( ! empty( $data['_kdb_colorway'] ) ) update_post_meta( $product_id, '_gh_kicksdb_colorway', sanitize_text_field( $data['_kdb_colorway'] ) );
    if ( ! empty( $data['_kdb_release'] ) )  update_post_meta( $product_id, '_gh_kicksdb_release_date', sanitize_text_field( $data['_kdb_release'] ) );

    update_post_meta( $product_id, '_gh_kicksdb_last_sync', current_time( 'mysql' ) );

    // Featured image — usa il parallel sideloader esistente (curl_multi 10x).
    // Gallery 360° disabilitata di default; l'utente opt-in via settings.
    if ( $sideload && ! empty( $data['_kdb_image'] ) && function_exists( 'gh_parallel_sideload_to_product' ) ) {
        $urls = [ (string) $data['_kdb_image'] ];

        // Futuro: aggiungere frame 360 quando disponibili e gallery.include_360=true.
        gh_parallel_sideload_to_product( $product_id, $urls, (string) ( $data['sku'] ?? '' ), [
            'first_is_featured' => true,
            'rest_is_gallery'   => true,
        ] );
    }
}

/**
 * Assegna brand (root) + model (child) in product_brand. Mirror di rp_rc_gs_assign_brand.
 */
function gh_kicksdb_assign_brand_term( int $product_id, string $brand, string $model = '' ): void {

    if ( $brand === '' || ! taxonomy_exists( 'product_brand' ) ) return;

    $term = term_exists( $brand, 'product_brand' );
    if ( ! $term ) $term = wp_insert_term( $brand, 'product_brand' );
    if ( is_wp_error( $term ) ) return;
    $brand_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );

    $ids = [ $brand_id ];

    if ( $model !== '' ) {
        $m = term_exists( $model, 'product_brand', $brand_id );
        if ( ! $m ) $m = wp_insert_term( $model, 'product_brand', [ 'parent' => $brand_id ] );
        if ( ! is_wp_error( $m ) ) {
            $ids[] = (int) ( is_array( $m ) ? $m['term_id'] : $m );
        }
    }

    wp_set_object_terms( $product_id, $ids, 'product_brand' );
}

/**
 * Assegna product_cat (sneakers | abbigliamento). Mirror di rp_rc_gs_assign_category.
 */
function gh_kicksdb_assign_category_term( int $product_id, string $category ): void {

    $labels = [
        'sneakers'      => 'Sneakers',
        'abbigliamento' => 'Abbigliamento',
    ];
    $slug = sanitize_title( $category );
    $name = $labels[ $category ] ?? ucfirst( $category );

    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( ! $term ) {
        $result  = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
        $term_id = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
    } else {
        $term_id = (int) $term->term_id;
    }

    if ( $term_id ) {
        wp_set_object_terms( $product_id, [ $term_id ], 'product_cat' );
    }
}
