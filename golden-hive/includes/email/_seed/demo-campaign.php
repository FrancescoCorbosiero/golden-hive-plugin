<?php
/**
 * Demo campaign payload — CAMPAIGN_* e META_* di esempio per il smoke test.
 * Usato dal seeder per creare una campagna "Weekend Coupon Demo" funzionante.
 */

return [
    'name'         => 'Weekend Coupon Demo',
    'subject'      => 'Solo per te: -20% sul weekend {META_DATE}',
    'preheader'    => '48 ore per approfittarne. Usa il codice qui sotto.',
    'payload'      => [
        'CAMPAIGN_HERO_TITLE'   => 'Weekend Coupon',
        'CAMPAIGN_HERO_SUBTITLE'=> 'Solo sabato e domenica: 20% di sconto su selezione.',
        'CAMPAIGN_COUPON_CODE'  => 'WEEKEND20',
        'CAMPAIGN_COUPON_LABEL' => 'Codice sconto',
        'CAMPAIGN_CTA_LABEL'    => 'Scopri la selezione',
        'CAMPAIGN_CTA_URL'      => function_exists( 'home_url' ) ? home_url( '/shop/' ) : '/shop/',
        'CAMPAIGN_FOOTER_NOTE'  => 'Offerta valida solo nel weekend, non cumulabile con altre promozioni.',
    ],
    'source_type'  => 'hustle',
    'module_ids'   => [],
    'csv_contacts' => '',
    'rate_limit'   => 200000,
];
