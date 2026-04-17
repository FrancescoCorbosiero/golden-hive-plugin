<?php
/**
 * Feed Golden Sneakers — fetch, trasformazione e importazione assortimento.
 *
 * Trasforma il formato proprietario Golden Sneakers in prodotti WooCommerce:
 * - SKU matching per update/create
 * - Brand → product_brand taxonomy (parent), modello → child of brand
 * - presented_price → sale_price, presented_price × 1.3 → regular_price (fake original)
 * - Tag "super-sale" su tutti i prodotti importati
 * - Immagine sideload da image_full_url con naming {sku}.{ext}
 */

defined( 'ABSPATH' ) || exit;

const RP_RC_GS_FAKE_PRICE_MULTIPLIER = 1.3;
const RP_RC_GS_TAG_SLUG              = 'super-sale';
const RP_RC_GS_TAG_NAME              = 'Super Sale';

// ── Fetch ───────────────────────────────────────────────────

/**
 * Recupera l'assortimento da Golden Sneakers.
 *
 * @param array $config [
 *   'url'    => string (endpoint URL with query params),
 *   'token'  => string (Bearer token),
 *   'cookie' => string (optional, csrftoken cookie),
 *   'format' => 'hierarchical' | 'flat' (default: hierarchical),
 * ]
 * @return array|WP_Error Array di prodotti normalizzati o errore.
 */
function rp_rc_gs_fetch( array $config ): array|WP_Error {

    $url    = $config['url'] ?? '';
    $token  = $config['token'] ?? '';
    $cookie = $config['cookie'] ?? '';

    if ( ! $url || ! $token ) {
        return new WP_Error( 'missing_config', 'URL e token sono obbligatori.' );
    }

    $headers = [
        'Accept'        => 'application/json',
        'Authorization' => 'Bearer ' . $token,
    ];
    if ( $cookie ) {
        $headers['Cookie'] = $cookie;
    }

    $response = rp_rc_request( [
        'url'     => $url,
        'method'  => 'GET',
        'headers' => $headers,
        'timeout' => 60,
    ] );

    if ( ! empty( $response['error'] ) ) {
        return new WP_Error( 'fetch_error', $response['error'] );
    }
    if ( $response['status'] !== 200 ) {
        return new WP_Error( 'http_error', "HTTP {$response['status']}: risposta non valida." );
    }

    $data = json_decode( $response['body'], true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'json_error', 'Risposta non e JSON valido.' );
    }

    $format = $config['format'] ?? 'hierarchical';

    return $format === 'flat'
        ? rp_rc_gs_normalize_flat( $data )
        : rp_rc_gs_normalize_hierarchical( $data );
}

// ── Normalize ───────────────────────────────────────────────

/**
 * Normalizza la risposta gerarchica (un oggetto per prodotto con sizes[]).
 *
 * @param array $data Risposta raw API.
 * @return array Array normalizzato di prodotti.
 */
function rp_rc_gs_normalize_hierarchical( array $data ): array {

    $products = [];

    foreach ( $data as $item ) {
        $sku        = $item['sku'] ?? '';
        $name       = $item['name'] ?? '';
        $brand      = $item['brand_name'] ?? '';
        $image_url  = ( $item['image_full_url'] ?? '' ) . ( $item['image_name'] ?? '' );

        $sizes = [];
        foreach ( $item['sizes'] ?? [] as $size ) {
            $sizes[] = [
                'size_eu'            => (string) ( $size['size_eu'] ?? '' ),
                'size_us'            => (string) ( $size['size_us'] ?? '' ),
                'offer_price'        => (float) ( $size['offer_price'] ?? 0 ),
                'presented_price'    => (float) ( $size['presented_price'] ?? 0 ),
                'available_quantity' => (int) ( $size['available_quantity'] ?? 0 ),
                'barcode'            => $size['barcode'] ?? '',
            ];
        }

        $products[] = [
            'gs_id'      => $item['id'] ?? null,
            'sku'        => $sku,
            'name'       => $name,
            'brand'      => $brand,
            'model'      => rp_rc_gs_parse_model( $name, $brand ),
            'image_url'  => $image_url,
            'sizes'      => $sizes,
            'total_available' => (int) ( $item['available_summary_quantity'] ?? array_sum( array_column( $sizes, 'available_quantity' ) ) ),
        ];
    }

    return $products;
}

