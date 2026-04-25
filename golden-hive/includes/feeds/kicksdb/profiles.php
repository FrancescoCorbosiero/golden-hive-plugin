<?php
/**
 * KicksDB — mapping profiles.
 *
 * Un "profile" e un layer di customizzazione che si applica al normalizer:
 * - required_fields      → import fallisce se uno di questi e vuoto
 * - description_template → rigenera description via placeholder {brand}/{model}/...
 * - gallery_opts         → override delle settings sulla selezione immagini
 *
 * NON e un full visual mapper: il binding source-path → WC-field e gestito
 * dal normalizer (vedi normalizer.php). Il profile aggiunge CONSTRAINTS e
 * OVERRIDES, non rimappatura completa. Se l'utente avra in futuro bisogno
 * di rebind, si appoggera al modulo mapper/ esistente.
 *
 * Una sola profile puo essere 'active' alla volta. L'active viene applicata
 * a ogni normalize() (lookup / discover import / refresh). Se nessuna e attiva,
 * il normalizer usa il comportamento di default (equivalente a un profile
 * con zero required + template vuoto + gallery da settings).
 */

defined( 'ABSPATH' ) || exit;

defined( 'GH_KICKSDB_PROFILES_KEY' ) || define( 'GH_KICKSDB_PROFILES_KEY', 'gh_kicksdb_profiles' );

if ( function_exists( 'gh_kicksdb_profiles_all' ) ) return;

/**
 * Campi canonici esposti dal normalizer che il profile puo marcare come
 * required o usare nei placeholder del description_template.
 *
 * @return array { key => { label, description_placeholder, path_hint } }
 */
function gh_kicksdb_profile_fields(): array {
    return [
        'sku'         => [ 'label' => 'SKU',          'placeholder' => '{sku}',          'path' => 'data.sku' ],
        'title'       => [ 'label' => 'Titolo',       'placeholder' => '{title}',        'path' => 'data.title' ],
        'brand'       => [ 'label' => 'Brand',        'placeholder' => '{brand}',        'path' => 'data.brand' ],
        'model'       => [ 'label' => 'Modello',      'placeholder' => '{model}',        'path' => 'data.model' ],
        'gender'      => [ 'label' => 'Gender',       'placeholder' => '{gender}',       'path' => 'data.gender' ],
        'colorway'    => [ 'label' => 'Colorway',     'placeholder' => '{colorway}',     'path' => 'data.colorway' ],
        'description' => [ 'label' => 'Descrizione',  'placeholder' => '{description}',  'path' => 'data.description' ],
        'image'       => [ 'label' => 'Immagine',     'placeholder' => '',               'path' => 'data.image' ],
        'release'     => [ 'label' => 'Release date', 'placeholder' => '{release}',      'path' => 'data.release_date' ],
        'variants'    => [ 'label' => 'Varianti',     'placeholder' => '',               'path' => 'data.variants[]' ],
    ];
}

/**
 * Ritorna tutte le profile ordinate (active first, poi per updated_at).
 */
function gh_kicksdb_profiles_all(): array {
    $profiles = gh_option_list_all( GH_KICKSDB_PROFILES_KEY );
    usort( $profiles, function ( $a, $b ) {
        $aa = ! empty( $a['active'] );
        $ba = ! empty( $b['active'] );
        if ( $aa !== $ba ) return $aa ? -1 : 1;
        return strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) );
    } );
    return $profiles;
}

function gh_kicksdb_profiles_find( string $id ): ?array {
    return gh_option_list_find( GH_KICKSDB_PROFILES_KEY, $id );
}

function gh_kicksdb_profile_active(): ?array {
    foreach ( gh_option_list_all( GH_KICKSDB_PROFILES_KEY ) as $p ) {
        if ( ! empty( $p['active'] ) ) return $p;
    }
    return null;
}

/**
 * Upsert. Se incoming.active === true, disattiva automaticamente gli altri
 * (vincolo di unicita: una sola active alla volta).
 */
