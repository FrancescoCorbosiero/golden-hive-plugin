<?php
/**
 * Sorter — ordinamento programmatico dei prodotti via menu_order.
 *
 * WooCommerce rispetta il campo menu_order per l'ordinamento nel catalogo
 * quando l'opzione "Default sorting" e impostata su "Default sorting (custom ordering + name)".
 *
 * Questo modulo calcola l'ordine desiderato e scrive menu_order in bulk.
 * Nessun hook WordPress qui — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regole di ordinamento disponibili.
 *
 * @return array [ rule_key => { label, description } ]
 */
function gh_get_sort_rules(): array {

    return [
        'name_asc' => [
            'label'       => 'Nome A → Z',
            'description' => 'Ordine alfabetico per nome prodotto.',
        ],
        'name_desc' => [
            'label'       => 'Nome Z → A',
            'description' => 'Ordine alfabetico inverso.',
        ],
        'price_asc' => [
            'label'       => 'Prezzo crescente',
            'description' => 'Dal piu economico al piu costoso.',
        ],
        'price_desc' => [
            'label'       => 'Prezzo decrescente',
            'description' => 'Dal piu costoso al piu economico.',
        ],
        'date_newest' => [
            'label'       => 'Piu recenti prima',
            'description' => 'Per data di creazione, dal piu recente.',
        ],
        'date_oldest' => [
            'label'       => 'Piu vecchi prima',
            'description' => 'Per data di creazione, dal piu vecchio.',
        ],
        'stock_first' => [
            'label'       => 'In stock prima',
            'description' => 'Prodotti disponibili in cima, esauriti in fondo.',
        ],
        'stock_last' => [
            'label'       => 'Esauriti prima',
            'description' => 'Prodotti esauriti in cima.',
        ],
        'sku_asc' => [
            'label'       => 'SKU A → Z',
            'description' => 'Ordine alfabetico per SKU.',
        ],
        'variant_count_desc' => [
            'label'       => 'Piu taglie prima',
            'description' => 'Prodotti con piu varianti in cima.',
        ],
        'sale_first' => [
            'label'       => 'In saldo prima',
            'description' => 'Prodotti scontati in cima.',
        ],
    ];
}

/**
 * Calcola e applica l'ordinamento a un set di prodotti.
 * Scrive menu_order incrementale (10, 20, 30, ...) per mantenere spazio
 * per inserimenti manuali futuri.
 *
 * @param int[]  $product_ids ID prodotti da ordinare (se vuoto, tutti i pubblicati).
 * @param string $rule        Chiave regola da gh_get_sort_rules().
 * @param int    $start_order Valore menu_order iniziale (default: 10).
 * @param int    $step        Incremento tra prodotti (default: 10).
 * @return array {
 *     rule: string,
 *     total: int,
 *     updated: int,
 *     preview: [ { id, name, old_order, new_order } ] (primi 20)
 * }
 *
 * Esempio:
 *   $result = gh_sort_products( $ids, 'price_asc' );
 *   // Ordina per prezzo crescente, scrive menu_order 10, 20, 30...
 */
function gh_sort_products( array $product_ids, string $rule, int $start_order = 10, int $step = 10 ): array {

    // Carica prodotti
    $products = [];
    foreach ( $product_ids as $pid ) {
        $p = wc_get_product( intval( $pid ) );
        if ( $p ) $products[] = $p;
    }

    if ( empty( $products ) ) {
        return [ 'rule' => $rule, 'total' => 0, 'updated' => 0, 'preview' => [] ];
    }

    // Ordina con la regola
    usort( $products, gh_get_comparator( $rule ) );

    // Scrivi menu_order
    $updated = 0;
    $preview = [];
    $order   = $start_order;

    foreach ( $products as $p ) {
        $pid       = $p->get_id();
        $old_order = (int) get_post_field( 'menu_order', $pid );
        $new_order = $order;

        if ( $old_order !== $new_order ) {
            wp_update_post( [ 'ID' => $pid, 'menu_order' => $new_order ] );
            $updated++;
        }

        if ( count( $preview ) < 20 ) {
            $preview[] = [
                'id'        => $pid,
                'name'      => $p->get_name(),
                'sku'       => $p->get_sku(),
                'old_order' => $old_order,
                'new_order' => $new_order,
            ];
        }

        $order += $step;
    }

    return [
        'rule'    => $rule,
        'total'   => count( $products ),
        'updated' => $updated,
        'preview' => $preview,
    ];
}