/**
 * Normalizza la risposta flat (una riga per taglia).
 *
 * @param array $data Risposta raw API flat.
 * @return array Array normalizzato di prodotti (raggruppati per SKU).
 */
function rp_rc_gs_normalize_flat( array $data ): array {

    $grouped = [];

    foreach ( $data as $row ) {
        $sku = $row['sku'] ?? '';
        if ( ! $sku ) continue;

        if ( ! isset( $grouped[ $sku ] ) ) {
            $image_url = ( $row['image_full_url'] ?? '' ) . ( $row['image_name'] ?? '' );
            $brand     = $row['brand_name'] ?? '';
            $name      = $row['product_name'] ?? '';

            $grouped[ $sku ] = [
                'gs_id'      => null,
                'sku'        => $sku,
                'name'       => $name,
                'brand'      => $brand,
                'model'      => rp_rc_gs_parse_model( $name, $brand ),
                'image_url'  => $image_url,
                'sizes'      => [],
                'total_available' => 0,
            ];
        }

        $qty = (int) ( $row['available_quantity'] ?? 0 );
        $grouped[ $sku ]['sizes'][] = [
            'size_eu'            => (string) ( $row['size_eu'] ?? '' ),
            'size_us'            => (string) ( $row['size_us'] ?? '' ),
            'offer_price'        => (float) ( $row['offer_price'] ?? 0 ),
            'presented_price'    => (float) ( $row['presented_price'] ?? 0 ),
            'available_quantity' => $qty,
            'barcode'            => $row['barcode'] ?? '',
        ];
        $grouped[ $sku ]['total_available'] += $qty;
    }

    return array_values( $grouped );
}

// ── Transform to WooCommerce ────────────────────────────────

/**
 * Trasforma un prodotto GS normalizzato in formato WooCommerce pronto per import.
 *
 * @param array $product Prodotto normalizzato da rp_rc_gs_normalize_*.
 * @return array Prodotto nel formato bulk import di rp-catalog-manager.
 */
function rp_rc_gs_transform_to_woo( array $product ): array {

    $sizes      = $product['sizes'] ?? [];
    $all_eu     = array_column( $sizes, 'size_eu' );
    $has_sizes  = count( $sizes ) > 1 || ( count( $sizes ) === 1 && $sizes[0]['size_eu'] );
    $type       = $has_sizes ? 'variable' : 'simple';

    // Detect category: numeric sizes (38, 42.5) = sneakers, alpha (S, M, XL) = abbigliamento
    $gs_category = 'sneakers';
    if ( ! empty( $all_eu ) ) {
        $alpha_count = 0;
        foreach ( $all_eu as $sz ) {
            if ( preg_match( '/^[A-Z]{1,3}(\/[A-Z]{1,3})?$/i', trim( $sz ) ) ) $alpha_count++;
        }
        if ( $alpha_count > count( $all_eu ) / 2 ) $gs_category = 'abbigliamento';
    }

    // Pricing: use first size's presented_price as base
    $base_price = $sizes[0]['presented_price'] ?? 0;
    $sale_price = (string) round( $base_price );
    $reg_price  = (string) round( $base_price * RP_RC_GS_FAKE_PRICE_MULTIPLIER );

    $woo = [
        'name'              => $product['name'],
        'sku'               => $product['sku'],
        'type'              => $type,
        'status'            => 'publish',
        '_gs_brand'         => $product['brand'],
        '_gs_model'         => $product['model'],
        '_gs_image_url'     => $product['image_url'],
        '_gs_tag'           => RP_RC_GS_TAG_SLUG,
        '_gs_category'      => $gs_category,
    ];

    if ( $type === 'simple' ) {
        $woo['regular_price'] = $reg_price;
        $woo['sale_price']    = $sale_price;
        $qty                  = $sizes[0]['available_quantity'] ?? 0;
        $woo['manage_stock']  = true;
        $woo['stock_quantity'] = $qty;
        $woo['stock_status']  = $qty > 0 ? 'instock' : 'outofstock';

        if ( ! empty( $product['brand'] ) ) {
            $woo['attributes'] = [
                'pa_brand' => [
                    'options'   => [ $product['brand'] ],
                    'visible'   => true,
                    'variation' => false,
                ],
            ];
        }
    } else {
        $attrs = [
            'pa_taglia' => [
                'options'   => array_values( array_unique( $all_eu ) ),
                'visible'   => true,
                'variation' => true,
            ],
        ];

        // Brand as a WC filterable attribute (for frontend filter widgets)
        if ( ! empty( $product['brand'] ) ) {
            $attrs['pa_brand'] = [
                'options'   => [ $product['brand'] ],
                'visible'   => true,
                'variation' => false,
            ];
        }

        $woo['attributes'] = $attrs;

        $variations = [];
        foreach ( $sizes as $size ) {
            $sp  = (string) round( $size['presented_price'] );
            $rp  = (string) round( $size['presented_price'] * RP_RC_GS_FAKE_PRICE_MULTIPLIER );
            $qty = $size['available_quantity'];

            $variations[] = [
                'attributes'     => [ 'pa_taglia' => $size['size_eu'] ],
                'sku'            => $product['sku'] . '-EU' . $size['size_eu'],
                'regular_price'  => $rp,
                'sale_price'     => $sp,
                'manage_stock'   => true,
                'stock_quantity' => $qty,
                'stock_status'   => $qty > 0 ? 'instock' : 'outofstock',
                'status'         => 'publish',
            ];
        }
        $woo['variations'] = $variations;
    }

    return $woo;
}

