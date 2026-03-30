<?php
/**
 * Bulk Creator — crea prodotti WooCommerce in massa da JSON.
 * Supporta prodotti simple e variable (con attributi e varianti).
 *
 * Schema documentato in: docs/BULK_IMPORT.md
 * Esempi in: docs/samples/
 */

defined( 'ABSPATH' ) || exit;

// ── Validation ──────────────────────────────────────────────

/**
 * Valida e normalizza il JSON di bulk import.
 *
 * Accetta sia { "products": [...] } che un array diretto [...].
 *
 * @param mixed $data JSON decodificato.
 * @return array|WP_Error Array normalizzato [ 'products' => [...] ] o errore.
 */
function rp_cm_validate_bulk_json( mixed $data ): array|WP_Error {

    // Accetta array diretto
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
            return new WP_Error( 'missing_price', "Prodotto '{$p['name']}': campo 'regular_price' obbligatorio per tipo simple." );
        }
        if ( $type === 'variable' && empty( $p['variations'] ) ) {
            return new WP_Error( 'missing_variations', "Prodotto '{$p['name']}': array 'variations' obbligatorio per tipo variable." );
        }
    }

    return $data;
}

// ── Preview ─────────────────────────────────────────────────

/**
 * Dry-run del bulk import: mostra cosa verra creato/aggiornato.
 *
 * @param array  $data Dati validati.
 * @param string $mode 'create' | 'create_or_update'
 * @return array Preview con summary e details.
 */
function rp_cm_bulk_preview( array $data, string $mode = 'create' ): array {

    $details = [];

    foreach ( $data['products'] as $entry ) {
        $type       = $entry['type'] ?? 'simple';
        $sku        = $entry['sku'] ?? null;
        $existing   = null;
        $action     = 'create';

        if ( $mode === 'create_or_update' && $sku ) {
            $existing_id = wc_get_product_id_by_sku( $sku );
            if ( $existing_id ) {
                $existing = wc_get_product( $existing_id );
                $action   = 'update';
            }
        }

        $var_count = count( $entry['variations'] ?? [] );

        $details[] = [
            'name'           => $entry['name'],
            'sku'            => $sku,
            'type'           => $type,
            'status'         => $entry['status'] ?? 'publish',
            'action'         => $action,
            'existing_id'    => $existing ? $existing->get_id() : null,
            'variation_count' => $var_count,
        ];
    }

    $to_create = count( array_filter( $details, fn( $d ) => $d['action'] === 'create' ) );
    $to_update = count( array_filter( $details, fn( $d ) => $d['action'] === 'update' ) );

    return [
        'summary' => [
            'total'     => count( $details ),
            'to_create' => $to_create,
            'to_update' => $to_update,
        ],
        'details' => $details,
    ];
}

// ── Apply ───────────────────────────────────────────────────

/**
 * Esegue il bulk import: crea/aggiorna prodotti in WooCommerce.
 *
 * @param array  $data Dati validati.
 * @param string $mode 'create' | 'create_or_update'
 * @return array Risultato con summary e details.
 */
function rp_cm_bulk_apply( array $data, string $mode = 'create' ): array {

    $details = [];

    foreach ( $data['products'] as $entry ) {
        $type = $entry['type'] ?? 'simple';
        $sku  = $entry['sku'] ?? null;

        // Check se aggiornare un esistente
        $existing_id = null;
        if ( $mode === 'create_or_update' && $sku ) {
            $existing_id = wc_get_product_id_by_sku( $sku );
        }

        try {
            if ( $existing_id ) {
                $result = rp_cm_bulk_update_product( $existing_id, $entry );
            } elseif ( $type === 'variable' ) {
                $result = rp_cm_bulk_create_variable( $entry );
            } else {
                $result = rp_cm_bulk_create_simple( $entry );
            }
            $details[] = $result;
        } catch ( \Exception $e ) {
            $details[] = [
                'name'   => $entry['name'],
                'sku'    => $sku,
                'type'   => $type,
                'status' => 'error',
                'reason' => $e->getMessage(),
                'id'     => null,
            ];
        }
    }

    $created = count( array_filter( $details, fn( $d ) => $d['status'] === 'created' ) );
    $updated = count( array_filter( $details, fn( $d ) => $d['status'] === 'updated' ) );
    $errors  = count( array_filter( $details, fn( $d ) => $d['status'] === 'error' ) );

    return [
        'summary' => [
            'total'   => count( $details ),
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
        ],
        'details' => $details,
    ];
}

