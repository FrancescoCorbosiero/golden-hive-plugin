<?php
/**
 * Taxonomy Query Engine — advanced filter/sort over product taxonomy terms.
 *
 * Complementa `taxonomy-manager.php` (CRUD) con una funzione di query
 * parametrica riutilizzabile sia dalla UI "Tassonomie" (lista filtrabile,
 * top-N, conteggi) sia dal modulo "Navigazione" (auto-populate menu con un
 * set di termini selezionati via criteri).
 *
 * Un solo entrypoint:
 *   rp_cm_query_taxonomies( $args ): array
 *
 * con shape di ritorno:
 *   [ 'items' => Term[], 'total' => int ]
 *
 * dove ogni Term ha:
 *   id, name, slug, parent, count, depth, path, permalink
 */

defined( 'ABSPATH' ) || exit;

/**
 * Query parametrica sull'albero tassonomico prodotto.
 *
 * Esempi:
 *   // top 15 categorie per numero di prodotti
 *   rp_cm_query_taxonomies( [ 'taxonomy' => 'product_cat', 'orderby' => 'count', 'order' => 'desc', 'limit' => 15 ] );
 *
 *   // tutte le categorie figlie dirette di "Abbigliamento" (id=123)
 *   rp_cm_query_taxonomies( [ 'taxonomy' => 'product_cat', 'parent' => 123 ] );
 *
 *   // tutti i discendenti di "Abbigliamento" con almeno 5 prodotti, ordinati per path
 *   rp_cm_query_taxonomies( [
 *       'taxonomy'         => 'product_cat',
 *       'ancestor'         => 123,
 *       'min_count'        => 5,
 *       'orderby'          => 'path',
 *   ] );
 *
 * @param array $args {
 *     @type string $taxonomy         product_cat|product_brand (default: product_cat).
 *     @type string $search           substring matching su name/slug (case-insensitive).
 *     @type int    $parent           filtra per parent diretto. -1 = root only. null = no filter.
 *     @type int    $ancestor         filtra per qualsiasi antenato (include discendenti, esclude l'antenato stesso).
 *     @type int    $depth_min        profondita minima (0 = root).
 *     @type int    $depth_max        profondita massima.
 *     @type int    $min_count        numero minimo di prodotti assegnati.
 *     @type int    $max_count        numero massimo di prodotti assegnati.
 *     @type bool   $has_products     scorciatoia per min_count=1.
 *     @type int[]  $include          term_id da includere (se omesso: tutti).
 *     @type int[]  $exclude          term_id da escludere.
 *     @type int[]  $in_product_cat   cross-filter: tieni solo i termini che hanno
 *                                    almeno un prodotto in uno di questi product_cat.
 *                                    Usato per "brand dei prodotti nella categoria X".
 *                                    `count` viene sovrascritto col conteggio filtrato.
 *     @type int[]  $in_product_brand cross-filter: come sopra ma su product_brand.
 *     @type string $orderby          name|count|id|depth|path (default: name).
 *     @type string $order            asc|desc (default: asc).
 *     @type int    $limit            numero massimo di risultati (-1 = illimitato).
 *     @type int    $offset           offset per paginazione.
 * }
 * @return array { items: array[], total: int } total = count pre-limit.
 */
