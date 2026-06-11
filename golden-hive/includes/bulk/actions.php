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
        'artificial_sale' => [
            'label'       => 'Crea saldo fittizio',
            'group'       => 'price',
            'description' => 'Trasforma il prezzo corrente in prezzo scontato: il prezzo che il cliente paga oggi diventa il sale_price e il regular_price viene ricalcolato al rialzo per mostrare lo sconto % indicato. Il prezzo pagato NON cambia, cambia solo come viene presentato.',
            'params'      => [
                'percent'  => 'number',
                'rounding' => 'select:none,2dec,99,00,nearest_1,nearest_5,nearest_10',
            ],
        ],
        'collapse_sale' => [
            'label'       => 'Consolida saldo nel prezzo',
            'group'       => 'price',
            'description' => 'Il sale_price corrente diventa il nuovo regular_price e lo sconto viene rimosso. Inverso di "Crea saldo fittizio": il prezzo pagato non cambia, sparisce solo il badge sconto. Salta i prodotti senza saldo attivo.',
            'params'      => [],
        ],
        'round_prices' => [
            'label'       => 'Normalizza prezzi',
            'group'       => 'price',
            'description' => 'Applica un preset di arrotondamento ai prezzi esistenti senza altre modifiche (es. tutto a .99 o al multiplo di 5). Utile dopo import o markup per uniformare il listino. Salta i prezzi a 0.',
            'params'      => [
                'target'   => 'select:regular_price,sale_price,both',
                'rounding' => 'select:99,00,nearest_5,nearest_10,2dec',
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

        // ── DELETE ──────────────────────────────────────
        'delete_product' => [
            'label'       => 'Elimina prodotto',
            'group'       => 'delete',
            'description' => 'Elimina definitivamente i prodotti selezionati (varianti incluse via cascata WooCommerce). Non tocca le immagini.',
            'params'      => [],
        ],
        'delete_with_media' => [
            'label'       => 'Elimina prodotto + media',
            'group'       => 'delete',
            'description' => 'Elimina il prodotto (varianti incluse) e poi tenta di eliminare featured, gallery e thumbnail delle varianti. Le immagini in whitelist o ancora usate da altri prodotti vengono preservate.',
            'params'      => [],
        ],

        // ── KICKSDB ─────────────────────────────────────
        'kicksdb_refresh_pricing' => [
            'label'       => 'Refresh prezzi KicksDB',
            'group'       => 'kicksdb',
            'description' => 'Aggiorna i prezzi tramite l\'endpoint batch KicksDB /stockx/prices (50 SKU per call). Solo prodotti con _gh_kicksdb_tracked=1 vengono toccati; gli altri sono skippati silenziosamente. Rispetta le conflict rules sulla slice "pricing".',
            'params'      => [],
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

    // ── BATCH-NATIVE ACTIONS ────────────────────────────
    // Alcune azioni operano in batch nativo (una sola call esterna per tutto
    // il set, non una per prodotto). Bypass del per-product loop.
    if ( $action === 'kicksdb_refresh_pricing' && function_exists( 'gh_kicksdb_refresh_pricing' ) ) {
        return gh_bulk_dispatch_kicksdb_refresh( $product_ids );
    }

    $results = [];
    $success = 0;
    $failed  = 0;

    // Suspend WC transient rebuilds during bulk — rebuild once at the end
    $suspend_transients = in_array( $action, [
        'set_sale_percent', 'remove_sale', 'adjust_price', 'markup_percent',
        'discount_percent', 'artificial_sale', 'collapse_sale', 'round_prices',
        'set_stock_status', 'set_stock_quantity', 'set_status',
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
            // Variable parents need a sync after meta writes — but not if
            // we just deleted the product, since WC already tore it down.
            $is_delete = $action === 'delete_product' || $action === 'delete_with_media';
            if ( ! $is_delete && $product->is_type( 'variable' ) ) $parents_to_sync[] = $pid;
        } else {
            $results[ $pid ] = is_wp_error( $result ) ? $result->get_error_message() : (string) $result;
            $failed++;
        }

        // Clear the RUNTIME object cache periodically to avoid memory bloat
        // during long loops. wp_cache_flush_runtime() (WP 6.1+) svuota solo
        // la cache in-memory del processo: il vecchio wp_cache_flush()
        // nukava anche la cache persistente (Redis/Memcached) dell'intero
        // sito ogni 50 prodotti.
        if ( ( $success + $failed ) % 50 === 0 ) {
            if ( function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            } else {
                wp_cache_flush();
            }
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
        'artificial_sale' => gh_create_artificial_sale(
            $product,
            floatval( $params['percent'] ?? 0 ),
            $params['rounding'] ?? '99'
        ),
        'collapse_sale' => gh_collapse_sale( $product ),
        'round_prices'  => gh_round_prices_action(
            $product,
            $params['target']   ?? 'regular_price',
            $params['rounding'] ?? '99'
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

        // ── DELETE ──────────────────────────────────────
        'delete_product'    => gh_bulk_delete_product( $product, false ),
        'delete_with_media' => gh_bulk_delete_product( $product, true ),

        // ── KICKSDB ─────────────────────────────────────
        // Non usa la pipeline per-prodotto perche il batch endpoint KicksDB
        // accetta 50 SKU per call: fare una call per prodotto sarebbe stupido.
        // Si appoggia a gh_kicksdb_refresh_pricing() che gestisce internamente
        // chunking + tracked-filter + conflict rules. Il dispatcher per-prodotto
        // ritorna 'ok' immediatamente; la vera esecuzione la fa il wrapper bulk
        // (vedi sotto).
        'kicksdb_refresh_pricing' => 'ok',

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
 * Crea un saldo "fittizio": il prezzo effettivo corrente (sale se presente,
 * altrimenti regular) diventa il sale_price, e il regular_price viene
 * ricalcolato al rialzo in modo che lo sconto mostrato sia $percent.
 *
 *   regular_nuovo = prezzo_corrente / (1 - percent/100)
 *
 * Il cliente continua a pagare lo stesso prezzo di prima: cambia solo la
 * presentazione (badge sconto + prezzo barrato). Idempotente: ri-applicarla
 * ricalcola il regular partendo sempre dal sale corrente, senza comporre
 * gli aumenti.
 *
 * L'arrotondamento si applica SOLO al regular fittizio (un prezzo barrato
 * che termina in .99 o e un multiplo di 5 sembra piu naturale). Se il
 * rounding farebbe scendere il regular sotto al prezzo corrente, fallback
 * a 2 decimali per garantire regular > sale.
 *
 * @param WC_Product $product
 * @param float      $percent  Sconto apparente da mostrare (1-99).
 * @param string     $rounding Preset di gh_round_price() per il regular fittizio.
 * @return true|string
 */
function gh_create_artificial_sale( WC_Product $product, float $percent, string $rounding ): true|string {

    if ( $percent < 1 || $percent > 99 ) {
        return 'Percentuale sconto non valida (1-99).';
    }

    $divisor = 1 - ( $percent / 100 );

    $compute_regular = static function ( float $price ) use ( $divisor, $rounding ): float {
        $regular = gh_round_price( $price / $divisor, $rounding );
        // Il rounding (es. nearest_10) puo riportare il regular sotto/uguale
        // al prezzo corrente: in quel caso niente preset, solo 2 decimali.
        if ( $regular <= $price ) {
            $regular = round( $price / $divisor, 2 );
        }
        return $regular;
    };

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $sale    = (float) get_post_meta( $var_id, '_sale_price', true );
            $regular = (float) get_post_meta( $var_id, '_regular_price', true );
            $price   = $sale > 0 ? $sale : $regular;
            if ( $price <= 0 ) continue;

            update_post_meta( $var_id, '_regular_price', $compute_regular( $price ) );
            update_post_meta( $var_id, '_sale_price', $price );
            update_post_meta( $var_id, '_price', $price );
        }
    } else {
        $sale    = (float) $product->get_sale_price();
        $regular = (float) $product->get_regular_price();
        $price   = $sale > 0 ? $sale : $regular;
        if ( $price <= 0 ) return true;

        $product->set_regular_price( $compute_regular( $price ) );
        $product->set_sale_price( $price );
        $product->save();
    }

    return true;
}

/**
 * Consolida il saldo nel prezzo: il sale_price corrente diventa il nuovo
 * regular_price e lo sconto viene rimosso. Inverso di
 * gh_create_artificial_sale() — il prezzo pagato non cambia, sparisce il
 * badge sconto. No-op sui prodotti/varianti senza saldo attivo.
 *
 * NB: diverso da remove_sale, che cancella il sale_price e fa RISALIRE il
 * prezzo pagato al regular.
 */
function gh_collapse_sale( WC_Product $product ): true {

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $sale = (float) get_post_meta( $var_id, '_sale_price', true );
            if ( $sale <= 0 ) continue;

            update_post_meta( $var_id, '_regular_price', $sale );
            delete_post_meta( $var_id, '_sale_price' );
            update_post_meta( $var_id, '_price', $sale );
        }
    } else {
        $sale = (float) $product->get_sale_price();
        if ( $sale > 0 ) {
            $product->set_regular_price( $sale );
            $product->set_sale_price( '' );
            $product->save();
        }
    }

    return true;
}

/**
 * Normalizza i prezzi esistenti applicando un preset di arrotondamento
 * (gh_round_price) senza altre trasformazioni. Target 'both' tocca sia
 * regular che sale; i prezzi a 0/vuoti vengono saltati.
 */
function gh_round_prices_action( WC_Product $product, string $target, string $rounding ): true {

    $keys = match ( $target ) {
        'sale_price' => [ '_sale_price' ],
        'both'       => [ '_regular_price', '_sale_price' ],
        default      => [ '_regular_price' ],
    };

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            foreach ( $keys as $meta_key ) {
                $current = (float) get_post_meta( $var_id, $meta_key, true );
                if ( $current <= 0 ) continue;
                $new = gh_round_price( $current, $rounding );
                if ( $new !== $current ) update_post_meta( $var_id, $meta_key, $new );
            }
            $sale = (float) get_post_meta( $var_id, '_sale_price', true );
            update_post_meta( $var_id, '_price', $sale > 0 ? $sale : get_post_meta( $var_id, '_regular_price', true ) );
        }
        return true;
    }

    $changed = false;
    foreach ( $keys as $meta_key ) {
        $current = (float) ( $meta_key === '_sale_price' ? $product->get_sale_price() : $product->get_regular_price() );
        if ( $current <= 0 ) continue;
        $new = gh_round_price( $current, $rounding );
        if ( $new === $current ) continue;
        if ( $meta_key === '_sale_price' ) {
            $product->set_sale_price( $new );
        } else {
            $product->set_regular_price( $new );
        }
        $changed = true;
    }
    if ( $changed ) $product->save();

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

