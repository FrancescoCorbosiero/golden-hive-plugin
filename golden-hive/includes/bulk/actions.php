<?php
/**
 * Bulk Actions — esecutori di operazioni in massa su set di prodotti.
 *
 * Ogni azione accetta un array di product_id e parametri specifici.
 * Le azioni sono idempotenti dove possibile e ritornano sempre risultati dettagliati.
 *
 * Nessun hook WordPress qui — solo logica pura (tranne WooCommerce API).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mappa delle azioni bulk disponibili con metadati per la UI.
 *
 * @return array [ action_key => { label, group, params[], description } ]
 */
function gh_get_bulk_action_definitions(): array {

    return [
        // ── TAXONOMY ────────────────────────────────────
        'assign_categories' => [
            'label'       => 'Aggiungi categorie',
            'group'       => 'taxonomy',
            'description' => 'Aggiunge una o piu categorie ai prodotti selezionati (non rimuove quelle esistenti).',
            'params'      => [ 'category_ids' => 'term_ids' ],
        ],
        'remove_categories' => [
            'label'       => 'Rimuovi categorie',
            'group'       => 'taxonomy',
            'description' => 'Rimuove categorie specifiche dai prodotti selezionati.',
            'params'      => [ 'category_ids' => 'term_ids' ],
        ],
        'set_categories' => [
            'label'       => 'Imposta categorie',
            'group'       => 'taxonomy',
            'description' => 'Sostituisce TUTTE le categorie dei prodotti selezionati.',
            'params'      => [ 'category_ids' => 'term_ids' ],
        ],
        'assign_tags' => [
            'label'       => 'Aggiungi tag',
            'group'       => 'taxonomy',
            'description' => 'Aggiunge tag ai prodotti selezionati.',
            'params'      => [ 'tag_ids' => 'term_ids' ],
        ],
        'remove_tags' => [
            'label'       => 'Rimuovi tag',
            'group'       => 'taxonomy',
            'description' => 'Rimuove tag specifici dai prodotti selezionati.',
            'params'      => [ 'tag_ids' => 'term_ids' ],
        ],

        // ── STATUS ──────────────────────────────────────
        'set_status' => [
            'label'       => 'Cambia stato',
            'group'       => 'status',
            'description' => 'Imposta lo stato (publish, draft, private) per tutti i prodotti.',
            'params'      => [ 'status' => 'select:publish,draft,private' ],
        ],

        // ── PRICE ───────────────────────────────────────
        'set_sale_percent' => [
            'label'       => 'Imposta sconto %',
            'group'       => 'price',
            'description' => 'Calcola il sale_price come percentuale del regular_price.',
            'params'      => [ 'percent' => 'number' ],
        ],
        'remove_sale' => [
            'label'       => 'Rimuovi saldo',
            'group'       => 'price',
            'description' => 'Rimuove il prezzo scontato da tutti i prodotti.',
            'params'      => [],
        ],
        'adjust_price' => [
            'label'       => 'Modifica prezzo',
            'group'       => 'price',
            'description' => 'Aggiunge/sottrae un importo al regular_price (+10 o -5).',
            'params'      => [ 'amount' => 'number', 'target' => 'select:regular_price,sale_price' ],
        ],

        // ── STOCK ───────────────────────────────────────
        'set_stock_status' => [
            'label'       => 'Imposta stato stock',
            'group'       => 'stock',
            'description' => 'Imposta instock/outofstock per prodotti e varianti.',
            'params'      => [ 'stock_status' => 'select:instock,outofstock' ],
        ],
        'set_stock_quantity' => [
            'label'       => 'Imposta quantita stock',
            'group'       => 'stock',
            'description' => 'Imposta la quantita di stock (abilita manage_stock se necessario).',
            'params'      => [ 'quantity' => 'number' ],
        ],

        // ── SEO ─────────────────────────────────────────
        'set_seo_template' => [
            'label'       => 'Template SEO',
            'group'       => 'seo',
            'description' => 'Genera meta title/description da template. Placeholder: {name}, {sku}, {price}, {brand}.',
            'params'      => [ 'meta_title_template' => 'text', 'meta_description_template' => 'text' ],
        ],

        // ── SORTING ─────────────────────────────────────
        'set_menu_order' => [
            'label'       => 'Imposta ordine',
            'group'       => 'order',
            'description' => 'Imposta menu_order a un valore fisso.',
            'params'      => [ 'menu_order' => 'number' ],
        ],
    ];
}

