<?php
/**
 * Email Templates — reusable HTML templates with smart placeholders.
 *
 * Templates store HTML with {placeholder} tokens. At render time, placeholders
 * are resolved from multiple sources: contact data, WooCommerce orders/customers,
 * site info, and custom values supplied by the user at send time.
 *
 * Placeholder syntax: {placeholder_name} (single braces, snake_case).
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'RP_EM_TEMPLATES_KEY' ) ) return;

define( 'RP_EM_TEMPLATES_KEY', 'rp_em_templates' );

// ── Template CRUD ────────────────────────────────────────

/**
 * @return array[]
 */
function rp_em_get_templates(): array {
    $tpl = get_option( RP_EM_TEMPLATES_KEY, [] );
    return is_array( $tpl ) ? $tpl : [];
}

/**
 * @param string $id
 * @return array|null
 */
function rp_em_get_template( string $id ): ?array {
    foreach ( rp_em_get_templates() as $t ) {
        if ( ( $t['id'] ?? '' ) === $id ) return $t;
    }
    return null;
}

/**
 * @param array $data { id?, name, subject, body, category? }
 * @return string Template ID.
 */
function rp_em_save_template( array $data ): string {
    $templates = rp_em_get_templates();
    $now       = wp_date( 'c' );

    if ( empty( $data['id'] ) ) {
        $data['id']         = 'tpl_' . substr( md5( uniqid( '', true ) ), 0, 8 );
        $data['created_at'] = $now;
    }
    $data['updated_at'] = $now;

    $found = false;
    foreach ( $templates as $i => $t ) {
        if ( ( $t['id'] ?? '' ) === $data['id'] ) {
            $templates[ $i ] = array_merge( $t, $data );
            $found = true;
            break;
        }
    }
    if ( ! $found ) $templates[] = $data;

    update_option( RP_EM_TEMPLATES_KEY, $templates, false );
    return $data['id'];
}

/**
 * @param string $id
 * @return bool
 */
function rp_em_delete_template( string $id ): bool {
    $templates = rp_em_get_templates();
    $filtered  = array_filter( $templates, fn( $t ) => ( $t['id'] ?? '' ) !== $id );
    if ( count( $filtered ) === count( $templates ) ) return false;
    update_option( RP_EM_TEMPLATES_KEY, array_values( $filtered ), false );
    return true;
}

// ── Placeholder Registry ─────────────────────────────────

/**
 * Returns all available placeholder definitions grouped by source.
 *
 * @return array { group_key => { label, placeholders: { key => description } } }
 */
function rp_em_get_placeholder_registry(): array {
    return [
        'contact' => [
            'label'        => 'Contatto',
            'placeholders' => [
                'first_name' => 'Nome del contatto',
                'email'      => 'Email del contatto',
                'full_name'  => 'Nome completo',
            ],
        ],
        'site' => [
            'label'        => 'Sito',
            'placeholders' => [
                'site_name' => 'Nome del sito WordPress',
                'site_url'  => 'URL del sito',
                'year'      => 'Anno corrente',
                'date'      => 'Data corrente (dd/mm/yyyy)',
            ],
        ],
        'order' => [
            'label'        => 'Ordine WooCommerce',
            'placeholders' => [
                'order_id'       => 'Numero ordine',
                'order_date'     => 'Data ordine',
                'order_total'    => 'Totale ordine',
                'order_status'   => 'Stato ordine',
                'billing_name'   => 'Nome fatturazione',
                'billing_email'  => 'Email fatturazione',
                'shipping_name'  => 'Nome spedizione',
                'shipping_city'  => 'Città spedizione',
                'payment_method' => 'Metodo di pagamento',
                'tracking_number' => 'Numero tracking',
            ],
        ],
        'customer' => [
            'label'        => 'Cliente WooCommerce',
            'placeholders' => [
                'customer_name'         => 'Nome cliente',
                'customer_email'        => 'Email cliente',
                'customer_orders_count' => 'Numero ordini totali',
                'customer_total_spent'  => 'Spesa totale',
                'customer_last_order'   => 'Data ultimo ordine',
            ],
        ],
        'product' => [
            'label'        => 'Prodotto',
            'placeholders' => [
                'product_name'  => 'Nome prodotto',
                'product_sku'   => 'SKU prodotto',
                'product_price' => 'Prezzo prodotto',
                'product_url'   => 'URL prodotto',
                'product_image' => 'URL immagine prodotto',
            ],
        ],
        'custom' => [
            'label'        => 'Personalizzato',
            'placeholders' => [
                'custom_1' => 'Campo personalizzato 1',
                'custom_2' => 'Campo personalizzato 2',
                'custom_3' => 'Campo personalizzato 3',
            ],
        ],
    ];
}