// ── DELETE HELPERS ────────────────────────────────────────────────────────────

/**
 * Elimina definitivamente un prodotto (con cascata varianti via WC).
 * Se $with_media e true, raccoglie prima featured/gallery/thumbnail varianti
 * e poi tenta di eliminarle tramite rp_mm_delete_attachment() che gia
 * rispetta whitelist e controllo di uso puntuale — quindi immagini
 * condivise con altri prodotti o esplicitamente whitelisted vengono
 * preservate silenziosamente.
 *
 * @param WC_Product $product
 * @param bool       $with_media
 * @return true|string
 */
function gh_bulk_delete_product( WC_Product $product, bool $with_media ): true|string {

    $pid = $product->get_id();

    // Raccogli attachment IDs PRIMA di eliminare il prodotto: dopo il delete
    // non sarebbero piu associati.
    $attachment_ids = $with_media ? gh_collect_product_attachment_ids( $product ) : [];

    $result = rp_delete_product( $pid, true ); // force delete (no trash)
    if ( is_wp_error( $result ) ) {
        return $result->get_error_message();
    }

    if ( $with_media && $attachment_ids ) {
        foreach ( $attachment_ids as $att_id ) {
            // Ignoriamo gli errori (whitelist / still in use): sono by-design.
            rp_mm_delete_attachment( $att_id );
        }
    }

    return true;
}