/**
 * Esegue un'azione bulk su un set di prodotti.
 *
 * @param string $action      Chiave dell'azione (da gh_get_bulk_action_definitions).
 * @param int[]  $product_ids Array di ID prodotto.
 * @param array  $params      Parametri specifici dell'azione.
 * @return array {
 *     action: string,
 *     total: int,
 *     success: int,
 *     failed: int,
 *     results: [ product_id => 'ok'|'errore...' ],
 *     summary: string
 * }
 *
 * Esempio:
 *   gh_execute_bulk_action( 'assign_categories', [101, 102], [ 'category_ids' => [15, 22] ] );
 */
function gh_execute_bulk_action( string $action, array $product_ids, array $params = [] ): array {

    $results = [];
    $success = 0;
    $failed  = 0;

    foreach ( $product_ids as $pid ) {
        $pid = intval( $pid );
        $product = wc_get_product( $pid );

        if ( ! $product ) {
            $results[ $pid ] = "Prodotto #{$pid} non trovato.";
            $failed++;
            continue;
        }

        $result = gh_apply_bulk_action( $product, $action, $params );

        if ( $result === true || $result === 'ok' ) {
            $results[ $pid ] = 'ok';
            $success++;
        } else {
            $results[ $pid ] = is_wp_error( $result ) ? $result->get_error_message() : (string) $result;
            $failed++;
        }
    }

    $total = count( $product_ids );

    return [
        'action'  => $action,
        'total'   => $total,
        'success' => $success,
        'failed'  => $failed,
        'results' => $results,
        'summary' => "{$success}/{$total} prodotti aggiornati" . ( $failed ? ", {$failed} errori" : '' ),
    ];
}

/**
 * Applica una singola azione bulk a un prodotto.
 *
 * @param WC_Product $product
 * @param string     $action
 * @param array      $params
 * @return true|string|WP_Error
 */
function gh_apply_bulk_action( WC_Product $product, string $action, array $params ): true|string|WP_Error {

    $pid = $product->get_id();

    return match ( $action ) {

        // ── TAXONOMY ────────────────────────────────────
        'assign_categories' => rp_cm_assign_product_categories( $pid, $params['category_ids'] ?? [] ),
        'remove_categories' => rp_cm_remove_product_categories( $pid, $params['category_ids'] ?? [] ),
        'set_categories'    => rp_cm_set_product_categories( $pid, $params['category_ids'] ?? [] ),

        'assign_tags' => gh_assign_product_tags( $pid, $params['tag_ids'] ?? [] ),
        'remove_tags' => gh_remove_product_tags( $pid, $params['tag_ids'] ?? [] ),

        // ── STATUS ──────────────────────────────────────
        'set_status' => gh_set_product_status( $product, $params['status'] ?? 'publish' ),

        // ── PRICE ───────────────────────────────────────
        'set_sale_percent' => gh_set_sale_percent( $product, floatval( $params['percent'] ?? 0 ) ),
        'remove_sale'      => gh_remove_sale( $product ),
        'adjust_price'     => gh_adjust_price( $product, floatval( $params['amount'] ?? 0 ), $params['target'] ?? 'regular_price' ),

        // ── STOCK ───────────────────────────────────────
        'set_stock_status'   => gh_set_stock_status( $product, $params['stock_status'] ?? 'instock' ),
        'set_stock_quantity' => gh_set_stock_quantity( $product, intval( $params['quantity'] ?? 0 ) ),

        // ── SEO ─────────────────────────────────────────
        'set_seo_template' => gh_apply_seo_template( $product, $params ),

        // ── ORDER ───────────────────────────────────────
        'set_menu_order' => gh_set_menu_order( $pid, intval( $params['menu_order'] ?? 0 ) ),

        default => "Azione sconosciuta: {$action}",
    };
}

// ── ACTION IMPLEMENTATIONS ────────────────────────────────────────────────────

/**
 * Aggiunge tag a un prodotto.
 */
function gh_assign_product_tags( int $product_id, array $tag_ids ): true|WP_Error {

    $result = wp_set_object_terms( $product_id, array_map( 'intval', $tag_ids ), 'product_tag', true );
    return is_wp_error( $result ) ? $result : true;
}

/**
 * Rimuove tag da un prodotto.
 */
function gh_remove_product_tags( int $product_id, array $tag_ids ): true|WP_Error {

    $current   = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'ids' ] );
    if ( is_wp_error( $current ) ) return $current;

    $remaining = array_diff( $current, array_map( 'intval', $tag_ids ) );
    $result    = wp_set_object_terms( $product_id, array_values( $remaining ), 'product_tag' );
    return is_wp_error( $result ) ? $result : true;
}

/**
 * Imposta lo stato di un prodotto.
 */
function gh_set_product_status( WC_Product $product, string $status ): true {

    $product->set_status( $status );
    $product->save();
    return true;
}

