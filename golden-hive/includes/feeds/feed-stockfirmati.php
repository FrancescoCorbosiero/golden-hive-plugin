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
    // sale_price = PRICE × moltiplicatore (prezzo di vendita)
    // regular_price = STREET_PRICE (pass-through dal CSV)
    $sale_price = round( $cost_price * GH_SF_PRICE_MULTIPLIER );
    $reg_price  = round( $street_price );

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

        // Provenance meta (legacy — preservato per backward compat)
        update_post_meta( $product_id, '_gh_import_source', 'stockfirmati' );
        update_post_meta( $product_id, '_gh_import_date', current_time( 'mysql' ) );

        // Provenance multi-source (nuovo, usato dal conflict engine)
        if ( function_exists( 'gh_conflict_record_source' ) ) {
            gh_conflict_record_source( $product_id, 'stockfirmati', [
                'catalog' => 'stockfirmati',
                'pricing' => 'stockfirmati',
                'stock'   => 'stockfirmati',
                'media'   => 'stockfirmati',
            ] );
        }

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
        if ( gh_is_duplicate_sku_error( $e ) && ! empty( $data['sku'] ) ) {
            $existing_id = wc_get_product_id_by_sku( $data['sku'] );
            if ( $existing_id ) {
                $data['_existing_id'] = $existing_id;
                return gh_sf_update_product( $data );
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
 * Aggiorna un prodotto WC esistente con dati SF (prezzi + stock).
 *
 * Idempotente: la stessa $data applicata N volte produce lo stesso
 * stato. Per i variable: matcha le varianti esistenti per SKU
 * (deterministico: <parent-sku>-<size-slug>), aggiorna prezzi/stock,
 * crea le nuove taglie del feed, e azzera lo stock di varianti
 * non più presenti nel feed (preservando l'ID per non rompere
 * referenze a ordini storici). Mirror diretto di
 * rp_rc_gs_update_product nel feed GS.
 *
 * Storia: in precedenza delegava a gh_csv_update_product, che
 * tocca SOLO i campi del parent — mai le varianti. Conseguenza:
 * le varianti SF restavano congelate al primo import (con prezzi
 * e stock potenzialmente errati o stantii) per sempre.
 */
function gh_sf_update_product( array $data ): array {

    $product_id = $data['_existing_id'] ?? 0;
    if ( ! $product_id ) {
        return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => 'ID mancante' ];
    }

    try {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => 'Prodotto non trovato' ];
        }

        // Re-attach attribute terms (handles pa_brand/pa_taglia
        // backfill on prodotti creati prima del fix-attribute-attach).
        if ( ! empty( $data['attributes'] ) && function_exists( 'gh_attach_attribute_terms' ) ) {
            gh_attach_attribute_terms( $product_id, $data['attributes'] );
        }

        // Refresh parent's `_product_attributes` meta so the options
        // list tracks the current feed sizes — mirrors the GS bridge
        // fix. Without this, sizes added upstream after the first
        // import become variations BUT the parent's pa_taglia options
        // list stays at first-import state, leaving the new variations
        // as "Qualsiasi Taglia" (orphan) in admin and invisible on
        // the storefront dropdown.
        if ( $product->is_type( 'variable' ) && ! empty( $data['attributes'] ) && function_exists( 'gh_build_wc_attributes' ) ) {
            $product->set_attributes( gh_build_wc_attributes( $data['attributes'] ) );
            $product->save();
        }

        // Parent fields — sempre aggiornabili a prescindere dal type
        if ( isset( $data['name'] ) )              $product->set_name( $data['name'] );
        if ( isset( $data['description'] ) )       $product->set_description( $data['description'] );
        if ( isset( $data['short_description'] ) ) $product->set_short_description( $data['short_description'] );
        if ( isset( $data['weight'] ) )            $product->set_weight( $data['weight'] );
        // Sync status when the source-config carries it. Mirrors the
        // operator's mental model: flipping import_status from 'draft'
        // to 'publish' on the source-config and re-syncing should
        // promote the existing products too. Manual edits made in
        // WC admin lose to the next sync — that's the trade-off, and
        // the conflict-rules layer is where to add finer protection.
        if ( isset( $data['status'] ) )            $product->set_status( $data['status'] );

        if ( $product->is_type( 'simple' ) ) {
            // Simple path: identica al gh_csv_update_product di sempre.
            if ( isset( $data['regular_price'] ) ) $product->set_regular_price( $data['regular_price'] );
            if ( isset( $data['sale_price'] ) )    $product->set_sale_price( $data['sale_price'] );
            if ( isset( $data['stock_quantity'] ) ) {
                $qty = (int) $data['stock_quantity'];
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $qty );
                $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            } elseif ( isset( $data['stock_status'] ) ) {
                $product->set_stock_status( $data['stock_status'] );
            }
            $product->save();
        } elseif ( $product->is_type( 'variable' ) && ! empty( $data['variations'] ) ) {
            // Variable path: il parent NON gestisce stock direttamente
            // (Woo aggrega dalle varianti). Salviamo i campi parent
            // prima di toccare le varianti.
            $product->set_manage_stock( false );
            $product->save();

            $seen_skus = [];
            foreach ( $data['variations'] as $var_data ) {
                $var_sku = (string) ( $var_data['sku'] ?? '' );
                if ( $var_sku === '' ) continue;
                $seen_skus[ $var_sku ] = true;

                $var_id = wc_get_product_id_by_sku( $var_sku );
                if ( $var_id ) {
                    $v = wc_get_product( $var_id );
                    if ( ! $v || ! $v->is_type( 'variation' ) ) continue;
                    if ( isset( $var_data['regular_price'] ) ) $v->set_regular_price( $var_data['regular_price'] );
                    if ( isset( $var_data['sale_price'] ) )    $v->set_sale_price( $var_data['sale_price'] );
                    if ( isset( $var_data['stock_quantity'] ) ) {
                        $qty = (int) $var_data['stock_quantity'];
                        $v->set_manage_stock( true );
                        $v->set_stock_quantity( $qty );
                        $v->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                    } elseif ( isset( $var_data['stock_status'] ) ) {
                        $v->set_stock_status( $var_data['stock_status'] );
                    }
                    if ( isset( $var_data['status'] ) ) $v->set_status( $var_data['status'] );
                    $v->save();
                } else {
                    // Nuova taglia nel feed → crea la variante
                    if ( function_exists( 'gh_create_variation' ) ) {
                        gh_create_variation( $product_id, $var_data );
                    }
                }
            }

            // Varianti che non sono più nel feed → azzera stock invece
            // di eliminarle. Preserva l'ID e non rompe ordini storici.
            // Effetto idempotente: stessa rimozione applicata più volte
            // converge nello stesso stato (qty=0, OOS).
            foreach ( $product->get_children() as $existing_var_id ) {
                $existing_var = wc_get_product( $existing_var_id );
                if ( ! $existing_var ) continue;
                $existing_sku = (string) $existing_var->get_sku();
                if ( $existing_sku === '' || isset( $seen_skus[ $existing_sku ] ) ) continue;
                $existing_var->set_manage_stock( true );
                $existing_var->set_stock_quantity( 0 );
                $existing_var->set_stock_status( 'outofstock' );
                $existing_var->save();
            }

            // Ricalcola il rollup parent (price range + stock_status
            // + lookup tables). Senza sync il front-end mostra
            // prezzo/stock stantii anche dopo un update riuscito.
            WC_Product_Variable::sync( $product_id );
            if ( function_exists( 'gh_fix_variable_stock_status' ) ) {
                gh_fix_variable_stock_status( $product_id );
            }
        } else {
            // Né simple né variable con variazioni → solo i campi
            // parent (già applicati sopra).
            $product->save();
        }

        gh_apply_product_meta( $product_id, $data );

        // Categoria + brand: il bridge è l'OWNER della tassonomia SF su
        // OGNI sync (create E update), esattamente come per il brand —
        // si matcha/risolve il valore del feed contro product_cat /
        // product_brand e lo si assegna. Senza questo l'update non
        // ri-applicava mai la categoria, e — peggio — il `category_ids`
        // "flat" prodotto da taxonomy.resolve dentro gh_apply_product_meta
        // (sopra) sovrascriveva la gerarchia cat > subcat impostata alla
        // creazione, lasciando i prodotti con la sola categoria di primo
        // livello (o senza, sui path adottati via update-only). Chiamato
        // DOPO gh_apply_product_meta così la gerarchia vince sempre.
        // Idempotente: wp_set_object_terms con lo stesso set è un no-op.
        if ( ! empty( $data['_sf_category'] ) ) {
            gh_sf_assign_category( $product_id, $data['_sf_category'], $data['_sf_subcategory'] ?? '' );
        }
        if ( ! empty( $data['_sf_brand'] ) ) {
            gh_sf_assign_brand( $product_id, $data['_sf_brand'] );
        }

        return [
            'action'  => 'updated',
            'id'      => $product_id,
            'sku'     => $data['sku'] ?? '',
            'name'    => $data['name'] ?? '',
            'changes' => $data['_changes'] ?? [],
        ];
    } catch ( \Throwable $e ) {
        return [ 'action' => 'error', 'sku' => $data['sku'] ?? '', 'name' => $data['name'] ?? '', 'reason' => $e->getMessage() ];
    }
}

