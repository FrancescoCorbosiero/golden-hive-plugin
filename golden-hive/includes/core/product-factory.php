<?php
/**
 * Product Factory — creazione/aggiornamento unificato prodotti WooCommerce.
 * Usato da: bulk importer, roundtrip importer, GS feed, qualsiasi modulo che crea prodotti.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crea un prodotto simple da un array di dati.
 *
 * @param array $data Dati del prodotto.
 * @return int Product ID.
 */
function gh_create_simple_product( array $data ): int {

    $product = new WC_Product_Simple();
    gh_apply_product_fields( $product, $data );

    if ( ! empty( $data['attributes'] ) ) {
        $product->set_attributes( gh_build_wc_attributes( $data['attributes'] ) );
    }

    $product_id = $product->save();

    gh_apply_product_meta( $product_id, $data );
    gh_attach_attribute_terms( $product_id, $data['attributes'] ?? [] );

    return $product_id;
}

/**
 * Crea un prodotto variable con attributi e varianti.
 *
 * @param array $data Dati del prodotto con 'attributes' e 'variations'.
 * @return int Product ID.
 */
function gh_create_variable_product( array $data ): int {

    $product = new WC_Product_Variable();
    gh_apply_product_fields( $product, $data );

    if ( ! empty( $data['attributes'] ) ) {
        $product->set_attributes( gh_build_wc_attributes( $data['attributes'] ) );
    }

    $product_id = $product->save();
    gh_apply_product_meta( $product_id, $data );
    gh_attach_attribute_terms( $product_id, $data['attributes'] ?? [] );

    // Crea varianti
    foreach ( $data['variations'] ?? [] as $var_data ) {
        gh_create_variation( $product_id, $var_data );
    }

    WC_Product_Variable::sync( $product_id );
    gh_fix_variable_stock_status( $product_id );

    return $product_id;
}

/**
 * Ensures a variable product's stock status matches its variations.
 *
 * WC_Product_Variable::sync() sometimes leaves the parent with a stale
 * stock_status (especially when variations are created in the same request).
 * This does a direct check: if ANY child has stock, parent must be instock.
 *
 * @param int $product_id
 */
function gh_fix_variable_stock_status( int $product_id ): void {
    global $wpdb;

    $has_instock = $wpdb->get_var( $wpdb->prepare(
        "SELECT 1 FROM {$wpdb->postmeta}
         WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation')
         AND meta_key = '_stock_status' AND meta_value = 'instock' LIMIT 1",
        $product_id
    ) );

    $correct_status = $has_instock ? 'instock' : 'outofstock';
    $current_status = get_post_meta( $product_id, '_stock_status', true );

    if ( $current_status !== $correct_status ) {
        update_post_meta( $product_id, '_stock_status', $correct_status );

        // Update the WC lookup table so frontend queries see the correct status
        if ( function_exists( 'wc_update_product_lookup_tables_column' ) ) {
            wc_update_product_lookup_tables_column( 'stock_status', [ $product_id ] );
        }
    }
}

/**
 * Crea una singola variante sotto un prodotto padre.
 *
 * @param int   $parent_id ID del prodotto padre.
 * @param array $data      Dati della variante.
 * @return int Variation ID.
 */
function gh_create_variation( int $parent_id, array $data ): int {

    $v = new WC_Product_Variation();
    $v->set_parent_id( $parent_id );

    // Attributi — for taxonomy attributes, WooCommerce expects the term slug
    $attrs = [];
    foreach ( $data['attributes'] ?? [] as $key => $val ) {
        $attr_key = str_starts_with( $key, 'attribute_' ) ? $key : 'attribute_' . $key;
        $taxonomy = str_replace( 'attribute_', '', $attr_key );

        if ( taxonomy_exists( $taxonomy ) ) {
            // Ensure term exists and use its slug
            $term = get_term_by( 'name', $val, $taxonomy );
            if ( ! $term ) {
                $term = get_term_by( 'slug', sanitize_title( $val ), $taxonomy );
            }
            if ( ! $term ) {
                $inserted = wp_insert_term( $val, $taxonomy );
                if ( ! is_wp_error( $inserted ) ) {
                    $term = get_term( $inserted['term_id'], $taxonomy );
                }
            }
            $attrs[ $attr_key ] = $term ? $term->slug : sanitize_title( $val );
        } else {
            $attrs[ $attr_key ] = $val;
        }
    }
    $v->set_attributes( $attrs );

    if ( ! empty( $data['sku'] ) )            $v->set_sku( $data['sku'] );
    if ( isset( $data['regular_price'] ) )     $v->set_regular_price( $data['regular_price'] );
    if ( isset( $data['sale_price'] ) )        $v->set_sale_price( $data['sale_price'] );
    if ( isset( $data['weight'] ) )            $v->set_weight( $data['weight'] );

    $v->set_status( $data['status'] ?? 'publish' );

    $manage = $data['manage_stock'] ?? false;
    $v->set_manage_stock( $manage );
    if ( $manage && isset( $data['stock_quantity'] ) ) {
        $v->set_stock_quantity( (int) $data['stock_quantity'] );
    }
    $v->set_stock_status( $data['stock_status'] ?? 'instock' );

    return $v->save();
}

