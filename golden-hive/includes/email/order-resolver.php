<?php
/**
 * Order resolver — risolve un WooCommerce order ID nei campi ORDER_*.
 *
 * Modulo gemello di renderer.php::rp_em_resolve_product_fields ma per order
 * (non product). Usato dal renderer transazionale per popolare i placeholder
 * ORDER_* dentro un template email transazionale.
 *
 * Campi esposti (tutti stringhe pronte da iniettare nell'HTML):
 *
 *   Top-level
 *     ORDER_ID, ORDER_NUMBER, ORDER_DATE, ORDER_DATETIME, ORDER_STATUS,
 *     ORDER_STATUS_LABEL, ORDER_URL (account view), ORDER_PAYMENT_METHOD
 *
 *   Totali (formattati via wc_price)
 *     ORDER_TOTAL, ORDER_SUBTOTAL, ORDER_SHIPPING_TOTAL, ORDER_TAX_TOTAL,
 *     ORDER_DISCOUNT_TOTAL, ORDER_ITEMS_COUNT, ORDER_ITEMS_TOTAL_QUANTITY
 *
 *   Customer
 *     ORDER_CUSTOMER_FIRST_NAME, ORDER_CUSTOMER_LAST_NAME,
 *     ORDER_CUSTOMER_FULL_NAME, ORDER_CUSTOMER_EMAIL, ORDER_CUSTOMER_PHONE
 *
 *   Billing / Shipping (stesso set, prefisso BILLING_ / SHIPPING_)
 *     FIRST_NAME, LAST_NAME, COMPANY, ADDRESS_1, ADDRESS_2, CITY,
 *     POSTCODE, STATE, COUNTRY, FULL_ADDRESS
 *
 *   Shipping extra
 *     ORDER_SHIPPING_METHOD, ORDER_TRACKING_CODE, ORDER_TRACKING_URL,
 *     ORDER_CARRIER
 *
 *   Line items (1-based)
 *     ORDER_ITEM_N_NAME, ORDER_ITEM_N_SIZE, ORDER_ITEM_N_COLOR,
 *     ORDER_ITEM_N_SKU, ORDER_ITEM_N_QUANTITY, ORDER_ITEM_N_PRICE,
 *     ORDER_ITEM_N_SUBTOTAL, ORDER_ITEM_N_TOTAL, ORDER_ITEM_N_IMAGE_URL,
 *     ORDER_ITEM_N_URL, ORDER_ITEM_N_VARIATION_LABEL
 *
 * Convenzioni:
 * - Le attribute "taglia" e "colore" vengono estratte cercando i taxonomy
 *   slug piu comuni (pa_taglia, pa_size, pa_colore, pa_color). Se non
 *   trovate, stringa vuota.
 * - I prezzi sono formattati HTML-stripped (es. "EUR 199,90") cosi sono
 *   sicuri da iniettare in qualsiasi contesto HTML.
 * - Gli indirizzi FULL_ADDRESS sono composti manualmente su piu righe
 *   (separatore ", ") perche WC_Order::get_formatted_billing_address()
 *   puo sputare HTML con <br>.
 *
 * Meta di tracking (ORDER_TRACKING_*, ORDER_CARRIER) sono letti da
 * order meta con chiavi _rp_em_tracking_code / _rp_em_tracking_url /
 * _rp_em_carrier, settate dal meta box WooCommerce (order-meta-box.php).
 *
 * Nessun hook WordPress — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

// Costanti PRIMA del guard (vedi nota in validator.php sul PHP hoisting).
defined( 'RP_EM_ORDER_META_TRACKING_CODE' ) || define( 'RP_EM_ORDER_META_TRACKING_CODE', '_rp_em_tracking_code' );
defined( 'RP_EM_ORDER_META_TRACKING_URL' )  || define( 'RP_EM_ORDER_META_TRACKING_URL',  '_rp_em_tracking_url' );
defined( 'RP_EM_ORDER_META_CARRIER' )       || define( 'RP_EM_ORDER_META_CARRIER',       '_rp_em_carrier' );

if ( function_exists( 'rp_em_resolve_order_fields' ) ) return;

/**
 * Risolve un order_id WooCommerce in una mappa ORDER_* => string.
 *
 * @param int $order_id
 * @return array<string,string>
 */
