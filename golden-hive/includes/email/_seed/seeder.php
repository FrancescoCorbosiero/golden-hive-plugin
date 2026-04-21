<?php
/**
 * Demo seeder — popola brand + template + campaign con valori dimostrativi
 * per smoke test end-to-end.
 *
 * Richiamato via AJAX dal tab Test Email tramite rp_em_ajax_seed_demo.
 * Idempotente: se i record esistono gia, non li duplica.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'rp_em_seed_demo' ) ) return;

/**
 * Esegue il seed completo.
 *
 * @param bool $reset_brand Se true, sovrascrive le impostazioni brand con i demo.
 * @return array {
 *   brand_seeded:    bool,
 *   template_id:     ?string,
 *   campaign_id:     ?string,
 *   product_ids:     int[],
 *   messages:        string[],
 * }
 */
function rp_em_seed_demo( bool $reset_brand = false ): array {
    $messages = [];

    // ── 1. Brand: solo reset se esplicitamente richiesto, altrimenti lascia
    //            la config utente intatta (i defaults vengono comunque uniti
    //            dai gaps in rp_em_get_brand).
    $brand_seeded = false;
    if ( $reset_brand ) {
        rp_em_reset_brand();
        $brand_seeded = true;
        $messages[]   = 'Brand reset ai valori demo.';
    } else {
        $messages[] = 'Brand non toccato (usa Reset brand per forzare).';
    }

    // ── 2. Template demo
    $template_id = rp_em_install_demo_template();
    if ( $template_id ) {
        $messages[] = "Template demo presente: {$template_id}";
    } else {
        $messages[] = 'Template demo: seed HTML non disponibile.';
    }

    // ── 3. Pick 2 prodotti WooCommerce reali per popolare PRODUCT_1_* e PRODUCT_2_*
    $product_ids = rp_em_pick_demo_products( 2 );
    if ( count( $product_ids ) > 0 ) {
        $messages[] = 'Prodotti picked: ' . implode( ', ', $product_ids );
    } else {
        $messages[] = 'Nessun prodotto WooCommerce pubblicato trovato — PRODUCT_N_* rimarranno vuoti.';
    }

    // ── 4. Campaign demo (se non esiste gia)
    $campaign_id = null;
    if ( $template_id ) {
        $existing = null;
        foreach ( rp_em_get_campaigns() as $c ) {
            if ( ( $c['name'] ?? '' ) === 'Weekend Coupon Demo' ) { $existing = $c; break; }
        }

        $seed_data = include __DIR__ . '/demo-campaign.php';
        if ( ! is_array( $seed_data ) ) $seed_data = [];

        $campaign_data = array_merge(
            [ 'id' => $existing['id'] ?? '' ],
            $seed_data,
            [
                'template_id' => $template_id,
                'product_ids' => $product_ids,
            ]
        );

        $campaign_id = rp_em_save_campaign( $campaign_data );
        $messages[]  = $existing
            ? "Campagna demo aggiornata: {$campaign_id}"
            : "Campagna demo creata: {$campaign_id}";
    }

    return [
        'brand_seeded' => $brand_seeded,
        'template_id'  => $template_id,
        'campaign_id'  => $campaign_id,
        'product_ids'  => $product_ids,
        'messages'     => $messages,
    ];
}

/**
 * Pesca fino a N product_id WooCommerce pubblicati (pref. in stock, pref. con featured image).
 *
 * @param int $n
 * @return int[]
 */
function rp_em_pick_demo_products( int $n = 2 ): array {
    if ( ! function_exists( 'wc_get_products' ) ) return [];

    $products = wc_get_products( [
        'status'  => 'publish',
        'limit'   => $n * 4,    // prendi piu margine per filtrare
        'orderby' => 'date',
        'order'   => 'DESC',
    ] );

    if ( ! is_array( $products ) ) return [];

    $scored = [];
    foreach ( $products as $p ) {
        $score = 0;
        if ( $p->get_image_id() )      $score += 2;
        if ( $p->is_in_stock() )       $score += 1;
        if ( $p->get_price() !== '' )  $score += 1;
        $scored[] = [ 'id' => $p->get_id(), 'score' => $score ];
    }

    usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );
    $scored = array_slice( $scored, 0, $n );
    return array_map( fn( $r ) => (int) $r['id'], $scored );
}
