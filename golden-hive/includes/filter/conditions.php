<?php
/**
 * Conditions — tipi di condizione e valutatori per il filter engine.
 *
 * Ogni condizione ha:
 * - type:     stringa identificativa (es. 'category', 'price_range', 'sku_pattern')
 * - operator: 'is', 'is_not', 'contains', 'gt', 'lt', 'between', 'in', 'not_in', 'matches', 'exists', 'not_exists'
 * - value:    valore da confrontare (tipo varia per condizione)
 *
 * Il filter engine chiama gh_evaluate_condition() per ogni condizione su ogni prodotto.
 * Le condizioni vengono combinate con AND (tutte devono essere vere).
 *
 * Nessun hook WordPress qui — solo logica pura.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mappa dei tipi di condizione disponibili con metadati per la UI.
 *
 * @return array [ type => { label, group, operators[], value_type, value_options? } ]
 *
 * Esempio:
 *   $defs = gh_get_condition_definitions();
 *   // 'category' => { label: 'Categoria', group: 'taxonomy', ... }
 */
function gh_get_condition_definitions(): array {

    return [
        // ── TAXONOMY ────────────────────────────────────
        'category' => [
            'label'      => 'Categoria',
            'group'      => 'taxonomy',
            'operators'  => [ 'in', 'not_in' ],
            'value_type' => 'term_ids',
            'multi'      => true,
        ],
        'tag' => [
            'label'      => 'Tag',
            'group'      => 'taxonomy',
            'operators'  => [ 'in', 'not_in' ],
            'value_type' => 'term_ids',
            'multi'      => true,
        ],
        'attribute' => [
            'label'      => 'Attributo',
            'group'      => 'taxonomy',
            'operators'  => [ 'has_value', 'not_has_value', 'has_attribute', 'not_has_attribute' ],
            'value_type' => 'attribute_value',
        ],

        // ── STATUS & TYPE ───────────────────────────────
        'status' => [
            'label'      => 'Stato',
            'group'      => 'status',
            'operators'  => [ 'is', 'is_not' ],
            'value_type' => 'select',
            'options'    => [ 'publish', 'draft', 'private', 'pending', 'trash' ],
        ],
        'type' => [
            'label'      => 'Tipo',
            'group'      => 'status',
            'operators'  => [ 'is', 'is_not' ],
            'value_type' => 'select',
            'options'    => [ 'simple', 'variable' ],
        ],

        // ── PRICE ───────────────────────────────────────
        'price_range' => [
            'label'      => 'Prezzo',
            'group'      => 'price',
            'operators'  => [ 'between', 'gt', 'lt' ],
            'value_type' => 'number_range',
        ],
        'has_sale' => [
            'label'      => 'In saldo',
            'group'      => 'price',
            'operators'  => [ 'is' ],
            'value_type' => 'boolean',
        ],

        // ── STOCK ───────────────────────────────────────
        'stock_status' => [
            'label'      => 'Stato stock',
            'group'      => 'stock',
            'operators'  => [ 'is', 'is_not' ],
            'value_type' => 'select',
            'options'    => [ 'instock', 'outofstock', 'onbackorder', 'partial' ],
        ],
        'stock_qty' => [
            'label'      => 'Quantita stock',
            'group'      => 'stock',
            'operators'  => [ 'gt', 'lt', 'between', 'is' ],
            'value_type' => 'number_range',
        ],

        // ── TEXT / IDENTIFIERS ──────────────────────────
        'sku_pattern' => [
            'label'      => 'SKU',
            'group'      => 'text',
            'operators'  => [ 'is', 'contains', 'starts_with', 'matches' ],
            'value_type' => 'text',
        ],
        'name_contains' => [
            'label'      => 'Nome prodotto',
            'group'      => 'text',
            'operators'  => [ 'contains', 'not_contains', 'is', 'starts_with' ],
            'value_type' => 'text',
        ],

        // ── DATES ───────────────────────────────────────
        'date_created' => [
            'label'      => 'Data creazione',
            'group'      => 'dates',
            'operators'  => [ 'after', 'before', 'between' ],
            'value_type' => 'date_range',
        ],
        'date_modified' => [
            'label'      => 'Data modifica',
            'group'      => 'dates',
            'operators'  => [ 'after', 'before', 'between' ],
            'value_type' => 'date_range',
        ],

        // ── SEO ─────────────────────────────────────────
        'seo_field' => [
            'label'      => 'Campo SEO',
            'group'      => 'seo',
            'operators'  => [ 'exists', 'not_exists' ],
            'value_type' => 'select',
            'options'    => [ 'meta_title', 'meta_description', 'focus_keyword' ],
        ],

        // ── MEDIA ───────────────────────────────────────
        'has_image' => [
            'label'      => 'Immagine',
            'group'      => 'media',
            'operators'  => [ 'exists', 'not_exists' ],
            'value_type' => 'none',
        ],
        'gallery_count' => [
            'label'      => 'Foto galleria',
            'group'      => 'media',
            'operators'  => [ 'gt', 'lt', 'is' ],
            'value_type' => 'number',
        ],

        // ── VARIANTS ────────────────────────────────────
        'variant_count' => [
            'label'      => 'Numero varianti',
            'group'      => 'variants',
            'operators'  => [ 'gt', 'lt', 'between', 'is' ],
            'value_type' => 'number_range',
        ],
        'has_size' => [
            'label'      => 'Taglia disponibile',
            'group'      => 'variants',
            'operators'  => [ 'has_value', 'not_has_value' ],
            'value_type' => 'text',
        ],

        // ── MENU ORDER ──────────────────────────────────
        'menu_order' => [
            'label'      => 'Ordine menu',
            'group'      => 'order',
            'operators'  => [ 'gt', 'lt', 'between', 'is' ],
            'value_type' => 'number_range',
        ],
    ];
}