function rp_em_resolve_order_fields( int $order_id ): array {
    $empty = rp_em_empty_order_fields();

    if ( ! function_exists( 'wc_get_order' ) ) return $empty;
    $order = wc_get_order( $order_id );
    if ( ! $order ) return $empty;

    $out = [];

    // ── Top-level
    $out['ORDER_ID']             = (string) $order->get_id();
    $out['ORDER_NUMBER']         = (string) $order->get_order_number();
    $out['ORDER_DATE']           = $order->get_date_created()
        ? (string) $order->get_date_created()->date_i18n( get_option( 'date_format', 'd/m/Y' ) )
        : '';
    $out['ORDER_DATETIME']       = $order->get_date_created()
        ? (string) $order->get_date_created()->date_i18n( get_option( 'date_format', 'd/m/Y' ) . ' ' . get_option( 'time_format', 'H:i' ) )
        : '';
    $out['ORDER_STATUS']         = (string) $order->get_status();
    $out['ORDER_STATUS_LABEL']   = function_exists( 'wc_get_order_status_name' )
        ? (string) wc_get_order_status_name( $order->get_status() )
        : (string) $order->get_status();
    $out['ORDER_URL']            = (string) $order->get_view_order_url();
    $out['ORDER_PAYMENT_METHOD'] = (string) $order->get_payment_method_title();

    // ── Totali
    $out['ORDER_TOTAL']          = rp_em_format_price( (float) $order->get_total() );
    $out['ORDER_SUBTOTAL']       = rp_em_format_price( (float) $order->get_subtotal() );
    $out['ORDER_SHIPPING_TOTAL'] = rp_em_format_price( (float) $order->get_shipping_total() );
    $out['ORDER_TAX_TOTAL']      = rp_em_format_price( (float) $order->get_total_tax() );
    $out['ORDER_DISCOUNT_TOTAL'] = rp_em_format_price( (float) $order->get_total_discount() );

    $items                         = $order->get_items();
    $out['ORDER_ITEMS_COUNT']      = (string) count( $items );
    $total_qty = 0;
    foreach ( $items as $item ) $total_qty += (int) $item->get_quantity();
    $out['ORDER_ITEMS_TOTAL_QUANTITY'] = (string) $total_qty;

    // ── Customer
    $out['ORDER_CUSTOMER_FIRST_NAME'] = (string) $order->get_billing_first_name();
    $out['ORDER_CUSTOMER_LAST_NAME']  = (string) $order->get_billing_last_name();
    $out['ORDER_CUSTOMER_FULL_NAME']  = trim( $out['ORDER_CUSTOMER_FIRST_NAME'] . ' ' . $out['ORDER_CUSTOMER_LAST_NAME'] );
    $out['ORDER_CUSTOMER_EMAIL']      = (string) $order->get_billing_email();
    $out['ORDER_CUSTOMER_PHONE']      = (string) $order->get_billing_phone();

    // ── Billing
    $out['ORDER_BILLING_FIRST_NAME'] = (string) $order->get_billing_first_name();
    $out['ORDER_BILLING_LAST_NAME']  = (string) $order->get_billing_last_name();
    $out['ORDER_BILLING_COMPANY']    = (string) $order->get_billing_company();
    $out['ORDER_BILLING_ADDRESS_1']  = (string) $order->get_billing_address_1();
    $out['ORDER_BILLING_ADDRESS_2']  = (string) $order->get_billing_address_2();
    $out['ORDER_BILLING_CITY']       = (string) $order->get_billing_city();
    $out['ORDER_BILLING_POSTCODE']   = (string) $order->get_billing_postcode();
    $out['ORDER_BILLING_STATE']      = (string) $order->get_billing_state();
    $out['ORDER_BILLING_COUNTRY']    = (string) $order->get_billing_country();
    $out['ORDER_BILLING_FULL_ADDRESS'] = rp_em_compose_address( [
        $out['ORDER_BILLING_ADDRESS_1'],
        $out['ORDER_BILLING_ADDRESS_2'],
        trim( $out['ORDER_BILLING_POSTCODE'] . ' ' . $out['ORDER_BILLING_CITY'] ),
        $out['ORDER_BILLING_STATE'],
        $out['ORDER_BILLING_COUNTRY'],
    ] );

    // ── Shipping address
    $out['ORDER_SHIPPING_FIRST_NAME'] = (string) $order->get_shipping_first_name();
    $out['ORDER_SHIPPING_LAST_NAME']  = (string) $order->get_shipping_last_name();
    $out['ORDER_SHIPPING_COMPANY']    = (string) $order->get_shipping_company();
    $out['ORDER_SHIPPING_ADDRESS_1']  = (string) $order->get_shipping_address_1();
    $out['ORDER_SHIPPING_ADDRESS_2']  = (string) $order->get_shipping_address_2();
    $out['ORDER_SHIPPING_CITY']       = (string) $order->get_shipping_city();
    $out['ORDER_SHIPPING_POSTCODE']   = (string) $order->get_shipping_postcode();
    $out['ORDER_SHIPPING_STATE']      = (string) $order->get_shipping_state();
    $out['ORDER_SHIPPING_COUNTRY']    = (string) $order->get_shipping_country();
    $out['ORDER_SHIPPING_FULL_ADDRESS'] = rp_em_compose_address( [
        $out['ORDER_SHIPPING_ADDRESS_1'],
        $out['ORDER_SHIPPING_ADDRESS_2'],
        trim( $out['ORDER_SHIPPING_POSTCODE'] . ' ' . $out['ORDER_SHIPPING_CITY'] ),
        $out['ORDER_SHIPPING_STATE'],
        $out['ORDER_SHIPPING_COUNTRY'],
    ] );
    $out['ORDER_SHIPPING_METHOD'] = (string) $order->get_shipping_method();

    // ── Tracking (meta settate dal metabox)
    $out['ORDER_TRACKING_CODE'] = (string) $order->get_meta( RP_EM_ORDER_META_TRACKING_CODE );
    $out['ORDER_TRACKING_URL']  = (string) $order->get_meta( RP_EM_ORDER_META_TRACKING_URL );
    $out['ORDER_CARRIER']       = (string) $order->get_meta( RP_EM_ORDER_META_CARRIER );

    // ── Line items (N-indexed)
    $slot = 1;
    foreach ( $items as $item ) {
        $out = array_merge( $out, rp_em_resolve_order_item_fields( $item, $slot ) );
        $slot++;
    }

    return array_merge( $empty, $out );
}

