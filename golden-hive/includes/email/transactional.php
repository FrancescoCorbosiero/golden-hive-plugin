<?php
/**
 * Transactional — email transazionali event-driven.
 *
 * Complementare alle campagne marketing: dove una campagna va a una lista
 * grande di contatti con contenuti uguali per tutti (CAMPAIGN_* + PRODUCT_N_*
 * dal catalogo), una email transazionale va a un singolo destinatario in
 * risposta a un evento (ordine creato, spedito, completato...) con placeholder
 * ORDER_* risolti dai dati dell'ordine specifico.
 *
 * ── Concetti
 *
 *   Evento         — slug registrato (es. 'order_shipped'). Ogni evento ha
 *                    un hook WordPress/Woo che lo fa scattare e un label UI.
 *
 *   Binding        — associazione evento → template + subject + preheader.
 *                    Persistita in wp_option 'rp_em_transactional'. Se un
 *                    evento non ha binding abilitato, lo scatto e un no-op.
 *
 *   Firing         — quando un evento scatta, il dispatcher risolve il binding,
 *                    renderizza il template con ORDER_* + BRAND_* + META_*,
 *                    e invia via wp_mail all'email del cliente dell'ordine.
 *
 * ── Storage
 *
 *   wp_option 'rp_em_transactional' = [
 *     'order_shipped' => [
 *       'enabled'     => true,
 *       'template_id' => 'tpl_xxx',
 *       'subject'     => 'Il tuo ordine {ORDER_NUMBER} e in viaggio',
 *       'preheader'   => 'Traccia la spedizione in tempo reale',
 *     ],
 *     ...
 *   ]
 *
 * ── Eventi registrati
 *
 *   order_processing  → woocommerce_order_status_processing
 *   order_completed   → woocommerce_order_status_completed
 *   order_cancelled   → woocommerce_order_status_cancelled
 *   order_refunded    → woocommerce_order_status_refunded
 *   order_shipped     → rp_em_order_shipped (fired manualmente dal metabox)
 *
 * ── Log
 *
 *   Ogni invio va in rp_em_email_log con type='transactional' e
 *   campaign_id=event_slug, campaign_name=evento label.
 */

defined( 'ABSPATH' ) || exit;

// Costante PRIMA del guard (vedi nota in validator.php sul PHP hoisting).
defined( 'RP_EM_TRANSACTIONAL_KEY' ) || define( 'RP_EM_TRANSACTIONAL_KEY', 'rp_em_transactional' );

if ( function_exists( 'rp_em_get_transactional_binding' ) ) return;

// ═══ EVENT REGISTRY ═════════════════════════════════════════════════════════

/**
 * Registry statico degli eventi transazionali supportati.
 *
 * Shape per evento:
 *   slug  — identificatore (storage key)
 *   label — testo UI
 *   desc  — descrizione breve per la UI
 *   hook  — action name WP/Woo che fa scattare l'evento (primo arg = order_id)
 *
 * @return array<string,array>
 */
function rp_em_transactional_events(): array {
    return [
        'order_processing' => [
            'slug'  => 'order_processing',
            'label' => 'Ordine in lavorazione',
            'desc'  => 'Pagamento ricevuto, ordine preso in carico.',
            'hook'  => 'woocommerce_order_status_processing',
        ],
        'order_completed' => [
            'slug'  => 'order_completed',
            'label' => 'Ordine completato',
            'desc'  => 'Ordine marcato come completato (usato da Woo come "shipped" di default).',
            'hook'  => 'woocommerce_order_status_completed',
        ],
        'order_shipped' => [
            'slug'  => 'order_shipped',
            'label' => 'Ordine spedito',
            'desc'  => 'Evento custom: fire manuale dal metabox ordine con tracking compilato.',
            'hook'  => 'rp_em_order_shipped',
        ],
        'order_cancelled' => [
            'slug'  => 'order_cancelled',
            'label' => 'Ordine annullato',
            'desc'  => 'Ordine cancellato dall\'admin o dal cliente.',
            'hook'  => 'woocommerce_order_status_cancelled',
        ],
        'order_refunded' => [
            'slug'  => 'order_refunded',
            'label' => 'Ordine rimborsato',
            'desc'  => 'Rimborso totale o parziale emesso.',
            'hook'  => 'woocommerce_order_status_refunded',
        ],
    ];
}