/**
 * Trasforma l'intero feed normalizzato in formato WooCommerce.
 *
 * @param array $products Array di prodotti normalizzati.
 * @return array Array di prodotti nel formato WooCommerce.
 */
function rp_rc_gs_transform_all( array $products ): array {

    return array_map( 'rp_rc_gs_transform_to_woo', $products );
}

// ── Diff against WooCommerce ────────────────────────────────

/**
 * Confronta i prodotti GS trasformati con lo stato attuale di WooCommerce.
 *
 * @param array $woo_products Prodotti trasformati (output di transform_all).
 * @return array [ 'new' => [...], 'update' => [...], 'unchanged' => [...], 'summary' => [...] ]
 */
function rp_rc_gs_diff( array $woo_products ): array {

    $new       = [];
    $update    = [];
    $unchanged = [];

    foreach ( $woo_products as $product ) {
        $sku = $product['sku'] ?? '';
        if ( ! $sku ) { $new[] = $product; continue; }

        $existing_id = wc_get_product_id_by_sku( $sku );
        if ( ! $existing_id ) {
            $new[] = $product;
            continue;
        }

        $existing = wc_get_product( $existing_id );
        if ( ! $existing ) { $new[] = $product; continue; }

        // Controlla se ci sono differenze
        $changes = rp_rc_gs_detect_changes( $existing, $product );
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
            'total'     => count( $woo_products ),
            'new'       => count( $new ),
            'update'    => count( $update ),
            'unchanged' => count( $unchanged ),
        ],
    ];
}

/**
 * Rileva differenze tra prodotto WC esistente e dati GS.
 *
 * @param WC_Product $existing Prodotto WC.
 * @param array      $new_data Dati trasformati.
 * @return array Lista di campi cambiati o array vuoto.
 */
function rp_rc_gs_detect_changes( WC_Product $existing, array $new_data ): array {

    $changes = [];

    // Controlla nome
    if ( isset( $new_data['name'] ) && $existing->get_name() !== $new_data['name'] ) {
        $changes[] = 'name';
    }

    // Per variabili: controlla stock delle varianti
    if ( $existing->is_type( 'variable' ) && ! empty( $new_data['variations'] ) ) {
        foreach ( $new_data['variations'] as $new_var ) {
            $var_sku = $new_var['sku'] ?? '';
            if ( ! $var_sku ) continue;
            $var_id = wc_get_product_id_by_sku( $var_sku );
            if ( $var_id ) {
                $v = wc_get_product( $var_id );
                if ( $v ) {
                    if ( (int) $v->get_stock_quantity() !== (int) $new_var['stock_quantity'] ) {
                        $changes[] = 'stock:' . $var_sku;
                    }
                    if ( $v->get_sale_price() !== $new_var['sale_price'] ) {
                        $changes[] = 'price:' . $var_sku;
                    }
                }
            } else {
                $changes[] = 'new_variation:' . $var_sku;
            }
        }
    }

    // Per simple: controlla prezzo e stock
    if ( $existing->is_type( 'simple' ) ) {
        if ( isset( $new_data['sale_price'] ) && $existing->get_sale_price() !== $new_data['sale_price'] ) {
            $changes[] = 'sale_price';
        }
        if ( isset( $new_data['stock_quantity'] ) && (int) $existing->get_stock_quantity() !== (int) $new_data['stock_quantity'] ) {
            $changes[] = 'stock_quantity';
        }
    }

    return $changes;
}

