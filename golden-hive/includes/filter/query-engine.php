<?php
/**
 * Query Engine — filter pipeline a due fasi (DB + memoria).
 *
 * Fase 1 (DB): usa WC_Product_Query per filtrare per status, tipo, categoria, prezzo
 *              a livello SQL. Veloce, scalabile.
 * Fase 2 (memoria): applica condizioni PHP per filtri complessi
 *              (attributi, varianti, SEO, regex). Flessibile.
 *
 * Nessun hook WordPress qui — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Esegue una query filtrata e ritorna i prodotti che soddisfano TUTTE le condizioni.
 *
 * @param array $conditions Array di condizioni [ { type, operator, value, ?attribute_name }, ... ]
 * @param array $options    Opzioni: per_page (int, -1=tutti), page (int, 1-based), orderby, order
 * @return array {
 *     products: array  — prodotti serializzati per la UI,
 *     total: int       — totale prodotti trovati (pre-paginazione),
 *     page: int,
 *     per_page: int,
 *     product_ids: int[] — ID raw per passarli alle bulk actions
 * }
 *
 * Esempio:
 *   $result = gh_filter_products([
 *       [ 'type' => 'status', 'operator' => 'is', 'value' => 'publish' ],
 *       [ 'type' => 'sku_pattern', 'operator' => 'starts_with', 'value' => 'AJ4' ],
 *       [ 'type' => 'stock_status', 'operator' => 'is', 'value' => 'partial' ],
 *   ]);
 */
function gh_filter_products( array $conditions = [], array $options = [] ): array {

    $per_page = intval( $options['per_page'] ?? -1 );
    $page     = max( 1, intval( $options['page'] ?? 1 ) );

    // ── FASE 1: DB query ─────────────────────────────────────
    $db_args      = gh_build_db_query( $conditions );
    $db_args['return']  = 'objects';
    $db_args['orderby'] = $options['orderby'] ?? 'title';
    $db_args['order']   = $options['order']   ?? 'ASC';

    $query    = new WC_Product_Query( $db_args );
    $products = $query->get_products();

    // ── FASE 2: filtri in memoria ────────────────────────────
    $memory_conditions = gh_get_memory_conditions( $conditions );

    if ( ! empty( $memory_conditions ) ) {
        $cache = [];
        $products = array_filter( $products, function ( WC_Product $p ) use ( $memory_conditions, &$cache ) {
            foreach ( $memory_conditions as $cond ) {
                if ( ! gh_evaluate_condition( $p, $cond, $cache ) ) {
                    return false;
                }
            }
            return true;
        } );
        $products = array_values( $products );
    }

    $total = count( $products );

    // ── Paginazione ──────────────────────────────────────────
    if ( $per_page > 0 ) {
        $offset   = ( $page - 1 ) * $per_page;
        $products = array_slice( $products, $offset, $per_page );
    }

    // ── Serializza per la UI ─────────────────────────────────
    $all_ids     = array_map( fn( WC_Product $p ) => $p->get_id(), $products );
    $serialized  = array_map( 'gh_serialize_product_row', $products );

    return [
        'products'    => $serialized,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'product_ids' => $all_ids,
    ];
}

/**
 * Come gh_filter_products ma ritorna SOLO gli ID (per bulk actions su grandi set).
 * Non pagina, non serializza — solo filtra e ritorna ID.
 *
 * @param array $conditions
 * @return int[] Array di product ID.
 */
function gh_filter_product_ids( array $conditions = [] ): array {

    $db_args = gh_build_db_query( $conditions );
    $db_args['return'] = 'objects';

    $query    = new WC_Product_Query( $db_args );
    $products = $query->get_products();

    $memory_conditions = gh_get_memory_conditions( $conditions );

    if ( ! empty( $memory_conditions ) ) {
        $cache = [];
        $products = array_filter( $products, function ( WC_Product $p ) use ( $memory_conditions, &$cache ) {
            foreach ( $memory_conditions as $cond ) {
                if ( ! gh_evaluate_condition( $p, $cond, $cache ) ) {
                    return false;
                }
            }
            return true;
        } );
    }

    return array_values( array_map( fn( WC_Product $p ) => $p->get_id(), $products ) );
}

/**
 * Ritorna i metadati delle condizioni disponibili + valori dinamici
 * (categorie, tag, attributi presenti nel negozio).
 *
 * @return array { conditions: array, categories: array, tags: array, attributes: array }
 */