/**
 * Risolve un singolo order line item in campi ORDER_ITEM_N_*.
 *
 * @param \WC_Order_Item_Product $item
 * @param int $slot 1-based
 * @return array<string,string>
 */
function rp_em_resolve_order_item_fields( $item, int $slot ): array {
    $prefix = 'ORDER_ITEM_' . $slot . '_';
    $empty  = [
        $prefix . 'NAME'                  => '',
        $prefix . 'SIZE'                  => '',
        $prefix . 'COLOR'                 => '',
        $prefix . 'SKU'                   => '',
        $prefix . 'QUANTITY'              => '',
        $prefix . 'PRICE'                 => '',
        $prefix . 'SUBTOTAL'              => '',
        $prefix . 'TOTAL'                 => '',
        $prefix . 'IMAGE_URL'             => '',
        $prefix . 'URL'                   => '',
        $prefix . 'VARIATION_LABEL'       => '',
    ];

    if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) return $empty;

    $product = $item->get_product();
    $out     = [];

    $out[ $prefix . 'NAME' ]     = (string) $item->get_name();
    $out[ $prefix . 'SKU' ]      = $product ? (string) $product->get_sku() : '';
    $out[ $prefix . 'QUANTITY' ] = (string) $item->get_quantity();
    $out[ $prefix . 'PRICE' ]    = rp_em_format_price( (float) ( $item->get_quantity() > 0 ? $item->get_total() / $item->get_quantity() : 0 ) );
    $out[ $prefix . 'SUBTOTAL' ] = rp_em_format_price( (float) $item->get_subtotal() );
    $out[ $prefix . 'TOTAL' ]    = rp_em_format_price( (float) $item->get_total() );
    $out[ $prefix . 'URL' ]      = $product ? (string) $product->get_permalink() : '';

    $img_id = $product ? (int) $product->get_image_id() : 0;
    $out[ $prefix . 'IMAGE_URL' ] = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'medium' ) : '';

    // Size / color da variation attributes (lookup su slug comuni).
    $attrs = rp_em_extract_variation_attributes( $item );
    $out[ $prefix . 'SIZE' ]  = rp_em_pick_attr( $attrs, [ 'pa_taglia', 'pa_size', 'taglia', 'size' ] );
    $out[ $prefix . 'COLOR' ] = rp_em_pick_attr( $attrs, [ 'pa_colore', 'pa_color', 'colore', 'color' ] );

    // Label completa di tutte le variazioni (es. "Taglia: 42, Colore: Nero")
    $labels = [];
    foreach ( $attrs as $label => $value ) {
        if ( $value !== '' ) $labels[] = $label . ': ' . $value;
    }
    $out[ $prefix . 'VARIATION_LABEL' ] = implode( ', ', $labels );

    return array_merge( $empty, $out );
}

