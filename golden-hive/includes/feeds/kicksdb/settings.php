<?php
/**
 * KicksDB — settings storage (API key, market, pricing formula, cache TTL).
 *
 * Single-row option (no list) perche le settings globali sono uniche per sito.
 * API key NON e loggata / redatta negli output AJAX (vedi handler ajax.php).
 */

defined( 'ABSPATH' ) || exit;

defined( 'GH_KICKSDB_SETTINGS_KEY' ) || define( 'GH_KICKSDB_SETTINGS_KEY', 'gh_kicksdb_settings' );

if ( function_exists( 'gh_kicksdb_get_settings' ) ) return;

/**
 * Default settings shape. Rispecchia la "Pricing Formula" del repo woo-importer
 * ma in forma minimale: un tier flat + floor + rounding.
 */
function gh_kicksdb_default_settings(): array {
    return [
        'api_key'     => '',
        'base_url'    => GH_KICKSDB_BASE_URL_DEFAULT,
        'market'      => 'IT',
        'cache_ttl'   => 24 * HOUR_IN_SECONDS,
        'concurrency' => GH_KICKSDB_DEFAULT_CONCUR,

        // Pricing formula: selling = round(max(market * (1 + margin), floor))
        'pricing' => [
            'margin_pct'    => 20.0,   // +20% sul market price
            'floor_price'   => 0.0,    // nessun floor
            'rounding_mode' => 'ceil', // 'ceil' | 'floor' | 'round'
            'rounding_step' => 1.0,    // arrotonda all'intero
            'currency'      => 'EUR',
        ],

        // Image gallery: main + every Nth 360-frame (se KicksDB li fornisce)
        'gallery' => [
            'include_main'      => true,
            'include_360'       => false, // da abilitare quando il 360-viewer e disponibile
            'every_nth_360'     => 6,
        ],
    ];
}

/**
 * Ritorna settings correnti (merge con default per chiavi mancanti).
 */
function gh_kicksdb_get_settings(): array {
    $stored = get_option( GH_KICKSDB_SETTINGS_KEY, [] );
    if ( ! is_array( $stored ) ) $stored = [];
    return gh_kicksdb_merge_settings( gh_kicksdb_default_settings(), $stored );
}

/**
 * Merge ricorsivo shallow: le chiavi di $override sovrascrivono $base.
 */
function gh_kicksdb_merge_settings( array $base, array $override ): array {
    foreach ( $override as $k => $v ) {
        if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
            $base[ $k ] = gh_kicksdb_merge_settings( $base[ $k ], $v );
        } else {
            $base[ $k ] = $v;
        }
    }
    return $base;
}

/**
 * Scrive settings (merge con esistenti). Ritorna il record salvato.
 *
 * @param array $data Partial update.
 * @return array Settings finali.
 */
function gh_kicksdb_save_settings( array $data ): array {
    $current = gh_kicksdb_get_settings();
    $merged  = gh_kicksdb_merge_settings( $current, $data );

    // Sanitize
    $merged['api_key']  = (string) $merged['api_key'];
    $merged['base_url'] = esc_url_raw( (string) $merged['base_url'] ) ?: GH_KICKSDB_BASE_URL_DEFAULT;
    $merged['market']   = strtoupper( substr( (string) $merged['market'], 0, 3 ) );

    $merged['cache_ttl']   = max( 60, min( 7 * DAY_IN_SECONDS, (int) $merged['cache_ttl'] ) );
    $merged['concurrency'] = max( 1, min( 16, (int) $merged['concurrency'] ) );

    $p = $merged['pricing'];
    $p['margin_pct']    = max( -99.0, min( 1000.0, (float) $p['margin_pct'] ) );
    $p['floor_price']   = max( 0.0, (float) $p['floor_price'] );
    $p['rounding_mode'] = in_array( $p['rounding_mode'], [ 'ceil', 'floor', 'round' ], true ) ? $p['rounding_mode'] : 'ceil';
    $p['rounding_step'] = max( 0.01, (float) $p['rounding_step'] );
    $p['currency']      = strtoupper( substr( (string) $p['currency'], 0, 3 ) );
    $merged['pricing']  = $p;

    $g = $merged['gallery'];
    $g['include_main']  = (bool) $g['include_main'];
    $g['include_360']   = (bool) $g['include_360'];
    $g['every_nth_360'] = max( 1, min( 60, (int) $g['every_nth_360'] ) );
    $merged['gallery']  = $g;

    update_option( GH_KICKSDB_SETTINGS_KEY, $merged, false );
    return $merged;
}

/**
 * Ritorna settings con campi sensibili redatti (per output AJAX/UI).
 */
function gh_kicksdb_get_settings_redacted(): array {
    $s = gh_kicksdb_get_settings();
    if ( ! empty( $s['api_key'] ) ) {
        $k = (string) $s['api_key'];
        $s['api_key'] = strlen( $k ) > 4
            ? str_repeat( '•', 8 ) . substr( $k, -4 )
            : '••••';
    }
    return $s;
}

/**
 * Convenience: ritorna la pricing formula come array semplice.
 */
function gh_kicksdb_get_pricing_formula(): array {
    $s = gh_kicksdb_get_settings();
    return $s['pricing'];
}
