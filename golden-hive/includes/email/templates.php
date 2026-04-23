<?php
/**
 * Templates — CRUD dei template HTML email.
 *
 * Un template e HTML email-safe con placeholder UPPERCASE namespaced
 * ({BRAND_*}, {CAMPAIGN_*}, {PRODUCT_N_*}, {RECIPIENT_*}, {META_*}).
 * Indipendente dalla campagna: lo stesso template puo essere riusato da
 * piu campagne.
 *
 * Storage: wp_option 'rp_em_templates' = array di template.
 * Shape template:
 *   - id:                 string  ID univoco 'tpl_XXXXXXXX'
 *   - name:               string  Nome per riferimento interno
 *   - description:        string  Descrizione / use case
 *   - html:               string  HTML del template con placeholder
 *   - placeholders_cache: string[] Cache dei placeholder estratti
 *   - created_at:         string
 *   - updated_at:         string
 *
 * Nessun hook WordPress — solo storage puro.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'RP_EM_TEMPLATES_KEY' ) ) return;

const RP_EM_TEMPLATES_KEY = 'rp_em_templates';

/**
 * Ritorna tutti i template, ordinati per updated_at DESC.
 *
 * @return array[]
 */
function rp_em_get_templates(): array {
    $all = get_option( RP_EM_TEMPLATES_KEY, [] );
    if ( ! is_array( $all ) ) return [];

    usort( $all, fn( $a, $b ) => strcmp( $b['updated_at'] ?? '', $a['updated_at'] ?? '' ) );
    return $all;
}

/**
 * Ritorna un template per ID, o null.
 *
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
 * Crea o aggiorna un template. Rigenera automaticamente la cache dei
 * placeholder ogni volta che l'HTML cambia.
 *
 * @param array $data { id?, name, description?, html }
 * @return string ID del template salvato.
 *
 * Esempio:
 *   $id = rp_em_save_template([
 *       'name'        => 'Weekend Coupon 2p',
 *       'description' => '2 prodotti in evidenza con codice coupon',
 *       'html'        => '<html>...{BRAND_NAME}...{PRODUCT_1_NAME}...</html>',
 *   ]);
 */
function rp_em_save_template( array $data ): string {
    $all = get_option( RP_EM_TEMPLATES_KEY, [] );
    if ( ! is_array( $all ) ) $all = [];

    $now = current_time( 'mysql' );
    $id  = $data['id'] ?? '';

    if ( $id === '' ) {
        $id = 'tpl_' . substr( md5( uniqid( '', true ) ), 0, 8 );
    }

    $html         = (string) ( $data['html'] ?? '' );
    $placeholders = rp_em_extract_placeholders( $html );

    $record = [
        'id'                 => $id,
        'name'               => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
        'description'        => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
        'html'               => $html,
        'placeholders_cache' => $placeholders,
        'updated_at'         => $now,
    ];

    $found = false;
    foreach ( $all as $i => $existing ) {
        if ( ( $existing['id'] ?? '' ) === $id ) {
            $record['created_at'] = $existing['created_at'] ?? $now;
            $all[ $i ] = array_merge( $existing, $record );
            $found = true;
            break;
        }
    }

    if ( ! $found ) {
        $record['created_at'] = $now;
        $all[] = $record;
    }

    update_option( RP_EM_TEMPLATES_KEY, $all, false );
    return $id;
}

/**
 * Elimina un template.
 *
 * @param string $id
 * @return bool True se trovato ed eliminato.
 */
function rp_em_delete_template( string $id ): bool {
    $all = get_option( RP_EM_TEMPLATES_KEY, [] );
    if ( ! is_array( $all ) ) return false;

    $filtered = array_values( array_filter( $all, fn( $t ) => ( $t['id'] ?? '' ) !== $id ) );
    if ( count( $filtered ) === count( $all ) ) return false;

    update_option( RP_EM_TEMPLATES_KEY, $filtered, false );
    return true;
}

/**
 * Lista compatta dei template (id + name) per selettori UI.
 *
 * @return array[] [ { id, name, placeholder_count } ]
 */
function rp_em_list_templates(): array {
    $out = [];
    foreach ( rp_em_get_templates() as $t ) {
        $out[] = [
            'id'                => $t['id'] ?? '',
            'name'              => $t['name'] ?? '',
            'description'       => $t['description'] ?? '',
            'placeholder_count' => count( $t['placeholders_cache'] ?? [] ),
            'updated_at'        => $t['updated_at'] ?? '',
        ];
    }
    return $out;
}

/**
 * Installa il template demo dai seed se non esiste gia.
 * Idempotente: non sovrascrive un template con lo stesso slug esistente.
 *
 * @return string|null ID del template installato, o null se gia presente.
 */
function rp_em_install_demo_template(): ?string {
    $seed_html = __DIR__ . '/_seed/demo-template.html';
    if ( ! is_readable( $seed_html ) ) return null;

    // Cerca un template demo esistente per evitare duplicati.
    foreach ( rp_em_get_templates() as $t ) {
        if ( ( $t['name'] ?? '' ) === 'Demo Weekend Coupon' ) return $t['id'];
    }

    $html = file_get_contents( $seed_html );
    if ( ! $html ) return null;

    return rp_em_save_template( [
        'name'        => 'Demo Weekend Coupon',
        'description' => 'Template demo con 2 slot prodotto. Usa tutti i namespace: BRAND, CAMPAIGN, PRODUCT_N, RECIPIENT, META.',
        'html'        => $html,
    ] );
}

