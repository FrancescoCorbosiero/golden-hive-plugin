<?php
/**
 * Validator — valida una campagna prima del send.
 *
 * Classi di segnalazione:
 *   errors   — bloccanti. La campagna non deve essere inviata.
 *   warnings — non bloccanti. L'UI le mostra ma puo permettere send.
 *
 * Error codes:
 *   MISSING_VALUE       — placeholder noto ma senza valore nella sorgente.
 *   NAMESPACE_VIOLATION — placeholder UNKNOWN, o BRAND_* dentro payload campagna.
 *   UNSUBSTITUTED       — campo PRODUCT_N non mappato dal resolver.
 *   TEMPLATE_NOT_FOUND  — template_id non esiste.
 *   INVALID_HEX         — valore brand colore non e hex valido.
 *   EMPTY_URL           — valore brand URL vuoto in un campo required.
 *
 * Warning codes:
 *   ORPHAN_KEY          — chiave nel payload non referenziata dal template.
 *   SUBJECT_TOO_LONG    — subject > 60 char.
 *   PREHEADER_TOO_LONG  — preheader > 110 char.
 *   LAYER_COLLISION     — stessa chiave in brand + payload.
 *
 * Nessun hook WordPress — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'rp_em_validate_campaign' ) ) return;

const RP_EM_SUBJECT_MAX   = 60;
const RP_EM_PREHEADER_MAX = 110;

/**
 * Valida una campagna.
 *
 * @param string $campaign_id
 * @return array { errors: array, warnings: array, validated_at: string }
 *
 * Esempio:
 *   $r = rp_em_validate_campaign( 'abc123' );
 *   if ( $r['errors'] ) { // blocca send }
 */
