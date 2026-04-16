<?php
/**
 * Feed Config Engine — interprets JSON config files to normalize,
 * transform, and import CSV/pipe feeds into WooCommerce.
 *
 * Drop a .json config in feeds/configs/ and the engine handles everything:
 * normalize rows (parent/variant grouping), transform fields,
 * diff against WC, create/update products.
 *
 * This replaces the need for dedicated PHP feed files per supplier.
 */

defined( 'ABSPATH' ) || exit;

/** Directory containing JSON config files. */
define( 'GH_FEED_CONFIGS_DIR', GH_DIR . 'includes/feeds/configs/' );

// ── Config CRUD ───────────────────────────────────────────

/**
 * Lists all available feed config files.
 *
 * @return array[] [ { id, name, version, file } ]
 */
function gh_fc_list_configs(): array {
    $configs = [];
    $dir     = GH_FEED_CONFIGS_DIR;

    if ( ! is_dir( $dir ) ) return $configs;

    foreach ( glob( $dir . '*.json' ) as $file ) {
        $json = json_decode( file_get_contents( $file ), true );
        if ( ! $json || empty( $json['id'] ) ) continue;

        $configs[] = [
            'id'      => $json['id'],
            'name'    => $json['name'] ?? basename( $file, '.json' ),
            'version' => $json['version'] ?? '1.0',
            'file'    => basename( $file ),
        ];
    }

    return $configs;
}

/**
 * Loads a config by ID.
 *
 * @param string $config_id
 * @return array|null Parsed config or null.
 */
function gh_fc_load_config( string $config_id ): ?array {
    $dir = GH_FEED_CONFIGS_DIR;

    // Try direct file match first
    $file = $dir . $config_id . '.json';
    if ( file_exists( $file ) ) {
        $json = json_decode( file_get_contents( $file ), true );
        if ( $json && ( $json['id'] ?? '' ) === $config_id ) return $json;
    }

    // Scan all files for matching id
    foreach ( glob( $dir . '*.json' ) as $f ) {
        $json = json_decode( file_get_contents( $f ), true );
        if ( $json && ( $json['id'] ?? '' ) === $config_id ) return $json;
    }

    return null;
}

// ── Normalize ─────────────────────────────────────────────

/**
 * Normalizes CSV rows according to a config: groups parent + variant rows
 * into structured products with sizes[].
 *
 * @param array $rows   Parsed CSV rows.
 * @param array $config Feed config.
 * @return array Normalized products.
 */
function gh_fc_normalize( array $rows, array $config ): array {

    $row_types = $config['row_types'] ?? [];

    // If no row types, treat every row as a flat product (no variants)
    if ( empty( $row_types['enabled'] ) ) {
        return gh_fc_normalize_flat( $rows, $config );
    }

    return gh_fc_normalize_grouped( $rows, $config );
}

/**
 * Flat normalization: every row = one simple product.
 */
function gh_fc_normalize_flat( array $rows, array $config ): array {
    $products = [];
    $pc       = $config['product'] ?? [];
    $sku_col  = $pc['sku'] ?? 'sku';

    foreach ( $rows as $row ) {
        $sku = trim( $row[ $sku_col ] ?? '' );
        if ( ! $sku ) continue;

        $products[] = [
            'sku'   => $sku,
            'row'   => $row,
            'sizes' => [],
        ];
    }

    return $products;
}

/**
 * Grouped normalization: parent rows (PRODUCT) + variant rows (MODEL).
 */
function gh_fc_normalize_grouped( array $rows, array $config ): array {
    $rt          = $config['row_types'];
    $type_col    = $rt['column'] ?? 'RECORD_TYPE';
    $parent_type = strtoupper( $rt['parent_type'] ?? 'PRODUCT' );
    $variant_type = strtoupper( $rt['variant_type'] ?? 'MODEL' );
    $link_col    = $rt['link_column'] ?? 'SKU';

    $vc          = $config['variations'] ?? [];
    $size_col    = $vc['size_column'] ?? 'MODEL_SIZE';
    $qty_col     = $vc['quantity_column'] ?? 'QUANTITY';
    $extra       = $vc['extra_fields'] ?? [];

    $products = [];

    // Pass 1: collect parents
    foreach ( $rows as $row ) {
        $type = strtoupper( trim( $row[ $type_col ] ?? '' ) );
        if ( $type !== $parent_type ) continue;

        $link_val = trim( $row[ $link_col ] ?? '' );
        if ( ! $link_val ) continue;

        $products[ $link_val ] = [
            'sku'   => $link_val,
            'row'   => $row,
            'sizes' => [],
        ];
    }

    // Pass 2: attach variants
    foreach ( $rows as $row ) {
        $type = strtoupper( trim( $row[ $type_col ] ?? '' ) );
        if ( $type !== $variant_type ) continue;

        $parent_key = trim( $row[ $link_col ] ?? '' );
        if ( ! $parent_key || ! isset( $products[ $parent_key ] ) ) continue;

        $size_entry = [
            'size'     => gh_fc_clean( $row[ $size_col ] ?? '' ),
            'quantity' => (int) ( $row[ $qty_col ] ?? 0 ),
        ];

        // Extra variant fields (barcode, ean, etc.)
        foreach ( $extra as $key => $col ) {
            $size_entry[ $key ] = trim( $row[ $col ] ?? '' );
        }

        // Variant-level price if per_variation_price is set
        if ( ! empty( $vc['per_variation_price'] ) ) {
            $price_cfg = $config['product']['sale_price'] ?? null;
            if ( $price_cfg && is_array( $price_cfg ) && ! empty( $price_cfg['column'] ) ) {
                $size_entry['_raw_price'] = (float) ( $row[ $price_cfg['column'] ] ?? 0 );
            }
        }

        $products[ $parent_key ]['sizes'][] = $size_entry;
    }

    return array_values( $products );
}

