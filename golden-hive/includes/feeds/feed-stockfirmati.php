<?php
/**
 * Feed StockFirmati — fetch, trasformazione e importazione assortimento.
 *
 * Formato CSV pipe-delimited con due tipi di record:
 * - PRODUCT: riga padre con dati prodotto (nome, brand, immagini, categorie)
 * - MODEL:   riga taglia/variante con size, qty, barcode
 *
 * Trasforma il formato StockFirmati in prodotti WooCommerce:
 * - PRODUCT + MODEL raggruppati per SKU → variable product con varianti
 * - PRODUCT senza MODEL → simple product
 * - STREET_PRICE → regular_price (prezzo barrato)
 * - PRICE × moltiplicatore → sale_price (prezzo vendita)
 * - Brand → product_brand taxonomy
 * - CAT/SUBCAT → product_cat taxonomy
 * - Immagini sideload da CDN StockFirmati
 * - Sesso, materiale, colore, stagione → attributi/meta
 */

defined( 'ABSPATH' ) || exit;

/**
 * Moltiplicatore sul costo all'ingrosso (PRICE) per calcolare il prezzo di vendita.
 * Es: PRICE=24.89, moltiplicatore=3.5 → sale_price=87.12
 * Modifica questo valore per cambiare il ricarico.
 */
const GH_SF_PRICE_MULTIPLIER = 3.5;

/** Tag applicato a tutti i prodotti importati da SF. */
const GH_SF_TAG_SLUG = 'stockfirmati';
const GH_SF_TAG_NAME = 'Stock Firmati';

// ── Normalize ──────────────────────────────────────────────

/**
 * Normalizza le righe CSV StockFirmati (pipe-delimited) raggruppando
 * PRODUCT + MODEL in prodotti strutturati.
 *
 * @param array $rows Righe CSV parsate (array di assoc arrays).
 * @return array Prodotti normalizzati con sizes[].
 */
function gh_sf_normalize( array $rows ): array {

    $products = [];  // Keyed by SKU

    // Primo pass: raccogli PRODUCT rows
    foreach ( $rows as $row ) {
        $type = strtoupper( trim( $row['RECORD_TYPE'] ?? '' ) );
        if ( $type !== 'PRODUCT' ) continue;

        $sku = trim( $row['SKU'] ?? $row['ORDERCODE'] ?? '' );
        if ( ! $sku ) continue;

        $products[ $sku ] = [
            'sku'               => $sku,
            'ordercode'         => trim( $row['ORDERCODE'] ?? $sku ),
            'product_id'        => trim( $row['PRODUCT_ID'] ?? '' ),
            'brand'             => gh_sf_clean( $row['BRAND'] ?? '' ),
            'model_name'        => gh_sf_clean( $row['MODEL_NAME'] ?? '' ),
            'name'              => gh_sf_clean( $row['Titel_ITA'] ?? '' ),
            'description'       => gh_sf_clean( $row['Description_ITA'] ?? '' ),
            'name_en'           => gh_sf_clean( $row['Titel_EN'] ?? '' ),
            'description_en'    => gh_sf_clean( $row['Description_EN'] ?? '' ),
            'street_price'      => (float) ( $row['STREET_PRICE'] ?? 0 ),
            'cost_price'        => (float) ( $row['PRICE'] ?? 0 ),
            'weight'            => (float) ( $row['WEIGHT'] ?? 0 ),
            'total_quantity'    => (int) ( $row['QUANTITY'] ?? 0 ),
            'images'            => array_filter( [
                trim( $row['PICTURE_1'] ?? '' ),
                trim( $row['PICTURE_2'] ?? '' ),
                trim( $row['PICTURE_3'] ?? '' ),
            ] ),
            'sex'               => gh_sf_clean( $row['SEX'] ?? '' ),
            'category'          => gh_sf_clean( $row['CAT'] ?? '' ),
            'subcategory'       => gh_sf_clean( $row['SUBCAT'] ?? '' ),
            'color_code'        => trim( $row['COLOR_CODE'] ?? '' ),
            'color'             => gh_sf_clean( $row['COLOR'] ?? '' ),
            'material'          => gh_sf_clean( $row['MATERIAL'] ?? '' ),
            'made_in'           => trim( $row['MADE_IN'] ?? '' ),
            'season'            => trim( $row['STAGIONE'] ?? '' ),
            'source_url'        => trim( $row['Product_url'] ?? '' ),
            'parent_code'       => trim( $row['Parent_code'] ?? '' ),
            'sizes'             => [],
        ];
    }

    // Secondo pass: assegna MODEL rows come varianti
    foreach ( $rows as $row ) {
        $type = strtoupper( trim( $row['RECORD_TYPE'] ?? '' ) );
        if ( $type !== 'MODEL' ) continue;

        // Il MODEL ha lo stesso SKU del PRODUCT padre
        $parent_sku = trim( $row['SKU'] ?? '' );
        if ( ! $parent_sku || ! isset( $products[ $parent_sku ] ) ) continue;

        $size = gh_sf_clean( $row['MODEL_SIZE'] ?? '' );
        $qty  = (int) ( $row['QUANTITY'] ?? 0 );

        $products[ $parent_sku ]['sizes'][] = [
            'size'       => $size,
            'quantity'   => $qty,
            'barcode'    => trim( $row['BARCODE'] ?? '' ),
            'ean'        => trim( $row['EAN'] ?? '' ),
            'model_id'   => trim( $row['MODEL_ID'] ?? '' ),
            'price'      => (float) ( $row['PRICE'] ?? $products[ $parent_sku ]['cost_price'] ),
        ];

        // Aggiorna quantità totale dal conteggio reale
        $products[ $parent_sku ]['total_quantity'] = array_sum(
            array_column( $products[ $parent_sku ]['sizes'], 'quantity' )
        );
    }

    return array_values( $products );
}

