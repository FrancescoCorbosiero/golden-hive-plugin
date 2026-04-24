<?php
/**
 * Demo render — renderizza un template con valori demo per test rapidi.
 *
 * Caso d'uso: l'utente vuole vedere come viene un template SENZA creare una
 * campagna o passare da un ordine reale. Chiede un render "demo" e riceve
 * HTML con tutti i placeholder sostituiti da:
 *
 *   BRAND_*      → valori reali dal tab Brand
 *   META_*       → auto (YEAR, DATE, DATETIME)
 *   CAMPAIGN_*   → defaults euristici in base al nome chiave (HEADLINE,
 *                  COUPON_CODE, CTA_URL, BADGE_BG...)
 *   PRODUCT_N_*  → picked di fino a 4 prodotti WooCommerce reali (lo stesso
 *                  pool usato da rp_em_pick_demo_products)
 *   ORDER_*      → ultimo ordine WooCommerce pubblicato (se esiste)
 *   RECIPIENT_*  → fake data ('demo@example.com', 'Mario')
 *
 * Dove il renderer non sa cosa mettere, sostituisce con "[PLACEHOLDER_KEY]"
 * come visual marker del placeholder non coperto.
 *
 * Usato dal tab Test Email ("Carica template") e dal bottone "Scarica HTML
 * demo" dell'editor template.
 *
 * Nessun hook WordPress — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'rp_em_render_template_with_demo' ) ) return;

/**
 * Renderizza un template con valori demo.
 *
 * @param string $template_id
 * @return array { html, subject, used_keys, unresolved_keys }
 *   html            → HTML renderizzato (vuoto se template non trovato)
 *   subject         → subject suggerito ('Test template · <name>')
 *   used_keys       → tutti i placeholder del template (dedup)
 *   unresolved_keys → placeholder a cui abbiamo dato un fallback visual
 *                     (utile per UI: segnala cosa non e "demo-ready")
 */
function rp_em_render_template_with_demo( string $template_id ): array {
    $template = rp_em_get_template( $template_id );
    if ( ! $template ) {
        return [ 'html' => '', 'subject' => '', 'used_keys' => [], 'unresolved_keys' => [] ];
    }

    $html = (string) ( $template['html'] ?? '' );
    $keys = $template['placeholders_cache'] ?? rp_em_extract_placeholders( $html );

    [ $values, $unresolved ] = rp_em_build_demo_values( $keys );

    $rendered = rp_em_render_raw( $html, $values, preserve_recipient: false );

    return [
        'html'            => $rendered,
        'subject'         => 'Test template · ' . (string) ( $template['name'] ?? $template_id ),
        'used_keys'       => $keys,
        'unresolved_keys' => $unresolved,
    ];
}

/**
 * Costruisce la mappa di valori demo per un elenco di placeholder richiesti.
 *
 * @param string[] $keys
 * @return array{0: array<string,string>, 1: string[]} [ values, unresolved_keys ]
 */