/**
 * Applica i campi comuni a un oggetto WC_Product.
 *
 * @param WC_Product $product
 * @param array      $data
 */
function gh_apply_product_fields( WC_Product $product, array $data ): void {

    if ( isset( $data['name'] ) )              $product->set_name( $data['name'] );
    if ( isset( $data['sku'] ) )               $product->set_sku( $data['sku'] );
    if ( isset( $data['slug'] ) )              $product->set_slug( $data['slug'] );
    if ( isset( $data['regular_price'] ) )     $product->set_regular_price( $data['regular_price'] );
    if ( isset( $data['sale_price'] ) )        $product->set_sale_price( $data['sale_price'] );
    if ( isset( $data['description'] ) )       $product->set_description( $data['description'] );
    if ( isset( $data['short_description'] ) ) $product->set_short_description( $data['short_description'] );
    if ( isset( $data['weight'] ) )            $product->set_weight( $data['weight'] );

    $product->set_status( $data['status'] ?? 'publish' );

    $manage = $data['manage_stock'] ?? false;
    $product->set_manage_stock( $manage );
    if ( $manage && isset( $data['stock_quantity'] ) ) {
        $product->set_stock_quantity( (int) $data['stock_quantity'] );
    }
    $product->set_stock_status( $data['stock_status'] ?? 'instock' );
}

/**
 * Applica categorie, tag e meta SEO dopo il save.
 *
 * @param int   $product_id
 * @param array $data
 */
function gh_apply_product_meta( int $product_id, array $data ): void {

    if ( ! empty( $data['category_ids'] ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $data['category_ids'] ), 'product_cat' );
    }
    if ( ! empty( $data['tag_ids'] ) ) {
        wp_set_object_terms( $product_id, array_map( 'intval', $data['tag_ids'] ), 'product_tag' );
    }
    if ( ! empty( $data['meta_title'] ) ) {
        update_post_meta( $product_id, 'rank_math_title', sanitize_text_field( $data['meta_title'] ) );
    }
    if ( ! empty( $data['meta_description'] ) ) {
        update_post_meta( $product_id, 'rank_math_description', sanitize_text_field( $data['meta_description'] ) );
    }
    if ( ! empty( $data['focus_keyword'] ) ) {
        update_post_meta( $product_id, 'rank_math_focus_keyword', sanitize_text_field( $data['focus_keyword'] ) );
    }
}

/**
 * Costruisce oggetti WC_Product_Attribute dal formato JSON.
 *
 * @param array $attrs_json { "pa_taglia": { "options": [...], "visible": true, "variation": true } }
 * @return WC_Product_Attribute[]
 */