function rp_cm_query_taxonomies( array $args = [] ): array {

    $defaults = [
        'taxonomy'         => 'product_cat',
        'search'           => '',
        'parent'           => null,
        'ancestor'         => 0,
        'depth_min'        => null,
        'depth_max'        => null,
        'min_count'        => null,
        'max_count'        => null,
        'has_products'     => false,
        'include'          => [],
        'exclude'          => [],
        'in_product_cat'   => [],
        'in_product_brand' => [],
        'orderby'          => 'name',
        'order'            => 'asc',
        'limit'            => 50,
        'offset'           => 0,
    ];
    $args = array_merge( $defaults, $args );

    $taxonomy = rp_cm_normalize_taxonomy( (string) $args['taxonomy'] );

    // get_terms hide_empty=false cosi il caller decide via min_count
    $terms = get_terms( [
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ] );
    if ( is_wp_error( $terms ) || ! $terms ) {
        return [ 'items' => [], 'total' => 0 ];
    }

    // Index by id + parent map per depth/path/ancestor lookups.
    $by_id  = [];
    foreach ( $terms as $t ) {
        $by_id[ $t->term_id ] = $t;
    }

    $depth_cache = [];
    $path_cache  = [];
    $compute_depth = function ( int $tid ) use ( &$depth_cache, $by_id, &$compute_depth ): int {
        if ( isset( $depth_cache[ $tid ] ) ) return $depth_cache[ $tid ];
        $t = $by_id[ $tid ] ?? null;
        if ( ! $t || ! $t->parent ) return $depth_cache[ $tid ] = 0;
        return $depth_cache[ $tid ] = 1 + $compute_depth( (int) $t->parent );
    };
    $compute_path = function ( int $tid ) use ( &$path_cache, $by_id, &$compute_path ): string {
        if ( isset( $path_cache[ $tid ] ) ) return $path_cache[ $tid ];
        $t = $by_id[ $tid ] ?? null;
        if ( ! $t ) return '';
        $segment = $t->name;
        if ( ! $t->parent ) return $path_cache[ $tid ] = $segment;
        return $path_cache[ $tid ] = $compute_path( (int) $t->parent ) . ' / ' . $segment;
    };
    $is_descendant_of = function ( int $tid, int $ancestor ) use ( $by_id, &$is_descendant_of ): bool {
        $t = $by_id[ $tid ] ?? null;
        if ( ! $t || ! $t->parent ) return false;
        if ( (int) $t->parent === $ancestor ) return true;
        return $is_descendant_of( (int) $t->parent, $ancestor );
    };

    // Build enriched rows, applying filters.
    $search = trim( (string) $args['search'] );
    $search = $search !== '' ? mb_strtolower( $search ) : '';

    $include = array_map( 'intval', (array) $args['include'] );
    $exclude = array_map( 'intval', (array) $args['exclude'] );

    $min_count = $args['has_products'] ? max( 1, (int) ( $args['min_count'] ?? 1 ) ) : $args['min_count'];

    // Cross-taxonomy filter: reduce the candidate set to the terms that share
    // at least one product with the given product_cat / product_brand IDs, and
    // override `count` with the filtered intersection count.
    $cross_counts = null;
    $cross_cats   = array_values( array_unique( array_map( 'intval', (array) $args['in_product_cat'] ) ) );
    $cross_brands = array_values( array_unique( array_map( 'intval', (array) $args['in_product_brand'] ) ) );
    if ( $cross_cats || $cross_brands ) {
        $cross_counts = rp_cm_cross_taxonomy_counts( $taxonomy, $cross_cats, $cross_brands );
    }

    $rows = [];
    foreach ( $terms as $t ) {

        $tid = (int) $t->term_id;

        if ( $include && ! in_array( $tid, $include, true ) ) continue;
        if ( $exclude && in_array( $tid, $exclude, true ) ) continue;

        if ( $args['parent'] !== null ) {
            $p = (int) $args['parent'];
            if ( $p === -1 ) {
                if ( $t->parent !== 0 ) continue;
            } elseif ( (int) $t->parent !== $p ) {
                continue;
            }
        }

        if ( $args['ancestor'] > 0 && ! $is_descendant_of( $tid, (int) $args['ancestor'] ) ) {
            continue;
        }

        $depth = $compute_depth( $tid );
        if ( $args['depth_min'] !== null && $depth < (int) $args['depth_min'] ) continue;
        if ( $args['depth_max'] !== null && $depth > (int) $args['depth_max'] ) continue;

        // When cross-filter is active, drop terms with zero intersection and
        // use the filtered count for min/max checks downstream.
        $effective_count = (int) $t->count;
        if ( $cross_counts !== null ) {
            if ( ! isset( $cross_counts[ $tid ] ) ) continue;
            $effective_count = (int) $cross_counts[ $tid ];
        }

        if ( $min_count !== null && $effective_count < (int) $min_count ) continue;
        if ( $args['max_count'] !== null && $effective_count > (int) $args['max_count'] ) continue;

        if ( $search !== '' ) {
            $hay = mb_strtolower( $t->name . ' ' . $t->slug );
            if ( strpos( $hay, $search ) === false ) continue;
        }

        $link = get_term_link( $t );
        $rows[] = [
            'id'        => $tid,
            'name'      => $t->name,
            'slug'      => $t->slug,
            'parent'    => (int) $t->parent,
            'count'     => $effective_count,
            'depth'     => $depth,
            'path'      => $compute_path( $tid ),
            'permalink' => is_string( $link ) ? $link : '',
        ];
    }

    // Sort
    $orderby = in_array( $args['orderby'], [ 'name', 'count', 'id', 'depth', 'path' ], true ) ? $args['orderby'] : 'name';
    $dir     = strtolower( (string) $args['order'] ) === 'desc' ? -1 : 1;
    usort( $rows, function ( $a, $b ) use ( $orderby, $dir ) {
        $av = $a[ $orderby ]; $bv = $b[ $orderby ];
        if ( is_string( $av ) ) return $dir * strnatcasecmp( $av, $bv );
        return $dir * ( $av <=> $bv );
    } );

    $total = count( $rows );

    $offset = max( 0, (int) $args['offset'] );
    $limit  = (int) $args['limit'];
    if ( $limit < 0 ) {
        $rows = array_slice( $rows, $offset );
    } else {
        $rows = array_slice( $rows, $offset, $limit );
    }

    return [ 'items' => $rows, 'total' => $total ];
}

