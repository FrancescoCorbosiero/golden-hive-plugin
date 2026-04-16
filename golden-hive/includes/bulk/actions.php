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
        'assign_brands' => [
            'label'       => 'Aggiungi brand',
            'group'       => 'taxonomy',
            'description' => 'Aggiunge uno o piu brand (product_brand) ai prodotti selezionati.',
            'params'      => [ 'brand_ids' => 'term_ids' ],
        ],
        'remove_brands' => [
            'label'       => 'Rimuovi brand',
            'group'       => 'taxonomy',
            'description' => 'Rimuove brand specifici dai prodotti selezionati.',
            'params'      => [ 'brand_ids' => 'term_ids' ],
        ],
        'set_brands' => [
            'label'       => 'Imposta brand',
            'group'       => 'taxonomy',
            'description' => 'Sostituisce TUTTI i brand dei prodotti selezionati.',
            'params'      => [ 'brand_ids' => 'term_ids' ],
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
        'markup_percent' => [
            'label'       => 'Aumento prezzo %',
            'group'       => 'price',
            'description' => 'Aumenta il prezzo della percentuale indicata (es. 30 = +30%). Salta i prodotti con prezzo target a 0.',
            'params'      => [
                'percent'  => 'number',
                'target'   => 'select:regular_price,sale_price',
                'rounding' => 'select:none,2dec,99,00,nearest_1,nearest_5,nearest_10',
            ],
        ],
        'discount_percent' => [
            'label'       => 'Sconto prezzo %',
            'group'       => 'price',
            'description' => 'Riduce il prezzo della percentuale indicata (es. 20 = -20%). Salta i prodotti con prezzo target a 0.',
            'params'      => [
                'percent'  => 'number',
                'target'   => 'select:regular_price,sale_price',
                'rounding' => 'select:none,2dec,99,00,nearest_1,nearest_5,nearest_10',
            ],
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

        // ── MEDIA ───────────────────────────────────────
        'remove_first_gallery_image' => [
            'label'       => 'Rimuovi prima immagine galleria',
            'group'       => 'media',
            'description' => 'Rimuove la PRIMA immagine della gallery (non tocca la featured). Utile quando un feed importa una thumb duplicata come primo elemento.',
            'params'      => [],
        ],
        'clear_gallery' => [
            'label'       => 'Svuota galleria',
            'group'       => 'media',
            'description' => 'Rimuove TUTTE le immagini della gallery (non tocca la featured).',
            'params'      => [],
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
 * Performance: suspends WC transient rebuilds during the batch, processes
 * all products, then flushes caches once at the end. For price/stock
 * actions on variable products, uses direct meta writes instead of loading
 * each variation through WC CRUD.
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

    // Suspend WC transient rebuilds during bulk — rebuild once at the end
    $suspend_transients = in_array( $action, [
        'set_sale_percent', 'remove_sale', 'adjust_price', 'markup_percent',
        'discount_percent', 'set_stock_status', 'set_stock_quantity', 'set_status',
    ], true );

    if ( $suspend_transients ) {
        add_filter( 'woocommerce_product_object_updated_props', '__return_empty_array', 999 );
    }

    // Track variable parents that need sync at the end
    $parents_to_sync = [];

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
            if ( $product->is_type( 'variable' ) ) $parents_to_sync[] = $pid;
        } else {
            $results[ $pid ] = is_wp_error( $result ) ? $result->get_error_message() : (string) $result;
            $failed++;
        }

        // Clear object cache periodically to avoid memory bloat
        if ( ( $success + $failed ) % 50 === 0 ) {
            wc_delete_product_transients();
            wp_cache_flush();
        }
    }

    if ( $suspend_transients ) {
        remove_filter( 'woocommerce_product_object_updated_props', '__return_empty_array', 999 );
    }

    // Batch-sync all variable parents once at the end
    foreach ( array_unique( $parents_to_sync ) as $parent_id ) {
        WC_Product_Variable::sync( $parent_id );
    }

    if ( $suspend_transients ) {
        wc_delete_product_transients();
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

        'assign_brands' => rp_cm_assign_product_categories( $pid, $params['brand_ids'] ?? [], 'product_brand' ),
        'remove_brands' => rp_cm_remove_product_categories( $pid, $params['brand_ids'] ?? [], 'product_brand' ),
        'set_brands'    => rp_cm_set_product_categories( $pid, $params['brand_ids'] ?? [], 'product_brand' ),

        'assign_tags' => gh_assign_product_tags( $pid, $params['tag_ids'] ?? [] ),
        'remove_tags' => gh_remove_product_tags( $pid, $params['tag_ids'] ?? [] ),

        // ── STATUS ──────────────────────────────────────
        'set_status' => gh_set_product_status( $product, $params['status'] ?? 'publish' ),

        // ── PRICE ───────────────────────────────────────
        'set_sale_percent' => gh_set_sale_percent( $product, floatval( $params['percent'] ?? 0 ) ),
        'remove_sale'      => gh_remove_sale( $product ),
        'adjust_price'     => gh_adjust_price( $product, floatval( $params['amount'] ?? 0 ), $params['target'] ?? 'regular_price' ),
        'markup_percent'   => gh_apply_percent_change(
            $product,
            1 + ( floatval( $params['percent'] ?? 0 ) / 100 ),
            $params['target']   ?? 'regular_price',
            $params['rounding'] ?? '2dec'
        ),
        'discount_percent' => gh_apply_percent_change(
            $product,
            1 - ( floatval( $params['percent'] ?? 0 ) / 100 ),
            $params['target']   ?? 'regular_price',
            $params['rounding'] ?? '2dec'
        ),

        // ── STOCK ───────────────────────────────────────
        'set_stock_status'   => gh_set_stock_status( $product, $params['stock_status'] ?? 'instock' ),
        'set_stock_quantity' => gh_set_stock_quantity( $product, intval( $params['quantity'] ?? 0 ) ),

        // ── SEO ─────────────────────────────────────────
        'set_seo_template' => gh_apply_seo_template( $product, $params ),

        // ── MEDIA ───────────────────────────────────────
        'remove_first_gallery_image' => gh_remove_first_gallery_image( $product ),
        'clear_gallery'              => gh_clear_gallery( $product ),

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

    wp_update_post( [ 'ID' => $product->get_id(), 'post_status' => $status ] );
    clean_post_cache( $product->get_id() );
    return true;
}

/**
 * Imposta sconto percentuale sul prezzo regolare.
 * Applica anche alle varianti per prodotti variabili.
 * Uses direct meta writes for variations (skip WC CRUD overhead).
 */
function gh_set_sale_percent( WC_Product $product, float $percent ): true {

    if ( $percent <= 0 || $percent >= 100 ) {
        return true;
    }

    $multiplier = 1 - ( $percent / 100 );

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $regular = (float) get_post_meta( $var_id, '_regular_price', true );
            if ( $regular > 0 ) {
                $sale = round( $regular * $multiplier, 2 );
                update_post_meta( $var_id, '_sale_price', $sale );
                update_post_meta( $var_id, '_price', $sale );
            }
        }
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
            delete_post_meta( $var_id, '_sale_price' );
            $regular = get_post_meta( $var_id, '_regular_price', true );
            update_post_meta( $var_id, '_price', $regular );
        }
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

    $meta_key = $target === 'sale_price' ? '_sale_price' : '_regular_price';

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $current = (float) get_post_meta( $var_id, $meta_key, true );
            $new     = max( 0, round( $current + $amount, 2 ) );
            update_post_meta( $var_id, $meta_key, $new > 0 ? $new : '' );
            $sale = (float) get_post_meta( $var_id, '_sale_price', true );
            update_post_meta( $var_id, '_price', $sale > 0 ? $sale : get_post_meta( $var_id, '_regular_price', true ) );
        }
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
            update_post_meta( $var_id, '_stock_status', $status );
        }
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

    $stock_status = $quantity > 0 ? 'instock' : 'outofstock';

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            update_post_meta( $var_id, '_manage_stock', 'yes' );
            update_post_meta( $var_id, '_stock', $quantity );
            update_post_meta( $var_id, '_stock_status', $stock_status );
        }
    } else {
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $quantity );
        $product->set_stock_status( $stock_status );
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

    // Resolve brand: prima prova la tassonomia product_brand (Woo Brands),
    // altrimenti fallback alla prima product_cat (legacy).
    $brand_names = function_exists( 'gh_get_product_brand_names' )
        ? gh_get_product_brand_names( $pid )
        : [];
    if ( ! empty( $brand_names ) ) {
        $brand = $brand_names[0];
    } else {
        $cats  = rp_cm_get_product_category_names( $pid );
        $brand = $cats[0] ?? '';
    }

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