/**
 * Anteprima dell'ordinamento senza scrivere nulla.
 *
 * @param int[]  $product_ids
 * @param string $rule
 * @return array { rule, total, preview: [ { id, name, sku, old_order, new_order } ] }
 */
function gh_sort_preview( array $product_ids, string $rule, int $start_order = 10, int $step = 10 ): array {

    $products = [];
    foreach ( $product_ids as $pid ) {
        $p = wc_get_product( intval( $pid ) );
        if ( $p ) $products[] = $p;
    }

    if ( empty( $products ) ) {
        return [ 'rule' => $rule, 'total' => 0, 'preview' => [] ];
    }

    usort( $products, gh_get_comparator( $rule ) );

    $preview = [];
    $order   = $start_order;

    foreach ( $products as $p ) {
        $preview[] = [
            'id'        => $p->get_id(),
            'name'      => $p->get_name(),
            'sku'       => $p->get_sku(),
            'price'     => $p->get_price(),
            'status'    => $p->get_status(),
            'old_order' => (int) get_post_field( 'menu_order', $p->get_id() ),
            'new_order' => $order,
        ];
        $order += $step;
    }

    return [
        'rule'    => $rule,
        'total'   => count( $products ),
        'preview' => $preview,
    ];
}

// ── COMPARATORS ───────────────────────────────────────────────────────────────

/**
 * Ritorna la funzione di confronto per una regola di ordinamento.
 *
 * @param string $rule
 * @return callable
 */
function gh_get_comparator( string $rule ): callable {

    return match ( $rule ) {
        'name_asc'  => fn( WC_Product $a, WC_Product $b ) =>
            strcmp( mb_strtolower( $a->get_name() ), mb_strtolower( $b->get_name() ) ),

        'name_desc' => fn( WC_Product $a, WC_Product $b ) =>
            strcmp( mb_strtolower( $b->get_name() ), mb_strtolower( $a->get_name() ) ),

        'price_asc' => fn( WC_Product $a, WC_Product $b ) =>
            (float) $a->get_price() <=> (float) $b->get_price(),

        'price_desc' => fn( WC_Product $a, WC_Product $b ) =>
            (float) $b->get_price() <=> (float) $a->get_price(),

        'date_newest' => fn( WC_Product $a, WC_Product $b ) =>
            ( $b->get_date_created()?->getTimestamp() ?? 0 ) <=> ( $a->get_date_created()?->getTimestamp() ?? 0 ),

        'date_oldest' => fn( WC_Product $a, WC_Product $b ) =>
            ( $a->get_date_created()?->getTimestamp() ?? 0 ) <=> ( $b->get_date_created()?->getTimestamp() ?? 0 ),

        'stock_first' => fn( WC_Product $a, WC_Product $b ) =>
            gh_stock_sort_value( $b ) <=> gh_stock_sort_value( $a ),

        'stock_last' => fn( WC_Product $a, WC_Product $b ) =>
            gh_stock_sort_value( $a ) <=> gh_stock_sort_value( $b ),

        'sku_asc' => fn( WC_Product $a, WC_Product $b ) =>
            strcmp( $a->get_sku(), $b->get_sku() ),

        'variant_count_desc' => fn( WC_Product $a, WC_Product $b ) =>
            count( $b->get_children() ) <=> count( $a->get_children() ),

        'sale_first' => fn( WC_Product $a, WC_Product $b ) =>
            ( $b->get_sale_price() !== '' ? 1 : 0 ) <=> ( $a->get_sale_price() !== '' ? 1 : 0 ),

        default => fn() => 0,
    };
}

/**
 * Valore numerico per ordinamento stock (1 = in stock, 0 = out).
 */