/**
 * Ritorna un evento per slug, o null.
 *
 * @param string $slug
 * @return array|null
 */
function rp_em_transactional_event( string $slug ): ?array {
    return rp_em_transactional_events()[ $slug ] ?? null;
}

// ═══ BINDINGS STORAGE ═══════════════════════════════════════════════════════

/**
 * Ritorna tutti i binding configurati (defaults per eventi senza binding).
 *
 * @return array<string,array>
 */
function rp_em_get_transactional_bindings(): array {
    $saved = get_option( RP_EM_TRANSACTIONAL_KEY, [] );
    if ( ! is_array( $saved ) ) $saved = [];

    $out = [];
    foreach ( rp_em_transactional_events() as $slug => $event ) {
        $b = is_array( $saved[ $slug ] ?? null ) ? $saved[ $slug ] : [];
        $out[ $slug ] = [
            'enabled'     => (bool) ( $b['enabled'] ?? false ),
            'template_id' => (string) ( $b['template_id'] ?? '' ),
            'subject'     => (string) ( $b['subject'] ?? '' ),
            'preheader'   => (string) ( $b['preheader'] ?? '' ),
        ];
    }
    return $out;
}

/**
 * Ritorna il binding per un singolo evento (defaults se mancante).
 *
 * @param string $slug
 * @return array { enabled, template_id, subject, preheader }
 */
function rp_em_get_transactional_binding( string $slug ): array {
    return rp_em_get_transactional_bindings()[ $slug ] ?? [
        'enabled'     => false,
        'template_id' => '',
        'subject'     => '',
        'preheader'   => '',
    ];
}

/**
 * Salva (merge) un binding per un evento. Accetta solo eventi registrati.
 *
 * @param string $slug
 * @param array  $data { enabled?, template_id?, subject?, preheader? }
 * @return bool
 */
function rp_em_save_transactional_binding( string $slug, array $data ): bool {
    if ( ! rp_em_transactional_event( $slug ) ) return false;

    $existing = get_option( RP_EM_TRANSACTIONAL_KEY, [] );
    if ( ! is_array( $existing ) ) $existing = [];

    $current = is_array( $existing[ $slug ] ?? null ) ? $existing[ $slug ] : [];
    $merged  = array_merge( $current, [
        'enabled'     => array_key_exists( 'enabled', $data )     ? (bool) $data['enabled'] : (bool) ( $current['enabled'] ?? false ),
        'template_id' => array_key_exists( 'template_id', $data ) ? sanitize_text_field( (string) $data['template_id'] ) : (string) ( $current['template_id'] ?? '' ),
        'subject'     => array_key_exists( 'subject', $data )     ? sanitize_text_field( (string) $data['subject'] )     : (string) ( $current['subject'] ?? '' ),
        'preheader'   => array_key_exists( 'preheader', $data )   ? sanitize_text_field( (string) $data['preheader'] )   : (string) ( $current['preheader'] ?? '' ),
    ] );

    $existing[ $slug ] = $merged;
    return update_option( RP_EM_TRANSACTIONAL_KEY, $existing, false );
}

// ═══ RENDER ════════════════════════════════════════════════════════════════

/**
 * Renderizza un'email transazionale per un ordine specifico.
 *
 * @param string $event_slug
 * @param int    $order_id
 * @return array { html: string, subject: string, preheader: string, recipient: string, binding: array, order_found: bool }
 *
 * Ritorna sempre un array (mai solleva). Se template/binding/order mancano,
 * html sara stringa vuota e recipient sara ''.
 */
function rp_em_render_transactional( string $event_slug, int $order_id ): array {
    $binding = rp_em_get_transactional_binding( $event_slug );
    $result  = [
        'html'        => '',
        'subject'     => '',
        'preheader'   => '',
        'recipient'   => '',
        'binding'     => $binding,
        'order_found' => false,
    ];

    $template = $binding['template_id'] !== '' ? rp_em_get_template( $binding['template_id'] ) : null;
    if ( ! $template ) return $result;

    $order_fields = rp_em_resolve_order_fields( $order_id );
    if ( empty( $order_fields['ORDER_ID'] ) ) {
        // Order non trovato — torna con result vuoto ma binding presente.
        return $result;
    }
    $result['order_found'] = true;
    $result['recipient']   = (string) $order_fields['ORDER_CUSTOMER_EMAIL'];

    $brand = rp_em_get_brand();
    $meta  = rp_em_auto_meta();

    // Merge layers: brand → meta → order (order vince in caso di collisioni).
    $values = array_merge( $brand, $meta, $order_fields );

    $html = rp_em_render_raw( (string) ( $template['html'] ?? '' ), $values, preserve_recipient: false );

    // Subject + preheader supportano anche placeholder (ORDER_*, BRAND_*, META_*).
    $result['html']      = $html;
    $result['subject']   = rp_em_render_raw( $binding['subject'], $values, preserve_recipient: false );
    $result['preheader'] = rp_em_render_raw( $binding['preheader'], $values, preserve_recipient: false );

    return $result;
}