/**
 * Rimuove la prima immagine della gallery del prodotto (non tocca la featured).
 *
 * No-op se la gallery e gia vuota: la action ritorna true e il product non
 * viene ri-salvato. Scenario tipico di uso: un feed importa una thumb
 * duplicata come primo elemento della gallery e vogliamo ripulirla in bulk.
 *
 * @return true|string
 */
function gh_remove_first_gallery_image( WC_Product $product ): true|string {

    $ids = $product->get_gallery_image_ids();
    if ( empty( $ids ) ) return true;

    array_shift( $ids );
    $product->set_gallery_image_ids( array_map( 'intval', $ids ) );
    $product->save();
    return true;
}

/**
 * Svuota completamente la gallery del prodotto (non tocca la featured).
 *
 * @return true
 */
function gh_clear_gallery( WC_Product $product ): true {

    $product->set_gallery_image_ids( [] );
    $product->save();
    return true;
}

/**
 * Applica un cambio percentuale (markup o sconto) al prezzo di un prodotto.
 *
 * Il fattore moltiplicativo e gia calcolato dal chiamante:
 *   - markup +30%  → factor = 1.30
 *   - sconto -20%  → factor = 0.80
 *
 * Comportamento:
 * - Salta prodotti con il prezzo target a 0 (no-op safe: non scriviamo "0" su
 *   un sale vuoto, e non modifichiamo prodotti senza prezzo regolare).
 * - Per prodotti variabili itera su tutte le varianti e poi richiama
 *   WC_Product_Variable::sync(), come fanno set_sale_percent / adjust_price.
 * - Il risultato e arrotondato secondo $rounding (vedi gh_round_price()).
 * - Clamp finale a 0 per coerenza con adjust_price.
 *
 * @param WC_Product $product
 * @param float      $factor   Moltiplicatore. >1 = aumento, <1 = sconto.
 * @param string     $target   'regular_price' | 'sale_price'
 * @param string     $rounding Chiave preset di gh_round_price().
 * @return true
 */
