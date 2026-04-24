<?php
/**
 * KicksDB — pricing extraction + markup formula.
 *
 * Due layer distinti:
 *
 * 1. EXTRACT: filtra la response dell'endpoint /stockx/prices per ottenere il
 *    "lowest ask di tipo standard" per taglia. GOTCHA: la response ha piu
 *    righe per (variant_id, size) con 'type' diverso (standard / express_*).
 *    Solo 'standard' e il prezzo di mercato reale; gli 'express_*' sono lo
 *    stesso oggetto con shipping premium pre-pagato. Inoltre anche DENTRO
 *    type=standard la stessa size puo apparire piu volte (seller listings
 *    diversi). Si prende il MIN, che corrisponde al "lowest ask".
 *
 * 2. APPLY: dato il market price per taglia, calcola il selling price locale
 *    con la formula configurata nelle settings:
 *        selling = round(max(market * (1 + margin_pct / 100), floor_price))
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_kicksdb_extract_standard_prices' ) ) return;

/**
 * Estrae la mappa size_eu → lowest ask standard dalla response di /stockx/prices.
 *
 * IMPORTANTE: la response usa size formato US ('size_type': 'us m') nei samples
 * che ho visto. Quando disponibile una corrispondenza EU (via variants endpoint
 * o via il size_eu del product-full con display=variants) si usa quella. Qui
 * accettiamo una mappa di conversione esterna sku→size_us→size_eu, altrimenti
 * ritorniamo keyed per la size nativa della response.
 *
 * @param array $prices_response Output di gh_kicksdb_get_prices_batch (o il 'data' del raw).
 * @param array $size_remap      Opzionale: mappa "sku|size_native" → "size_eu".
 * @return array Mappa annidata: sku → { size => lowest_ask_price_cents }.
 *               Size e la size EU se remap disponibile, altrimenti la native.
 *               Prezzo in currency della response (di solito USD o EUR a seconda del market).
 */
function gh_kicksdb_extract_standard_prices( array $prices_response, array $size_remap = [] ): array {

    $rows = $prices_response['data'] ?? $prices_response;
    if ( ! is_array( $rows ) ) return [];

    $out = [];

    foreach ( $rows as $product ) {
        $sku      = (string) ( $product['sku'] ?? '' );
        if ( $sku === '' ) continue;

        $variants = $product['variants'] ?? [];
        if ( ! is_array( $variants ) ) continue;

        foreach ( $variants as $v ) {
            // Filtro #1: solo type=standard (ignora express_*)
            if ( ( $v['type'] ?? '' ) !== 'standard' ) continue;

            $price = (float) ( $v['price'] ?? 0 );
            if ( $price <= 0 ) continue;

            $size_native = (string) ( $v['size'] ?? '' );
            if ( $size_native === '' ) continue;

            // Remap a EU se disponibile
            $remap_key = $sku . '|' . $size_native;
            $size_key  = $size_remap[ $remap_key ] ?? $size_native;

            // Filtro #2: MIN per (sku, size) — lowest ask
            if ( ! isset( $out[ $sku ][ $size_key ] ) || $price < $out[ $sku ][ $size_key ] ) {
                $out[ $sku ][ $size_key ] = $price;
            }
        }
    }

    return $out;
}

/**
 * Applica la formula di markup al market price.
 *
 * @param float $market_price
 * @param array $formula Output di gh_kicksdb_get_pricing_formula().
 * @return float Selling price arrotondato alla currency.
 */
function gh_kicksdb_apply_markup( float $market_price, array $formula ): float {

    if ( $market_price <= 0 ) return 0.0;

    $margin = (float) ( $formula['margin_pct'] ?? 0 );
    $floor  = (float) ( $formula['floor_price'] ?? 0 );
    $mode   = (string) ( $formula['rounding_mode'] ?? 'ceil' );
    $step   = max( 0.01, (float) ( $formula['rounding_step'] ?? 1.0 ) );

    $base = $market_price * ( 1 + $margin / 100 );
    $base = max( $base, $floor );

    // Rounding a $step
    $units = $base / $step;
    $units = match ( $mode ) {
        'floor' => floor( $units ),
        'round' => round( $units ),
        default => ceil( $units ),
    };

    return round( $units * $step, 2 );
}

/**
 * Applica il markup a tutta la mappa sku → size → market_price.
 *
 * @return array sku → size => { market, selling, currency }
 */
function gh_kicksdb_apply_markup_to_map( array $prices_map, ?array $formula = null ): array {

    $formula = $formula ?? gh_kicksdb_get_pricing_formula();
    $curr    = (string) ( $formula['currency'] ?? 'EUR' );
    $out     = [];

    foreach ( $prices_map as $sku => $sizes ) {
        foreach ( (array) $sizes as $size => $market ) {
            $out[ $sku ][ $size ] = [
                'market'   => (float) $market,
                'selling'  => gh_kicksdb_apply_markup( (float) $market, $formula ),
                'currency' => $curr,
            ];
        }
    }

    return $out;
}

/**
 * Estrae pricing dalla response "full product" (GET /products/{sku}?display=variants).
 * Comodo quando si fa enrichment + pricing in una sola call e si vuole evitare
 * il batch endpoint.
 *
 * ATTENZIONE: usa variant.lowest_ask che NON e filtrato per type standard (il
 * product endpoint non ha il concetto di type). E il "primo" lowest ask visto
 * dall'API, generalmente equivalente ma potenzialmente meno preciso del batch.
 * Usa questa funzione per il PREVIEW iniziale; il REFRESH periodico deve usare
 * il batch endpoint.
 *
 * @return array size_eu → lowest_ask
 */
function gh_kicksdb_extract_prices_from_full( array $full_response ): array {

    $data     = $full_response['body']['data'] ?? $full_response['data'] ?? $full_response;
    $variants = $data['variants'] ?? [];
    if ( ! is_array( $variants ) ) return [];

    $out = [];
    foreach ( $variants as $v ) {
        $eu    = (string) ( $v['size_eu'] ?? '' );
        $price = (float) ( $v['lowest_ask'] ?? 0 );
        if ( $eu === '' || $price <= 0 ) continue;
        if ( ! isset( $out[ $eu ] ) || $price < $out[ $eu ] ) {
            $out[ $eu ] = $price;
        }
    }
    return $out;
}
