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

    // Include-by-IDs: abilita hand-off da Tax Query / altre sorgenti per
    // pre-popolare il set di prodotti da bulk-editare. Se settato, limita
    // la query ai soli ID specificati (le condizioni continuano a filtrare
    // ulteriormente).
    $has_include_ids = false;
    if ( ! empty( $options['include_ids'] ) && is_array( $options['include_ids'] ) ) {
        $ids = array_values( array_filter( array_map( 'intval', $options['include_ids'] ) ) );
        if ( ! empty( $ids ) ) {
            $db_args['include'] = $ids;
            $db_args['limit']   = -1;
            $has_include_ids    = true;
        }
    }

    $memory_conditions = gh_get_memory_conditions( $conditions );

    // Fast path: nessuna condizione memory-phase → la paginazione va in
    // SQL. Il path legacy idratava l'INTERO catalogo come oggetti
    // (limit -1) per poi buttarne via tutto tranne una pagina da 50:
    // ~2.000 hydration e ~4.000 query per render di tabella.
    if ( empty( $memory_conditions ) && ! $has_include_ids && $per_page > 0 ) {
        $db_args['paginate'] = true;
        $db_args['limit']    = $per_page;
        $db_args['page']     = $page;

        $result   = ( new WC_Product_Query( $db_args ) )->get_products();
        $products = is_object( $result ) ? (array) ( $result->products ?? [] ) : (array) $result;
        $total    = is_object( $result ) ? (int) ( $result->total ?? count( $products ) ) : count( $products );

        return [
            'products'    => gh_serialize_product_rows( $products ),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'product_ids' => array_map( fn( WC_Product $p ) => $p->get_id(), $products ),
        ];
    }

    $query    = new WC_Product_Query( $db_args );
    $products = $query->get_products();

    // ── FASE 2: filtri in memoria ────────────────────────────
    if ( ! empty( $memory_conditions ) ) {
        $cache = gh_build_condition_cache( $memory_conditions, $products );
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
    $serialized  = gh_serialize_product_rows( $products );

    return [
        'products'    => $serialized,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'product_ids' => $all_ids,
    ];
}

/**
 * Costruisce la cache condivisa per la valutazione delle condizioni
 * memory-phase. Se una condizione tocca le varianti (variant_count,
 * stock_status, has_size), i figli di TUTTI i candidati vengono risolti
 * e primed in un colpo solo: senza, ogni prodotto pagava 1 query
 * get_children + 2 query di hydration per variante durante il filtro
 * (2.000 candidati × 10 varianti ≈ 40.000 query per una singola query
 * "stock_status = partial").
 *
 * @param array        $memory_conditions Condizioni memory-phase attive.
 * @param WC_Product[] $products          Candidati della fase DB.
 * @return array Cache iniziale per gh_evaluate_condition (by-ref).
 */