function gh_kicksdb_profile_upsert( array $data ): string {
    $will_be_active = ! empty( $data['active'] );

    $id = gh_option_list_upsert(
        GH_KICKSDB_PROFILES_KEY,
        $data,
        'id',
        'kdbprof_',
        'gh_kicksdb_profile_sanitize',
        true
    );

    if ( $will_be_active ) {
        // Disattiva gli altri
        $all = gh_option_list_all( GH_KICKSDB_PROFILES_KEY );
        $dirty = false;
        foreach ( $all as &$p ) {
            if ( ( $p['id'] ?? '' ) !== $id && ! empty( $p['active'] ) ) {
                $p['active'] = false;
                $dirty = true;
            }
        }
        if ( $dirty ) gh_option_list_replace( GH_KICKSDB_PROFILES_KEY, $all );
    }

    return $id;
}

function gh_kicksdb_profile_remove( string $id ): bool {
    return gh_option_list_remove( GH_KICKSDB_PROFILES_KEY, $id );
}

function gh_kicksdb_profile_set_active( string $id ): bool {
    $all = gh_option_list_all( GH_KICKSDB_PROFILES_KEY );
    $found = false;
    foreach ( $all as &$p ) {
        $is = ( $p['id'] ?? '' ) === $id;
        $p['active'] = $is;
        if ( $is ) $found = true;
    }
    if ( $found ) gh_option_list_replace( GH_KICKSDB_PROFILES_KEY, $all );
    return $found;
}

/**
 * Sanitize: accetta solo i campi noti, normalizza tipi.
 */
function gh_kicksdb_profile_sanitize( array $p ): array {
    $out = [
        'id'                   => (string) ( $p['id'] ?? '' ),
        'name'                 => sanitize_text_field( (string) ( $p['name'] ?? 'Profile' ) ),
        'active'               => ! empty( $p['active'] ),
        'required_fields'      => [],
        'description_template' => (string) ( $p['description_template'] ?? '' ),
        'gallery_opts' => [
            'include_main'  => ! isset( $p['gallery_opts']['include_main'] ) || (bool) $p['gallery_opts']['include_main'],
            'include_360'   => (bool) ( $p['gallery_opts']['include_360'] ?? false ),
            'every_nth_360' => max( 1, min( 60, (int) ( $p['gallery_opts']['every_nth_360'] ?? 6 ) ) ),
        ],
    ];

    // Required: solo chiavi note
    $known = array_keys( gh_kicksdb_profile_fields() );
    foreach ( (array) ( $p['required_fields'] ?? [] ) as $f ) {
        $f = sanitize_key( (string) $f );
        if ( in_array( $f, $known, true ) && ! in_array( $f, $out['required_fields'], true ) ) {
            $out['required_fields'][] = $f;
        }
    }

    // Description template: textarea, preserve newlines
    $out['description_template'] = (string) wp_unslash( $out['description_template'] );

    return $out;
}

/**
 * Rende un description_template contro un set di valori risolti.
 *
 * Supporta placeholder {field_name} dove field_name e una chiave di
 * gh_kicksdb_profile_fields(). Placeholder non risolti sono lasciati
 * letterali (facilita il debug).
 *
 * @param string $template Es. "{brand} {model} in colore {colorway}"
 * @param array  $values   Es. [ 'brand' => 'Nike', 'model' => 'Dunk Low', ... ]
 */
function gh_kicksdb_profile_render_template( string $template, array $values ): string {
    if ( $template === '' ) return '';
    $out = $template;
    foreach ( $values as $k => $v ) {
        if ( ! is_scalar( $v ) ) continue;
        $out = str_replace( '{' . $k . '}', (string) $v, $out );
    }
    return $out;
}

/**
 * Verifica che tutti i required_fields siano non-vuoti nel set di valori.
 *
 * @return string[] Lista dei campi required mancanti (vuota se ok).
 */
function gh_kicksdb_profile_check_required( array $required, array $values ): array {
    $missing = [];
    foreach ( $required as $f ) {
        $v = $values[ $f ] ?? null;
        if ( $v === null || $v === '' || ( is_array( $v ) && empty( $v ) ) ) {
            $missing[] = $f;
        }
    }
    return $missing;
}