function gh_apply_percent_change( WC_Product $product, float $factor, string $target, string $rounding ): true {

    if ( abs( $factor - 1 ) < 0.0001 ) return true;

    $target   = $target === 'sale_price' ? 'sale_price' : 'regular_price';
    $meta_key = $target === 'sale_price' ? '_sale_price' : '_regular_price';

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $current = (float) get_post_meta( $var_id, $meta_key, true );
            if ( $current <= 0 ) continue;
            $new = max( 0, gh_round_price( $current * $factor, $rounding ) );
            update_post_meta( $var_id, $meta_key, $new > 0 ? $new : '' );
            $sale = (float) get_post_meta( $var_id, '_sale_price', true );
            update_post_meta( $var_id, '_price', $sale > 0 ? $sale : get_post_meta( $var_id, '_regular_price', true ) );
        }
    } else {
        gh_apply_percent_to_single( $product, $factor, $target, $rounding );
    }

    return true;
}

/**
 * Applica un cambio percentuale a un singolo prodotto (simple o variation).
 * Helper interno di gh_apply_percent_change(): non chiamare dall'esterno.
 */
function gh_apply_percent_to_single( WC_Product $product, float $factor, string $target, string $rounding ): void {

    $current = (float) ( $target === 'sale_price'
        ? $product->get_sale_price()
        : $product->get_regular_price() );

    if ( $current <= 0 ) return;

    $new = max( 0, gh_round_price( $current * $factor, $rounding ) );

    if ( $target === 'sale_price' ) {
        $product->set_sale_price( $new > 0 ? $new : '' );
    } else {
        $product->set_regular_price( $new );
    }
    $product->save();
}

/**
 * Arrotonda un prezzo secondo un preset.
 *
 * Preset disponibili:
 * - 'none'       → nessun arrotondamento (full precision)
 * - '2dec'       → round a 2 decimali (default storico del codice)
 * - '99'         → ending .99 (es. 12.34 → 12.99, 13.01 → 13.99)
 * - '00'         → ending .00 (round al piu vicino intero, es. 12.34 → 12)
 * - 'nearest_1'  → alias di '00' — round al piu vicino intero
 * - 'nearest_5'  → multiplo di 5 piu vicino  (es. 23 → 25, 27 → 25)
 * - 'nearest_10' → multiplo di 10 piu vicino (es. 23 → 20, 27 → 30)
 *
 * Helper riusabile da future bulk action o da feature non-bulk: tienilo qui
 * come "primitiva" del modulo bulk.
 *
 * @param float  $value
 * @param string $mode
 * @return float
 */
function gh_round_price( float $value, string $mode ): float {

    if ( $value <= 0 ) return 0.0;

    return match ( $mode ) {
        'none'                  => $value,
        '99'                    => floor( $value ) + 0.99,
        '00', 'nearest_1'       => (float) round( $value ),
        'nearest_5'             => (float) ( round( $value / 5 ) * 5 ),
        'nearest_10'            => (float) ( round( $value / 10 ) * 10 ),
        default                 => round( $value, 2 ), // '2dec' e fallback sicuro.
    };
}