// ── Transform to WooCommerce ───────────────────────────────

/**
 * Trasforma un prodotto SF normalizzato in formato WooCommerce.
 *
 * @param array $product Prodotto normalizzato.
 * @return array Prodotto nel formato product-factory.
 */
function gh_sf_transform_to_woo( array $product ): array {

    $sizes     = $product['sizes'] ?? [];
    $has_sizes = count( $sizes ) > 0;
    $type      = $has_sizes ? 'variable' : 'simple';

    $street_price = $product['street_price'];
    $cost_price   = $product['cost_price'];

    // Calcolo prezzi:
    // regular_price = STREET_PRICE (prezzo barrato originale)
    // sale_price = PRICE × moltiplicatore (prezzo di vendita)
    $sale_price = round( $cost_price * GH_SF_PRICE_MULTIPLIER );
    $reg_price  = round( $street_price );

    // Se sale_price >= regular_price, usa solo regular_price (no sconto finto)
    if ( $sale_price >= $reg_price ) {
        $reg_price  = $sale_price;
        $sale_price = 0;
    }

    // Nome: usa titolo ITA se disponibile, altrimenti componi da brand + model
    $name = $product['name'] ?: ( $product['brand'] . ' ' . $product['model_name'] );

    $woo = [
        'name'              => $name,
        'sku'               => $product['sku'],
        'type'              => $type,
        'status'            => 'publish',
        'description'       => $product['description'],
        'weight'            => $product['weight'] > 0 ? (string) $product['weight'] : '',
        // Campi custom per post-processing
        '_sf_brand'         => $product['brand'],
        '_sf_category'      => $product['category'],
        '_sf_subcategory'   => $product['subcategory'],
        '_sf_sex'           => $product['sex'],
        '_sf_color'         => $product['color'],
        '_sf_material'      => $product['material'],
        '_sf_made_in'       => $product['made_in'],
        '_sf_season'        => $product['season'],
        '_sf_images'        => $product['images'],
        '_sf_source_url'    => $product['source_url'],
        '_sf_cost_price'    => $cost_price,
    ];

    if ( $type === 'simple' ) {
        $woo['regular_price']  = (string) $reg_price;
        $woo['sale_price']     = $sale_price > 0 ? (string) $sale_price : '';
        $woo['manage_stock']   = true;
        $woo['stock_quantity'] = $product['total_quantity'];
        $woo['stock_status']   = $product['total_quantity'] > 0 ? 'instock' : 'outofstock';
    } else {
        $all_sizes = array_column( $sizes, 'size' );

        $woo['attributes'] = [
            'pa_taglia' => [
                'options'   => array_values( array_unique( $all_sizes ) ),
                'visible'   => true,
                'variation' => true,
            ],
        ];

        $variations = [];
        foreach ( $sizes as $size ) {
            $var_cost       = $size['price'] ?: $cost_price;
            $var_sale_price = round( $var_cost * GH_SF_PRICE_MULTIPLIER );
            $var_reg_price  = round( $street_price );

            if ( $var_sale_price >= $var_reg_price ) {
                $var_reg_price  = $var_sale_price;
                $var_sale_price = 0;
            }

            $var_sku = $product['sku'] . '-' . sanitize_title( $size['size'] );
            $qty     = $size['quantity'];

            $variations[] = [
                'attributes'     => [ 'pa_taglia' => $size['size'] ],
                'sku'            => $var_sku,
                'regular_price'  => (string) $var_reg_price,
                'sale_price'     => $var_sale_price > 0 ? (string) $var_sale_price : '',
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
 * Trasforma l'intero feed normalizzato.
 */
function gh_sf_transform_all( array $products ): array {
    return array_map( 'gh_sf_transform_to_woo', $products );
}

// ── Diff ───────────────────────────────────────────────────

/**
 * Confronta prodotti SF trasformati con WooCommerce.
 * Riusa la logica generica gh_csv_diff() da feed-csv.php.
 *
 * @param array $woo_products Output di gh_sf_transform_all().
 * @return array { new[], update[], unchanged[], summary{} }
 */
function gh_sf_diff( array $woo_products ): array {
    return gh_csv_diff( $woo_products );
}

// ── Apply ──────────────────────────────────────────────────

/**
 * Applica i prodotti SF a WooCommerce.
 *
 * @param array $diff    Output di gh_sf_diff().
 * @param array $options { create_new, update_existing, sideload_images }
 * @return array Risultato.
 */
function gh_sf_apply( array $diff, array $options = [], array $tax_map = [] ): array {

    $create_new      = $options['create_new'] ?? true;
    $update_existing = $options['update_existing'] ?? true;
    $sideload        = $options['sideload_images'] ?? true;

    $results = [];

    if ( $create_new && ! empty( $diff['new'] ) ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['new'],
            fn( $p ) => gh_sf_create_product( $p, $sideload, $tax_map )
        ) );
    }

    if ( $update_existing && ! empty( $diff['update'] ) ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['update'],
            fn( $p ) => gh_sf_update_product( $p )
        ) );
    }

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    return [
        'summary' => compact( 'created', 'updated', 'errors' ),
        'details' => $results,
    ];
}