/**
 * Extracts all {placeholder} tokens from an HTML template.
 *
 * @param string $html
 * @return string[] Unique placeholder keys.
 */
function rp_em_extract_placeholders( string $html ): array {
    preg_match_all( '/\{([a-z_][a-z0-9_]*)\}/i', $html, $matches );
    return array_values( array_unique( $matches[1] ?? [] ) );
}

// ── Placeholder Resolution ───────────────────────────────

/**
 * Renders a template by resolving all placeholders.
 *
 * @param string $html     Template HTML with {placeholders}.
 * @param array  $context  {
 *   contact?:   object { email, display_name },
 *   order_id?:  int,
 *   customer_id?: int,
 *   product_id?:  int,
 *   custom?:    array { key => value },
 * }
 * @return string Rendered HTML.
 */
function rp_em_render_template( string $html, array $context = [] ): string {

    $values = [];

    // Site
    $values['site_name'] = get_bloginfo( 'name' );
    $values['site_url']  = home_url();
    $values['year']      = wp_date( 'Y' );
    $values['date']      = wp_date( 'd/m/Y' );

    // Contact
    if ( ! empty( $context['contact'] ) ) {
        $c = $context['contact'];
        $name = is_object( $c ) ? ( $c->display_name ?? '' ) : ( $c['display_name'] ?? '' );
        $email = is_object( $c ) ? ( $c->email ?? '' ) : ( $c['email'] ?? '' );
        $values['first_name'] = $name ?: 'Amico';
        $values['full_name']  = $name ?: 'Amico';
        $values['email']      = $email;
    }

    // WooCommerce Order
    if ( ! empty( $context['order_id'] ) && function_exists( 'wc_get_order' ) ) {
        $order = wc_get_order( (int) $context['order_id'] );
        if ( $order ) {
            $values['order_id']       = $order->get_order_number();
            $values['order_date']     = $order->get_date_created()?->date( 'd/m/Y' ) ?? '';
            $values['order_total']    = wp_strip_all_tags( $order->get_formatted_order_total() );
            $values['order_status']   = wc_get_order_status_name( $order->get_status() );
            $values['billing_name']   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $values['billing_email']  = $order->get_billing_email();
            $values['shipping_name']  = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
            $values['shipping_city']  = $order->get_shipping_city();
            $values['payment_method'] = $order->get_payment_method_title();
            $values['tracking_number'] = $order->get_meta( '_tracking_number' ) ?: $order->get_meta( '_wc_shipment_tracking_items' ) ?: '';
        }
    }

    // WooCommerce Customer
    if ( ! empty( $context['customer_id'] ) && function_exists( 'wc_get_customer' ) ) {
        $cust = new WC_Customer( (int) $context['customer_id'] );
        if ( $cust->get_id() ) {
            $values['customer_name']         = trim( $cust->get_first_name() . ' ' . $cust->get_last_name() ) ?: $cust->get_display_name();
            $values['customer_email']        = $cust->get_email();
            $values['customer_orders_count'] = $cust->get_order_count();
            $values['customer_total_spent']  = wc_price( $cust->get_total_spent() );
            $last = $cust->get_last_order();
            $values['customer_last_order']   = $last ? $last->get_date_created()?->date( 'd/m/Y' ) : '';
        }
    }

    // Product
    if ( ! empty( $context['product_id'] ) && function_exists( 'wc_get_product' ) ) {
        $prod = wc_get_product( (int) $context['product_id'] );
        if ( $prod ) {
            $values['product_name']  = $prod->get_name();
            $values['product_sku']   = $prod->get_sku();
            $values['product_price'] = wp_strip_all_tags( $prod->get_price_html() );
            $values['product_url']   = $prod->get_permalink();
            $img_id = $prod->get_image_id();
            $values['product_image'] = $img_id ? wp_get_attachment_url( $img_id ) : '';
        }
    }

    // Custom values (user-supplied at send time)
    foreach ( $context['custom'] ?? [] as $key => $val ) {
        $values[ $key ] = $val;
    }

    // Replace all {placeholder} tokens
    return preg_replace_callback( '/\{([a-z_][a-z0-9_]*)\}/i', function ( $m ) use ( $values ) {
        return esc_html( $values[ $m[1] ] ?? '' );
    }, $html );
}

