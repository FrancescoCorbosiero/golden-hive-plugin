<?php
/**
 * Feed credentials — storage centralizzato per le credenziali dei feed
 * (URL endpoint, Bearer token, cookie di sessione).
 *
 * Sostituisce la coppia gh_ajax_feed_save_settings / _load_settings che
 * esisteva in feeds/ajax.php senza schema validation: accettava qualsiasi
 * feed_key e qualsiasi campo. Quel pattern era ok per un URL non-secret
 * (SF), ma diventa pericoloso non appena vogliamo salvare token Bearer
 * (GS).
 *
 * Difese implementate (lista dichiarativa, non lasciare nulla al caso):
 *
 * 1. WHITELIST FEED_KEY — solo i feed dichiarati in gh_feed_credentials_schema()
 *    possono essere letti/scritti. Niente pollution arbitraria di wp_options.
 * 2. SCHEMA PER-FEED — ogni campo ha tipo {url|secret|text} e max length.
 *    I campi extra inviati dal client vengono droppati silenziosamente.
 * 3. SANITIZE PER TIPO — url → esc_url_raw + protocol whitelist (http/https),
 *    secret → strip controlli + cap length, text → sanitize_text_field.
 * 4. REDACT IN OUTPUT — i campi 'secret' nelle response GET sono mascherati
 *    a "••••XXXX" (last 4 char). Niente plaintext lascia il server.
 * 5. PLACEHOLDER REJECT — se il client invia un valore che inizia con "•"
 *    per un campo secret, lo trattiamo come "non modificato" e preserviamo
 *    il valore storato (cosi che salvare il form senza ri-incollare il token
 *    non lo cancelli ne lo corrompa).
 * 6. AUTOLOAD=FALSE — le option non vengono caricate ad ogni request WP.
 * 7. CAP LENGTH — max length applicato lato server. Cap difensivo contro
 *    abuso (DB bloat) anche se manage_woocommerce e gia gating.
 * 8. NO LOGGING — questo modulo non logga ne URL ne secret. L'HTTP client
 *    fa gia redazione header sensibili (Authorization, Cookie) negli output
 *    AJAX (vedi rp_rc_redact_sensitive_headers).
 *
 * NB: storage cleartext in DB — voluto. Aggiungere encryption con key in
 * wp-config.php non aumenta significativamente la sicurezza (chi ha accesso
 * al DB ha tipicamente anche accesso a wp-config) e rompe i backup standard.
 * Le difese reali sono:
 * - filesystem permission su wp-config.php (responsabilita ops)
 * - DB user permission (responsabilita ops)
 * - capability gating sull'admin UI (manage_woocommerce — qui)
 * - redazione consistente in OGNI output (qui).
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'gh_feed_credentials_schema' ) ) return;

const GH_FEED_CREDS_PREFIX = 'gh_feed_settings_';

/**
 * Schema dei feed con credenziali persistabili. Whitelist canonica.
 *
 * Per ciascun campo:
 * - type: 'url' | 'secret' | 'text' | 'enum'
 * - max:  cap in bytes
 * - allow_empty: bool (default true; se false, salvare vuoto fa errore)
 * - options: array di valori ammessi (solo per 'enum')
 *
 * @return array<string, array<string, array>>
 */
function gh_feed_credentials_schema(): array {
    return [
        'goldensneakers' => [
            'url'    => [ 'type' => 'url',    'max' => 4096, 'allow_empty' => false ],
            'token'  => [ 'type' => 'secret', 'max' => 8192, 'allow_empty' => false ],
            'cookie' => [ 'type' => 'secret', 'max' => 16384 ],
            'format' => [ 'type' => 'enum',   'options' => [ 'hierarchical', 'flat' ], 'max' => 16 ],
        ],
        'stockfirmati' => [
            'url'    => [ 'type' => 'url', 'max' => 4096 ],
        ],
    ];
}

/**
 * Helper: e' un feed_key valido (presente nello schema)?
 */
function gh_feed_credentials_is_valid_key( string $feed_key ): bool {
    return array_key_exists( $feed_key, gh_feed_credentials_schema() );
}

/**
 * Lettura raw delle credenziali. NON usare per output al client — usare
 * gh_feed_credentials_get_redacted().
 */
function gh_feed_credentials_get( string $feed_key ): array {
    if ( ! gh_feed_credentials_is_valid_key( $feed_key ) ) return [];
    $v = get_option( GH_FEED_CREDS_PREFIX . $feed_key, [] );
    return is_array( $v ) ? $v : [];
}

/**
 * Lettura per output AJAX/UI: i campi 'secret' sono mascherati.
 * Restituisce SEMPRE un array con tutte le chiavi dello schema (anche vuote)
 * cosi che la UI sappia quali campi mostrare.
 */