/**
 * Crea un nuovo prodotto WC da dati SF.
 */
function gh_sf_create_product( array $data, bool $sideload = true, array $tax_map = [] ): array {

    try {
        $type = $data['type'] ?? 'simple';

        $product_id = $type === 'variable'
            ? gh_create_variable_product( $data )
            : gh_create_simple_product( $data );

        // Brand → product_brand taxonomy (use cached map if available)
        if ( ! empty( $data['_sf_brand'] ) ) {
            $cached_brand = $tax_map['brands'][ $data['_sf_brand'] ] ?? null;
            if ( $cached_brand ) {
                wp_set_object_terms( $product_id, [ $cached_brand ], 'product_brand' );
            } else {
                gh_sf_assign_brand( $product_id, $data['_sf_brand'] );
            }
        }

        // Categoria → product_cat (use cached map if available)
        if ( ! empty( $data['_sf_category'] ) ) {
            $cached_cat = $tax_map['categories'][ $data['_sf_category'] ] ?? null;
            $sub_key    = $data['_sf_category'] . '>' . ( $data['_sf_subcategory'] ?? '' );
            $cached_sub = $tax_map['subcategories'][ $sub_key ] ?? null;
            if ( $cached_cat ) {
                $ids = [ $cached_cat ];
                if ( $cached_sub ) $ids[] = $cached_sub;
                wp_set_object_terms( $product_id, $ids, 'product_cat' );
            } else {
                gh_sf_assign_category( $product_id, $data['_sf_category'], $data['_sf_subcategory'] ?? '' );
            }
        }

        // Tag stockfirmati + stagione
        $tags = [ GH_SF_TAG_SLUG ];
        if ( ! empty( $data['_sf_season'] ) ) {
            $tags[] = gh_sf_season_tag( $data['_sf_season'] );
        }
        wp_set_object_terms( $product_id, $tags, 'product_tag', true );

        // Attributi extra come meta
        if ( ! empty( $data['_sf_color'] ) )      update_post_meta( $product_id, '_sf_color', $data['_sf_color'] );
        if ( ! empty( $data['_sf_material'] ) )    update_post_meta( $product_id, '_sf_material', $data['_sf_material'] );
        if ( ! empty( $data['_sf_made_in'] ) )     update_post_meta( $product_id, '_sf_made_in', $data['_sf_made_in'] );
        if ( ! empty( $data['_sf_sex'] ) )         update_post_meta( $product_id, '_sf_sex', $data['_sf_sex'] );
        if ( ! empty( $data['_sf_cost_price'] ) )  update_post_meta( $product_id, '_sf_cost_price', $data['_sf_cost_price'] );
        if ( ! empty( $data['_sf_source_url'] ) )  update_post_meta( $product_id, '_sf_source_url', $data['_sf_source_url'] );

        // Provenance meta
        update_post_meta( $product_id, '_gh_import_source', 'stockfirmati' );
        update_post_meta( $product_id, '_gh_import_date', current_time( 'mysql' ) );

        // Images: prefer pre-imported media map, fallback to sideload
        if ( ! empty( $data['_sf_images'] ) ) {
            $resolved = gh_preimport_resolve_urls( $data['_sf_images'] );
            if ( ! empty( $resolved ) ) {
                gh_preimport_assign_images( $product_id, $data['_sf_images'] );
            } elseif ( $sideload ) {
                gh_sf_sideload_images( $product_id, $data['_sf_images'], $data['sku'] ?? '' );
            }
        }

        return [
            'action' => 'created',
            'id'     => $product_id,
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'],
        ];
    } catch ( \Throwable $e ) {
        return [
            'action' => 'error',
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'] ?? '?',
            'reason' => $e->getMessage(),
        ];
    }
}