// ── WooCommerce Data Helpers (for UI pickers) ────────────

/**
 * Searches WooCommerce orders by ID, email, or name.
 *
 * @param string $query Search string.
 * @param int    $limit Max results.
 * @return array [ { id, date, total, customer, email, status } ]
 */
function rp_em_search_orders( string $query, int $limit = 10 ): array {
    if ( ! function_exists( 'wc_get_orders' ) ) return [];

    $args = [ 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC' ];

    if ( is_numeric( $query ) ) {
        $order = wc_get_order( (int) $query );
        if ( ! $order ) return [];
        return [ rp_em_format_order_result( $order ) ];
    }

    if ( is_email( $query ) ) {
        $args['billing_email'] = $query;
    } else {
        $args['s'] = $query;
    }

    $orders  = wc_get_orders( $args );
    $results = [];
    foreach ( $orders as $order ) {
        $results[] = rp_em_format_order_result( $order );
    }
    return $results;
}

function rp_em_format_order_result( WC_Order $order ): array {
    return [
        'id'       => $order->get_id(),
        'number'   => $order->get_order_number(),
        'date'     => $order->get_date_created()?->date( 'd/m/Y H:i' ) ?? '',
        'total'    => wp_strip_all_tags( $order->get_formatted_order_total() ),
        'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'email'    => $order->get_billing_email(),
        'status'   => $order->get_status(),
    ];
}

/**
 * Searches WooCommerce customers by ID, email, or name.
 *
 * @param string $query
 * @param int    $limit
 * @return array [ { id, name, email, orders, spent } ]
 */
function rp_em_search_customers( string $query, int $limit = 10 ): array {
    $args = [
        'number'  => $limit,
        'orderby' => 'registered',
        'order'   => 'DESC',
    ];

    if ( is_numeric( $query ) ) {
        $args['include'] = [ (int) $query ];
    } elseif ( is_email( $query ) ) {
        $args['search']         = $query;
        $args['search_columns'] = [ 'user_email' ];
    } else {
        $args['search']         = '*' . $query . '*';
        $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
    }

    $users   = get_users( $args );
    $results = [];
    foreach ( $users as $user ) {
        $cust = new WC_Customer( $user->ID );
        $results[] = [
            'id'     => $user->ID,
            'name'   => trim( $cust->get_first_name() . ' ' . $cust->get_last_name() ) ?: $user->display_name,
            'email'  => $user->user_email,
            'orders' => $cust->get_order_count(),
            'spent'  => wp_strip_all_tags( wc_price( $cust->get_total_spent() ) ),
        ];
    }
    return $results;
}