// ── Transform ─────────────────────────────────────────────

/**
 * Transforms normalized products into WooCommerce-ready format.
 *
 * @param array $products Normalized products.
 * @param array $config   Feed config.
 * @return array WooCommerce product arrays.
 */
function gh_fc_transform_all( array $products, array $config ): array {
    return array_map( fn( $p ) => gh_fc_transform_one( $p, $config ), $products );
}

/**
 * Transforms a single normalized product.
 */
function gh_fc_transform_one( array $product, array $config ): array {
    $row   = $product['row'];
    $sizes = $product['sizes'] ?? [];
    $pc    = $config['product'] ?? [];
    $vc    = $config['variations'] ?? [];
    $tc    = $config['taxonomy'] ?? [];
    $ic    = $config['images'] ?? [];
    $mc    = $config['meta'] ?? [];

    // Determine product type: variable only if multiple sizes or a single non-universal size
    $uni_sizes  = [ 'uni', 'unica', 'tu', 'os', 'one size', 'onesize', '' ];
    $real_sizes = array_filter( $sizes, fn( $s ) => ! in_array( strtolower( trim( $s['size'] ) ), $uni_sizes, true ) );
    $has_sizes  = count( $real_sizes ) > 0;
    $type       = $has_sizes ? 'variable' : 'simple';

    // For simple products with a UNI size, use that row's quantity
    if ( ! $has_sizes && count( $sizes ) > 0 ) {
        $sizes = [];  // Treat as simple — qty comes from the UNI row or parent
    }

    // Resolve product fields
    $name = gh_fc_resolve_field( $pc['name'] ?? '', $row );
    $sku  = trim( $row[ $pc['sku'] ?? 'SKU' ] ?? $product['sku'] );

    $reg_price  = gh_fc_resolve_price( $pc['regular_price'] ?? null, $row );
    $sale_price = gh_fc_resolve_price( $pc['sale_price'] ?? null, $row );

    // Price logic: ensure regular > sale so a discount is always visible.
    // If sale >= regular (markup exceeds street price), derive regular from sale.
    $price_logic = $pc['price_logic'] ?? '';
    if ( $price_logic === 'sale_below_regular' && $sale_price > 0 ) {
        $min_regular = round( $sale_price * 1.3 );
        if ( $reg_price <= $sale_price ) {
            $reg_price = $min_regular;
        } else {
            $reg_price = max( $reg_price, $min_regular );
        }
    }

    $woo = [
        'name'        => $name ?: $sku,
        'sku'         => $sku,
        'type'        => $type,
        'status'      => gh_fc_resolve_field( $pc['status'] ?? 'publish', $row ),
        'description' => gh_fc_clean( $row[ $pc['description'] ?? '' ] ?? '' ),
        'weight'      => gh_fc_resolve_numeric( $pc['weight'] ?? '', $row ),
    ];

    // Clean empty values
    if ( ! $woo['weight'] ) unset( $woo['weight'] );
    if ( ! $woo['description'] ) unset( $woo['description'] );

    if ( $type === 'simple' ) {
        $woo['regular_price']  = $reg_price > 0 ? (string) $reg_price : '';
        $woo['sale_price']     = $sale_price > 0 ? (string) $sale_price : '';
        $woo['manage_stock']   = true;
        // Use total from original sizes (incl. UNI) if available, else from parent row
        $orig_sizes = $product['sizes'] ?? [];
        $total_qty  = $orig_sizes
            ? array_sum( array_column( $orig_sizes, 'quantity' ) )
            : (int) ( $row[ $vc['quantity_column'] ?? 'QUANTITY' ] ?? 0 );
        $woo['stock_quantity'] = $total_qty;
        $woo['stock_status']   = $total_qty > 0 ? 'instock' : 'outofstock';
    } else {
        $attr_name = $vc['attribute'] ?? 'pa_taglia';
        $all_sizes = array_unique( array_column( $sizes, 'size' ) );

        $woo['attributes'] = [
            $attr_name => [
                'options'   => array_values( $all_sizes ),
                'visible'   => true,
                'variation' => true,
            ],
        ];

        $suffix_tpl = $vc['sku_suffix'] ?? '-{size_slug}';
        $variations = [];

        foreach ( $sizes as $sz ) {
            $var_reg  = $reg_price;
            $var_sale = $sale_price;

            // Per-variation price
            if ( ! empty( $vc['per_variation_price'] ) && isset( $sz['_raw_price'] ) && $sz['_raw_price'] > 0 ) {
                $var_sale = gh_fc_apply_transforms( $sz['_raw_price'], $pc['sale_price']['transforms'] ?? [] );
                if ( $price_logic === 'sale_below_regular' && $var_sale > 0 ) {
                    $min_reg = round( $var_sale * 1.3 );
                    if ( $var_reg <= $var_sale ) {
                        $var_reg = $min_reg;
                    } else {
                        $var_reg = max( $var_reg, $min_reg );
                    }
                }
            }

            $size_slug = sanitize_title( $sz['size'] );
            $var_sku   = str_replace( '{size_slug}', $size_slug, str_replace( '{size}', $sz['size'], $suffix_tpl ) );
            $var_sku   = $sku . $var_sku;
            $qty       = $sz['quantity'];

            $variations[] = [
                'attributes'     => [ $attr_name => $sz['size'] ],
                'sku'            => $var_sku,
                'regular_price'  => $var_reg > 0 ? (string) $var_reg : '',
                'sale_price'     => $var_sale > 0 ? (string) $var_sale : '',
                'manage_stock'   => true,
                'stock_quantity' => $qty,
                'stock_status'   => $qty > 0 ? 'instock' : 'outofstock',
                'status'         => 'publish',
            ];
        }

        $woo['variations'] = $variations;
    }

    // Taxonomy
    if ( ! empty( $tc['brand'] ) ) {
        $woo['_fc_brand'] = gh_fc_clean( $row[ $tc['brand']['column'] ?? '' ] ?? '' );
        $woo['_fc_brand_taxonomy'] = $tc['brand']['target'] ?? 'product_brand';
    }

    if ( ! empty( $tc['category'] ) ) {
        $woo['_fc_category']        = gh_fc_clean( $row[ $tc['category']['parent_column'] ?? '' ] ?? '' );
        $woo['_fc_subcategory']     = gh_fc_clean( $row[ $tc['category']['child_column'] ?? '' ] ?? '' );
        $woo['_fc_category_target'] = $tc['category']['target'] ?? 'product_cat';
    }

    if ( ! empty( $tc['tags'] ) ) {
        $tags = [];
        foreach ( $tc['tags'] as $tag_def ) {
            if ( isset( $tag_def['static'] ) ) {
                $tags[] = $tag_def['static'];
            } elseif ( isset( $tag_def['column'] ) ) {
                $raw = trim( $row[ $tag_def['column'] ] ?? '' );
                if ( $raw && isset( $tag_def['lookup'][ $raw ] ) ) {
                    $tags[] = $tag_def['lookup'][ $raw ];
                } elseif ( $raw ) {
                    $tags[] = sanitize_title( $raw );
                }
            }
        }
        $woo['_fc_tags'] = $tags;
    }

    // Images
    if ( ! empty( $ic['columns'] ) ) {
        $urls = [];
        foreach ( $ic['columns'] as $col ) {
            $url = trim( $row[ $col ] ?? '' );
            if ( $url ) $urls[] = $url;
        }
        $woo['_fc_images'] = $urls;
        $woo['_fc_images_cfg'] = $ic;
    }

    // Meta
    if ( ! empty( $mc ) ) {
        $meta = [];
        foreach ( $mc as $meta_key => $meta_def ) {
            if ( is_array( $meta_def ) && isset( $meta_def['static'] ) ) {
                $meta[ $meta_key ] = $meta_def['static'];
            } elseif ( is_string( $meta_def ) ) {
                $val = gh_fc_clean( $row[ $meta_def ] ?? '' );
                if ( $val !== '' ) $meta[ $meta_key ] = $val;
            }
        }
        $woo['_fc_meta'] = $meta;
    }

    // Provenance: config ID as source identifier
    $woo['_gh_import_source']  = 'config';
    $woo['_gh_import_feed_id'] = $config['id'] ?? '';

    return $woo;
}

