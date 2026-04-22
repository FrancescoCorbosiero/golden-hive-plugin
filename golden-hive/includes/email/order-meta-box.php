<?php
/**
 * Order meta box — form di tracking + invio notifica "spedito" dalla
 * schermata di modifica ordine WooCommerce.
 *
 * Supporta sia legacy CPT (shop_order) sia HPOS (woocommerce_page_wc-orders).
 *
 * Il form compila 3 meta: _rp_em_tracking_code, _rp_em_tracking_url,
 * _rp_em_carrier. Il bottone "Salva & invia notifica" salva i meta e fa
 * scattare l'action 'rp_em_order_shipped' che il dispatcher transazionale
 * ascolta per inviare l'email con template bindato all'evento order_shipped.
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'rp_em_register_order_meta_box' ) ) return;

/**
 * Carrier preset comuni in Italia. Libero per l'utente di scriverne uno diverso.
 *
 * @return array<string,string> slug => label
 */
function rp_em_order_carriers(): array {
    return [
        'DHL'              => 'DHL',
        'BRT'              => 'BRT',
        'SDA'              => 'SDA',
        'Poste Italiane'   => 'Poste Italiane',
        'UPS'              => 'UPS',
        'FedEx'            => 'FedEx',
        'TNT'              => 'TNT',
        'GLS'              => 'GLS',
        'InPost'           => 'InPost',
    ];
}

/**
 * Registra il metabox su entrambi gli screen (legacy CPT + HPOS).
 *
 * Screen IDs:
 *   shop_order                  — legacy CPT
 *   woocommerce_page_wc-orders  — HPOS
 *
 * Li registriamo sempre entrambi: WordPress ignora silenziosamente quello
 * non attivo, quindi non servono check runtime su HPOS enabled.
 */
function rp_em_register_order_meta_box(): void {
    $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
    foreach ( $screens as $screen ) {
        add_meta_box(
            'rp_em_order_shipping',
            'Spedizione &amp; Notifica',
            'rp_em_render_order_meta_box',
            $screen,
            'side',
            'high'
        );
    }
}
add_action( 'add_meta_boxes', 'rp_em_register_order_meta_box' );

/**
 * Render del metabox — form inline con nonce, fetch ordini via $post o
 * $order object (a seconda di HPOS/legacy).
 *
 * @param mixed $post_or_order
 */