/**
 * Installa il template "Weekend · Coupon + 2 Prodotti" dai seed se non esiste.
 * Layout editoriale dark-themed con accent bar, hero, coupon box, 2 product
 * card (uomo/donna), 3-pillar strip brand e CTA finale.
 *
 * Idempotente: match per nome.
 *
 * @return string|null ID del template installato, o null se non installabile.
 */
function rp_em_install_weekend_2products_template(): ?string {
    $seed_html = __DIR__ . '/_seed/weekend-2products-template.html';
    if ( ! is_readable( $seed_html ) ) return null;

    $name = 'Weekend · Coupon + 2 Prodotti';
    foreach ( rp_em_get_templates() as $t ) {
        if ( ( $t['name'] ?? '' ) === $name ) return $t['id'];
    }

    $html = file_get_contents( $seed_html );
    if ( ! $html ) return null;

    return rp_em_save_template( [
        'name'        => $name,
        'description' => 'Newsletter weekend: codice sconto + 2 prodotti in evidenza (uomo/donna). Design editoriale serif, accent dal BRAND_COLOR_PRIMARY, 3-pillar strip dal tab Brand.',
        'html'        => $html,
    ] );
}

/**
 * Installa il template transazionale "Order · Spedito con Tracking" dai seed.
 * Destinato all'evento transazionale order_shipped: renderizzato con ORDER_*
 * risolti dall'ordine WooCommerce.
 *
 * Idempotente: match per nome.
 *
 * @return string|null ID del template installato, o null se non installabile.
 */
function rp_em_install_order_shipped_template(): ?string {
    $seed_html = __DIR__ . '/_seed/order-shipped-template.html';
    if ( ! is_readable( $seed_html ) ) return null;

    $name = 'Order · Spedito con Tracking';
    foreach ( rp_em_get_templates() as $t ) {
        if ( ( $t['name'] ?? '' ) === $name ) return $t['id'];
    }

    $html = file_get_contents( $seed_html );
    if ( ! $html ) return null;

    return rp_em_save_template( [
        'name'        => $name,
        'description' => 'Email transazionale per evento order_shipped. Mostra step di spedizione, codice tracking, corriere e CTA al link del corriere. Usa {ORDER_*} risolti dall\'ordine WooCommerce.',
        'html'        => $html,
    ] );
}

/**
 * Installa il template "Catalog · 6 Prodotti con Sezioni" dai seed.
 * 3 sezioni categorizzate (accent-color differenziato) con 2 prodotti ciascuna.
 * Ogni prodotto ha badge custom (bg/color/text) e CTA.
 *
 * Idempotente: match per nome.
 *
 * @return string|null ID del template installato, o null se non installabile.
 */
function rp_em_install_catalog_6products_template(): ?string {
    $seed_html = __DIR__ . '/_seed/catalog-6products-template.html';
    if ( ! is_readable( $seed_html ) ) return null;

    $name = 'Catalog · 6 Prodotti con Sezioni';
    foreach ( rp_em_get_templates() as $t ) {
        if ( ( $t['name'] ?? '' ) === $name ) return $t['id'];
    }

    $html = file_get_contents( $seed_html );
    if ( ! $html ) return null;

    return rp_em_save_template( [
        'name'        => $name,
        'description' => 'Newsletter catalogo: hero + 3 sezioni categorizzate, ognuna con 2 prodotti + link "Vedi tutti". Accent colors progressivi (brand primary → rosa → pink). Badge custom per prodotto dal payload campagna.',
        'html'        => $html,
    ] );
}

// ── Auto-install dei seed template curati (one-shot per flag).
// Se l'utente elimina il template a mano, non viene reinstallato.
add_action( 'admin_init', function () {
    if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) return;

    if ( get_option( 'rp_em_seed_weekend_2p_installed' ) !== '1' ) {
        if ( rp_em_install_weekend_2products_template() ) {
            update_option( 'rp_em_seed_weekend_2p_installed', '1', false );
        }
    }

    if ( get_option( 'rp_em_seed_order_shipped_installed' ) !== '1' ) {
        $id = rp_em_install_order_shipped_template();
        if ( $id ) {
            update_option( 'rp_em_seed_order_shipped_installed', '1', false );

            // Pre-wire del binding order_shipped → questo template (subject/preheader
            // di default). L'utente lo vedra gia compilato quando apre il tab, ma
            // DISABILITATO di default (non si attiva senza conferma esplicita).
            if ( function_exists( 'rp_em_save_transactional_binding' )
                 && function_exists( 'rp_em_get_transactional_binding' ) ) {
                $existing = rp_em_get_transactional_binding( 'order_shipped' );
                if ( $existing['template_id'] === '' ) {
                    rp_em_save_transactional_binding( 'order_shipped', [
                        'template_id' => $id,
                        'subject'     => 'Il tuo ordine {ORDER_NUMBER} e in viaggio',
                        'preheader'   => 'Traccia la spedizione {ORDER_CARRIER} in tempo reale',
                    ] );
                }
            }
        }
    }

    if ( get_option( 'rp_em_seed_catalog_6p_installed' ) !== '1' ) {
        if ( rp_em_install_catalog_6products_template() ) {
            update_option( 'rp_em_seed_catalog_6p_installed', '1', false );
        }
    }
} );