// ── Apply (post-processing after product creation) ─────────

/**
 * Creates a product from config-transformed data and applies
 * taxonomy, images, and meta post-processing.
 *
 * @param array $data     WooCommerce product data.
 * @param bool  $sideload Whether to sideload images.
 * @return array Result.
 */
function gh_fc_create_product( array $data, bool $sideload = true, array $tax_map = [] ): array {
    try {
        $type = $data['type'] ?? 'simple';
        $product_id = $type === 'variable'
            ? gh_create_variable_product( $data )
            : gh_create_simple_product( $data );

        gh_fc_post_process( $product_id, $data, $sideload, $tax_map );

        return [
            'action' => 'created',
            'id'     => $product_id,
            'sku'    => $data['sku'] ?? '',
            'name'   => $data['name'] ?? '',
        ];
    } catch ( \Throwable $e ) {
        // Duplicate SKU recovery: if creation failed because SKU exists, update instead
        if ( gh_is_duplicate_sku_error( $e ) && ! empty( $data['sku'] ) ) {
            $existing_id = wc_get_product_id_by_sku( $data['sku'] );
            if ( $existing_id ) {
                $data['_existing_id'] = $existing_id;
                $update_result = gh_csv_update_product( $data );
                if ( $update_result['action'] === 'updated' ) {
                    gh_fc_post_process( $existing_id, $data, $sideload, $tax_map );
                    $update_result['action'] = 'updated';
                    $update_result['_recovered'] = true;
                }
                return $update_result;
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
 * Checks if an exception is a duplicate SKU error.
 *
 * @param \Throwable $e
 * @return bool
 */
function gh_is_duplicate_sku_error( \Throwable $e ): bool {
    $msg = strtolower( $e->getMessage() );
    return str_contains( $msg, 'sku' ) && ( str_contains( $msg, 'duplicat' ) || str_contains( $msg, 'unique' ) || str_contains( $msg, 'already' ) );
}

/**
 * Post-process: taxonomy, tags, images, meta.
 *
 * @param int   $product_id
 * @param array $data
 * @param bool  $sideload
 * @param array $tax_map Pre-resolved taxonomy map from gh_fc_prepare_taxonomies().
 */
function gh_fc_post_process( int $product_id, array $data, bool $sideload = true, array $tax_map = [] ): void {

    // Brand taxonomy
    if ( ! empty( $data['_fc_brand'] ) ) {
        $tax = $data['_fc_brand_taxonomy'] ?? 'product_brand';
        if ( taxonomy_exists( $tax ) ) {
            $cached_id = $tax_map['brands'][ $data['_fc_brand'] ] ?? null;
            if ( $cached_id ) {
                wp_set_object_terms( $product_id, [ $cached_id ], $tax );
            } else {
                $term = term_exists( $data['_fc_brand'], $tax );
                if ( ! $term ) $term = wp_insert_term( $data['_fc_brand'], $tax );
                if ( ! is_wp_error( $term ) ) {
                    wp_set_object_terms( $product_id, [ (int) ( is_array( $term ) ? $term['term_id'] : $term ) ], $tax );
                }
            }
        }
    }

    // Category taxonomy
    if ( ! empty( $data['_fc_category'] ) ) {
        $tax     = $data['_fc_category_target'] ?? 'product_cat';
        $cat_id  = $tax_map['categories'][ $data['_fc_category'] ] ?? null;
        $sub_key = $data['_fc_category'] . '>' . ( $data['_fc_subcategory'] ?? '' );
        $sub_id  = $tax_map['subcategories'][ $sub_key ] ?? null;

        if ( $cat_id ) {
            $term_ids = [ $cat_id ];
            if ( $sub_id ) $term_ids[] = $sub_id;
            wp_set_object_terms( $product_id, $term_ids, $tax );
        } else {
            $cat_term = term_exists( $data['_fc_category'], $tax );
            if ( ! $cat_term ) $cat_term = wp_insert_term( $data['_fc_category'], $tax );
            if ( ! is_wp_error( $cat_term ) ) {
                $cid = (int) ( is_array( $cat_term ) ? $cat_term['term_id'] : $cat_term );
                $term_ids = [ $cid ];
                if ( ! empty( $data['_fc_subcategory'] ) ) {
                    $sub = term_exists( $data['_fc_subcategory'], $tax, $cid );
                    if ( ! $sub ) $sub = wp_insert_term( $data['_fc_subcategory'], $tax, [ 'parent' => $cid ] );
                    if ( ! is_wp_error( $sub ) ) $term_ids[] = (int) ( is_array( $sub ) ? $sub['term_id'] : $sub );
                }
                wp_set_object_terms( $product_id, $term_ids, $tax );
            }
        }
    }

    // Tags
    if ( ! empty( $data['_fc_tags'] ) ) {
        $tag_ids = [];
        foreach ( $data['_fc_tags'] as $tag ) {
            if ( isset( $tax_map['tags'][ $tag ] ) ) {
                $tag_ids[] = $tax_map['tags'][ $tag ];
            } else {
                $tag_ids[] = $tag;
            }
        }
        wp_set_object_terms( $product_id, $tag_ids, 'product_tag', true );
    }

    // Meta
    if ( ! empty( $data['_fc_meta'] ) ) {
        foreach ( $data['_fc_meta'] as $key => $val ) {
            update_post_meta( $product_id, $key, sanitize_text_field( $val ) );
        }
    }

    // Provenance meta
    update_post_meta( $product_id, '_gh_import_source', $data['_gh_import_source'] ?? 'config' );
    update_post_meta( $product_id, '_gh_import_date', current_time( 'mysql' ) );
    if ( ! empty( $data['_gh_import_feed_id'] ) ) {
        update_post_meta( $product_id, '_gh_import_feed_id', $data['_gh_import_feed_id'] );
    }

    // Images: prefer pre-imported media map, fallback to sideload if explicitly requested
    if ( ! empty( $data['_fc_images'] ) ) {
        $cfg = $data['_fc_images_cfg'] ?? [];
        $resolved = gh_preimport_resolve_urls( $data['_fc_images'] );
        if ( ! empty( $resolved ) ) {
            // Pre-imported: assign directly, no download
            gh_preimport_assign_images( $product_id, $data['_fc_images'], $cfg );
        } elseif ( $sideload ) {
            // Fallback: sideload on-the-fly (legacy behavior)
            gh_fc_sideload_images( $product_id, $data['_fc_images'], $data['sku'] ?? '', $cfg );
        }
    }
}

/**
 * Sideload images: delegates to the shared parallel downloader.
 */
function gh_fc_sideload_images( int $product_id, array $urls, string $sku, array $cfg = [] ): void {
    gh_parallel_sideload_to_product( $product_id, $urls, $sku, $cfg );
}

// ── Full pipeline ─────────────────────────────────────────

/**
 * Runs a complete config-driven import: parse → normalize → transform → diff → apply.
 *
 * @param string $config_id Config ID (matches filename in configs/).
 * @param array  $rows      Parsed CSV rows.
 * @param array  $options   { create_new, update_existing, sideload_images, dry_run }
 * @return array|WP_Error
 */
function gh_fc_run( string $config_id, array $rows, array $options = [] ): array|WP_Error {

    $config = gh_fc_load_config( $config_id );
    if ( ! $config ) {
        return new WP_Error( 'config_not_found', 'Config non trovato: ' . $config_id );
    }

    // 1. Normalize
    $products = gh_fc_normalize( $rows, $config );
    if ( empty( $products ) ) {
        return new WP_Error( 'no_products', 'Nessun prodotto trovato dopo normalizzazione.' );
    }

    // 2. Transform
    $woo_products = gh_fc_transform_all( $products, $config );

    // 3. Diff
    $diff = gh_csv_diff( $woo_products );

    // 4. Dry run?
    if ( ! empty( $options['dry_run'] ) ) {
        return [
            'status'    => 'preview',
            'config'    => $config['name'] ?? $config_id,
            'rows_read' => count( $rows ),
            'products'  => count( $products ),
            'diff'      => $diff,
        ];
    }

    // 5. Apply
    $create   = $options['create_new'] ?? true;
    $update   = $options['update_existing'] ?? true;
    $sideload = $options['sideload_images'] ?? true;
    $results  = [];

    $tax_map = gh_fc_prepare_taxonomies( $woo_products );

    if ( $create ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['new'],
            fn( $p ) => gh_fc_create_product( $p, $sideload, $tax_map )
        ) );
    }
    if ( $update ) {
        $results = array_merge( $results, gh_fc_batch_with_retry(
            $diff['update'],
            fn( $p ) => gh_csv_update_product( $p )
        ) );
    }

    $created = count( array_filter( $results, fn( $r ) => $r['action'] === 'created' ) );
    $updated = count( array_filter( $results, fn( $r ) => $r['action'] === 'updated' ) );
    $errors  = count( array_filter( $results, fn( $r ) => $r['action'] === 'error' ) );

    return [
        'summary' => compact( 'created', 'updated', 'errors' ),
        'details' => $results,
        'rows_read' => count( $rows ),
    ];
}

// ── Field resolution helpers ──────────────────────────────

/**
 * Resolves a field definition to a value.
 *
 * Supports:
 * - string: column name → value from row
 * - { "static": "value" } → literal
 * - { "column": "X", "fallback": "{A} {B}" } → with template fallback
 * - { "column": "X", "transforms": [...] } → with transforms
 */
function gh_fc_resolve_field( mixed $def, array $row ): string {
    if ( is_string( $def ) ) {
        // Could be a column name or a static value like "publish"
        if ( isset( $row[ $def ] ) ) {
            return gh_fc_clean( $row[ $def ] );
        }
        return $def;  // treat as literal
    }

    if ( ! is_array( $def ) ) return '';

    if ( isset( $def['static'] ) ) {
        return (string) $def['static'];
    }

    $col   = $def['column'] ?? '';
    $value = $col ? gh_fc_clean( $row[ $col ] ?? '' ) : '';

    // Fallback with template
    if ( ! $value && isset( $def['fallback'] ) ) {
        $value = preg_replace_callback( '/\{(\w+)\}/', function ( $m ) use ( $row ) {
            return gh_fc_clean( $row[ $m[1] ] ?? '' );
        }, $def['fallback'] );
    }

    // Transforms
    if ( ! empty( $def['transforms'] ) ) {
        $value = gh_fc_apply_transforms( $value, $def['transforms'] );
    }

    return (string) $value;
}

/**
 * Resolves a price field: column + transforms → float.
 */
function gh_fc_resolve_price( mixed $def, array $row ): float {
    if ( ! $def ) return 0;

    if ( is_string( $def ) ) {
        return (float) ( $row[ $def ] ?? 0 );
    }

    if ( ! is_array( $def ) ) return 0;

    $col   = $def['column'] ?? '';
    $value = (float) ( $row[ $col ] ?? 0 );

    if ( ! empty( $def['transforms'] ) ) {
        $value = gh_fc_apply_transforms( $value, $def['transforms'] );
    }

    return (float) $value;
}

/**
 * Resolves a numeric field.
 */
function gh_fc_resolve_numeric( mixed $def, array $row ): float {
    if ( is_string( $def ) ) {
        return (float) ( $row[ $def ] ?? 0 );
    }
    if ( is_array( $def ) && isset( $def['column'] ) ) {
        return (float) ( $row[ $def['column'] ] ?? 0 );
    }
    return 0;
}

/**
 * Applies a chain of transforms.
 */
function gh_fc_apply_transforms( mixed $value, array $transforms ): mixed {
    foreach ( $transforms as $t ) {
        $type  = $t['type'] ?? '';
        $param = $t['value'] ?? '';

        $value = match ( $type ) {
            'multiply'   => round( (float) $value * (float) $param, 4 ),
            'add'        => round( (float) $value + (float) $param, 4 ),
            'round'      => round( (float) $value, max( 0, (int) $param ) ),
            'markup'     => round( (float) $value * ( 1 + (float) $param / 100 ), 4 ),  // markup 30 → ×1.30
            'vat_add'    => round( (float) $value * ( 1 + (float) $param / 100 ), 4 ),  // vat_add 22 → ×1.22
            'percentage' => round( (float) $value * (float) $param / 100, 4 ),           // percentage 130 → ×1.30
            'prefix'     => $param . (string) $value,
            'suffix'     => (string) $value . $param,
            'lowercase'  => mb_strtolower( (string) $value ),
            'uppercase'  => mb_strtoupper( (string) $value ),
            'trim'       => trim( (string) $value ),
            default      => $value,
        };
    }
    return $value;
}

/**
 * Overrides the sale_price multiplier in a config at runtime.
 * Finds the first "multiply" transform in sale_price and replaces its value.
 *
 * @param array $config The feed config.
 * @param float $markup New multiplier value (e.g. 3.5).
 * @return array Modified config.
 */
function gh_fc_override_markup( array $config, float $markup ): array {
    if ( isset( $config['product']['sale_price']['transforms'] ) ) {
        foreach ( $config['product']['sale_price']['transforms'] as $i => $t ) {
            if ( ( $t['type'] ?? '' ) === 'multiply' ) {
                $config['product']['sale_price']['transforms'][ $i ]['value'] = $markup;
                break;
            }
        }
    }
    return $config;
}

// ── Quick patch (variation-only price/stock) ─────────────

/**
 * Fast-path: patches only price and stock on existing product variations.
 * Skips product-level processing (no taxonomy, images, SEO).
 *
 * For each product in the list, finds the WC product by SKU. If it's
 * a variable product, compares each variation's price+stock and patches
 * only changed fields. Simple products get price+stock patched directly.
 *
 * @param array $woo_products Transformed WooCommerce product arrays.
 * @return array { patched: int, skipped: int, errors: int, details: array }
 */
function gh_fc_quick_patch( array $woo_products ): array {
    $patched = 0;
    $skipped = 0;
    $errors  = 0;
    $details = [];

    foreach ( $woo_products as $p ) {
        $sku = $p['sku'] ?? '';
        if ( ! $sku ) { $skipped++; continue; }

        $existing_id = wc_get_product_id_by_sku( $sku );
        if ( ! $existing_id ) { $skipped++; continue; }

        $existing = wc_get_product( $existing_id );
        if ( ! $existing ) { $skipped++; continue; }

        try {
            $changed = 0;

            if ( $existing->is_type( 'variable' ) && ! empty( $p['variations'] ) ) {
                foreach ( $p['variations'] as $var_data ) {
                    $var_sku = $var_data['sku'] ?? '';
                    if ( ! $var_sku ) continue;

                    $var_id = wc_get_product_id_by_sku( $var_sku );
                    if ( ! $var_id ) continue;

                    $v = wc_get_product( $var_id );
                    if ( ! $v || ! $v->is_type( 'variation' ) ) continue;

                    $dirty = false;
                    if ( isset( $var_data['regular_price'] ) && $v->get_regular_price() !== (string) $var_data['regular_price'] ) {
                        $v->set_regular_price( $var_data['regular_price'] );
                        $dirty = true;
                    }
                    if ( isset( $var_data['sale_price'] ) && $v->get_sale_price() !== (string) $var_data['sale_price'] ) {
                        $v->set_sale_price( $var_data['sale_price'] );
                        $dirty = true;
                    }
                    if ( isset( $var_data['stock_quantity'] ) && (int) $v->get_stock_quantity() !== (int) $var_data['stock_quantity'] ) {
                        $v->set_manage_stock( true );
                        $v->set_stock_quantity( (int) $var_data['stock_quantity'] );
                        $v->set_stock_status( (int) $var_data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
                        $dirty = true;
                    }

                    if ( $dirty ) { $v->save(); $changed++; }
                }

                if ( $changed ) WC_Product_Variable::sync( $existing_id );
            } elseif ( $existing->is_type( 'simple' ) ) {
                $dirty = false;
                if ( isset( $p['regular_price'] ) && $existing->get_regular_price() !== (string) $p['regular_price'] ) {
                    $existing->set_regular_price( $p['regular_price'] );
                    $dirty = true;
                }
                if ( isset( $p['sale_price'] ) && $existing->get_sale_price() !== (string) $p['sale_price'] ) {
                    $existing->set_sale_price( $p['sale_price'] );
                    $dirty = true;
                }
                if ( isset( $p['stock_quantity'] ) && (int) $existing->get_stock_quantity() !== (int) $p['stock_quantity'] ) {
                    $existing->set_manage_stock( true );
                    $existing->set_stock_quantity( (int) $p['stock_quantity'] );
                    $existing->set_stock_status( (int) $p['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
                    $dirty = true;
                }
                if ( $dirty ) { $existing->save(); $changed = 1; }
            }

            if ( $changed ) {
                $patched++;
                $details[] = [ 'action' => 'patched', 'id' => $existing_id, 'sku' => $sku, 'name' => $existing->get_name(), 'changes' => $changed ];
            } else {
                $skipped++;
            }
        } catch ( \Throwable $e ) {
            $errors++;
            $details[] = [ 'action' => 'error', 'sku' => $sku, 'name' => $p['name'] ?? '?', 'reason' => $e->getMessage() ];
        }
    }

    return [
        'summary' => compact( 'patched', 'skipped', 'errors' ),
        'details' => $details,
    ];
}

// ── Taxonomy pre-creation ────────────────────────────────

/**
 * Scans all products for unique taxonomy terms, creates missing ones,
 * and returns a slug→term_id map per taxonomy. Called once before the
 * create/update loop to avoid redundant term_exists() queries.
 *
 * @param array $woo_products Transformed WooCommerce product arrays.
 * @return array { brands: {name => id}, categories: {name => id}, subcategories: {key => id}, tags: {slug => id} }
 */
function gh_fc_prepare_taxonomies( array $woo_products ): array {

    $map = [ 'brands' => [], 'categories' => [], 'subcategories' => [], 'tags' => [] ];

    $needed_brands = [];
    $needed_cats   = [];
    $needed_subs   = [];
    $needed_tags   = [];

    foreach ( $woo_products as $p ) {
        if ( ! empty( $p['_fc_brand'] ) )       $needed_brands[ $p['_fc_brand'] ] = $p['_fc_brand_taxonomy'] ?? 'product_brand';
        if ( ! empty( $p['_sf_brand'] ) )        $needed_brands[ $p['_sf_brand'] ] = 'product_brand';
        if ( ! empty( $p['_gs_brand'] ) )        $needed_brands[ $p['_gs_brand'] ] = 'product_brand';

        if ( ! empty( $p['_fc_category'] ) )     $needed_cats[ $p['_fc_category'] ] = $p['_fc_category_target'] ?? 'product_cat';
        if ( ! empty( $p['_sf_category'] ) )     $needed_cats[ $p['_sf_category'] ] = 'product_cat';

        if ( ! empty( $p['_fc_subcategory'] ) )  $needed_subs[ $p['_fc_category'] . '>' . $p['_fc_subcategory'] ] = [
            'parent_name' => $p['_fc_category'], 'name' => $p['_fc_subcategory'], 'taxonomy' => $p['_fc_category_target'] ?? 'product_cat',
        ];
        if ( ! empty( $p['_sf_subcategory'] ) )  $needed_subs[ $p['_sf_category'] . '>' . $p['_sf_subcategory'] ] = [
            'parent_name' => $p['_sf_category'], 'name' => $p['_sf_subcategory'], 'taxonomy' => 'product_cat',
        ];

        foreach ( $p['_fc_tags'] ?? [] as $tag ) $needed_tags[ $tag ] = true;
    }

    foreach ( $needed_brands as $name => $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) continue;
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( $term ) {
            $map['brands'][ $name ] = (int) $term->term_id;
        } else {
            $result = wp_insert_term( $name, $taxonomy );
            if ( ! is_wp_error( $result ) ) $map['brands'][ $name ] = (int) $result['term_id'];
        }
    }

    foreach ( $needed_cats as $name => $taxonomy ) {
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( $term ) {
            $map['categories'][ $name ] = (int) $term->term_id;
        } else {
            $result = wp_insert_term( $name, $taxonomy );
            if ( ! is_wp_error( $result ) ) $map['categories'][ $name ] = (int) $result['term_id'];
        }
    }

    foreach ( $needed_subs as $key => $sub ) {
        $parent_id = $map['categories'][ $sub['parent_name'] ] ?? 0;
        if ( ! $parent_id ) continue;
        $term = get_term_by( 'name', $sub['name'], $sub['taxonomy'] );
        if ( $term && (int) $term->parent === $parent_id ) {
            $map['subcategories'][ $key ] = (int) $term->term_id;
        } else {
            $result = wp_insert_term( $sub['name'], $sub['taxonomy'], [ 'parent' => $parent_id ] );
            if ( ! is_wp_error( $result ) ) $map['subcategories'][ $key ] = (int) $result['term_id'];
        }
    }

    foreach ( array_keys( $needed_tags ) as $tag ) {
        $term = get_term_by( 'slug', $tag, 'product_tag' );
        if ( ! $term ) $term = get_term_by( 'name', $tag, 'product_tag' );
        if ( $term ) {
            $map['tags'][ $tag ] = (int) $term->term_id;
        } else {
            $result = wp_insert_term( $tag, 'product_tag' );
            if ( ! is_wp_error( $result ) ) $map['tags'][ $tag ] = (int) $result['term_id'];
        }
    }

    return $map;
}

// ── Retry with binary split ──────────────────────────────

/**
 * Processes a batch of products with retry-on-failure using binary split.
 *
 * When a product fails (timeout/fatal), the batch is split in half and
 * each half is retried with exponential backoff. Max 3 retry levels
 * (25 → 12+13 → 6+7). Single-product failures are logged and skipped.
 *
 * Adapted from woo-importer WooCommerceImporter::executeBatchWithRetry().
 *
 * @param array    $items       Products to process.
 * @param callable $process_fn  fn(array $product): array — returns result row.
 * @param int      $retry_depth Current recursion depth (0-based).
 * @return array Result rows.
 */
function gh_fc_batch_with_retry( array $items, callable $process_fn, int $retry_depth = 0 ): array {
    $results = [];

    foreach ( $items as $i => $product ) {
        try {
            $results[] = $process_fn( $product );
        } catch ( \Throwable $e ) {
            $remaining = array_slice( $items, $i );

            if ( $retry_depth < 3 && count( $remaining ) > 1 ) {
                $half = (int) ceil( count( $remaining ) / 2 );
                sleep( min( (int) pow( 2, $retry_depth + 1 ), 8 ) );
                $results = array_merge(
                    $results,
                    gh_fc_batch_with_retry( array_slice( $remaining, 0, $half ), $process_fn, $retry_depth + 1 ),
                    gh_fc_batch_with_retry( array_slice( $remaining, $half ), $process_fn, $retry_depth + 1 )
                );
            } else {
                $results[] = [
                    'action' => 'error',
                    'sku'    => $product['sku'] ?? '',
                    'name'   => $product['name'] ?? '?',
                    'reason' => $e->getMessage(),
                ];
            }
            break;
        }
    }

    return $results;
}

/**
 * Cleans a CSV value.
 */
function gh_fc_clean( string $value ): string {
    $value = trim( $value, " \t\n\r\0\x0B\"'" );
    $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    return trim( $value );
}