/**
 * Conteggio di prodotti per ciascun termine della `$target_taxonomy` che hanno
 * almeno un'assegnazione in una delle `$product_cat_ids` e/o una delle
 * `$product_brand_ids`. Se entrambi i set sono specificati, richiede AND
 * (il prodotto deve appartenere ad almeno una di ogni set).
 *
 * Implementazione: SQL diretto con GROUP BY su term_taxonomy_id per evitare di
 * hydratare centinaia di oggetti prodotto in memoria. Case d'uso principale:
 *   "brand dei prodotti nella categoria X" → target=product_brand, cat=[X]
 *
 * @return array<int,int> [ term_id => filtered_count ]
 */
function rp_cm_cross_taxonomy_counts( string $target_taxonomy, array $product_cat_ids = [], array $product_brand_ids = [] ): array {
    global $wpdb;

    $target_taxonomy   = rp_cm_normalize_taxonomy( $target_taxonomy );
    $product_cat_ids   = array_values( array_unique( array_map( 'intval', $product_cat_ids ) ) );
    $product_brand_ids = array_values( array_unique( array_map( 'intval', $product_brand_ids ) ) );
    if ( ! $product_cat_ids && ! $product_brand_ids ) return [];

    // Subquery per ogni cross-filter: restituisce gli object_id (prodotti) che
    // hanno almeno un termine in quel set. Se entrambi i set sono attivi,
    // intersechiamo con INNER JOIN.
    $joins  = [];
    $params = [];

    if ( $product_cat_ids ) {
        $ph = implode( ',', array_fill( 0, count( $product_cat_ids ), '%d' ) );
        $joins[] = "INNER JOIN (
            SELECT DISTINCT tr.object_id
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ($ph)
        ) f_cat ON f_cat.object_id = p.ID";
        $params = array_merge( $params, $product_cat_ids );
    }
    if ( $product_brand_ids ) {
        $ph = implode( ',', array_fill( 0, count( $product_brand_ids ), '%d' ) );
        $joins[] = "INNER JOIN (
            SELECT DISTINCT tr.object_id
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'product_brand' AND tt.term_id IN ($ph)
        ) f_brand ON f_brand.object_id = p.ID";
        $params = array_merge( $params, $product_brand_ids );
    }

    $join_sql = implode( "\n", $joins );

    $sql = "
        SELECT tt_tgt.term_id AS term_id, COUNT(DISTINCT p.ID) AS cnt
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_tgt ON tr_tgt.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_tgt ON tt_tgt.term_taxonomy_id = tr_tgt.term_taxonomy_id
        $join_sql
        WHERE p.post_type = 'product'
          AND p.post_status NOT IN ('trash','auto-draft')
          AND tt_tgt.taxonomy = %s
        GROUP BY tt_tgt.term_id
    ";
    $params[] = $target_taxonomy;

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    if ( ! is_array( $rows ) ) return [];

    $out = [];
    foreach ( $rows as $r ) {
        $out[ (int) $r['term_id'] ] = (int) $r['cnt'];
    }
    return $out;
}

/**
 * Ritorna gli ID prodotto assegnati a uno o piu termini. Utilita per bulk ops
 * sui prodotti di un set di tassonomie selezionate via query.
 *
 * Usa tax_query IN su tutti i term_id in un unico WC_Product_Query.
 *
 * @param int[]  $term_ids
 * @param string $taxonomy
 * @param array  $extra    argomenti WC_Product_Query aggiuntivi (status, type, limit).
 * @return int[] product IDs (unici).
 */
function rp_cm_get_products_for_terms( array $term_ids, string $taxonomy = 'product_cat', array $extra = [] ): array {

    $term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
    if ( ! $term_ids ) return [];

    $taxonomy = rp_cm_normalize_taxonomy( $taxonomy );

    $args = array_merge( [
        'limit'  => -1,
        'status' => 'any',
        'type'   => [ 'simple', 'variable' ],
        'return' => 'ids',
    ], $extra );
    $args['tax_query'] = [
        [
            'taxonomy' => $taxonomy,
            'field'    => 'term_id',
            'terms'    => $term_ids,
            'operator' => 'IN',
        ],
    ];

    $q   = new WC_Product_Query( $args );
    $ids = $q->get_products();
    return array_map( 'intval', (array) $ids );
}