// ── Create: Simple ──────────────────────────────────────────

/**
 * Crea un prodotto simple da un'entry JSON.
 *
 * @param array $entry Dati del prodotto.
 * @return array Risultato.
 */
function rp_cm_bulk_create_simple( array $entry ): array {

    $product = new WC_Product_Simple();
    rp_cm_bulk_set_common_fields( $product, $entry );
    $product_id = $product->save();

    rp_cm_bulk_set_taxonomies_and_meta( $product_id, $entry );

    return [
        'name'            => $entry['name'],
        'sku'             => $entry['sku'] ?? null,
        'type'            => 'simple',
        'status'          => 'created',
        'id'              => $product_id,
        'variation_count' => 0,
    ];
}

// ── Create: Variable ────────────────────────────────────────

/**
 * Crea un prodotto variable con attributi e varianti.
 *
 * @param array $entry Dati del prodotto con attributes e variations.
 * @return array Risultato.
 */
function rp_cm_bulk_create_variable( array $entry ): array {

    $product = new WC_Product_Variable();
    rp_cm_bulk_set_common_fields( $product, $entry );

    // Setup attributi
    $wc_attributes = rp_cm_bulk_build_attributes( $entry['attributes'] ?? [] );
    $product->set_attributes( $wc_attributes );

    $product_id = $product->save();
    rp_cm_bulk_set_taxonomies_and_meta( $product_id, $entry );

    // Crea varianti
    $var_created = 0;
    $var_errors  = [];

    foreach ( $entry['variations'] ?? [] as $var_entry ) {
        try {
            rp_cm_bulk_create_variation( $product_id, $var_entry );
            $var_created++;
        } catch ( \Exception $e ) {
            $var_errors[] = ( $var_entry['sku'] ?? '?' ) . ': ' . $e->getMessage();
        }
    }

    // Sync del prodotto padre dopo tutte le varianti
    WC_Product_Variable::sync( $product_id );

    $result = [
        'name'            => $entry['name'],
        'sku'             => $entry['sku'] ?? null,
        'type'            => 'variable',
        'status'          => 'created',
        'id'              => $product_id,
        'variation_count' => $var_created,
    ];

    if ( $var_errors ) {
        $result['variation_errors'] = $var_errors;
    }

    return $result;
}

/**
 * Crea una singola variante sotto un prodotto padre.
 *
 * @param int   $parent_id ID del prodotto padre.
 * @param array $var_entry Dati della variante.
 * @return int ID della variante creata.
 */
function rp_cm_bulk_create_variation( int $parent_id, array $var_entry ): int {

    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );

    // Attributi della variante
    $attrs = [];
    foreach ( $var_entry['attributes'] ?? [] as $key => $value ) {
        // Normalizza: assicura il formato 'attribute_pa_*'
        $attr_key = str_starts_with( $key, 'attribute_' ) ? $key : 'attribute_' . $key;
        $attrs[ $attr_key ] = $value;
    }
    $variation->set_attributes( $attrs );

    // Campi base
    if ( ! empty( $var_entry['sku'] ) )            $variation->set_sku( $var_entry['sku'] );
    if ( isset( $var_entry['regular_price'] ) )     $variation->set_regular_price( $var_entry['regular_price'] );
    if ( isset( $var_entry['sale_price'] ) )        $variation->set_sale_price( $var_entry['sale_price'] );
    if ( isset( $var_entry['weight'] ) )            $variation->set_weight( $var_entry['weight'] );

    $variation->set_status( $var_entry['status'] ?? 'publish' );

    // Stock
    $manage = $var_entry['manage_stock'] ?? false;
    $variation->set_manage_stock( $manage );
    if ( $manage && isset( $var_entry['stock_quantity'] ) ) {
        $variation->set_stock_quantity( (int) $var_entry['stock_quantity'] );
    }
    $variation->set_stock_status( $var_entry['stock_status'] ?? 'instock' );

    return $variation->save();
}

