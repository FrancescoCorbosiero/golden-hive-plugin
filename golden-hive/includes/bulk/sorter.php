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

    // Una regola sconosciuta NON deve riscrivere menu_order in ordine
    // arbitrario (il vecchio comparator default fn() => 0 lo faceva,
    // riportando pure "rule applicata" al chiamante).
    if ( ! isset( gh_get_sort_rules()[ $rule ] ) ) {
        return [ 'rule' => $rule, 'total' => 0, 'updated' => 0, 'preview' => [], 'error' => 'unknown_rule' ];
    }

    // Carica prodotti (cache primed: niente 2 query per prodotto)
    if ( function_exists( 'gh_prime_product_caches' ) ) {
        gh_prime_product_caches( array_map( 'intval', $product_ids ) );
    }
    $products = [];
    foreach ( $product_ids as $pid ) {
        $p = wc_get_product( intval( $pid ) );
        if ( $p ) $products[] = $p;
    }

    if ( empty( $products ) ) {
        return [ 'rule' => $rule, 'total' => 0, 'updated' => 0, 'preview' => [] ];
    }

    // Ordina con la regola
    $products = gh_sort_products_list( $products, $rule );

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

    if ( ! isset( gh_get_sort_rules()[ $rule ] ) ) {
        return [ 'rule' => $rule, 'total' => 0, 'preview' => [], 'error' => 'unknown_rule' ];
    }

    if ( function_exists( 'gh_prime_product_caches' ) ) {
        gh_prime_product_caches( array_map( 'intval', $product_ids ) );
    }
    $products = [];
    foreach ( $product_ids as $pid ) {
        $p = wc_get_product( intval( $pid ) );
        if ( $p ) $products[] = $p;
    }

    if ( empty( $products ) ) {
        return [ 'rule' => $rule, 'total' => 0, 'preview' => [] ];
    }

    $products = gh_sort_products_list( $products, $rule );

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

// ── SORT CORE ─────────────────────────────────────────────────────────────────

/**
 * Ordina una lista di prodotti secondo una regola — decorate/sort/undecorate.
 *
 * La chiave di ordinamento viene calcolata UNA volta per prodotto, non
 * dentro il comparatore: il vecchio usort richiamava il comparator
 * O(n log n) volte e per stock/varianti ogni chiamata ricaricava i figli
 * dal DB (2.000 prodotti ≈ 22.000 confronti × fino a 10 wc_get_product
 * l'uno ≈ centinaia di migliaia di load per UN ordinamento). Il contesto
 * batch (mappa figli, set on-sale) viene risolto in 1-4 query totali.
 *
 * Stabile per costruzione: a parita di chiave vince l'ordine di input.
 *
 * @param WC_Product[] $products Prodotti da ordinare.
 * @param string       $rule     Chiave regola da gh_get_sort_rules().
 * @return WC_Product[] Lista ordinata.
 */
function gh_sort_products_list( array $products, string $rule ): array {

    $ids = array_map( static fn( WC_Product $p ): int => $p->get_id(), $products );

    // Contesto batch per le regole che ne hanno bisogno.
    $children_map = null;
    if ( in_array( $rule, [ 'stock_first', 'stock_last', 'variant_count_desc' ], true )
        && function_exists( 'rp_cm_prime_variant_caches' ) ) {
        $children_map = rp_cm_prime_variant_caches( $ids );
    }

    // Set canonico WooCommerce dei prodotti in saldo: include i parent
    // variable delle varianti scontate — il vecchio comparator leggeva
    // get_sale_price() sul parent, che per i variable è SEMPRE '' →
    // sale_first era un no-op silenzioso sull'intero catalogo variable.
    $onsale = null;
    if ( $rule === 'sale_first' && function_exists( 'wc_get_product_ids_on_sale' ) ) {
        $onsale = array_flip( array_map( 'intval', (array) wc_get_product_ids_on_sale() ) );
    }

    // Decorate: [ chiave, indice input (stabilita), prodotto ].
    $decorated = [];
    foreach ( $products as $i => $p ) {
        $decorated[] = [ gh_sort_key( $p, $rule, $children_map, $onsale ), $i, $p ];
    }

    // Direzione: le regole "desc-like" invertono il confronto della chiave.
    $dir = in_array(
        $rule,
        [ 'name_desc', 'price_desc', 'date_newest', 'stock_first', 'variant_count_desc', 'sale_first' ],
        true
    ) ? -1 : 1;

    usort( $decorated, static function ( array $x, array $y ) use ( $dir ): int {
        // strcmp per chiavi stringa (byte-compare, come i comparator
        // legacy): lo spaceship su due stringhe numeriche confronterebbe
        // numericamente, cambiando l'ordine di nomi/SKU tipo "501".
        $c = is_string( $x[0] )
            ? strcmp( $x[0], (string) $y[0] )
            : ( $x[0] <=> $y[0] );
        if ( $c !== 0 ) return $dir * $c;
        return $x[1] <=> $y[1];
    } );

    return array_column( $decorated, 2 );
}

/**
 * Chiave scalare di ordinamento per un prodotto secondo una regola.
 *
 * @param WC_Product      $product
 * @param string          $rule         Chiave regola (gia validata).
 * @param array|null      $children_map [ parent_id => child_ids ] batch (o null).
 * @param array|null      $onsale       Set (flipped) degli ID on-sale (o null).
 * @return int|float|string
 */
function gh_sort_key( WC_Product $product, string $rule, ?array $children_map, ?array $onsale ): int|float|string {

    $pid = $product->get_id();

    return match ( $rule ) {
        'name_asc', 'name_desc'     => mb_strtolower( $product->get_name() ),
        'price_asc', 'price_desc'   => (float) $product->get_price(),
        'date_newest', 'date_oldest' => $product->get_date_created()?->getTimestamp() ?? 0,
        'stock_first', 'stock_last' => gh_stock_sort_value( $product, $children_map[ $pid ] ?? null ),
        'sku_asc'                   => (string) $product->get_sku(),
        'variant_count_desc'        => count( $children_map[ $pid ] ?? $product->get_children() ),
        'sale_first'                => $onsale !== null
            ? ( isset( $onsale[ $pid ] ) ? 1 : 0 )
            : ( $product->is_on_sale() ? 1 : 0 ),
        default                     => 0,
    };
}

/**
 * Valore numerico per ordinamento stock (1 = in stock, 0 = out).
 *
 * @param WC_Product $product
 * @param int[]|null $children_ids Figli gia risolti (batch); null = lookup autonomo.
 */
function gh_stock_sort_value( WC_Product $product, ?array $children_ids = null ): int {

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $children_ids ?? $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( $v && $v->get_stock_status() === 'instock' ) return 1;
        }
        return 0;
    }

    return $product->get_stock_status() === 'instock' ? 1 : 0;
}
