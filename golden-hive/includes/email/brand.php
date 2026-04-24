<?php
/**
 * Brand — configurazione brand globale del sito (single-row).
 *
 * Il plugin gira dentro un sito brandizzato: il brand e il sito. Una sola
 * configurazione, non una lista. Persistita in wp_options['rp_em_brand'] come
 * array associativo di chiavi BRAND_*.
 *
 * Nessun hook WordPress — solo logica pura di storage + defaults.
 */

defined( 'ABSPATH' ) || exit;

// Costante PRIMA del guard (vedi nota in validator.php sul PHP hoisting).
defined( 'RP_EM_BRAND_KEY' ) || define( 'RP_EM_BRAND_KEY', 'rp_em_brand' );

if ( function_exists( 'rp_em_get_brand' ) ) return;

/**
 * Ritorna la configurazione brand completa, con defaults per le chiavi
 * mancanti. Sempre un array (mai null).
 *
 * @return array { BRAND_* => string }
 */
function rp_em_get_brand(): array {
    $saved = get_option( RP_EM_BRAND_KEY, [] );
    if ( ! is_array( $saved ) ) $saved = [];

    $defaults = rp_em_get_brand_defaults();
    return array_merge( $defaults, $saved );
}

/**
 * Salva (merge) le chiavi brand passate. I campi non menzionati non vengono
 * toccati. Per azzerare un campo, passare stringa vuota.
 *
 * @param array $data Subset di chiavi BRAND_*.
 * @return bool True se l'update ha avuto successo.
 *
 * Esempio:
 *   rp_em_save_brand([ 'BRAND_NAME' => 'Acme', 'BRAND_COLOR_PRIMARY' => '#3d7fff' ]);
 */
function rp_em_save_brand( array $data ): bool {
    $existing = get_option( RP_EM_BRAND_KEY, [] );
    if ( ! is_array( $existing ) ) $existing = [];

    $clean = [];
    foreach ( $data as $key => $value ) {
        // Accetta solo chiavi BRAND_*. Scarta tutto il resto.
        if ( ! is_string( $key ) || ! str_starts_with( $key, 'BRAND_' ) ) continue;
        if ( ! preg_match( '/^BRAND_[A-Z0-9_]+$/', $key ) ) continue;

        $clean[ $key ] = rp_em_sanitize_brand_value( $key, (string) $value );
    }

    $merged = array_merge( $existing, $clean );
    return update_option( RP_EM_BRAND_KEY, $merged, false );
}

/**
 * Ripristina la configurazione brand ai valori di default.
 *
 * @return bool
 */
function rp_em_reset_brand(): bool {
    return update_option( RP_EM_BRAND_KEY, rp_em_get_brand_defaults(), false );
}

/**
 * Defaults sensati per tutte le chiavi BRAND_*.
 * Caricati da _seed/demo-brand.php. Palette neutra, testi placeholder.
 *
 * @return array
 */
function rp_em_get_brand_defaults(): array {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $path = __DIR__ . '/_seed/demo-brand.php';
    if ( is_readable( $path ) ) {
        $data = include $path;
        if ( is_array( $data ) ) {
            $cache = $data;
            return $cache;
        }
    }

    // Fallback inline se il file seed e mancante.
    $cache = [
        'BRAND_NAME'              => get_bloginfo( 'name' ),
        'BRAND_TAGLINE'           => '',
        'BRAND_LOGO_URL'          => '',
        'BRAND_LOGO_ALT'          => get_bloginfo( 'name' ),
        'BRAND_WEBSITE_URL'       => home_url(),
        'BRAND_COLOR_PRIMARY'     => '#0c0d10',
        'BRAND_COLOR_SECONDARY'   => '#3d7fff',
        'BRAND_COLOR_ACCENT'      => '#22c78b',
        'BRAND_COLOR_BG'          => '#ffffff',
        'BRAND_COLOR_TEXT'        => '#111111',
        'BRAND_FONT_HEADING'      => 'Arial, sans-serif',
        'BRAND_FONT_BODY'         => 'Arial, sans-serif',
        'BRAND_PILLAR_1_TITLE'    => '',
        'BRAND_PILLAR_1_DESC'     => '',
        'BRAND_PILLAR_2_TITLE'    => '',
        'BRAND_PILLAR_2_DESC'     => '',
        'BRAND_PILLAR_3_TITLE'    => '',
        'BRAND_PILLAR_3_DESC'     => '',
        'BRAND_INSTAGRAM'         => '',
        'BRAND_FACEBOOK'          => '',
        'BRAND_TIKTOK'            => '',
        'BRAND_EMAIL'             => get_option( 'admin_email', '' ),
        'BRAND_PHONE'             => '',
        'BRAND_LEGAL_ADDRESS'     => '',
        'BRAND_LEGAL_VAT'         => '',
        'BRAND_UNSUBSCRIBE_URL'   => home_url( '/unsubscribe' ),
    ];
    return $cache;
}