/**
 * Aggiorna un prodotto WC esistente con dati SF (prezzi + stock).
 */
function gh_sf_update_product( array $data ): array {
    // Delega all'updater generico del CSV pipeline
    return gh_csv_update_product( $data );
}

// ── Taxonomy helpers ───────────────────────────────────────

/**
 * Assegna brand come termine product_brand.
 */
function gh_sf_assign_brand( int $product_id, string $brand ): void {
    $taxonomy = 'product_brand';
    if ( ! taxonomy_exists( $taxonomy ) ) return;

    $term = term_exists( $brand, $taxonomy );
    if ( ! $term ) {
        $term = wp_insert_term( $brand, $taxonomy );
    }
    if ( is_wp_error( $term ) ) return;

    $term_id = is_array( $term ) ? $term['term_id'] : $term;
    wp_set_object_terms( $product_id, [ (int) $term_id ], $taxonomy );
}

/**
 * Assegna categoria e sottocategoria come product_cat.
 */
function gh_sf_assign_category( int $product_id, string $category, string $subcategory = '' ): void {
    $taxonomy = 'product_cat';

    $cat_term = term_exists( $category, $taxonomy );
    if ( ! $cat_term ) {
        $cat_term = wp_insert_term( $category, $taxonomy );
    }
    if ( is_wp_error( $cat_term ) ) return;
    $cat_id = is_array( $cat_term ) ? $cat_term['term_id'] : $cat_term;

    $term_ids = [ (int) $cat_id ];

    if ( $subcategory ) {
        $sub_term = term_exists( $subcategory, $taxonomy, $cat_id );
        if ( ! $sub_term ) {
            $sub_term = wp_insert_term( $subcategory, $taxonomy, [ 'parent' => (int) $cat_id ] );
        }
        if ( ! is_wp_error( $sub_term ) ) {
            $sub_id = is_array( $sub_term ) ? $sub_term['term_id'] : $sub_term;
            $term_ids[] = (int) $sub_id;
        }
    }

    wp_set_object_terms( $product_id, $term_ids, $taxonomy );
}

/**
 * Converte codice stagione in tag leggibile.
 */
function gh_sf_season_tag( string $code ): string {
    return match ( strtoupper( $code ) ) {
        'AI' => 'autunno-inverno',
        'PE' => 'primavera-estate',
        'TS' => 'continuativo',
        default => strtolower( $code ),
    };
}

// ── Image sideload ─────────────────────────────────────────

/**
 * Sideload immagini: prima → featured, resto → gallery.
 */
function gh_sf_sideload_images( int $product_id, array $image_urls, string $sku = '' ): void {
    gh_parallel_sideload_to_product( $product_id, $image_urls, $sku );
}

// ── Clean helpers ──────────────────────────────────────────

/**
 * Pulisce un valore CSV: rimuove quotes, trim, decode HTML entities.
 */
function gh_sf_clean( string $value ): string {
    $value = trim( $value, " \t\n\r\0\x0B\"'" );
    $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    return trim( $value );
}