function gh_stock_sort_value( WC_Product $product ): int {

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( $v && $v->get_stock_status() === 'instock' ) return 1;
        }
        return 0;
    }

    return $product->get_stock_status() === 'instock' ? 1 : 0;
}

// ══════════════════════════════════════════════════════════════════════════════
// BATCH REPOSITIONING — spostamento manuale di gruppi di prodotti
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Carica i prodotti di una categoria ordinati per menu_order corrente.
 *
 * @param int $category_id Term ID della categoria.
 * @return array [ { id, name, sku, type, status, price, stock_status, menu_order, has_image } ]
 */
function gh_get_category_ordered_products( int $category_id ): array {

    $term = get_term( $category_id, 'product_cat' );
    if ( ! $term || is_wp_error( $term ) ) return [];

    $query = new WC_Product_Query( [
        'limit'    => -1,
        'status'   => 'any',
        'type'     => [ 'simple', 'variable' ],
        'category' => [ $term->slug ],
        'return'   => 'objects',
        'orderby'  => 'menu_order',
        'order'    => 'ASC',
    ] );

    $products = $query->get_products();
    $result   = [];

    foreach ( $products as $p ) {
        $pid = $p->get_id();
        // Only include products directly in this category
        $cat_ids = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! in_array( $category_id, $cat_ids, true ) ) continue;

        $result[] = [
            'id'           => $pid,
            'name'         => $p->get_name(),
            'sku'          => $p->get_sku(),
            'type'         => $p->get_type(),
            'status'       => $p->get_status(),
            'price'        => $p->get_price(),
            'stock_status' => $p->get_stock_status(),
            'menu_order'   => (int) get_post_field( 'menu_order', $pid ),
            'has_image'    => (bool) $p->get_image_id(),
        ];
    }

    // Sort by menu_order, then by name for ties
    usort( $result, function ( $a, $b ) {
        $o = $a['menu_order'] <=> $b['menu_order'];
        return $o !== 0 ? $o : strcmp( $a['name'], $b['name'] );
    } );

    return $result;
}

/**
 * Riposiziona un gruppo di prodotti all'interno dell'ordine di una categoria.
 *
 * Operazioni supportate:
 * - 'to_top':      sposta i selezionati in cima (prima di tutti gli altri)
 * - 'to_bottom':   sposta i selezionati in fondo (dopo tutti gli altri)
 * - 'to_position': sposta i selezionati alla posizione N (1-based)
 * - 'after':       sposta i selezionati dopo il prodotto con ID $target_id
 * - 'before':      sposta i selezionati prima del prodotto con ID $target_id
 *
 * La funzione ricostruisce l'ordine completo della categoria e scrive
 * menu_order incrementale (10, 20, 30...) per tutti i prodotti coinvolti.
 *
 * @param int    $category_id  Term ID della categoria.
 * @param int[]  $move_ids     ID dei prodotti da spostare.
 * @param string $operation    'to_top' | 'to_bottom' | 'to_position' | 'after' | 'before'
 * @param int    $target       Posizione (1-based) per 'to_position', oppure product ID per 'after'/'before'.
 * @param int    $step         Incremento menu_order tra prodotti (default: 10).
 * @return array {
 *     total: int,
 *     updated: int,
 *     order: [ { id, name, old_order, new_order } ]
 * }
 */