function rp_em_build_demo_values( array $keys ): array {
    $values     = [];
    $unresolved = [];

    // ── BRAND + META (sempre)
    $values = array_merge( $values, rp_em_get_brand(), rp_em_auto_meta() );

    // ── RECIPIENT defaults
    $values['RECIPIENT_EMAIL']      = 'demo@example.com';
    $values['RECIPIENT_FIRST_NAME'] = 'Mario';
    $values['RECIPIENT_LAST_NAME']  = 'Rossi';
    $values['RECIPIENT_FULL_NAME']  = 'Mario Rossi';

    // ── PRODUCT_N_*: pesca fino a 4 demo products, risolvi se usati
    $product_ids       = rp_em_pick_demo_products( 4 );
    $product_slots_used = [];
    foreach ( $keys as $k ) {
        if ( rp_em_extract_namespace( $k ) !== 'PRODUCT' ) continue;
        $slot = rp_em_product_index( $k );
        if ( $slot !== null ) $product_slots_used[ $slot ] = true;
    }
    foreach ( array_keys( $product_slots_used ) as $slot ) {
        $pid = $product_ids[ $slot - 1 ] ?? 0;
        if ( $pid > 0 ) {
            $values = array_merge( $values, rp_em_resolve_product_fields( (int) $pid, (int) $slot ) );
        }
    }

    // ── ORDER_*: ultimo ordine (se usato nel template)
    $needs_order = false;
    foreach ( $keys as $k ) {
        if ( rp_em_extract_namespace( $k ) === 'ORDER' ) { $needs_order = true; break; }
    }
    if ( $needs_order ) {
        $order_id = rp_em_latest_order_id();
        if ( $order_id > 0 ) {
            $values = array_merge( $values, rp_em_resolve_order_fields( (int) $order_id ) );
        }
    }

    // ── CAMPAIGN_* + qualsiasi fallback: per ogni chiave ancora senza valore,
    //    applica euristica o visual marker.
    foreach ( $keys as $k ) {
        // Se la chiave e gia stata scritta da un resolver autorevole
        // (BRAND / META / PRODUCT / ORDER / RECIPIENT), la teniamo cosi
        // com'e — anche se e stringa vuota. Esempio: PRICE_ORIGINAL vuoto
        // significa "prodotto non in saldo", e un valore valido, non un
        // placeholder non coperto.
        if ( array_key_exists( $k, $values ) ) continue;

        $ns = rp_em_extract_namespace( $k );
        $fallback = rp_em_demo_fallback_value( $k, $ns );
        if ( $fallback !== null ) {
            $values[ $k ] = $fallback;
        } else {
            // Visual marker per placeholder non coperti.
            $values[ $k ] = '[' . $k . ']';
            $unresolved[]  = $k;
        }
    }

    return [ $values, array_values( array_unique( $unresolved ) ) ];
}

/**
 * Euristica per generare un valore demo da un nome placeholder.
 * Restituisce null se non sa cosa mettere (il caller applicera visual marker).
 *
 * @param string $key
 * @param string $ns
 * @return string|null
 */