function gh_get_filter_meta(): array {

    $definitions = gh_get_condition_definitions();

    // Categorie
    $cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
    $categories = [];
    if ( ! is_wp_error( $cats ) ) {
        foreach ( $cats as $t ) {
            $categories[] = [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent ];
        }
    }

    // Tag
    $tag_terms = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false, 'orderby' => 'name' ] );
    $tags = [];
    if ( ! is_wp_error( $tag_terms ) ) {
        foreach ( $tag_terms as $t ) {
            $tags[] = [ 'id' => $t->term_id, 'name' => $t->name ];
        }
    }

    // Attributi WooCommerce (pa_taglia, pa_colore, ecc.)
    $wc_attrs   = wc_get_attribute_taxonomies();
    $attributes = [];
    foreach ( $wc_attrs as $attr ) {
        $tax_name = wc_attribute_taxonomy_name( $attr->attribute_name );
        $terms    = get_terms( [ 'taxonomy' => $tax_name, 'hide_empty' => false ] );
        $values   = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $values[] = [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug ];
            }
        }
        $attributes[] = [
            'name'     => $tax_name,
            'label'    => $attr->attribute_label,
            'values'   => $values,
        ];
    }

    return [
        'conditions' => $definitions,
        'categories' => $categories,
        'tags'       => $tags,
        'attributes' => $attributes,
    ];
}

// ── DB QUERY BUILDER ──────────────────────────────────────────────────────────

/**
 * Converte condizioni in parametri WC_Product_Query dove possibile (fase DB).
 *
 * @param array $conditions
 * @return array WC_Product_Query args.
 */
function gh_build_db_query( array $conditions ): array {

    $args = [
        'limit'  => -1,
        'status' => 'any',
        'type'   => [ 'simple', 'variable' ],
    ];

    foreach ( $conditions as $cond ) {
        $type = $cond['type'] ?? '';
        $op   = $cond['operator'] ?? '';
        $val  = $cond['value'] ?? null;

        match ( $type ) {
            'status' => $op === 'is' ? $args['status'] = $val : null,
            'type' => $op === 'is'
                ? $args['type'] = [ $val ]
                : ( $op === 'is_not'
                    ? $args['type'] = array_diff( [ 'simple', 'variable' ], [ $val ] )
                    : null ),
            'category' => $op === 'in' && ! empty( $val )
                ? $args['tax_query'][] = [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => array_map( 'intval', (array) $val ),
                    'operator' => 'IN',
                ]
                : ( $op === 'not_in' && ! empty( $val )
                    ? $args['tax_query'][] = [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => array_map( 'intval', (array) $val ),
                        'operator' => 'NOT IN',
                    ]
                    : null ),
            'tag' => $op === 'in' && ! empty( $val )
                ? $args['tax_query'][] = [
                    'taxonomy' => 'product_tag',
                    'field'    => 'term_id',
                    'terms'    => array_map( 'intval', (array) $val ),
                    'operator' => 'IN',
                ]
                : ( $op === 'not_in' && ! empty( $val )
                    ? $args['tax_query'][] = [
                        'taxonomy' => 'product_tag',
                        'field'    => 'term_id',
                        'terms'    => array_map( 'intval', (array) $val ),
                        'operator' => 'NOT IN',
                    ]
                    : null ),
            default => null,
        };
    }

    return $args;
}

/**
 * Filtra condizioni che NON possono essere gestite a livello DB
 * e devono essere valutate in PHP (fase memoria).
 */
function gh_get_memory_conditions( array $conditions ): array {

    // Questi tipi sono gia gestiti in gh_build_db_query
    $db_types = [ 'status', 'type', 'category', 'tag' ];

    // Ma status con is_not e category/tag sono gia gestiti, tranne casi speciali
    return array_values( array_filter( $conditions, function ( $c ) use ( $db_types ) {
        $type = $c['type'] ?? '';
        $op   = $c['operator'] ?? '';

        // status con 'is' e gestito a DB
        if ( $type === 'status' && $op === 'is' ) return false;
        // type con is/is_not e gestito a DB
        if ( $type === 'type' ) return false;
        // category/tag in/not_in gestiti a DB
        if ( in_array( $type, [ 'category', 'tag' ], true ) && in_array( $op, [ 'in', 'not_in' ], true ) ) return false;

        return true;
    } ) );
}

// ── SERIALIZER ────────────────────────────────────────────────────────────────

/**
 * Serializza un WC_Product in un array leggero per la UI della tabella risultati.
 *
 * @param WC_Product $product
 * @return array
 */
function gh_serialize_product_row( WC_Product $product ): array {

    $pid = $product->get_id();

    return [
        'id'             => $pid,
        'name'           => $product->get_name(),
        'sku'            => $product->get_sku(),
        'type'           => $product->get_type(),
        'status'         => $product->get_status(),
        'price'          => $product->get_price(),
        'regular_price'  => $product->get_regular_price(),
        'sale_price'     => $product->get_sale_price(),
        'stock_status'   => $product->get_stock_status(),
        'stock_quantity' => $product->get_stock_quantity(),
        'menu_order'     => (int) get_post_field( 'menu_order', $pid ),
        'categories'     => rp_cm_get_product_category_names( $pid ),
        'has_image'      => (bool) $product->get_image_id(),
        'variant_count'  => $product->is_type( 'variable' ) ? count( $product->get_children() ) : 0,
        'date_created'   => $product->get_date_created()?->date( 'Y-m-d' ) ?? '',
        'date_modified'  => $product->get_date_modified()?->date( 'Y-m-d' ) ?? '',
        'permalink'      => get_permalink( $pid ),
    ];
}
