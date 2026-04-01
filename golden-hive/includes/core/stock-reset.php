<?php
/**
 * Auto-reset stock & price — ripristina prezzo e giacenza originali dopo l'acquisto.
 *
 * Flusso:
 * 1. Admin attiva auto-reset su una variante: salva stock/prezzo originali come meta
 * 2. Applica sconto (sale_price) e riduce stock a 1
 * 3. Quando il cliente acquista e lo stock viene ridotto a 0, il hook ripristina
 *    automaticamente stock e prezzo ai valori originali salvati
 *
 * Meta keys (sulla variante):
 *   _gh_auto_reset            = 'yes'
 *   _gh_reset_stock           = int (stock da ripristinare)
 *   _gh_reset_regular_price   = string (regular_price da ripristinare)
 *   _gh_reset_sale_price      = string (sale_price da ripristinare, vuoto = nessuno sconto)
 */

defined( 'ABSPATH' ) || exit;

// ── Hook: ripristina dopo riduzione stock ──────────────────

add_action( 'woocommerce_reduce_stock_levels', function ( int $order_id ): void {

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    foreach ( $order->get_items() as $item ) {
        $variation_id = $item->get_variation_id();
        if ( ! $variation_id ) continue;

        if ( get_post_meta( $variation_id, '_gh_auto_reset', true ) !== 'yes' ) continue;

        $variation = wc_get_product( $variation_id );
        if ( ! $variation || ! $variation->is_type( 'variation' ) ) continue;

        // Solo se lo stock e' arrivato a 0
        if ( (int) $variation->get_stock_quantity() > 0 ) continue;

        $reset_stock = get_post_meta( $variation_id, '_gh_reset_stock', true );
        $reset_reg   = get_post_meta( $variation_id, '_gh_reset_regular_price', true );
        $reset_sale  = get_post_meta( $variation_id, '_gh_reset_sale_price', true );

        if ( $reset_reg !== '' && $reset_reg !== false ) {
            $variation->set_regular_price( $reset_reg );
        }

        // Ripristina o rimuovi sale_price
        if ( $reset_sale !== '' && $reset_sale !== false ) {
            $variation->set_sale_price( $reset_sale );
        } else {
            $variation->set_sale_price( '' );
        }

        if ( $reset_stock !== '' && $reset_stock !== false ) {
            $variation->set_stock_quantity( (int) $reset_stock );
            $variation->set_stock_status( (int) $reset_stock > 0 ? 'instock' : 'outofstock' );
        }

        $variation->save();

        // Sync parent
        $parent_id = $variation->get_parent_id();
        if ( $parent_id ) {
            WC_Product_Variable::sync( $parent_id );
        }

        // Rimuovi il flag — reset one-shot
        delete_post_meta( $variation_id, '_gh_auto_reset' );
        delete_post_meta( $variation_id, '_gh_reset_stock' );
        delete_post_meta( $variation_id, '_gh_reset_regular_price' );
        delete_post_meta( $variation_id, '_gh_reset_sale_price' );
    }
} );

// ── Helpers ────────────────────────────────────────────────

/**
 * Attiva auto-reset su una variante: salva valori correnti, applica sconto e stock=1.
 *
 * @param int    $variation_id   ID della variante.
 * @param float  $discount_price Prezzo scontato (sale_price).
 * @return array|WP_Error Dettagli dell'operazione o errore.
 */
function gh_enable_auto_reset( int $variation_id, float $discount_price ): array|WP_Error {

    $variation = wc_get_product( $variation_id );
    if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
        return new WP_Error( 'invalid_variation', 'Variante non trovata.' );
    }

    // Salva valori correnti come target di reset
    $current_stock = (int) $variation->get_stock_quantity();
    $current_reg   = $variation->get_regular_price();
    $current_sale  = $variation->get_sale_price();

    update_post_meta( $variation_id, '_gh_auto_reset', 'yes' );
    update_post_meta( $variation_id, '_gh_reset_stock', $current_stock );
    update_post_meta( $variation_id, '_gh_reset_regular_price', $current_reg );
    update_post_meta( $variation_id, '_gh_reset_sale_price', $current_sale );

    // Applica sconto e stock = 1
    $variation->set_sale_price( (string) round( $discount_price ) );
    $variation->set_stock_quantity( 1 );
    $variation->set_stock_status( 'instock' );
    $variation->save();

    $parent_id = $variation->get_parent_id();
    if ( $parent_id ) {
        WC_Product_Variable::sync( $parent_id );
    }

    return [
        'variation_id'   => $variation_id,
        'sku'            => $variation->get_sku(),
        'original_stock' => $current_stock,
        'original_reg'   => $current_reg,
        'original_sale'  => $current_sale,
        'discount_price' => (string) round( $discount_price ),
    ];
}

/**
 * Disattiva auto-reset su una variante (rimuove meta, non tocca prezzo/stock).
 *
 * @param int $variation_id ID della variante.
 * @return bool
 */
function gh_disable_auto_reset( int $variation_id ): bool {

    delete_post_meta( $variation_id, '_gh_auto_reset' );
    delete_post_meta( $variation_id, '_gh_reset_stock' );
    delete_post_meta( $variation_id, '_gh_reset_regular_price' );
    delete_post_meta( $variation_id, '_gh_reset_sale_price' );

    return true;
}

/**
 * Ritorna lo stato auto-reset di una variante.
 *
 * @param int $variation_id
 * @return array|null Null se non attivo.
 */
function gh_get_auto_reset_status( int $variation_id ): ?array {

    if ( get_post_meta( $variation_id, '_gh_auto_reset', true ) !== 'yes' ) {
        return null;
    }

    return [
        'variation_id'       => $variation_id,
        'reset_stock'        => get_post_meta( $variation_id, '_gh_reset_stock', true ),
        'reset_regular_price' => get_post_meta( $variation_id, '_gh_reset_regular_price', true ),
        'reset_sale_price'   => get_post_meta( $variation_id, '_gh_reset_sale_price', true ),
    ];
}

/**
 * Ritorna tutte le varianti con auto-reset attivo.
 *
 * @return array Lista di varianti con i relativi dati di reset.
 */
function gh_get_all_auto_resets(): array {

    $meta_query = new WP_Query( [
        'post_type'      => 'product_variation',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_gh_auto_reset', 'value' => 'yes' ] ],
    ] );

    $results = [];
    foreach ( $meta_query->posts as $var_id ) {
        $v = wc_get_product( $var_id );
        if ( ! $v ) continue;

        $parent = wc_get_product( $v->get_parent_id() );

        $results[] = [
            'variation_id'        => $var_id,
            'sku'                 => $v->get_sku(),
            'product_name'        => $parent ? $parent->get_name() : '',
            'product_id'          => $v->get_parent_id(),
            'current_sale_price'  => $v->get_sale_price(),
            'current_stock'       => (int) $v->get_stock_quantity(),
            'reset_stock'         => get_post_meta( $var_id, '_gh_reset_stock', true ),
            'reset_regular_price' => get_post_meta( $var_id, '_gh_reset_regular_price', true ),
            'reset_sale_price'    => get_post_meta( $var_id, '_gh_reset_sale_price', true ),
            'attribute_summary'   => implode( ', ', $v->get_attributes() ),
        ];
    }

    return $results;
}