/**
 * Valuta una singola condizione su un prodotto WooCommerce.
 *
 * @param WC_Product $product   Il prodotto da valutare.
 * @param array      $condition { type, operator, value, ?attribute_name }
 * @param array      $cache     Cache condivisa per dati costosi (varianti, immagini).
 * @return bool True se la condizione e soddisfatta.
 *
 * Esempio:
 *   gh_evaluate_condition( $product, [
 *       'type' => 'category', 'operator' => 'in', 'value' => [15, 22]
 *   ] );
 */
function gh_evaluate_condition( WC_Product $product, array $condition, array &$cache = [] ): bool {

    $type     = $condition['type']     ?? '';
    $operator = $condition['operator'] ?? '';
    $value    = $condition['value']    ?? null;
    $pid      = $product->get_id();

    return match ( $type ) {

        // ── TAXONOMY ────────────────────────────────────
        'category' => gh_eval_taxonomy( $pid, 'product_cat', $operator, (array) $value ),
        'tag'      => gh_eval_taxonomy( $pid, 'product_tag', $operator, (array) $value ),
        'attribute' => gh_eval_attribute(
            $product,
            $condition['attribute_name'] ?? '',
            $operator,
            $value,
            $cache
        ),

        // ── STATUS & TYPE ───────────────────────────────
        'status' => gh_eval_compare( $product->get_status(), $operator, $value ),
        'type'   => gh_eval_compare( $product->get_type(), $operator, $value ),

        // ── PRICE ───────────────────────────────────────
        'price_range' => gh_eval_numeric( (float) $product->get_price(), $operator, $value ),
        'has_sale'    => ( (bool) $value ) === ( $product->get_sale_price() !== '' ),

        // ── STOCK ───────────────────────────────────────
        'stock_status' => gh_eval_stock_status( $product, $operator, $value, $cache ),
        'stock_qty'    => gh_eval_numeric(
            (float) ( $product->get_stock_quantity() ?? 0 ),
            $operator,
            $value
        ),

        // ── TEXT ────────────────────────────────────────
        'sku_pattern'   => gh_eval_text( $product->get_sku(), $operator, (string) $value ),
        'name_contains' => gh_eval_text( $product->get_name(), $operator, (string) $value ),

        // ── DATES ───────────────────────────────────────
        'date_created'  => gh_eval_date( $product->get_date_created()?->date( 'Y-m-d' ) ?? '', $operator, $value ),
        'date_modified' => gh_eval_date( $product->get_date_modified()?->date( 'Y-m-d' ) ?? '', $operator, $value ),

        // ── SEO ─────────────────────────────────────────
        'seo_field' => gh_eval_seo_field( $pid, $operator, (string) $value ),

        // ── MEDIA ───────────────────────────────────────
        'has_image' => gh_eval_has_image( $product, $operator ),
        'gallery_count' => gh_eval_numeric(
            (float) count( $product->get_gallery_image_ids() ),
            $operator,
            $value
        ),

        // ── VARIANTS ────────────────────────────────────
        'variant_count' => gh_eval_numeric(
            (float) count( gh_get_cached_variants( $pid, $cache ) ),
            $operator,
            $value
        ),
        'has_size' => gh_eval_has_size( $product, $operator, (string) $value, $cache ),

        // ── ORDER ───────────────────────────────────────
        'menu_order' => gh_eval_numeric(
            (float) ( get_post_field( 'menu_order', $pid ) ?: 0 ),
            $operator,
            $value
        ),

        default => true,
    };
}