// ═══ FIRE / SEND ═══════════════════════════════════════════════════════════

/**
 * Fa scattare un evento transazionale: render + send + log.
 *
 * Idempotenza: questa funzione NON deduplica automaticamente. Chi la chiama
 * (hook handler, AJAX endpoint) e responsabile di non chiamarla due volte per
 * lo stesso ordine+evento se non voluto.
 *
 * @param string $event_slug
 * @param int    $order_id
 * @return array { success: bool, message: string, recipient: string, subject: string, skipped_reason?: string }
 */
function rp_em_fire_transactional( string $event_slug, int $order_id ): array {
    $event = rp_em_transactional_event( $event_slug );
    if ( ! $event ) {
        return [ 'success' => false, 'message' => "Evento sconosciuto: {$event_slug}", 'recipient' => '', 'subject' => '' ];
    }

    $binding = rp_em_get_transactional_binding( $event_slug );
    if ( ! $binding['enabled'] ) {
        return [ 'success' => false, 'message' => '', 'skipped_reason' => 'binding_disabled', 'recipient' => '', 'subject' => '' ];
    }
    if ( $binding['template_id'] === '' ) {
        return [ 'success' => false, 'message' => '', 'skipped_reason' => 'no_template', 'recipient' => '', 'subject' => '' ];
    }

    $rendered = rp_em_render_transactional( $event_slug, $order_id );

    if ( ! $rendered['order_found'] ) {
        return [ 'success' => false, 'message' => "Ordine #{$order_id} non trovato.", 'recipient' => '', 'subject' => '' ];
    }
    if ( $rendered['html'] === '' ) {
        return [ 'success' => false, 'message' => 'Render vuoto (template mancante?).', 'recipient' => '', 'subject' => '' ];
    }
    $to = $rendered['recipient'];
    if ( $to === '' || ! is_email( $to ) ) {
        return [ 'success' => false, 'message' => 'Email cliente mancante o invalida.', 'recipient' => $to, 'subject' => $rendered['subject'] ];
    }

    $subject = $rendered['subject'] !== '' ? $rendered['subject'] : (string) $event['label'];
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
    $sent    = wp_mail( $to, $subject, $rendered['html'], $headers );

    if ( function_exists( 'rp_em_log_email_safe' ) ) {
        rp_em_log_email_safe( [
            'to'            => $to,
            'subject'       => $subject,
            'type'          => 'transactional',
            'campaign_id'   => $event_slug,
            'campaign_name' => (string) $event['label'] . ' · #' . $order_id,
            'status'        => $sent ? 'sent' : 'failed',
            'error'         => $sent ? '' : 'wp_mail returned false',
        ] );
    }

    return [
        'success'   => (bool) $sent,
        'message'   => $sent ? "Email inviata a {$to}" : "Invio fallito verso {$to}",
        'recipient' => $to,
        'subject'   => $subject,
    ];
}

// ═══ HOOK REGISTRATION ═════════════════════════════════════════════════════

/**
 * Registra gli hook WordPress/Woo per ogni evento transazionale.
 * Chiamato su 'init' — prima che woocommerce_order_status_* possa scattare.
 */
add_action( 'init', function () {
    foreach ( rp_em_transactional_events() as $event ) {
        $slug = (string) $event['slug'];
        $hook = (string) $event['hook'];
        if ( $hook === '' ) continue;

        add_action( $hook, function ( $order_id ) use ( $slug ) {
            // Guard: solo se binding abilitato (evita render inutile).
            $b = rp_em_get_transactional_binding( $slug );
            if ( ! $b['enabled'] || $b['template_id'] === '' ) return;
            rp_em_fire_transactional( $slug, (int) $order_id );
        }, 20, 1 );
    }
}, 5 );