function rp_em_validate_campaign( string $campaign_id ): array {
    $errors   = [];
    $warnings = [];

    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) {
        return rp_em_validation_result(
            [ rp_em_err( 'TEMPLATE_NOT_FOUND', '', 'Campagna non trovata.' ) ],
            []
        );
    }

    $template = rp_em_get_template( (string) ( $campaign['template_id'] ?? '' ) );
    if ( ! $template ) {
        return rp_em_validation_result(
            [ rp_em_err( 'TEMPLATE_NOT_FOUND', (string) ( $campaign['template_id'] ?? '' ), 'Template della campagna non trovato.' ) ],
            []
        );
    }

    // ── Step 1: brand config
    $brand = rp_em_get_brand();
    foreach ( rp_em_get_brand_schema() as $section ) {
        foreach ( $section['fields'] as $f ) {
            $key  = $f['key'];
            $type = $f['type'] ?? 'text';
            $val  = (string) ( $brand[ $key ] ?? '' );

            // Hex check (solo se valore presente: l'assenza la prendono altri controlli).
            if ( $type === 'color' && $val !== '' && ! preg_match( '/^#[0-9a-f]{6}$/i', $val ) ) {
                $errors[] = rp_em_err( 'INVALID_HEX', $key, "Colore '{$key}' non e un hex valido: '{$val}'." );
            }

            // URL required vuoto.
            if ( $type === 'url' && ! empty( $f['required'] ) && $val === '' ) {
                $errors[] = rp_em_err( 'EMPTY_URL', $key, "URL brand richiesto '{$key}' e vuoto." );
            }
        }
    }

    // ── Step 2: placeholder del template
    $placeholders = $template['placeholders_cache'] ?? rp_em_extract_placeholders( (string) ( $template['html'] ?? '' ) );

    $payload     = is_array( $campaign['payload'] ?? null ) ? $campaign['payload'] : [];
    $product_ids = is_array( $campaign['product_ids'] ?? null ) ? array_values( array_filter( array_map( 'intval', $campaign['product_ids'] ) ) ) : [];
    $products_by_slot = []; // slot 1-based → resolved map
    foreach ( $product_ids as $i => $pid ) {
        $products_by_slot[ $i + 1 ] = rp_em_resolve_product_fields( $pid, $i + 1 );
    }

    $brand_keys      = rp_em_get_brand_keys();
    $referenced_keys = [];

    foreach ( $placeholders as $key ) {
        $ns = rp_em_extract_namespace( $key );
        $referenced_keys[] = $key;

        if ( $ns === 'UNKNOWN' ) {
            $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', $key, "Placeholder '{$key}' non appartiene a nessun namespace noto (BRAND, CAMPAIGN, PRODUCT, RECIPIENT, META)." );
            continue;
        }

        if ( $ns === 'RECIPIENT' ) continue; // letterale, nessun check

        if ( $ns === 'BRAND' ) {
            if ( ! in_array( $key, $brand_keys, true ) ) {
                $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', $key, "Chiave brand '{$key}' non e nello schema brand." );
                continue;
            }
            if ( trim( (string) ( $brand[ $key ] ?? '' ) ) === '' && rp_em_brand_field_required( $key ) ) {
                $errors[] = rp_em_err( 'MISSING_VALUE', $key, "Campo brand richiesto '{$key}' e vuoto. Vai al tab Brand per compilarlo." );
            }
            continue;
        }

        if ( $ns === 'CAMPAIGN' || $ns === 'META' ) {
            // META auto-generati (YEAR/DATE/DATETIME) sono sempre disponibili.
            if ( $ns === 'META' && in_array( $key, [ 'META_YEAR', 'META_DATE', 'META_DATETIME' ], true ) ) continue;

            if ( ! array_key_exists( $key, $payload ) || trim( (string) $payload[ $key ] ) === '' ) {
                $errors[] = rp_em_err( 'MISSING_VALUE', $key, "Valore '{$key}' mancante nel payload campagna." );
            }
            continue;
        }

        if ( $ns === 'PRODUCT' ) {
            $slot  = rp_em_product_index( $key );
            $field = rp_em_product_field( $key );

            if ( $slot === null || $field === null ) {
                $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', $key, "Placeholder PRODUCT malformato: '{$key}'." );
                continue;
            }
            if ( ! isset( $products_by_slot[ $slot ] ) ) {
                $errors[] = rp_em_err( 'MISSING_VALUE', $key, "Slot prodotto #{$slot} vuoto. Aggiungi un prodotto al picker o rimuovi il placeholder dal template." );
                continue;
            }
            $resolved = $products_by_slot[ $slot ];
            if ( ! array_key_exists( $key, $resolved ) ) {
                $errors[] = rp_em_err( 'UNSUBSTITUTED', $key, "Campo prodotto '{$field}' non mappato dal resolver." );
            }
            continue;
        }

        if ( $ns === 'ORDER' ) {
            $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', $key, "Placeholder '{$key}' (ORDER_*) appartiene al sistema transazionale. Usalo in un template transazionale, non in una campagna marketing." );
            continue;
        }
    }

    // ── Step 3: NAMESPACE_VIOLATION — payload non puo contenere BRAND_*
    foreach ( $payload as $k => $v ) {
        $ns = rp_em_extract_namespace( (string) $k );
        if ( $ns === 'BRAND' ) {
            $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', (string) $k, "Chiave '{$k}' e del namespace BRAND: i brand value vanno nel tab Brand, non nel payload campagna." );
        }
        if ( $ns === 'PRODUCT' ) {
            $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', (string) $k, "Chiave '{$k}' e del namespace PRODUCT: i product value si popolano dal product picker, non dal payload campagna." );
        }
        if ( $ns === 'ORDER' ) {
            $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', (string) $k, "Chiave '{$k}' e del namespace ORDER: appartiene al sistema transazionale, non al payload campagna." );
        }
        if ( $ns === 'RECIPIENT' ) {
            $errors[] = rp_em_err( 'NAMESPACE_VIOLATION', (string) $k, "Chiave '{$k}' e del namespace RECIPIENT: restano letterali per l'ESP, non vanno nel payload." );
        }
    }

    // ── Step 4: warnings
    $subject = (string) ( $campaign['subject'] ?? '' );
    if ( mb_strlen( $subject ) > RP_EM_SUBJECT_MAX ) {
        $warnings[] = rp_em_err( 'SUBJECT_TOO_LONG', 'subject', 'Subject lungo ' . mb_strlen( $subject ) . " char (raccomandato ≤ " . RP_EM_SUBJECT_MAX . ")." );
    }
    $preheader = (string) ( $campaign['preheader'] ?? '' );
    if ( $preheader !== '' && mb_strlen( $preheader ) > RP_EM_PREHEADER_MAX ) {
        $warnings[] = rp_em_err( 'PREHEADER_TOO_LONG', 'preheader', 'Preheader lungo ' . mb_strlen( $preheader ) . " char (raccomandato ≤ " . RP_EM_PREHEADER_MAX . ")." );
    }

    // ORPHAN_KEY: chiavi nel payload mai referenziate dal template.
    foreach ( array_keys( $payload ) as $k ) {
        if ( ! in_array( (string) $k, $referenced_keys, true ) ) {
            $warnings[] = rp_em_err( 'ORPHAN_KEY', (string) $k, "Chiave '{$k}' nel payload ma non usata dal template." );
        }
    }

    return rp_em_validation_result( $errors, $warnings );
}

/**
 * Costruisce un record errore/warning uniforme.
 *
 * @param string $code
 * @param string $key
 * @param string $message
 * @return array { code, key, namespace, message }
 */
function rp_em_err( string $code, string $key, string $message ): array {
    return [
        'code'      => $code,
        'key'       => $key,
        'namespace' => $key !== '' ? rp_em_extract_namespace( $key ) : '',
        'message'   => $message,
    ];
}

/**
 * Shape finale del risultato validator.
 *
 * @param array $errors
 * @param array $warnings
 * @return array
 */
function rp_em_validation_result( array $errors, array $warnings ): array {
    return [
        'errors'       => array_values( $errors ),
        'warnings'     => array_values( $warnings ),
        'validated_at' => current_time( 'mysql' ),
        'ok'           => empty( $errors ),
    ];
}