function gh_reposition_products( int $category_id, array $move_ids, string $operation, int $target = 0, int $step = 10 ): array {

    $all = gh_get_category_ordered_products( $category_id );
    if ( empty( $all ) ) {
        return [ 'total' => 0, 'updated' => 0, 'order' => [] ];
    }

    $move_set   = array_flip( array_map( 'intval', $move_ids ) );
    $moving     = [];
    $remaining  = [];

    // Separa prodotti da spostare dal resto
    foreach ( $all as $p ) {
        if ( isset( $move_set[ $p['id'] ] ) ) {
            $moving[] = $p;
        } else {
            $remaining[] = $p;
        }
    }

    if ( empty( $moving ) ) {
        return [ 'total' => count( $all ), 'updated' => 0, 'order' => [] ];
    }

    // Ricostruisci l'ordine in base all'operazione
    $new_order = match ( $operation ) {
        'to_top'      => array_merge( $moving, $remaining ),
        'to_bottom'   => array_merge( $remaining, $moving ),
        'to_position' => gh_insert_at_position( $remaining, $moving, max( 1, $target ) ),
        'after'       => gh_insert_relative( $remaining, $moving, $target, 'after' ),
        'before'      => gh_insert_relative( $remaining, $moving, $target, 'before' ),
        default       => $all,
    };

    // Scrivi menu_order e raccogli risultati
    $updated   = 0;
    $order_num = $step;
    $result    = [];

    foreach ( $new_order as $p ) {
        $old = $p['menu_order'];
        $new = $order_num;

        if ( $old !== $new ) {
            wp_update_post( [ 'ID' => $p['id'], 'menu_order' => $new ] );
            $updated++;
        }

        $result[] = [
            'id'        => $p['id'],
            'name'      => $p['name'],
            'sku'       => $p['sku'] ?? '',
            'old_order' => $old,
            'new_order' => $new,
            'moved'     => isset( $move_set[ $p['id'] ] ),
        ];

        $order_num += $step;
    }

    return [
        'total'   => count( $new_order ),
        'updated' => $updated,
        'order'   => $result,
    ];
}

/**
 * Anteprima del riposizionamento senza scrivere nulla.
 */
function gh_reposition_preview( int $category_id, array $move_ids, string $operation, int $target = 0, int $step = 10 ): array {

    $all = gh_get_category_ordered_products( $category_id );
    if ( empty( $all ) ) {
        return [ 'total' => 0, 'order' => [] ];
    }

    $move_set  = array_flip( array_map( 'intval', $move_ids ) );
    $moving    = [];
    $remaining = [];

    foreach ( $all as $p ) {
        if ( isset( $move_set[ $p['id'] ] ) ) {
            $moving[] = $p;
        } else {
            $remaining[] = $p;
        }
    }

    $new_order = match ( $operation ) {
        'to_top'      => array_merge( $moving, $remaining ),
        'to_bottom'   => array_merge( $remaining, $moving ),
        'to_position' => gh_insert_at_position( $remaining, $moving, max( 1, $target ) ),
        'after'       => gh_insert_relative( $remaining, $moving, $target, 'after' ),
        'before'      => gh_insert_relative( $remaining, $moving, $target, 'before' ),
        default       => $all,
    };

    $result    = [];
    $order_num = $step;

    foreach ( $new_order as $p ) {
        $result[] = [
            'id'        => $p['id'],
            'name'      => $p['name'],
            'sku'       => $p['sku'] ?? '',
            'old_order' => $p['menu_order'],
            'new_order' => $order_num,
            'moved'     => isset( $move_set[ $p['id'] ] ),
        ];
        $order_num += $step;
    }

    return [ 'total' => count( $new_order ), 'order' => $result ];
}

// ── REPOSITIONING HELPERS ─────────────────────────────────────────────────────

/**
 * Inserisce un blocco di prodotti alla posizione N (1-based) nella lista rimanente.
 */
function gh_insert_at_position( array $remaining, array $moving, int $position ): array {

    $pos = min( $position - 1, count( $remaining ) );
    $pos = max( 0, $pos );

    $before = array_slice( $remaining, 0, $pos );
    $after  = array_slice( $remaining, $pos );

    return array_merge( $before, $moving, $after );
}

/**
 * Inserisce un blocco di prodotti prima/dopo un prodotto specifico.
 */
function gh_insert_relative( array $remaining, array $moving, int $target_id, string $where ): array {

    $result = [];
    $inserted = false;

    foreach ( $remaining as $p ) {
        if ( $p['id'] === $target_id && $where === 'before' && ! $inserted ) {
            $result   = array_merge( $result, $moving );
            $inserted = true;
        }

        $result[] = $p;

        if ( $p['id'] === $target_id && $where === 'after' && ! $inserted ) {
            $result   = array_merge( $result, $moving );
            $inserted = true;
        }
    }

    // If target not found, append at end
    if ( ! $inserted ) {
        $result = array_merge( $result, $moving );
    }

    return $result;
}