function gh_feed_credentials_get_redacted( string $feed_key ): array {
    if ( ! gh_feed_credentials_is_valid_key( $feed_key ) ) return [];

    $stored = gh_feed_credentials_get( $feed_key );
    $schema = gh_feed_credentials_schema()[ $feed_key ];
    $out    = [];

    foreach ( $schema as $field => $cfg ) {
        $val = (string) ( $stored[ $field ] ?? '' );
        if ( ( $cfg['type'] ?? '' ) === 'secret' && $val !== '' ) {
            $out[ $field ] = strlen( $val ) > 4
                ? str_repeat( '•', 8 ) . substr( $val, -4 )
                : '••••';
        } else {
            $out[ $field ] = $val;
        }
    }

    return $out;
}

/**
 * Salva (merge) un set parziale di credenziali. Sanitize per-tipo + reject
 * dei placeholder redatti. Restituisce { saved (array redacted), errors (array) }.
 *
 * @param string $feed_key Whitelisted (vedi schema).
 * @param array  $incoming Dati grezzi dal client (post JSON).
 * @return array { saved, errors }
 */
function gh_feed_credentials_save( string $feed_key, array $incoming ): array {

    if ( ! gh_feed_credentials_is_valid_key( $feed_key ) ) {
        return [ 'saved' => [], 'errors' => [ '__feed' => 'feed_key non valido' ] ];
    }

    $schema   = gh_feed_credentials_schema()[ $feed_key ];
    $existing = gh_feed_credentials_get( $feed_key );
    $merged   = $existing;
    $errors   = [];

    foreach ( $schema as $field => $cfg ) {
        // Campi non inviati: preserva esistente.
        if ( ! array_key_exists( $field, $incoming ) ) continue;

        $raw = $incoming[ $field ];
        // Stringify solo: niente strutture annidate ammesse nei campi credenziali.
        if ( is_array( $raw ) || is_object( $raw ) ) {
            $errors[ $field ] = 'tipo non valido';
            continue;
        }
        $raw = (string) $raw;

        $type = $cfg['type'] ?? 'text';

        // Placeholder reject: se il client manda un valore mascherato per un
        // campo secret, NON sovrascrivere — significa "non modificato".
        if ( $type === 'secret' && preg_match( '/^•+/', $raw ) ) {
            continue;
        }

        // Empty handling
        if ( $raw === '' ) {
            $allow_empty = $cfg['allow_empty'] ?? true;
            if ( ! $allow_empty ) {
                $errors[ $field ] = 'campo obbligatorio';
                continue;
            }
            $merged[ $field ] = '';
            continue;
        }

        // Length cap (defensive — evita DB bloat anche con cap admin auth).
        $max = (int) ( $cfg['max'] ?? 8192 );
        if ( strlen( $raw ) > $max ) {
            $errors[ $field ] = "lunghezza massima {$max} caratteri";
            continue;
        }

        // Type-specific sanitize
        switch ( $type ) {
            case 'url':
                $clean = esc_url_raw( $raw, [ 'http', 'https' ] );
                if ( ! $clean ) {
                    $errors[ $field ] = 'URL non valido (solo http/https)';
                    continue 2;
                }
                $merged[ $field ] = $clean;
                break;

            case 'secret':
                // Strip control chars (newline esclusi se servono — qui no:
                // token e cookie sono single-line). Preservare unicode.
                $clean = preg_replace( '/[\x00-\x1F\x7F]/u', '', $raw );
                $merged[ $field ] = trim( $clean );
                break;

            case 'enum':
                $opts = (array) ( $cfg['options'] ?? [] );
                if ( ! in_array( $raw, $opts, true ) ) {
                    $errors[ $field ] = 'valore non ammesso';
                    continue 2;
                }
                $merged[ $field ] = $raw;
                break;

            case 'text':
            default:
                $merged[ $field ] = sanitize_text_field( $raw );
        }
    }

    // Non scrivere chiavi extra non in schema.
    $clean_merged = [];
    foreach ( array_keys( $schema ) as $field ) {
        if ( array_key_exists( $field, $merged ) ) {
            $clean_merged[ $field ] = $merged[ $field ];
        }
    }

    update_option( GH_FEED_CREDS_PREFIX . $feed_key, $clean_merged, false );

    return [
        'saved'  => gh_feed_credentials_get_redacted( $feed_key ),
        'errors' => $errors,
    ];
}

/**
 * Elimina tutte le credenziali per un feed.
 */
function gh_feed_credentials_delete( string $feed_key ): bool {
    if ( ! gh_feed_credentials_is_valid_key( $feed_key ) ) return false;
    return delete_option( GH_FEED_CREDS_PREFIX . $feed_key );
}