/**
 * Estrae attributi di variazione da un order line item.
 * Ritorna mappa label-localizzata => valore-localizzato (come mostrato in UI).
 *
 * @param \WC_Order_Item_Product $item
 * @return array<string,string>
 */
function rp_em_extract_variation_attributes( $item ): array {
    $out = [];

    // Meta items contengono sia attributes pa_* sia custom "Taglia".
    foreach ( $item->get_meta_data() as $meta ) {
        $data  = $meta->get_data();
        $key   = (string) ( $data['key'] ?? '' );
        $value = (string) ( $data['value'] ?? '' );

        // Skip meta interni (prefix _)
        if ( $key === '' || $key[0] === '_' ) continue;

        // Se la chiave e un taxonomy slug (pa_*), risolvi il termine.
        if ( str_starts_with( $key, 'pa_' ) && taxonomy_exists( $key ) ) {
            $term = get_term_by( 'slug', $value, $key );
            if ( $term && ! is_wp_error( $term ) ) {
                $tax_obj = get_taxonomy( $key );
                $label   = $tax_obj ? $tax_obj->labels->singular_name : $key;
                $out[ (string) $label ] = (string) $term->name;
                // Tieni anche la slug key per pick_attr lookup
                $out[ $key ] = (string) $term->name;
                continue;
            }
        }

        // Fallback: valore letterale
        $out[ $key ] = $value;
    }

    return $out;
}

/**
 * Cerca il primo valore non vuoto tra un elenco di chiavi possibili.
 * Case-insensitive sulle chiavi.
 *
 * @param array<string,string> $attrs
 * @param string[]             $keys
 * @return string
 */
function rp_em_pick_attr( array $attrs, array $keys ): string {
    $lower = [];
    foreach ( $attrs as $k => $v ) {
        $lower[ strtolower( (string) $k ) ] = (string) $v;
    }
    foreach ( $keys as $k ) {
        $lk = strtolower( $k );
        if ( isset( $lower[ $lk ] ) && $lower[ $lk ] !== '' ) return $lower[ $lk ];
    }
    return '';
}

/**
 * Compone un indirizzo multi-riga dai frammenti, eliminando i vuoti.
 *
 * @param string[] $parts
 * @return string Separatore: ", "
 */
