<?php
/**
 * Renderer — sostituisce i placeholder {UPPERCASE_KEY} dei template con
 * valori calcolati dai 5 layer: BRAND (config sito), CAMPAIGN (payload),
 * PRODUCT_N (WooCommerce), META (auto), RECIPIENT (letterali per ESP).
 *
 * Regole:
 * - I placeholder {RECIPIENT_*} restano nell'HTML finale come merge tag:
 *   saranno sostituiti al send-time dal provider ESP (WP Mail SMTP → SES).
 * - I placeholder sconosciuti (UNKNOWN namespace) vengono lasciati letterali
 *   qui: il validator li cattura e segnala NAMESPACE_VIOLATION separatamente.
 * - I placeholder noti ma senza valore vengono sostituiti con stringa vuota:
 *   il validator li cattura come MISSING_VALUE separatamente.
 *
 * Nessun hook WordPress — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'rp_em_render_campaign' ) ) return;

/**
 * Renderizza una campagna in HTML completo.
 *
 * @param string $campaign_id ID campagna.
 * @return string HTML renderizzato. Stringa vuota se campagna o template non trovati.
 *
 * Esempio:
 *   $html = rp_em_render_campaign( 'abc123' );
 *   wp_mail( 'me@site.com', 'Subject', $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
 */
function rp_em_render_campaign( string $campaign_id ): string {

    $campaign = rp_em_get_campaign( $campaign_id );
    if ( ! $campaign ) return '';

    $template = rp_em_get_template( (string) ( $campaign['template_id'] ?? '' ) );
    if ( ! $template ) return '';

    $brand    = rp_em_get_brand();
    $payload  = rp_em_build_campaign_payload( $campaign_id );
    $meta     = rp_em_auto_meta();

    $merged   = rp_em_merge_layers( $brand, $payload, $meta );

    return rp_em_render_raw( (string) ( $template['html'] ?? '' ), $merged, preserve_recipient: true );
}

/**
 * Renderizza HTML raw con un set di valori gia calcolati. Low-level: utile per
 * preview in UI dove non si vuole passare da una campagna persistita.
 *
 * @param string $html                HTML sorgente con placeholder.
 * @param array  $values              Map KEY => value.
 * @param bool   $preserve_recipient  Se true, {RECIPIENT_*} restano letterali.
 * @return string
 */
function rp_em_render_raw( string $html, array $values, bool $preserve_recipient = true ): string {

    if ( $html === '' ) return '';

    return preg_replace_callback( RP_EM_PLACEHOLDER_REGEX, function ( $m ) use ( $values, $preserve_recipient ) {
        $key = $m[1];

        // Recipient: resta letterale nell'HTML, l'ESP/SES lo sostituisce.
        if ( $preserve_recipient && rp_em_is_recipient_placeholder( $key ) ) {
            return '{' . $key . '}';
        }

        // Valore noto: usalo (anche se stringa vuota).
        if ( array_key_exists( $key, $values ) ) {
            return (string) $values[ $key ];
        }

        // Sconosciuto: lascia letterale, il validator lo prendera come
        // NAMESPACE_VIOLATION o MISSING_VALUE a seconda del namespace.
        return '{' . $key . '}';
    }, $html );
}

/**
 * Mergia i 3 layer (brand, campaign payload, meta) in un'unica map KEY => value.
 * In caso di collisione: ultimo vince (payload > meta > brand), ma in pratica
 * le chiavi dei namespace non si sovrappongono.
 *
 * Non include RECIPIENT_* (quelli restano letterali per definizione).
 *
 * @param array $brand    Map BRAND_* da rp_em_get_brand().
 * @param array $payload  Map con CAMPAIGN_* + PRODUCT_N_* + META_* override.
 * @param array $meta     Map META_* auto-generato.
 * @return array
 */
function rp_em_merge_layers( array $brand, array $payload, array $meta ): array {
    // Ordine: brand (base) → meta (auto) → payload (override permessi solo per
    // CAMPAIGN_* e META_*, ma non forziamo qui — il validator lo controlla).
    return array_merge( $brand, $meta, $payload );
}

/**
 * Genera i META_* auto-calcolati.
 *
 * @return array { META_YEAR, META_DATE, META_DATETIME }
 */
function rp_em_auto_meta(): array {
    return [
        'META_YEAR'     => wp_date( 'Y' ),
        'META_DATE'     => wp_date( 'd/m/Y' ),
        'META_DATETIME' => wp_date( 'd/m/Y H:i' ),
    ];
}