function gh_build_condition_cache( array $memory_conditions, array $products ): array {

    $cache = [];

    $variant_types = [ 'variant_count', 'stock_status', 'has_size' ];
    $needs_variants = (bool) array_filter(
        $memory_conditions,
        static fn( $c ) => in_array( $c['type'] ?? '', $variant_types, true )
    );

    if ( $needs_variants && function_exists( 'rp_cm_prime_variant_caches' ) ) {
        $cache['children_map'] = rp_cm_prime_variant_caches(
            array_map( static fn( WC_Product $p ): int => $p->get_id(), $products )
        );
    }

    return $cache;
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

    $memory_conditions = gh_get_memory_conditions( $conditions );

    // Fast path: nessuna condizione memory-phase → solo ID dal DB.
    // Il path legacy idratava OGNI prodotto del catalogo come oggetto
    // WC_Product per poi tenerne solo l'ID: migliaia di query bruciate
    // prima di ogni bulk action su set ampi.
    if ( empty( $memory_conditions ) ) {
        $db_args['return'] = 'ids';
        $ids = ( new WC_Product_Query( $db_args ) )->get_products();
        return array_values( array_map( 'intval', (array) $ids ) );
    }

    $db_args['return'] = 'objects';

    $query    = new WC_Product_Query( $db_args );
    $products = $query->get_products();

    $cache = gh_build_condition_cache( $memory_conditions, $products );
    $products = array_filter( $products, function ( WC_Product $p ) use ( $memory_conditions, &$cache ) {
        foreach ( $memory_conditions as $cond ) {
            if ( ! gh_evaluate_condition( $p, $cond, $cache ) ) {
                return false;
            }
        }
        return true;
    } );

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

    // Brand (woo brands taxonomy — presente solo se WooCommerce Brands e attivo)
    $brands = [];
    if ( taxonomy_exists( 'product_brand' ) ) {
        $brand_terms = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false, 'orderby' => 'name' ] );
        if ( ! is_wp_error( $brand_terms ) ) {
            foreach ( $brand_terms as $t ) {
                $brands[] = [ 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent ];
            }
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
        'brands'     => $brands,
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
            'brand' => $op === 'in' && ! empty( $val )
                ? $args['tax_query'][] = [
                    'taxonomy' => 'product_brand',
                    'field'    => 'term_id',
                    'terms'    => array_map( 'intval', (array) $val ),
                    'operator' => 'IN',
                ]
                : ( $op === 'not_in' && ! empty( $val )
                    ? $args['tax_query'][] = [
                        'taxonomy' => 'product_brand',
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
    $db_types = [ 'status', 'type', 'category', 'brand', 'tag' ];

    return array_values( array_filter( $conditions, function ( $c ) use ( $db_types ) {
        $type = $c['type'] ?? '';
        $op   = $c['operator'] ?? '';

        // status con 'is' e gestito a DB
        if ( $type === 'status' && $op === 'is' ) return false;
        // type con is/is_not e gestito a DB
        if ( $type === 'type' ) return false;
        // category/brand/tag in/not_in gestiti a DB
        if ( in_array( $type, [ 'category', 'brand', 'tag' ], true ) && in_array( $op, [ 'in', 'not_in' ], true ) ) return false;

        return true;
    } ) );
}

// ── SERIALIZER ────────────────────────────────────────────────────────────────

/**
 * Serializza una PAGINA di WC_Product per la UI, con term cache primed.
 *
 * Il serializer per-riga faceva 2 query di termini non-cachate a riga
 * (wp_get_post_terms per categorie + brand): ~100 query per ogni render
 * della tabella da 50 righe. Qui l'object term cache viene scaldata UNA
 * volta per la pagina e le righe leggono da cache (get_the_terms).
 *
 * @param WC_Product[] $products
 * @return array[]
 */
function gh_serialize_product_rows( array $products ): array {

    $ids = array_map( static fn( WC_Product $p ): int => $p->get_id(), $products );
    if ( $ids && function_exists( 'update_object_term_cache' ) ) {
        update_object_term_cache( $ids, 'product' );
    }

    return array_map( 'gh_serialize_product_row', $products );
}

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
        'categories'     => gh_term_names_cached( $pid, 'product_cat' ),
        'brands'         => gh_get_product_brand_names( $pid ),
        'has_image'      => (bool) $product->get_image_id(),
        'variant_count'  => $product->is_type( 'variable' ) ? count( $product->get_children() ) : 0,
        'date_created'   => $product->get_date_created()?->date( 'Y-m-d' ) ?? '',
        'date_modified'  => $product->get_date_modified()?->date( 'Y-m-d' ) ?? '',
        'permalink'      => get_permalink( $pid ),
    ];
}

/**
 * Nomi dei termini di una tassonomia via object term cache.
 *
 * A differenza di wp_get_post_terms (che interroga SEMPRE il DB),
 * get_the_terms legge la cache scaldata da update_object_term_cache —
 * su una pagina primed il costo è zero query. Stesso ordinamento
 * (name ASC, il default di wp_get_object_terms usato da entrambe le vie).
 *
 * @param int    $product_id
 * @param string $taxonomy
 * @return string[]
 */
function gh_term_names_cached( int $product_id, string $taxonomy ): array {

    $terms = get_the_terms( $product_id, $taxonomy );
    if ( $terms === false || is_wp_error( $terms ) ) return [];
    return array_values( wp_list_pluck( $terms, 'name' ) );
}

/**
 * Ritorna i nomi dei brand (termini product_brand) assegnati a un prodotto.
 *
 * Se la tassonomia product_brand non e registrata (Woo Brands non attivo),
 * ritorna array vuoto: evitiamo errori su installazioni dove il plugin Woo
 * Brands e assente.
 *
 * @param int $product_id
 * @return string[]
 */
function gh_get_product_brand_names( int $product_id ): array {

    if ( ! taxonomy_exists( 'product_brand' ) ) return [];

    return gh_term_names_cached( $product_id, 'product_brand' );
}