function gh_build_wc_attributes( array $attrs_json ): array {

    $wc_attrs = [];
    $position = 0;

    foreach ( $attrs_json as $name => $config ) {
        $attr = new WC_Product_Attribute();

        $tax_id = wc_attribute_taxonomy_id_by_name( $name );

        // Auto-register attribute taxonomy if it doesn't exist
        if ( ! $tax_id && str_starts_with( $name, 'pa_' ) ) {
            $tax_id = gh_ensure_attribute_taxonomy( $name );
        }

        if ( $tax_id ) {
            $attr->set_id( $tax_id );
            $attr->set_name( $name );

            // Pre-resolve option NAMES → term IDs before set_options.
            // WC's WC_Product_Attribute::set_options() on a taxonomy
            // attribute calls wp_parse_id_list() which absint's every
            // option — '40.5' becomes 40, '42' stays 42 but now refers
            // to whichever term has the global term_id 42 (likely not
            // a pa_taglia term at all). The downstream save() then
            // calls wp_set_object_terms() with the absint'd "IDs",
            // either attaching the wrong term (if 42 exists somewhere
            // else) or dropping it silently → parent's pa_taglia ends
            // up missing every integer-named size and any decimal that
            // doesn't happen to collide with an existing term ID.
            //
            // Resolving names → real term_ids here means set_options
            // receives proper IDs, wp_parse_id_list becomes a no-op,
            // and WC's save() writes term_relationships for the actual
            // terms (decimals included). Idempotent: re-running with
            // the same names produces the same term IDs.
            $resolved_term_ids = [];
            foreach ( $config['options'] ?? [] as $opt ) {
                if ( is_int( $opt ) ) {
                    $resolved_term_ids[] = $opt;
                    continue;
                }
                $val = trim( (string) $opt );
                if ( $val === '' ) continue;
                $term = get_term_by( 'name', $val, $name );
                if ( ! $term ) {
                    $term = get_term_by( 'slug', sanitize_title( $val ), $name );
                }
                if ( ! $term ) {
                    $inserted = wp_insert_term( $val, $name );
                    if ( is_wp_error( $inserted ) ) continue;
                    $resolved_term_ids[] = (int) $inserted['term_id'];
                } else {
                    $resolved_term_ids[] = (int) $term->term_id;
                }
            }
            $attr->set_options( $resolved_term_ids );
        } else {
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

/**
 * Attach term assignments to the product for every taxonomy attribute
 * declared in $attrs_json. Belt-and-suspenders alongside WC's data
 * store: when a taxonomy is registered in the same request as the
 * product save, WC's `WC_Product_Attribute::get_terms()` lookup can
 * return an empty array (term/taxonomy cache is stale), which makes
 * WC's `wp_set_object_terms` call no-op and the attribute appears
 * "empty" on the product even though the option_labels are stored.
 *
 * Symptom this fixes: imported products show only `pa_taglia` (which
 * also gets attached via the variation create loop) while other
 * attributes (`pa_brand`, `pa_marchio`, ...) are silently empty —
 * breaking advanced front-end filters that depend on
 * `wp_get_object_terms( $pid, 'pa_brand' )`.
 *
 * Idempotent: safe to re-run on update. Uses `append=false` semantics
 * so re-imports converge to whatever the source currently declares.
 *
 * @param int   $product_id
 * @param array $attrs_json { "pa_brand": { "options": [...], ... } }
 */
function gh_attach_attribute_terms( int $product_id, array $attrs_json ): void {
    if ( $product_id <= 0 || empty( $attrs_json ) ) return;

    foreach ( $attrs_json as $tax_name => $config ) {
        if ( ! is_string( $tax_name ) || ! str_starts_with( $tax_name, 'pa_' ) ) continue;

        // Variation-defining attributes (pa_taglia for sneakers) get
        // their terms attached implicitly via the variation create
        // loop — skipping here would leave them in place. We still
        // run the assignment so the parent product carries the full
        // term set even when a variation step is missing/skipped.
        $options = $config['options'] ?? [];
        if ( ! is_array( $options ) || empty( $options ) ) continue;

        if ( ! taxonomy_exists( $tax_name ) ) continue;

        // Resolve each option to a term ID. NOTE: numeric STRINGS
        // ("40", "42") are NAMES not term IDs — feeds carry size
        // names, not WP term IDs. Treating them as IDs (the old
        // ctype_digit shortcut) silently mis-attached: term ID 42
        // in pa_taglia rarely exists, or if it does it points to
        // an unrelated term. Result: integer-named sizes from the
        // feed (40, 42, 44, 45, 46…) never landed on the parent,
        // while decimal-named sizes (40.5, 41.5…) did — the exact
        // pattern users see as "8 of 10 sizes attached, the 4
        // integer ones are missing → variations show as Qualsiasi
        // Taglia". Only an actual PHP int counts as a term ID;
        // callers can still pass ints explicitly to bypass the
        // name lookup.
        $term_ids = [];
        foreach ( $options as $opt ) {
            if ( is_int( $opt ) ) {
                $term_ids[] = $opt;
                continue;
            }
            $name = trim( (string) $opt );
            if ( $name === '' ) continue;

            $term = get_term_by( 'name', $name, $tax_name );
            if ( ! $term ) {
                $term = get_term_by( 'slug', sanitize_title( $name ), $tax_name );
            }
            if ( ! $term ) {
                $inserted = wp_insert_term( $name, $tax_name );
                if ( is_wp_error( $inserted ) ) continue;
                $term_ids[] = (int) $inserted['term_id'];
            } else {
                $term_ids[] = (int) $term->term_id;
            }
        }

        if ( ! empty( $term_ids ) ) {
            wp_set_object_terms( $product_id, $term_ids, $tax_name, false );
        }
    }
}

/**
 * Wipes a variable product's variation set, pa_* term assignments, and
 * _product_attributes meta — leaving the parent post (and its ID, SKU,
 * brand, category, gallery) intact. A subsequent update call then
 * writes the full shape from scratch, exactly the same way a first
 * create would.
 *
 * Use case: force-recreate. The operator hit "Forza ricreazione" because
 * a regular re-sync didn't converge — the parent's pa_taglia options
 * drifted from the feed, variations show as "Qualsiasi Taglia", and
 * the self-heal path didn't land. This reset zeroes the corrupt parts
 * deterministically so the follow-up write isn't fighting stale state.
 *
 * Doesn't touch (so historical context isn't lost):
 *   - The parent post itself (preserving the WC product ID — historical
 *     orders + permalinks stay valid).
 *   - product_brand, product_cat, product_tag (the feed re-asserts
 *     these on every sync anyway, so wiping them buys nothing).
 *   - Featured image / gallery (media re-sideload is the expensive
 *     half of a sync; the URL→attachment map already keeps these
 *     correct via skip-if-set semantics).
 *   - Non-pa_* postmeta the operator may have set manually.
 *
 * Idempotent: re-running on an already-reset product is a no-op (no
 * children to delete, no terms to clear, no meta to drop).
 *
 * @param int $product_id
 */
function gh_reset_variable_product_state( int $product_id ): void {
    if ( $product_id <= 0 ) return;

    // 1. Delete variation children. force=true bypasses trash so the
    // update path's SKU lookup (wc_get_product_id_by_sku) doesn't
    // resurrect a trashed variation and overwrite the freshly-created
    // one — the duplicate-SKU corner case that re-imports of force-
    // recreated products would otherwise hit.
    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
    if ( $product && method_exists( $product, 'get_children' ) ) {
        foreach ( (array) $product->get_children() as $vid ) {
            wp_delete_post( (int) $vid, true );
        }
    }

    // 2. Clear pa_* term assignments. Walks every taxonomy currently
    // attached to the product (not just well-known pa_taglia) so
    // any non-standard pa_* slot the operator added is also wiped.
    // wp_set_object_terms with [] + append=false drops the term-
    // relationships row.
    $object_taxonomies = get_object_taxonomies( 'product' );
    foreach ( $object_taxonomies as $tax ) {
        if ( ! is_string( $tax ) ) continue;
        if ( ! str_starts_with( $tax, 'pa_' ) ) continue;
        wp_set_object_terms( $product_id, [], $tax, false );
    }

    // 3. Drop the _product_attributes meta entirely. The next
    // set_attributes() call writes a fresh meta blob without
    // inheriting stale options.
    delete_post_meta( $product_id, '_product_attributes' );

    // 4. Reset stock/price aggregation meta so WC doesn't show a
    // stale "X in stock" while the new variations are still being
    // written. Parent stock/price get recomputed by
    // WC_Product_Variable::sync() at the end of the update path.
    delete_post_meta( $product_id, '_stock' );
    delete_post_meta( $product_id, '_price' );
    delete_post_meta( $product_id, '_min_variation_price' );
    delete_post_meta( $product_id, '_max_variation_price' );
    delete_post_meta( $product_id, '_min_variation_regular_price' );
    delete_post_meta( $product_id, '_max_variation_regular_price' );
    delete_post_meta( $product_id, '_min_variation_sale_price' );
    delete_post_meta( $product_id, '_max_variation_sale_price' );

    // 5. Drop caches so the next wc_get_product() reload reflects
    // the cleared state, not the stale in-process snapshot.
    clean_post_cache( $product_id );
    wp_cache_delete( $product_id, 'posts' );
    wp_cache_delete( $product_id, 'post_meta' );
    if ( function_exists( 'wc_delete_product_transients' ) ) {
        wc_delete_product_transients( $product_id );
    }
}

/**
 * Ensures a WooCommerce attribute taxonomy exists, creating it if needed.
 *
 * @param string $taxonomy_name Taxonomy name (e.g. "pa_taglia").
 * @return int Attribute taxonomy ID, or 0 on failure.
 */
function gh_ensure_attribute_taxonomy( string $taxonomy_name ): int {
    // Already registered?
    $existing_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
    if ( $existing_id ) return $existing_id;

    // Derive label from slug: "pa_taglia" → "Taglia"
    $slug  = str_replace( 'pa_', '', $taxonomy_name );
    $label = ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );

    $id = wc_create_attribute( [
        'name'         => $label,
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ] );

    if ( is_wp_error( $id ) ) return 0;

    // Register the taxonomy immediately so it's available in the same request
    register_taxonomy( $taxonomy_name, 'product', [
        'labels'       => [ 'name' => $label ],
        'hierarchical' => false,
        'show_ui'      => false,
        'query_var'    => true,
        'rewrite'      => [ 'slug' => $slug ],
    ] );

    return $id;
}
