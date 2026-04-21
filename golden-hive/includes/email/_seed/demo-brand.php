<?php
/**
 * Demo brand defaults — valori neutri e sicuri da usare come seed iniziale.
 * Caricati da rp_em_get_brand_defaults() in assenza di configurazione salvata.
 *
 * Sostituibili dall'utente tramite il tab Brand nell'admin.
 */

return [
    'BRAND_NAME'              => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'Il Tuo Brand',
    'BRAND_TAGLINE'           => 'Quality you can feel',
    'BRAND_LOGO_URL'          => function_exists( 'home_url' ) ? home_url( '/wp-content/uploads/logo.png' ) : '',
    'BRAND_LOGO_ALT'          => 'Logo',
    'BRAND_WEBSITE_URL'       => function_exists( 'home_url' ) ? home_url() : '',
    'BRAND_COLOR_PRIMARY'     => '#0c0d10',
    'BRAND_COLOR_SECONDARY'   => '#3d7fff',
    'BRAND_COLOR_ACCENT'      => '#22c78b',
    'BRAND_COLOR_BG'          => '#ffffff',
    'BRAND_COLOR_TEXT'        => '#111111',
    'BRAND_FONT_HEADING'      => 'Helvetica Neue, Arial, sans-serif',
    'BRAND_FONT_BODY'         => 'Helvetica Neue, Arial, sans-serif',
    'BRAND_PILLAR_1_TITLE'    => 'Spedizione veloce',
    'BRAND_PILLAR_1_DESC'     => 'Consegna in 24/48h su tutto il territorio.',
    'BRAND_PILLAR_2_TITLE'    => 'Prodotti originali',
    'BRAND_PILLAR_2_DESC'     => 'Autenticita garantita al 100%.',
    'BRAND_PILLAR_3_TITLE'    => 'Supporto dedicato',
    'BRAND_PILLAR_3_DESC'     => 'Assistenza clienti in italiano, tutti i giorni.',
    'BRAND_INSTAGRAM'         => '',
    'BRAND_FACEBOOK'          => '',
    'BRAND_TIKTOK'            => '',
    'BRAND_EMAIL'             => function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '',
    'BRAND_PHONE'             => '',
    'BRAND_LEGAL_ADDRESS'     => '',
    'BRAND_LEGAL_VAT'         => '',
    'BRAND_UNSUBSCRIBE_URL'   => function_exists( 'home_url' ) ? home_url( '/unsubscribe' ) : '/unsubscribe',
];