/**
 * Raccoglie gli attachment IDs associati a un prodotto: featured image,
 * gallery e featured image delle varianti (per prodotti variabili).
 *
 * @param WC_Product $product
 * @return int[] Deduplicati, non-zero.
 */
function gh_collect_product_attachment_ids( WC_Product $product ): array {

    $ids = [];

    $featured = (int) $product->get_image_id();
    if ( $featured ) $ids[] = $featured;

    foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
        $gid = (int) $gid;
        if ( $gid ) $ids[] = $gid;
    }

    if ( $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $var_id ) {
            $var = wc_get_product( $var_id );
            if ( ! $var ) continue;
            $var_img = (int) $var->get_image_id();
            if ( $var_img ) $ids[] = $var_img;
        }
    }

    return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Bulk dispatch per kicksdb_refresh_pricing — batch nativo (50 SKU per call
 * lato KicksDB, conflict-rules-aware lato applicazione).
 *
 * Resolva product_ids → SKU, chiama gh_kicksdb_refresh_pricing() una volta
 * sola, mappa i risultati per-prodotto nello shape che gh_execute_bulk_action
 * deve ritornare (compatibile con la UI Filter results table).
 *
 * NOTA: prodotti senza SKU o con _gh_kicksdb_tracked != '1' sono skippati e
 * marcati come 'skipped' nei risultati (non come error).
 */