/**
 * Schema dichiarativo delle chiavi brand, raggruppate per sezione UI.
 * Usato dall'editor brand per generare il form e dal validator per sapere
 * quali campi sono URL / hex / email / richiesti.
 *
 * @return array[]
 */
function rp_em_get_brand_schema(): array {
    return [
        [
            'section' => 'Identity',
            'fields'  => [
                [ 'key' => 'BRAND_NAME',        'label' => 'Nome',        'type' => 'text',  'required' => true ],
                [ 'key' => 'BRAND_TAGLINE',     'label' => 'Tagline',     'type' => 'text' ],
                [ 'key' => 'BRAND_LOGO_URL',    'label' => 'Logo URL',    'type' => 'url',   'required' => true ],
                [ 'key' => 'BRAND_LOGO_ALT',    'label' => 'Logo ALT',    'type' => 'text' ],
                [ 'key' => 'BRAND_WEBSITE_URL', 'label' => 'Sito web',    'type' => 'url',   'required' => true ],
            ],
        ],
        [
            'section' => 'Palette',
            'fields'  => [
                [ 'key' => 'BRAND_COLOR_PRIMARY',   'label' => 'Primary',   'type' => 'color', 'required' => true ],
                [ 'key' => 'BRAND_COLOR_SECONDARY', 'label' => 'Secondary', 'type' => 'color', 'required' => true ],
                [ 'key' => 'BRAND_COLOR_ACCENT',    'label' => 'Accent',    'type' => 'color', 'required' => true ],
                [ 'key' => 'BRAND_COLOR_BG',        'label' => 'Background','type' => 'color', 'required' => true ],
                [ 'key' => 'BRAND_COLOR_TEXT',      'label' => 'Text',      'type' => 'color', 'required' => true ],
            ],
        ],
        [
            'section' => 'Typography',
            'fields'  => [
                [ 'key' => 'BRAND_FONT_HEADING', 'label' => 'Font heading', 'type' => 'text' ],
                [ 'key' => 'BRAND_FONT_BODY',    'label' => 'Font body',    'type' => 'text' ],
            ],
        ],
        [
            'section' => 'Pillars',
            'fields'  => [
                [ 'key' => 'BRAND_PILLAR_1_TITLE', 'label' => 'Pillar 1 · title',      'type' => 'text' ],
                [ 'key' => 'BRAND_PILLAR_1_DESC',  'label' => 'Pillar 1 · description','type' => 'textarea' ],
                [ 'key' => 'BRAND_PILLAR_2_TITLE', 'label' => 'Pillar 2 · title',      'type' => 'text' ],
                [ 'key' => 'BRAND_PILLAR_2_DESC',  'label' => 'Pillar 2 · description','type' => 'textarea' ],
                [ 'key' => 'BRAND_PILLAR_3_TITLE', 'label' => 'Pillar 3 · title',      'type' => 'text' ],
                [ 'key' => 'BRAND_PILLAR_3_DESC',  'label' => 'Pillar 3 · description','type' => 'textarea' ],
            ],
        ],
        [
            'section' => 'Social',
            'fields'  => [
                [ 'key' => 'BRAND_INSTAGRAM', 'label' => 'Instagram URL', 'type' => 'url' ],
                [ 'key' => 'BRAND_FACEBOOK',  'label' => 'Facebook URL',  'type' => 'url' ],
                [ 'key' => 'BRAND_TIKTOK',    'label' => 'TikTok URL',    'type' => 'url' ],
                [ 'key' => 'BRAND_EMAIL',     'label' => 'Email pubblica','type' => 'email' ],
                [ 'key' => 'BRAND_PHONE',     'label' => 'Telefono',      'type' => 'text' ],
            ],
        ],
        [
            'section' => 'Legal / Copy',
            'fields'  => [
                [ 'key' => 'BRAND_LEGAL_ADDRESS',   'label' => 'Indirizzo legale', 'type' => 'textarea' ],
                [ 'key' => 'BRAND_LEGAL_VAT',       'label' => 'P.IVA / CF',       'type' => 'text' ],
                [ 'key' => 'BRAND_UNSUBSCRIBE_URL', 'label' => 'Unsubscribe URL',  'type' => 'url',  'required' => true ],
            ],
        ],
    ];
}