function rp_em_demo_fallback_value( string $key, string $ns ): ?string {
    $lk = strtolower( $key );

    // Color hex fallback (campi *_BG o *_COLOR dentro CAMPAIGN)
    if ( $ns === 'CAMPAIGN' ) {
        if ( preg_match( '/_(bg|bgcolor|background)$/', $lk ) ) return '#721124';
        if ( preg_match( '/_color$/', $lk ) )                   return '#ffffff';
    }

    // URL fallback (qualsiasi ns)
    if ( preg_match( '/_(url|link|href)$/', $lk ) ) {
        return function_exists( 'home_url' ) ? home_url( '/shop/' ) : '/shop/';
    }

    // Specifici CAMPAIGN (coupon, hero, cta, badge, ecc.)
    if ( $ns === 'CAMPAIGN' ) {
        if ( str_contains( $lk, 'preheader' ) )                      return 'Anteprima del testo email in arrivo';
        if ( str_contains( $lk, 'eyebrow' ) )                        return 'OFFERTA LIMITATA';
        if ( str_contains( $lk, 'headline_line1' ) )                 return 'Nuova collezione';
        if ( str_contains( $lk, 'headline_line2' ) )                 return 'in arrivo.';
        if ( str_contains( $lk, 'headline' ) )                       return 'Titolo dimostrativo';
        if ( str_contains( $lk, 'subtitle_line1' ) )                 return 'Scopri i pezzi selezionati';
        if ( str_contains( $lk, 'subtitle_line2' ) )                 return 'solo per il weekend.';
        if ( str_contains( $lk, 'subtitle' ) )                       return 'Sottotitolo descrittivo';
        if ( str_contains( $lk, 'tagline_line1' ) )                  return 'Qualita autentica,';
        if ( str_contains( $lk, 'tagline_line2' ) )                  return 'selezionata con cura.';
        if ( str_contains( $lk, 'tagline' ) )                        return 'Qualita che senti';
        if ( str_contains( $lk, 'coupon_code' ) )                    return 'DEMO20';
        if ( str_contains( $lk, 'coupon_label' ) )                   return 'Codice sconto';
        if ( str_contains( $lk, 'coupon_description' ) )             return '20% su tutta la selezione';
        if ( str_contains( $lk, 'coupon_title' ) )                   return 'Risparmia subito';
        if ( str_contains( $lk, 'coupon_validity' ) )                return 'Valido fino a domenica';
        if ( str_contains( $lk, 'coupon_urgency' ) )                 return 'Ultimi posti disponibili';
        if ( str_contains( $lk, 'coupon' ) )                         return 'Coupon demo';
        if ( str_contains( $lk, 'products_section_eyebrow' ) )       return 'I PEZZI DEL WEEKEND';
        if ( str_contains( $lk, 'products_section_title' ) )         return 'Selezione uomo &middot; donna';
        if ( str_contains( $lk, 'cta_button_text' ) )                return 'Scopri di piu';
        if ( str_contains( $lk, 'cta_button' ) )                     return 'Scopri';
        if ( str_contains( $lk, 'cta' ) )                            return 'Scopri di piu';
        if ( preg_match( '/_p\d+_badge_text$/', $lk ) )              return 'NUOVO';
        if ( preg_match( '/_p\d+_cta_text$/', $lk ) )                return 'Acquista';
        if ( preg_match( '/_p\d+_badge_bg$/', $lk ) )                return '#721124';
        if ( preg_match( '/_p\d+_badge_color$/', $lk ) )             return '#ffffff';
        if ( str_contains( $lk, 'footer' ) )                         return 'Offerta valida fino a esaurimento scorte.';
        if ( str_contains( $lk, 'section' ) || str_contains( $lk, 'title' ) ) return 'Titolo sezione';
        if ( str_contains( $lk, 'label' ) )                          return 'Etichetta';
        if ( str_contains( $lk, 'description' ) || str_contains( $lk, 'body' ) ) return 'Testo dimostrativo di esempio.';
    }

    // ORDER_* senza ordine reale: generico fallback
    if ( $ns === 'ORDER' ) {
        if ( str_contains( $lk, 'first_name' ) )  return 'Mario';
        if ( str_contains( $lk, 'last_name' ) )   return 'Rossi';
        if ( str_contains( $lk, 'full_name' ) )   return 'Mario Rossi';
        if ( str_contains( $lk, 'email' ) )       return 'demo@example.com';
        if ( str_contains( $lk, 'number' ) )      return '12345';
        if ( str_contains( $lk, 'date' ) )        return wp_date( 'd/m/Y' );
        if ( str_contains( $lk, 'total' ) )       return rp_em_format_price( 199.90 );
        if ( str_contains( $lk, 'tracking_code' ) ) return 'DEMO123XYZ';
        if ( str_contains( $lk, 'tracking_url' ) )  return 'https://example.com/tracking/DEMO123XYZ';
        if ( str_contains( $lk, 'carrier' ) )       return 'DHL';
        if ( str_contains( $lk, 'size' ) )          return 'EU 42';
        if ( str_contains( $lk, 'color' ) )         return 'Nero';
        if ( preg_match( '/_item_\d+_name$/', $lk ) ) return 'Prodotto Demo';
        if ( preg_match( '/_item_\d+_price$/', $lk ) ) return rp_em_format_price( 199.90 );
        if ( preg_match( '/_item_\d+_sku$/', $lk ) )  return 'DEMO-SKU';
        if ( str_contains( $lk, 'address' ) )       return 'Via Roma 1, 00100 Roma (RM)';
        if ( str_contains( $lk, 'status' ) )        return 'In lavorazione';
    }

    return null;
}

/**
 * ID dell'ordine WooCommerce piu recente (qualsiasi status), o 0 se assente.
 *
 * @return int
 */
function rp_em_latest_order_id(): int {
    if ( ! function_exists( 'wc_get_orders' ) ) return 0;

    $orders = wc_get_orders( [
        'limit'   => 1,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
    ] );
    if ( empty( $orders ) ) {
        // Fallback: qualsiasi status.
        $orders = wc_get_orders( [ 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ] );
    }
    if ( empty( $orders ) ) return 0;
    return (int) $orders[0]->get_id();
}