/**
 * Imposta sconto percentuale sul prezzo regolare.
 * Applica anche alle varianti per prodotti variabili.
 */
function gh_set_sale_percent( WC_Product $product, float $percent ): true {

    if ( $percent <= 0 || $percent >= 100 ) {
        return true; // Nop
    }

    $multiplier = 1 - ( $percent / 100 );

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( ! $v ) continue;
            $regular = (float) $v->get_regular_price();
            if ( $regular > 0 ) {
                $v->set_sale_price( round( $regular * $multiplier, 2 ) );
                $v->save();
            }
        }
        WC_Product_Variable::sync( $product->get_id() );
    } else {
        $regular = (float) $product->get_regular_price();
        if ( $regular > 0 ) {
            $product->set_sale_price( round( $regular * $multiplier, 2 ) );
            $product->save();
        }
    }

    return true;
}

/**
 * Rimuove il prezzo scontato (sale_price).
 */
function gh_remove_sale( WC_Product $product ): true {

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( ! $v ) continue;
            $v->set_sale_price( '' );
            $v->save();
        }
        WC_Product_Variable::sync( $product->get_id() );
    } else {
        $product->set_sale_price( '' );
        $product->save();
    }

    return true;
}

/**
 * Aggiusta il prezzo (aggiunge/sottrae importo).
 */
function gh_adjust_price( WC_Product $product, float $amount, string $target ): true {

    if ( abs( $amount ) < 0.01 ) return true;

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( ! $v ) continue;
            $current = (float) ( $target === 'sale_price' ? $v->get_sale_price() : $v->get_regular_price() );
            $new     = max( 0, round( $current + $amount, 2 ) );
            if ( $target === 'sale_price' ) {
                $v->set_sale_price( $new > 0 ? $new : '' );
            } else {
                $v->set_regular_price( $new );
            }
            $v->save();
        }
        WC_Product_Variable::sync( $product->get_id() );
    } else {
        $current = (float) ( $target === 'sale_price' ? $product->get_sale_price() : $product->get_regular_price() );
        $new     = max( 0, round( $current + $amount, 2 ) );
        if ( $target === 'sale_price' ) {
            $product->set_sale_price( $new > 0 ? $new : '' );
        } else {
            $product->set_regular_price( $new );
        }
        $product->save();
    }

    return true;
}

/**
 * Imposta stato stock per prodotto e varianti.
 */
function gh_set_stock_status( WC_Product $product, string $status ): true {

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( ! $v ) continue;
            $v->set_stock_status( $status );
            $v->save();
        }
        WC_Product_Variable::sync( $product->get_id() );
    } else {
        $product->set_stock_status( $status );
        $product->save();
    }

    return true;
}

/**
 * Imposta quantita stock (abilita manage_stock se necessario).
 */
function gh_set_stock_quantity( WC_Product $product, int $quantity ): true {

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $v = wc_get_product( $var_id );
            if ( ! $v ) continue;
            $v->set_manage_stock( true );
            $v->set_stock_quantity( $quantity );
            $v->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
            $v->save();
        }
        WC_Product_Variable::sync( $product->get_id() );
    } else {
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $quantity );
        $product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
        $product->save();
    }

    return true;
}

/**
 * Applica template SEO (meta_title, meta_description) con placeholder.
 * Placeholder: {name}, {sku}, {price}, {brand}, {type}
 */
function gh_apply_seo_template( WC_Product $product, array $params ): true {

    $pid = $product->get_id();

    // Resolve brand (prima categoria di profondita 1)
    $cats  = rp_cm_get_product_category_names( $pid );
    $brand = $cats[0] ?? '';

    $replacements = [
        '{name}'  => $product->get_name(),
        '{sku}'   => $product->get_sku(),
        '{price}' => $product->get_price(),
        '{brand}' => $brand,
        '{type}'  => $product->get_type(),
    ];

    if ( ! empty( $params['meta_title_template'] ) ) {
        $title = str_replace( array_keys( $replacements ), array_values( $replacements ), $params['meta_title_template'] );
        update_post_meta( $pid, 'rank_math_title', sanitize_text_field( $title ) );
    }

    if ( ! empty( $params['meta_description_template'] ) ) {
        $desc = str_replace( array_keys( $replacements ), array_values( $replacements ), $params['meta_description_template'] );
        update_post_meta( $pid, 'rank_math_description', sanitize_text_field( $desc ) );
    }

    return true;
}

/**
 * Imposta menu_order di un prodotto.
 */
function gh_set_menu_order( int $product_id, int $order ): true {

    wp_update_post( [ 'ID' => $product_id, 'menu_order' => $order ] );
    return true;
}