/**
 * Risolve un product_id WooCommerce nella map dei campi PRODUCT_N_*.
 *
 * Campi esposti:
 *   PRODUCT_N_NAME           — nome completo
 *   PRODUCT_N_NAME_LINE1     — prima "riga" (split su " - " / " — " / " | " / newline)
 *   PRODUCT_N_NAME_LINE2     — seconda riga, o vuota
 *   PRODUCT_N_SKU            — sku
 *   PRODUCT_N_PRICE          — prezzo corrente (sale o regular), formattato
 *   PRODUCT_N_PRICE_ORIGINAL — prezzo di listino (solo se in saldo), formattato
 *   PRODUCT_N_PRICE_RAW      — numerico corrente, non formattato
 *   PRODUCT_N_URL            — permalink
 *   PRODUCT_N_IMAGE_URL      — URL immagine featured (size 'large')
 *   PRODUCT_N_CATEGORY       — nome prima categoria
 *   PRODUCT_N_BRAND          — nome primo product_brand (o vuoto)
 *
 * @param int $product_id
 * @param int $slot         Indice 1-based dello slot nel template.
 * @return array<string,string> Map PRODUCT_{slot}_FIELD => string.
 */
function rp_em_resolve_product_fields( int $product_id, int $slot ): array {
    $prefix = 'PRODUCT_' . $slot . '_';
    $empty  = [
        $prefix . 'NAME'           => '',
        $prefix . 'NAME_LINE1'     => '',
        $prefix . 'NAME_LINE2'     => '',
        $prefix . 'SKU'            => '',
        $prefix . 'PRICE'          => '',
        $prefix . 'PRICE_ORIGINAL' => '',
        $prefix . 'PRICE_RAW'      => '',
        $prefix . 'URL'            => '',
        $prefix . 'IMAGE_URL'      => '',
        $prefix . 'CATEGORY'       => '',
        $prefix . 'BRAND'          => '',
    ];

    if ( ! function_exists( 'wc_get_product' ) ) return $empty;
    $p = wc_get_product( $product_id );
    if ( ! $p ) return $empty;

    // Nome + split in 2 righe.
    $name = (string) $p->get_name();
    [ $line1, $line2 ] = rp_em_split_name_two_lines( $name );

    // Prezzo: se in saldo, PRICE = sale, PRICE_ORIGINAL = regular. Altrimenti
    // PRICE = regular e PRICE_ORIGINAL e vuoto.
    $sale_price    = $p->get_sale_price();
    $regular_price = $p->get_regular_price();
    $current       = $p->get_price();

    $price_raw        = $current !== '' ? $current : ( $regular_price !== '' ? $regular_price : '' );
    $price_fmt        = $price_raw !== '' ? rp_em_format_price( (float) $price_raw ) : '';
    $price_original   = ( $sale_price !== '' && $regular_price !== '' && $sale_price !== $regular_price )
        ? rp_em_format_price( (float) $regular_price )
        : '';

    // Immagine featured.
    $img_id  = $p->get_image_id();
    $img_url = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'large' ) : '';

    // Categoria + brand (opzionali).
    $cat_name = '';
    $cats = get_the_terms( $product_id, 'product_cat' );
    if ( is_array( $cats ) && ! empty( $cats ) ) {
        $cat_name = (string) $cats[0]->name;
    }

    $brand_name = '';
    if ( taxonomy_exists( 'product_brand' ) ) {
        $brands = get_the_terms( $product_id, 'product_brand' );
        if ( is_array( $brands ) && ! empty( $brands ) ) {
            $brand_name = (string) $brands[0]->name;
        }
    }

    return [
        $prefix . 'NAME'           => $name,
        $prefix . 'NAME_LINE1'     => $line1,
        $prefix . 'NAME_LINE2'     => $line2,
        $prefix . 'SKU'            => (string) $p->get_sku(),
        $prefix . 'PRICE'          => $price_fmt,
        $prefix . 'PRICE_ORIGINAL' => $price_original,
        $prefix . 'PRICE_RAW'      => (string) $price_raw,
        $prefix . 'URL'            => (string) $p->get_permalink(),
        $prefix . 'IMAGE_URL'      => $img_url,
        $prefix . 'CATEGORY'       => $cat_name,
        $prefix . 'BRAND'          => $brand_name,
    ];
}

/**
 * Spezza un nome prodotto in due righe se contiene un separatore riconosciuto.
 * Utile per layout "TitleLine1\nSubtitleLine2" in card prodotto.
 *
 * @param string $name
 * @return array{0:string,1:string} [ line1, line2 ]
 */
function rp_em_split_name_two_lines( string $name ): array {
    $name = trim( $name );
    foreach ( [ "\n", ' — ', ' – ', ' - ', ' | ' ] as $sep ) {
        if ( str_contains( $name, $sep ) ) {
            $parts = explode( $sep, $name, 2 );
            return [ trim( $parts[0] ), trim( $parts[1] ?? '' ) ];
        }
    }
    return [ $name, '' ];
}

/**
 * Formatta un prezzo usando il formato WooCommerce se disponibile, altrimenti
 * fallback "EUR XX,YY".
 *
 * @param float $amount
 * @return string
 */
function rp_em_format_price( float $amount ): string {
    if ( function_exists( 'wc_price' ) ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
    }
    return '€' . number_format( $amount, 2, ',', '.' );
}