// ── EVALUATORS ────────────────────────────────────────────────────────────────

/**
 * Valuta condizione su tassonomia (category, tag).
 */
function gh_eval_taxonomy( int $product_id, string $taxonomy, string $operator, array $term_ids ): bool {

    $assigned = wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
    if ( is_wp_error( $assigned ) ) $assigned = [];

    $has_match = ! empty( array_intersect( $assigned, array_map( 'intval', $term_ids ) ) );

    return match ( $operator ) {
        'in'     => $has_match,
        'not_in' => ! $has_match,
        default  => true,
    };
}

/**
 * Valuta condizione su attributi prodotto (pa_taglia, pa_colore, ecc).
 */
function gh_eval_attribute( WC_Product $product, string $attr_name, string $operator, mixed $value, array &$cache ): bool {

    $attrs = $product->get_attributes();

    return match ( $operator ) {
        'has_attribute'     => isset( $attrs[ $attr_name ] ),
        'not_has_attribute' => ! isset( $attrs[ $attr_name ] ),
        'has_value' => gh_attr_has_value( $product, $attr_name, (string) $value, $cache ),
        'not_has_value' => ! gh_attr_has_value( $product, $attr_name, (string) $value, $cache ),
        default => true,
    };
}

/**
 * Controlla se un attributo ha un valore specifico (anche a livello variante).
 */
function gh_attr_has_value( WC_Product $product, string $attr_name, string $value, array &$cache ): bool {

    // Controlla a livello prodotto
    $attrs = $product->get_attributes();
    if ( isset( $attrs[ $attr_name ] ) ) {
        $attr = $attrs[ $attr_name ];
        if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
            $options = $attr->get_options();
            // Per attributi tassonomici, get_options ritorna term_id
            foreach ( $options as $opt ) {
                $term = get_term( $opt );
                $opt_value = is_object( $term ) ? $term->name : (string) $opt;
                if ( strcasecmp( $opt_value, $value ) === 0 ) return true;
            }
        }
    }

    return false;
}

/**
 * Valuta confronto semplice (is, is_not).
 */
function gh_eval_compare( string $actual, string $operator, mixed $expected ): bool {

    return match ( $operator ) {
        'is'     => $actual === (string) $expected,
        'is_not' => $actual !== (string) $expected,
        default  => true,
    };
}

/**
 * Valuta condizione numerica (gt, lt, between, is).
 */
function gh_eval_numeric( float $actual, string $operator, mixed $value ): bool {

    return match ( $operator ) {
        'gt'      => $actual > (float) ( is_array( $value ) ? $value['min'] ?? $value[0] ?? 0 : $value ),
        'lt'      => $actual < (float) ( is_array( $value ) ? $value['max'] ?? $value[0] ?? 0 : $value ),
        'is'      => abs( $actual - (float) ( is_array( $value ) ? $value[0] ?? $value['min'] ?? 0 : $value ) ) < 0.01,
        'between' => is_array( $value )
            && $actual >= (float) ( $value['min'] ?? $value[0] ?? 0 )
            && $actual <= (float) ( $value['max'] ?? $value[1] ?? PHP_FLOAT_MAX ),
        default => true,
    };
}

/**
 * Valuta condizione testuale (contains, starts_with, is, matches).
 */
function gh_eval_text( string $actual, string $operator, string $value ): bool {

    $actual_lower = mb_strtolower( $actual );
    $value_lower  = mb_strtolower( $value );

    return match ( $operator ) {
        'is'           => $actual_lower === $value_lower,
        'contains'     => str_contains( $actual_lower, $value_lower ),
        'not_contains' => ! str_contains( $actual_lower, $value_lower ),
        'starts_with'  => str_starts_with( $actual_lower, $value_lower ),
        'matches'      => (bool) @preg_match( '/' . $value . '/i', $actual ),
        default        => true,
    };
}

