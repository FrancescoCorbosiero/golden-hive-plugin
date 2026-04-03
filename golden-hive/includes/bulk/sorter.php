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
