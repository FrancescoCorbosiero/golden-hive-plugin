<?php
/**
 * Placeholders — estrazione e namespacing dei token {UPPERCASE_KEY}.
 *
 * Sintassi unica: {NAMESPACE_FIELD} oppure {NAMESPACE_N_FIELD} per PRODUCT.
 * Namespace riconosciuti: BRAND, CAMPAIGN, PRODUCT, RECIPIENT, META.
 * Tutto il resto e UNKNOWN (il validator lo flagga come NAMESPACE_VIOLATION).
 *
 * Nessun hook WordPress — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

// Const indipendente dal guard delle funzioni: renderer/validator ne dipendono
// e non devono mai beccarla undefined anche se qualcun altro ha definito
// rp_em_extract_placeholders con una firma diversa (legacy lowercase).
if ( ! defined( 'RP_EM_PLACEHOLDER_REGEX' ) ) {
    /**
     * Regex unica per token multi-layer.
     * Match: {BRAND_NAME}, {CAMPAIGN_HERO_TITLE}, {PRODUCT_1_PRICE}, {RECIPIENT_FIRST_NAME}, {META_YEAR}.
     * Non match: {first_name}, { BRAND_NAME }, {{double}}.
     */
    define( 'RP_EM_PLACEHOLDER_REGEX', '/\{([A-Z][A-Z0-9_]*)\}/' );
}

// Guard sulle funzioni: rp_em_extract_namespace e una funzione NUOVA esclusiva
// del multi-layer system. Evita la collisione col legacy rp_em_extract_placeholders
// (lowercase) che il vecchio plugin standalone poteva definire.
if ( function_exists( 'rp_em_extract_namespace' ) ) return;

/**
 * Namespace riconosciuti.
 *
 * @return string[]
 */
function rp_em_known_namespaces(): array {
    return [ 'BRAND', 'CAMPAIGN', 'PRODUCT', 'RECIPIENT', 'META' ];
}

/**
 * Estrae tutti i placeholder da una stringa HTML.
 * Deduplicato, in ordine di prima apparizione.
 *
 * @param string $html
 * @return string[] Chiavi placeholder senza graffe.
 *
 * Esempio:
 *   rp_em_extract_placeholders('<h1>{BRAND_NAME}</h1><p>{CAMPAIGN_HERO_TITLE}</p>')
 *   // => ['BRAND_NAME', 'CAMPAIGN_HERO_TITLE']
 */
function rp_em_extract_placeholders( string $html ): array {
    if ( $html === '' ) return [];
    preg_match_all( RP_EM_PLACEHOLDER_REGEX, $html, $m );
    return array_values( array_unique( $m[1] ?? [] ) );
}

/**
 * Classifica una chiave placeholder nel suo namespace.
 * PRODUCT_N_FIELD ritorna 'PRODUCT' (l'indice si estrae a parte).
 *
 * @param string $key
 * @return string 'BRAND' | 'CAMPAIGN' | 'PRODUCT' | 'RECIPIENT' | 'META' | 'UNKNOWN'
 */
function rp_em_extract_namespace( string $key ): string {
    foreach ( rp_em_known_namespaces() as $ns ) {
        if ( str_starts_with( $key, $ns . '_' ) ) return $ns;
    }
    return 'UNKNOWN';
}

/**
 * Estrae l'indice N da una chiave PRODUCT_N_FIELD.
 * Torna null se non e una chiave PRODUCT valida.
 *
 * @param string $key
 * @return int|null Indice 1-based, o null.
 *
 * Esempio:
 *   rp_em_product_index('PRODUCT_1_PRICE')  // => 1
 *   rp_em_product_index('PRODUCT_12_NAME')  // => 12
 *   rp_em_product_index('PRODUCT_X_NAME')   // => null
 *   rp_em_product_index('BRAND_NAME')       // => null
 */
function rp_em_product_index( string $key ): ?int {
    if ( ! preg_match( '/^PRODUCT_(\d+)_/', $key, $m ) ) return null;
    $n = (int) $m[1];
    return $n > 0 ? $n : null;
}

/**
 * Estrae il nome del campo da una chiave PRODUCT_N_FIELD.
 *
 * @param string $key
 * @return string|null 'PRICE', 'NAME_LINE1' etc., o null.
 */
function rp_em_product_field( string $key ): ?string {
    if ( ! preg_match( '/^PRODUCT_\d+_(.+)$/', $key, $m ) ) return null;
    return $m[1];
}

/**
 * True se la chiave appartiene al namespace RECIPIENT — deve restare letterale
 * nell'HTML finale (sostituita dall'ESP/SES a send-time).
 *
 * @param string $key
 * @return bool
 */
function rp_em_is_recipient_placeholder( string $key ): bool {
    return rp_em_extract_namespace( $key ) === 'RECIPIENT';
}

/**
 * Raggruppa un elenco di placeholder per namespace.
 * Utile per l'UI: mostrare i placeholder raggruppati.
 *
 * @param string[] $keys
 * @return array { 'BRAND' => string[], 'CAMPAIGN' => [...], ..., 'UNKNOWN' => [...] }
 */
function rp_em_group_placeholders( array $keys ): array {
    $out = [ 'BRAND' => [], 'CAMPAIGN' => [], 'PRODUCT' => [], 'RECIPIENT' => [], 'META' => [], 'UNKNOWN' => [] ];
    foreach ( $keys as $k ) {
        $ns = rp_em_extract_namespace( $k );
        $out[ $ns ][] = $k;
    }
    return $out;
}