// ── Apply (Create / Update) ─────────────────────────────────

/**
 * Applica i prodotti GS a WooCommerce (crea nuovi, aggiorna esistenti).
 *
 * @param array $diff Output di rp_rc_gs_diff().
 * @param array $options [
 *   'create_new'      => bool (default: true),
 *   'update_existing'  => bool (default: true),
 *   'sideload_images' => bool (default: true),
 * ]
 * @return array Risultato con details per ogni prodotto.
 */
function rp_rc_gs_apply( array $diff, array $options = [] ): array {

    $create_new      = $options['create_new'] ?? true;
    $update_existing = $options['update_existing'] ?? true;
    $sideload        = $options['sideload_images'] ?? true;

    $results = [];

    if ( $create_new && ! empty( $diff['new'] ) ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['new'],
            fn( $p ) => rp_rc_gs_create_product( $p, $sideload )
        ) );
    }

    if ( $update_existing && ! empty( $diff['update'] ) ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['update'],
            fn( $p ) => rp_rc_gs_update_product( $p )
        ) );
    }

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    return [
        'summary' => [
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
        ],
        'details' => $results,
    ];
}

/**
 * Crea un nuovo prodotto WooCommerce da dati GS trasformati.
 * Usa il product factory condiviso (core/product-factory.php).
 *
 * @param array $data   Dati trasformati.
 * @param bool  $sideload Se scaricare l'immagine.
 * @return array Risultato.
 */