function rp_em_render_order_meta_box( $post_or_order ): void {
    $order = $post_or_order instanceof \WC_Order
        ? $post_or_order
        : ( function_exists( 'wc_get_order' ) ? wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order ) : null );
    if ( ! $order ) {
        echo '<p style="color:#999">Ordine non trovato.</p>';
        return;
    }

    $order_id       = $order->get_id();
    $tracking_code  = (string) $order->get_meta( RP_EM_ORDER_META_TRACKING_CODE );
    $tracking_url   = (string) $order->get_meta( RP_EM_ORDER_META_TRACKING_URL );
    $carrier        = (string) $order->get_meta( RP_EM_ORDER_META_CARRIER );

    $binding = rp_em_get_transactional_binding( 'order_shipped' );
    $nonce   = wp_create_nonce( 'rp_em_order_shipped_' . $order_id );

    $carriers = rp_em_order_carriers();
    ?>
    <div id="rp-em-shipping-box" data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
        <p style="margin:8px 0 4px;font-size:12px;color:#666;">
            Dati di tracking della spedizione. Il bottone qui sotto salva e
            (opzionalmente) invia la notifica al cliente usando il template
            bindato all'evento <code>order_shipped</code>.
        </p>

        <p>
            <label for="rp-em-carrier" style="display:block;font-weight:600;margin-bottom:4px;">Corriere</label>
            <select id="rp-em-carrier" style="width:100%;">
                <option value="">&mdash; seleziona &mdash;</option>
                <?php foreach ( $carriers as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $carrier, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
                <?php if ( $carrier !== '' && ! isset( $carriers[ $carrier ] ) ) : ?>
                    <option value="<?php echo esc_attr( $carrier ); ?>" selected><?php echo esc_html( $carrier ); ?> (custom)</option>
                <?php endif; ?>
            </select>
        </p>

        <p>
            <label for="rp-em-tracking-code" style="display:block;font-weight:600;margin-bottom:4px;">Codice tracking</label>
            <input type="text" id="rp-em-tracking-code" value="<?php echo esc_attr( $tracking_code ); ?>"
                   placeholder="Es: OB6ETQ6HuYec" style="width:100%;font-family:monospace;" />
        </p>

        <p>
            <label for="rp-em-tracking-url" style="display:block;font-weight:600;margin-bottom:4px;">URL tracking</label>
            <input type="url" id="rp-em-tracking-url" value="<?php echo esc_attr( $tracking_url ); ?>"
                   placeholder="https://del.dhl.com/IT/..." style="width:100%;" />
        </p>

        <?php if ( ! $binding['enabled'] || $binding['template_id'] === '' ) : ?>
            <p style="margin:12px 0;padding:8px;background:#fff4e5;border-left:3px solid #c68500;font-size:12px;color:#6b4a00;">
                <strong>Evento <code>order_shipped</code> non configurato.</strong><br>
                Vai in <em>Golden Hive &rarr; Email &rarr; Transazionali</em> per bindare
                un template ed abilitare l'invio.
            </p>
        <?php endif; ?>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;">
            <button type="button" class="button" id="rp-em-save-tracking-only">Salva solo</button>
            <button type="button" class="button button-primary" id="rp-em-save-and-send"
                <?php disabled( ! $binding['enabled'] || $binding['template_id'] === '' ); ?>>
                Salva &amp; invia notifica
            </button>
        </div>
        <div id="rp-em-shipping-result" style="margin-top:10px;font-size:12px;"></div>
    </div>

    <script>
    (function() {
        var box = document.getElementById('rp-em-shipping-box');
        if (!box) return;
        var orderId = box.dataset.orderId;
        var nonce   = box.dataset.nonce;
        var out     = document.getElementById('rp-em-shipping-result');

        function collect() {
            return {
                carrier:        document.getElementById('rp-em-carrier').value,
                tracking_code:  document.getElementById('rp-em-tracking-code').value,
                tracking_url:   document.getElementById('rp-em-tracking-url').value,
            };
        }

        function flash(msg, ok) {
            out.textContent = msg;
            out.style.color = ok ? '#2e7d32' : '#c62828';
            setTimeout(function(){ out.textContent = ''; }, 8000);
        }

        function call(action, send) {
            var data = collect();
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('order_id', orderId);
            body.append('nonce', nonce);
            body.append('carrier', data.carrier);
            body.append('tracking_code', data.tracking_code);
            body.append('tracking_url', data.tracking_url);
            if (send) body.append('send', '1');

            out.textContent = '...';
            out.style.color = '#666';
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function(r){ return r.json(); })
                .then(function(json) {
                    if (!json || !json.success) {
                        flash('Errore: ' + (json && json.data ? json.data : 'unknown'), false);
                        return;
                    }
                    var d = json.data || {};
                    if (d.sent) {
                        flash('Notifica inviata a ' + d.recipient, true);
                    } else if (d.saved) {
                        flash('Dati di tracking salvati.', true);
                    } else {
                        flash(d.message || 'OK', true);
                    }
                })
                .catch(function(err){ flash('Fetch failed: ' + err.message, false); });
        }

        document.getElementById('rp-em-save-tracking-only').addEventListener('click', function(){ call('rp_em_ajax_save_tracking', false); });
        var sendBtn = document.getElementById('rp-em-save-and-send');
        if (sendBtn) sendBtn.addEventListener('click', function(){ call('rp_em_ajax_save_tracking', true); });
    })();
    </script>
    <?php
}

// AJAX handler — salva meta + (opzionalmente) scatta order_shipped.
add_action( 'wp_ajax_rp_em_ajax_save_tracking', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    $order_id = (int) ( $_POST['order_id'] ?? 0 );
    $nonce    = (string) ( $_POST['nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, 'rp_em_order_shipped_' . $order_id ) ) {
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
    if ( ! $order ) wp_send_json_error( 'Ordine non trovato.' );

    $carrier       = sanitize_text_field( (string) ( $_POST['carrier'] ?? '' ) );
    $tracking_code = sanitize_text_field( (string) ( $_POST['tracking_code'] ?? '' ) );
    $tracking_url  = esc_url_raw( (string) ( $_POST['tracking_url'] ?? '' ) );
    $should_send   = ! empty( $_POST['send'] );

    $order->update_meta_data( RP_EM_ORDER_META_CARRIER, $carrier );
    $order->update_meta_data( RP_EM_ORDER_META_TRACKING_CODE, $tracking_code );
    $order->update_meta_data( RP_EM_ORDER_META_TRACKING_URL, $tracking_url );
    $order->save();

    $response = [
        'saved'     => true,
        'sent'      => false,
        'recipient' => '',
        'message'   => 'Dati tracking salvati.',
    ];

    if ( $should_send ) {
        // Chiamata diretta: ci serve il risultato per il feedback UI.
        // (Non uso do_action 'rp_em_order_shipped' per evitare double-send
        //  col listener registrato in transactional.php.)
        $result = rp_em_fire_transactional( 'order_shipped', $order_id );
        $response['sent']      = (bool) $result['success'];
        $response['recipient'] = (string) ( $result['recipient'] ?? '' );
        $response['message']   = (string) ( $result['message'] ?? '' );

        if ( ! $result['success'] && ! empty( $result['message'] ) ) {
            wp_send_json_error( $result['message'] );
        }
    }

    wp_send_json_success( $response );
} );