function gh_bulk_dispatch_kicksdb_refresh( array $product_ids ): array {

    $results = [];
    $skus    = [];
    $sku_to_pid = [];

    foreach ( $product_ids as $pid ) {
        $pid = (int) $pid;
        $product = wc_get_product( $pid );
        if ( ! $product ) {
            $results[ $pid ] = "Prodotto #{$pid} non trovato.";
            continue;
        }
        $sku = $product->get_sku();
        if ( $sku === '' ) {
            $results[ $pid ] = 'SKU vuoto — skip';
            continue;
        }
        $skus[]            = $sku;
        $sku_to_pid[ $sku ] = $pid;
    }

    $success = 0;
    $failed  = 0;

    if ( ! empty( $skus ) ) {
        $resp = gh_kicksdb_refresh_pricing( $skus );

        foreach ( ( $resp['details'] ?? [] ) as $d ) {
            $sku = (string) ( $d['sku'] ?? '' );
            $pid = $sku_to_pid[ $sku ] ?? 0;
            if ( ! $pid ) continue;

            switch ( $d['action'] ?? '' ) {
                case 'updated':
                    $sizes = ! empty( $d['sizes'] ) ? ' (' . implode( ',', $d['sizes'] ) . ')' : '';
                    $results[ $pid ] = 'ok' . $sizes;
                    $success++;
                    break;
                case 'skipped':
                    $results[ $pid ] = 'skip: ' . ( $d['reason'] ?? 'n/a' );
                    break;
                default:
                    $results[ $pid ] = 'error: ' . ( $d['reason'] ?? 'n/a' );
                    $failed++;
            }
        }
    }

    $total = count( $product_ids );

    return [
        'action'  => 'kicksdb_refresh_pricing',
        'total'   => $total,
        'success' => $success,
        'failed'  => $failed,
        'results' => $results,
        'summary' => "{$success}/{$total} prezzi aggiornati" . ( $failed ? ", {$failed} errori" : '' ),
    ];
}