function rp_rc_gs_create_product( array $data, bool $sideload = true ): array {

    try {
        $type = $data['type'] ?? 'simple';

        $product_id = $type === 'variable'
            ? gh_create_variable_product( $data )
            : gh_create_simple_product( $data );

        // Brand taxonomy (product_brand)
        if ( ! empty( $data['_gs_brand'] ) ) {
            rp_rc_gs_assign_brand( $product_id, $data['_gs_brand'], $data['_gs_model'] ?? '' );
        }

        // Category: sneakers or abbigliamento
        if ( ! empty( $data['_gs_category'] ) ) {
            rp_rc_gs_assign_category( $product_id, $data['_gs_category'] );
        }

        // Tag super-sale
        if ( ! empty( $data['_gs_tag'] ) ) {
            wp_set_object_terms( $product_id, [ $data['_gs_tag'] ], 'product_tag', true );
        }

        // Sideload immagine
        if ( $sideload && ! empty( $data['_gs_image_url'] ) ) {
            rp_rc_gs_sideload_image( $product_id, $data['_gs_image_url'], $data['sku'] ?? '' );
        }

        // Provenance meta
        update_post_meta( $product_id, '_gh_import_source', 'goldensneakers' );
        update_post_meta( $product_id, '_gh_import_date', current_time( 'mysql' ) );

        return [
            'action' => 'created',
            'id'     => $product_id,
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'],
        ];
    } catch ( \Exception $e ) {
        if ( gh_is_duplicate_sku_error( $e ) && ! empty( $data['sku'] ) ) {
            $existing_id = wc_get_product_id_by_sku( $data['sku'] );
            if ( $existing_id ) {
                $data['_existing_id'] = $existing_id;
                return rp_rc_gs_update_product( $data );
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
 * Aggiorna un prodotto WooCommerce esistente con dati GS.
 *
 * @param array $data Dati trasformati con _existing_id.
 * @return array Risultato.
 */
function rp_rc_gs_update_product( array $data ): array {

    $product_id = $data['_existing_id'] ?? 0;
    if ( ! $product_id ) {
        return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => 'ID mancante' ];
    }

    try {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => 'Prodotto non trovato' ];
        }

        // Aggiorna prezzo parent (simple)
        if ( $product->is_type( 'simple' ) ) {
            if ( isset( $data['regular_price'] ) ) $product->set_regular_price( $data['regular_price'] );
            if ( isset( $data['sale_price'] ) )    $product->set_sale_price( $data['sale_price'] );
            if ( isset( $data['stock_quantity'] ) ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( (int) $data['stock_quantity'] );
                $product->set_stock_status( (int) $data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
            }
            $product->save();
        }

        // Aggiorna varianti
        if ( $product->is_type( 'variable' ) && ! empty( $data['variations'] ) ) {
            foreach ( $data['variations'] as $var_data ) {
                $var_sku = $var_data['sku'] ?? '';
                if ( ! $var_sku ) continue;

                $var_id = wc_get_product_id_by_sku( $var_sku );
                if ( $var_id ) {
                    $v = wc_get_product( $var_id );
                    if ( $v && $v->is_type( 'variation' ) ) {
                        if ( isset( $var_data['regular_price'] ) ) $v->set_regular_price( $var_data['regular_price'] );
                        if ( isset( $var_data['sale_price'] ) )    $v->set_sale_price( $var_data['sale_price'] );
                        if ( isset( $var_data['stock_quantity'] ) ) {
                            $v->set_manage_stock( true );
                            $v->set_stock_quantity( (int) $var_data['stock_quantity'] );
                            $v->set_stock_status( (int) $var_data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
                        }
                        $v->save();
                    }
                } else {
                    // Nuova variante: crea
                    $v = new WC_Product_Variation();
                    $v->set_parent_id( $product_id );
                    $attrs = [];
                    foreach ( $var_data['attributes'] ?? [] as $key => $val ) {
                        $attrs[ str_starts_with( $key, 'attribute_' ) ? $key : 'attribute_' . $key ] = $val;
                    }
                    $v->set_attributes( $attrs );
                    if ( $var_sku )                             $v->set_sku( $var_sku );
                    if ( isset( $var_data['regular_price'] ) )  $v->set_regular_price( $var_data['regular_price'] );
                    if ( isset( $var_data['sale_price'] ) )     $v->set_sale_price( $var_data['sale_price'] );
                    if ( isset( $var_data['manage_stock'] ) )   $v->set_manage_stock( $var_data['manage_stock'] );
                    if ( isset( $var_data['stock_quantity'] ) )  $v->set_stock_quantity( (int) $var_data['stock_quantity'] );
                    if ( isset( $var_data['stock_status'] ) )   $v->set_stock_status( $var_data['stock_status'] );
                    $v->set_status( 'publish' );
                    $v->save();
                }
            }
            WC_Product_Variable::sync( $product_id );
        }

        return [
            'action'  => 'updated',
            'id'      => $product_id,
            'sku'     => $data['sku'] ?? '',
            'name'    => $data['name'],
            'changes' => $data['_changes'] ?? [],
        ];
    } catch ( \Exception $e ) {
        return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => $e->getMessage() ];
    }
}

// ── Brand / Model helpers ───────────────────────────────────

/**
 * Parsa il nome modello dal nome prodotto, rimuovendo brand e colorway.
 *
 * Es: "Nike Air Max 97 Triple White Wolf Grey" → "Air Max 97"
 *
 * @param string $name  Nome completo del prodotto.
 * @param string $brand Nome del brand.
 * @return string Nome del modello o stringa vuota.
 */
function rp_rc_gs_parse_model( string $name, string $brand ): string {

    // Rimuovi brand dal nome
    $clean = $name;
    if ( $brand && stripos( $clean, $brand ) === 0 ) {
        $clean = trim( substr( $clean, strlen( $brand ) ) );
    }

    // Patterns noti per i modelli sneaker
    $model_patterns = [
        '/^(Air\s+Jordan\s+\d+)/i',
        '/^(Air\s+Max\s+\d+\w*)/i',
        '/^(Air\s+Force\s+\d+)/i',
        '/^(Dunk\s+(?:Low|High|Mid))/i',
        '/^(Cortez)/i',
        '/^(Blazer\s+(?:Low|Mid))/i',
        '/^(Yeezy\s+(?:Boost\s+)?\d+\s*\w*)/i',
        '/^(Campus\s+\d+\w*)/i',
        '/^(Samba\s+\w+)/i',
        '/^(Forum\s+(?:Low|Mid|High))/i',
        '/^(Gazelle\s*\w*)/i',
        '/^(NMD\s+\w+)/i',
        '/^(Ultra\s*Boost\s*\w*)/i',
        '/^(New\s+Balance\s+\d+\w*)/i',
        '/^(\d{3,4}\w*)/i',  // Modelli numerici (574, 550, 2002R, etc.)
    ];

    foreach ( $model_patterns as $pattern ) {
        if ( preg_match( $pattern, $clean, $m ) ) {
            return trim( $m[1] );
        }
    }

    // Fallback: prendi le prime 2-3 parole (prima di una parola tutta minuscola o colorway)
    $words = explode( ' ', $clean );
    $model_words = [];
    foreach ( $words as $w ) {
        // Stop alla prima parola che sembra una colorway
        if ( count( $model_words ) >= 2 && preg_match( '/^[a-z]/', $w ) ) break;
        if ( count( $model_words ) >= 4 ) break;
        $model_words[] = $w;
    }

    return implode( ' ', $model_words );
}

/**
 * Assegna brand e modello come termini product_brand.
 *
 * @param int    $product_id ID del prodotto WC.
 * @param string $brand      Nome del brand (es. "Nike").
 * @param string $model      Nome del modello (es. "Air Max 97"), child of brand.
 */
function rp_rc_gs_assign_brand( int $product_id, string $brand, string $model = '' ): void {

    $taxonomy = 'product_brand';

    // Verifica che la tassonomia esista
    if ( ! taxonomy_exists( $taxonomy ) ) return;

    // Crea/trova il brand parent
    $brand_term = term_exists( $brand, $taxonomy );
    if ( ! $brand_term ) {
        $brand_term = wp_insert_term( $brand, $taxonomy );
    }
    if ( is_wp_error( $brand_term ) ) return;
    $brand_id = is_array( $brand_term ) ? $brand_term['term_id'] : $brand_term;

    $term_ids = [ (int) $brand_id ];

    // Crea/trova il modello come child del brand
    if ( $model ) {
        $model_term = term_exists( $model, $taxonomy, $brand_id );
        if ( ! $model_term ) {
            $model_term = wp_insert_term( $model, $taxonomy, [ 'parent' => (int) $brand_id ] );
        }
        if ( ! is_wp_error( $model_term ) ) {
            $model_id = is_array( $model_term ) ? $model_term['term_id'] : $model_term;
            $term_ids[] = (int) $model_id;
        }
    }

    wp_set_object_terms( $product_id, $term_ids, $taxonomy );
}

/**
 * Assigns product category: 'Sneakers' or 'Abbigliamento'.
 * Creates the term if it doesn't exist.
 *
 * @param int    $product_id
 * @param string $category   'sneakers' or 'abbigliamento'
 */
function rp_rc_gs_assign_category( int $product_id, string $category ): void {
    $labels = [
        'sneakers'      => 'Sneakers',
        'abbigliamento' => 'Abbigliamento',
    ];
    $name = $labels[ $category ] ?? ucfirst( $category );

    $term = get_term_by( 'slug', sanitize_title( $category ), 'product_cat' );
    if ( ! $term ) {
        $result = wp_insert_term( $name, 'product_cat', [ 'slug' => sanitize_title( $category ) ] );
        $term_id = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
    } else {
        $term_id = (int) $term->term_id;
    }

    if ( $term_id ) {
        wp_set_object_terms( $product_id, [ $term_id ], 'product_cat' );
    }
}

/**
 * Sideload un'immagine da URL e la imposta come featured image.
 *
 * @param int    $product_id ID del prodotto WC.
 * @param string $image_url  URL dell'immagine.
 * @param string $sku        SKU per il naming del file.
 */
function rp_rc_gs_sideload_image( int $product_id, string $image_url, string $sku = '' ): void {
    if ( ! $image_url ) return;
    gh_parallel_sideload_to_product( $product_id, [ $image_url ], $sku, [
        'first_is_featured' => true,
        'rest_is_gallery'   => false,
    ] );
}