// ── Update existing ─────────────────────────────────────────

/**
 * Aggiorna un prodotto esistente con i dati dal JSON.
 *
 * @param int   $product_id ID del prodotto WC esistente.
 * @param array $entry      Dati dal JSON.
 * @return array Risultato.
 */
function rp_cm_bulk_update_product( int $product_id, array $entry ): array {

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return [
            'name'   => $entry['name'],
            'sku'    => $entry['sku'] ?? null,
            'type'   => $entry['type'] ?? '?',
            'status' => 'error',
            'reason' => "Prodotto #{$product_id} non trovato",
            'id'     => null,
        ];
    }

    rp_cm_bulk_set_common_fields( $product, $entry );

    // Se variable, aggiorna attributi
    if ( $product->is_type( 'variable' ) && ! empty( $entry['attributes'] ) ) {
        $wc_attributes = rp_cm_bulk_build_attributes( $entry['attributes'] );
        $product->set_attributes( $wc_attributes );
    }

    $product->save();
    rp_cm_bulk_set_taxonomies_and_meta( $product_id, $entry );

    // Aggiorna/crea varianti
    $var_processed = 0;
    if ( $product->is_type( 'variable' ) && ! empty( $entry['variations'] ) ) {
        foreach ( $entry['variations'] as $var_entry ) {
            $var_id = null;
            if ( ! empty( $var_entry['sku'] ) ) {
                $var_id = wc_get_product_id_by_sku( $var_entry['sku'] );
                // Verifica che appartenga a questo parent
                if ( $var_id ) {
                    $v = wc_get_product( $var_id );
                    if ( ! $v || ! $v->is_type( 'variation' ) || $v->get_parent_id() !== $product_id ) {
                        $var_id = null;
                    }
                }
            }

            if ( $var_id ) {
                // Aggiorna variante esistente
                $v = wc_get_product( $var_id );
                if ( isset( $var_entry['regular_price'] ) )  $v->set_regular_price( $var_entry['regular_price'] );
                if ( isset( $var_entry['sale_price'] ) )     $v->set_sale_price( $var_entry['sale_price'] );
                if ( isset( $var_entry['status'] ) )         $v->set_status( $var_entry['status'] );
                if ( isset( $var_entry['weight'] ) )         $v->set_weight( $var_entry['weight'] );
                if ( isset( $var_entry['manage_stock'] ) )   $v->set_manage_stock( $var_entry['manage_stock'] );
                if ( isset( $var_entry['stock_quantity'] ) )  $v->set_stock_quantity( (int) $var_entry['stock_quantity'] );
                if ( isset( $var_entry['stock_status'] ) )   $v->set_stock_status( $var_entry['stock_status'] );
                $v->save();
            } else {
                // Crea nuova variante
                rp_cm_bulk_create_variation( $product_id, $var_entry );
            }
            $var_processed++;
        }
        WC_Product_Variable::sync( $product_id );
    }

    return [
        'name'            => $product->get_name(),
        'sku'             => $product->get_sku(),
        'type'            => $product->get_type(),
        'status'          => 'updated',
        'id'              => $product_id,
        'variation_count' => $var_processed,
    ];
}

// ── Shared helpers ──────────────────────────────────────────