/**
 * Lista piatta di tutte le chiavi brand definite nello schema.
 *
 * @return string[]
 */
function rp_em_get_brand_keys(): array {
    $keys = [];
    foreach ( rp_em_get_brand_schema() as $section ) {
        foreach ( $section['fields'] as $f ) {
            $keys[] = $f['key'];
        }
    }
    return $keys;
}

/**
 * Sanitizza un singolo valore brand in base al tipo dichiarato nello schema.
 * Non valida la obbligatorieta (quella e compito del validator campagna).
 *
 * @param string $key
 * @param string $value
 * @return string
 */
function rp_em_sanitize_brand_value( string $key, string $value ): string {
    $type = rp_em_brand_field_type( $key );
    $v    = trim( $value );

    return match ( $type ) {
        'url'      => esc_url_raw( $v ),
        'email'    => sanitize_email( $v ),
        'color'    => rp_em_sanitize_hex_color( $v ),
        'textarea' => sanitize_textarea_field( $v ),
        default    => sanitize_text_field( $v ),
    };
}

/**
 * Normalizza un colore esadecimale. Se malformato, restituisce stringa vuota
 * (il validator lo prendera come INVALID_HEX).
 *
 * Accetta: #rgb, #rrggbb, con o senza #, maiuscolo o minuscolo.
 *
 * @param string $v
 * @return string '#rrggbb' lowercase, o '' se invalido.
 */
function rp_em_sanitize_hex_color( string $v ): string {
    $v = ltrim( trim( $v ), '#' );
    if ( ! preg_match( '/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $v ) ) return '';
    if ( strlen( $v ) === 3 ) {
        $v = $v[0].$v[0].$v[1].$v[1].$v[2].$v[2];
    }
    return '#' . strtolower( $v );
}

/**
 * Ritorna il tipo UI di un campo brand ('text' di default se non nello schema).
 *
 * @param string $key
 * @return string
 */
function rp_em_brand_field_type( string $key ): string {
    foreach ( rp_em_get_brand_schema() as $section ) {
        foreach ( $section['fields'] as $f ) {
            if ( $f['key'] === $key ) return $f['type'] ?? 'text';
        }
    }
    return 'text';
}

/**
 * True se il campo e dichiarato come required nello schema.
 *
 * @param string $key
 * @return bool
 */
function rp_em_brand_field_required( string $key ): bool {
    foreach ( rp_em_get_brand_schema() as $section ) {
        foreach ( $section['fields'] as $f ) {
            if ( $f['key'] === $key ) return ! empty( $f['required'] );
        }
    }
    return false;
}