function rp_em_compose_address( array $parts ): string {
    $clean = array_values( array_filter( array_map( 'trim', $parts ), fn( $p ) => $p !== '' ) );
    return implode( ', ', $clean );
}

/**
 * Mappa "vuota" di default per tutti i campi ORDER_* top-level.
 * Usata come fallback quando order non trovato (evita warning).
 *
 * @return array<string,string>
 */
function rp_em_empty_order_fields(): array {
    return [
        'ORDER_ID'                      => '',
        'ORDER_NUMBER'                  => '',
        'ORDER_DATE'                    => '',
        'ORDER_DATETIME'                => '',
        'ORDER_STATUS'                  => '',
        'ORDER_STATUS_LABEL'            => '',
        'ORDER_URL'                     => '',
        'ORDER_PAYMENT_METHOD'          => '',
        'ORDER_TOTAL'                   => '',
        'ORDER_SUBTOTAL'                => '',
        'ORDER_SHIPPING_TOTAL'          => '',
        'ORDER_TAX_TOTAL'               => '',
        'ORDER_DISCOUNT_TOTAL'          => '',
        'ORDER_ITEMS_COUNT'             => '',
        'ORDER_ITEMS_TOTAL_QUANTITY'    => '',
        'ORDER_CUSTOMER_FIRST_NAME'     => '',
        'ORDER_CUSTOMER_LAST_NAME'      => '',
        'ORDER_CUSTOMER_FULL_NAME'      => '',
        'ORDER_CUSTOMER_EMAIL'          => '',
        'ORDER_CUSTOMER_PHONE'          => '',
        'ORDER_BILLING_FIRST_NAME'      => '',
        'ORDER_BILLING_LAST_NAME'       => '',
        'ORDER_BILLING_COMPANY'         => '',
        'ORDER_BILLING_ADDRESS_1'       => '',
        'ORDER_BILLING_ADDRESS_2'       => '',
        'ORDER_BILLING_CITY'            => '',
        'ORDER_BILLING_POSTCODE'        => '',
        'ORDER_BILLING_STATE'           => '',
        'ORDER_BILLING_COUNTRY'         => '',
        'ORDER_BILLING_FULL_ADDRESS'    => '',
        'ORDER_SHIPPING_FIRST_NAME'     => '',
        'ORDER_SHIPPING_LAST_NAME'      => '',
        'ORDER_SHIPPING_COMPANY'        => '',
        'ORDER_SHIPPING_ADDRESS_1'      => '',
        'ORDER_SHIPPING_ADDRESS_2'      => '',
        'ORDER_SHIPPING_CITY'           => '',
        'ORDER_SHIPPING_POSTCODE'       => '',
        'ORDER_SHIPPING_STATE'          => '',
        'ORDER_SHIPPING_COUNTRY'        => '',
        'ORDER_SHIPPING_FULL_ADDRESS'   => '',
        'ORDER_SHIPPING_METHOD'         => '',
        'ORDER_TRACKING_CODE'           => '',
        'ORDER_TRACKING_URL'            => '',
        'ORDER_CARRIER'                 => '',
    ];
}

/**
 * Lista flat delle chiavi ORDER_* top-level (senza ORDER_ITEM_N_*).
 * Usata dalla UI (picker placeholder) e dal validator.
 *
 * @return string[]
 */
function rp_em_order_top_level_keys(): array {
    return array_keys( rp_em_empty_order_fields() );
}

/**
 * Lista delle chiavi ORDER_ITEM_N_* (senza indice; il "FIELD" dopo _N_).
 *
 * @return string[]
 */
function rp_em_order_item_field_keys(): array {
    return [
        'NAME',
        'SIZE',
        'COLOR',
        'SKU',
        'QUANTITY',
        'PRICE',
        'SUBTOTAL',
        'TOTAL',
        'IMAGE_URL',
        'URL',
        'VARIATION_LABEL',
    ];
}