/**
 * Imposta i campi comuni su un oggetto WC_Product.
 *
 * @param WC_Product $product
 * @param array      $entry
 */
function rp_cm_bulk_set_common_fields( WC_Product $product, array $entry ): void {

    if ( isset( $entry['name'] ) )              $product->set_name( $entry['name'] );
    if ( isset( $entry['sku'] ) )               $product->set_sku( $entry['sku'] );
    if ( isset( $entry['slug'] ) )              $product->set_slug( $entry['slug'] );
    if ( isset( $entry['regular_price'] ) )     $product->set_regular_price( $entry['regular_price'] );
    if ( isset( $entry['sale_price'] ) )        $product->set_sale_price( $entry['sale_price'] );
    if ( isset( $entry['description'] ) )       $product->set_description( $entry['description'] );
    if ( isset( $entry['short_description'] ) ) $product->set_short_description( $entry['short_description'] );
    if ( isset( $entry['weight'] ) )            $product->set_weight( $entry['weight'] );

    $product->set_status( $entry['status'] ?? 'publish' );

    // Stock
    $manage = $entry['manage_stock'] ?? false;
    $product->set_manage_stock( $manage );
    if ( $manage && isset( $entry['stock_quantity'] ) ) {
        $product->set_stock_quantity( (int) $entry['stock_quantity'] );
    }
    $product->set_stock_status( $entry['stock_status'] ?? 'instock' );
}

/**
 * Imposta categorie, tag e meta SEO dopo il save del prodotto.
 *
 * @param int   $product_id
 * @param array $entry
 */
function rp_cm_bulk_set_taxonomies_and_meta( int $product_id, array $entry ): void {

    if ( ! empty( $entry['category_ids'] ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $entry['category_ids'] ), 'product_cat' );
    }
    if ( ! empty( $entry['tag_ids'] ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $entry['tag_ids'] ), 'product_tag' );
    }
    if ( ! empty( $entry['meta_title'] ) ) {
        update_post_meta( $product_id, 'rank_math_title', sanitize_text_field( $entry['meta_title'] ) );
    }
    if ( ! empty( $entry['meta_description'] ) ) {
        update_post_meta( $product_id, 'rank_math_description', sanitize_text_field( $entry['meta_description'] ) );
    }
    if ( ! empty( $entry['focus_keyword'] ) ) {
        update_post_meta( $product_id, 'rank_math_focus_keyword', sanitize_text_field( $entry['focus_keyword'] ) );
    }
}

/**
 * Costruisce oggetti WC_Product_Attribute dal formato JSON.
 *
 * @param array $attributes_json { "pa_taglia": { "options": [...], "visible": true, "variation": true } }
 * @return WC_Product_Attribute[]
 */
function rp_cm_bulk_build_attributes( array $attributes_json ): array {

    $wc_attrs = [];
    $position = 0;

    foreach ( $attributes_json as $name => $config ) {
        $attr = new WC_Product_Attribute();

        // Determina se e un attributo globale (pa_*)
        $taxonomy_id = wc_attribute_taxonomy_id_by_name( $name );
        if ( $taxonomy_id ) {
            $attr->set_id( $taxonomy_id );
            $attr->set_name( $name );

            // Assicura che i termini esistano nella tassonomia
            $options = $config['options'] ?? [];
            foreach ( $options as $term_name ) {
                if ( ! term_exists( $term_name, $name ) ) {
                    wp_insert_term( $term_name, $name );
                }
            }
            $attr->set_options( $options );
        } else {
            // Attributo custom (non tassonomia)
            $attr->set_id( 0 );
            $attr->set_name( $name );
            $attr->set_options( $config['options'] ?? [] );
        }

        $attr->set_visible( $config['visible'] ?? true );
        $attr->set_variation( $config['variation'] ?? true );
        $attr->set_position( $position++ );

        $wc_attrs[] = $attr;
    }

    return $wc_attrs;
}