/**
 * Force-recreate path for SF. Mirror of rp_rc_gs_force_recreate_product —
 * resets the existing variable product's children + pa_* terms +
 * _product_attributes meta, then delegates to gh_sf_update_product so
 * the full SF transform writes a fresh shape. See the GS variant for
 * the full rationale.
 *
 * @param array $data
 * @param bool  $sideload   Only honored on the create-fallback branch.
 * @return array
 */
function gh_sf_force_recreate_product( array $data, bool $sideload = true ): array {

    $sku        = (string) ( $data['sku'] ?? '' );
    $product_id = (int) ( $data['_existing_id'] ?? 0 );

    if ( $product_id === 0 && $sku !== '' && function_exists( 'wc_get_product_id_by_sku' ) ) {
        $product_id = (int) wc_get_product_id_by_sku( $sku );
    }

    if ( $product_id === 0 ) {
        return gh_sf_create_product( $data, $sideload );
    }

    try {
        if ( function_exists( 'gh_reset_variable_product_state' ) ) {
            gh_reset_variable_product_state( $product_id );
        }

        $data['_existing_id'] = $product_id;
        $result = gh_sf_update_product( $data );

        if ( ($result['action'] ?? '') === 'updated' ) {
            $result['action'] = 'recreated';
        }
        return $result;
    } catch ( \Throwable $e ) {
        return [
            'action' => 'error',
            'sku'    => $sku,
            'name'   => $data['name'] ?? '?',
            'reason' => $e->getMessage(),
        ];
    }
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
 * Assegna categoria e (opzionale) sottocategoria come product_cat
 * gerarchico (cat > subcat). Match per nome SOTTO il parent corretto,
 * crea il termine se manca. Stesso schema del brand, diversa tassonomia.
 */
function gh_sf_assign_category( int $product_id, string $category, string $subcategory = '' ): void {
    $taxonomy = 'product_cat';

    $cat_id = gh_sf_resolve_term( $category, $taxonomy, 0 );
    if ( ! $cat_id ) return;

    $term_ids = [ $cat_id ];

    if ( trim( $subcategory ) !== '' ) {
        $sub_id = gh_sf_resolve_term( $subcategory, $taxonomy, $cat_id );
        if ( $sub_id ) $term_ids[] = $sub_id;
    }

    wp_set_object_terms( $product_id, $term_ids, $taxonomy );
}

/**
 * Risolve un termine di una tassonomia gerarchica per NOME sotto un
 * parent specifico, creandolo se manca. Ritorna il term_id o 0.
 *
 * Robusto contro due gotcha che lasciavano i prodotti senza categoria:
 *  - match per parent: due categorie con lo stesso nome sotto parent
 *    diversi (es. "Accessori") non collidono più — si riusa quella
 *    giusta invece di creare duplicati o sbagliare ramo.
 *  - recovery su WP_Error 'term_exists': se wp_insert_term fallisce per
 *    collisione di slug, si recupera il term_id esistente dai dati
 *    dell'errore invece di abortire silenziosamente (il vecchio
 *    comportamento che lasciava il prodotto senza categoria).
 */
function gh_sf_resolve_term( string $name, string $taxonomy, int $parent = 0 ): int {
    $name = trim( $name );
    if ( $name === '' || ! taxonomy_exists( $taxonomy ) ) return 0;

    // Prefer an existing term with this exact name under this exact parent.
    $existing = get_terms( [
        'taxonomy'   => $taxonomy,
        'name'       => $name,
        'parent'     => $parent,
        'hide_empty' => false,
        'number'     => 1,
        'fields'     => 'ids',
    ] );
    if ( is_array( $existing ) && ! empty( $existing ) ) {
        return (int) $existing[0];
    }

    $inserted = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent ] );
    if ( is_wp_error( $inserted ) ) {
        // Slug/name collision → recover the pre-existing term id WP
        // attached to the error rather than dropping the assignment.
        $err = $inserted->get_error_data();
        if ( is_array( $err ) && ! empty( $err['term_id'] ) ) return (int) $err['term_id'];
        if ( is_numeric( $err ) ) return (int) $err;
        return 0;
    }
    return (int) ( $inserted['term_id'] ?? 0 );
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