/**
 * Valuta condizione su date (after, before, between).
 */
function gh_eval_date( string $actual, string $operator, mixed $value ): bool {

    if ( empty( $actual ) ) return false;

    return match ( $operator ) {
        'after'   => $actual >= (string) ( is_array( $value ) ? $value['min'] ?? $value[0] ?? '' : $value ),
        'before'  => $actual <= (string) ( is_array( $value ) ? $value['max'] ?? $value[0] ?? '' : $value ),
        'between' => is_array( $value )
            && $actual >= (string) ( $value['min'] ?? $value[0] ?? '' )
            && $actual <= (string) ( $value['max'] ?? $value[1] ?? '' ),
        default => true,
    };
}

/**
 * Valuta stock status con supporto 'partial' per prodotti variabili.
 */
function gh_eval_stock_status( WC_Product $product, string $operator, string $value, array &$cache ): bool {

    if ( $value === 'partial' ) {
        // Solo per prodotti variabili: almeno una variante in stock e almeno una out
        if ( ! $product->is_type( 'variable' ) ) {
            $actual = $product->get_stock_status() === 'instock' ? 'instock' : 'outofstock';
        } else {
            $variants = gh_get_cached_variants( $product->get_id(), $cache );
            $in  = 0;
            $out = 0;
            foreach ( $variants as $v ) {
                if ( $v->get_stock_status() === 'instock' ) $in++; else $out++;
            }
            $actual = ( $in > 0 && $out > 0 ) ? 'partial' : ( $in > 0 ? 'instock' : 'outofstock' );
        }
    } else {
        if ( $product->is_type( 'simple' ) ) {
            $actual = $product->get_stock_status();
        } else {
            $variants = gh_get_cached_variants( $product->get_id(), $cache );
            $has_stock = false;
            foreach ( $variants as $v ) {
                if ( $v->get_stock_status() === 'instock' ) { $has_stock = true; break; }
            }
            $actual = $has_stock ? 'instock' : 'outofstock';
        }
    }

    return match ( $operator ) {
        'is'     => $actual === $value,
        'is_not' => $actual !== $value,
        default  => true,
    };
}

/**
 * Valuta campo SEO (exists / not_exists).
 */
function gh_eval_seo_field( int $product_id, string $operator, string $field ): bool {

    $meta_key = match ( $field ) {
        'meta_title'       => 'rank_math_title',
        'meta_description' => 'rank_math_description',
        'focus_keyword'    => 'rank_math_focus_keyword',
        default            => '',
    };

    if ( ! $meta_key ) return true;

    $val = get_post_meta( $product_id, $meta_key, true );
    $has = ! empty( $val );

    return match ( $operator ) {
        'exists'     => $has,
        'not_exists' => ! $has,
        default      => true,
    };
}

/**
 * Valuta presenza immagine featured.
 */
function gh_eval_has_image( WC_Product $product, string $operator ): bool {

    $has = (bool) $product->get_image_id();

    return match ( $operator ) {
        'exists'     => $has,
        'not_exists' => ! $has,
        default      => true,
    };
}

/**
 * Valuta se un prodotto variabile ha una taglia specifica in stock.
 */
function gh_eval_has_size( WC_Product $product, string $operator, string $size, array &$cache ): bool {

    if ( ! $product->is_type( 'variable' ) ) return false;

    $variants = gh_get_cached_variants( $product->get_id(), $cache );
    $found = false;

    foreach ( $variants as $v ) {
        $v_size = rp_cm_get_variant_size( $v );
        if ( $v_size !== null && strcasecmp( $v_size, $size ) === 0 ) {
            if ( $v->get_stock_status() === 'instock' ) {
                $found = true;
                break;
            }
        }
    }

    return match ( $operator ) {
        'has_value'     => $found,
        'not_has_value' => ! $found,
        default         => true,
    };
}

// ── CACHE HELPERS ─────────────────────────────────────────────────────────────

/**
 * Ritorna le varianti di un prodotto con cache per evitare query ripetute.
 *
 * @param int   $product_id
 * @param array &$cache
 * @return WC_Product_Variation[]
 */
function gh_get_cached_variants( int $product_id, array &$cache ): array {

    if ( ! isset( $cache['variants'][ $product_id ] ) ) {
        $cache['variants'][ $product_id ] = rp_cm_get_product_variants( $product_id );
    }
    return $cache['variants'][ $product_id ];
}
