<?php
/**
 * Price Calculator — tiered margin engine for import pricing.
 *
 * Calculates final selling price from cost/market data with configurable
 * strategies: flat percentage markup, tiered margins by price range and/or
 * brand, floor price, and rounding.
 *
 * Ported from woo-importer PriceCalculator. Adapted for WordPress: rules
 * are stored in wp_options instead of config files.
 */

defined( 'ABSPATH' ) || exit;

/** wp_options key for pricing rules. */
define( 'GH_PRICE_RULES_KEY', 'gh_price_rules' );

/**
 * Calculates selling price from cost using configured rules.
 *
 * @param float  $cost       Wholesale/market cost.
 * @param string $brand      Brand name (for brand-specific tiers).
 * @param array  $rules_override Optional rules override (skip loading from DB).
 * @return float Final selling price.
 */
function gh_calculate_price( float $cost, string $brand = '', array $rules_override = [] ): float {
    if ( $cost <= 0 ) return 0.0;

    $config = $rules_override ?: gh_get_price_rules();
    if ( empty( $config['enabled'] ) ) return $cost;

    $margin  = gh_resolve_margin( $cost, $brand, $config );
    $price   = $cost * $margin;
    $floor   = (float) ( $config['floor_price'] ?? 0 );

    if ( $floor > 0 && $price < $floor ) {
        $price = $floor;
    }

    return gh_apply_price_rounding( $price, $config['rounding'] ?? 'ceil' );
}

/**
 * Calculates price with a full breakdown for debugging/preview.
 *
 * @param float  $cost
 * @param string $brand
 * @param array  $rules_override
 * @return array { cost, margin, margin_type, raw_price, floor_applied, final_price }
 */
function gh_calculate_price_breakdown( float $cost, string $brand = '', array $rules_override = [] ): array {
    if ( $cost <= 0 ) {
        return [ 'cost' => 0, 'margin' => 0, 'margin_type' => 'none', 'raw_price' => 0, 'floor_applied' => false, 'final_price' => 0 ];
    }

    $config = $rules_override ?: gh_get_price_rules();
    if ( empty( $config['enabled'] ) ) {
        return [ 'cost' => $cost, 'margin' => 1, 'margin_type' => 'disabled', 'raw_price' => $cost, 'floor_applied' => false, 'final_price' => $cost ];
    }

    $margin      = gh_resolve_margin( $cost, $brand, $config );
    $margin_type = gh_resolve_margin_type( $cost, $brand, $config );
    $raw_price   = $cost * $margin;
    $floor       = (float) ( $config['floor_price'] ?? 0 );
    $floor_hit   = $floor > 0 && $raw_price < $floor;

    if ( $floor_hit ) $raw_price = $floor;

    $final = gh_apply_price_rounding( $raw_price, $config['rounding'] ?? 'ceil' );

    return [
        'cost'          => round( $cost, 2 ),
        'margin'        => $margin,
        'margin_type'   => $margin_type,
        'raw_price'     => round( $raw_price, 2 ),
        'floor_applied' => $floor_hit,
        'final_price'   => $final,
    ];
}

/**
 * Resolves the margin multiplier for a given cost and brand.
 *
 * @param float  $cost
 * @param string $brand
 * @param array  $config
 * @return float Multiplier (e.g. 3.5 means ×3.5).
 */
function gh_resolve_margin( float $cost, string $brand, array $config ): float {
    $tiers = $config['tiers'] ?? [];

    if ( ! empty( $tiers ) ) {
        // Brand-specific tiers first, then wildcard
        foreach ( $tiers as $tier ) {
            $tier_brand = $tier['brand'] ?? '*';
            if ( $tier_brand !== '*' && strcasecmp( $tier_brand, $brand ) !== 0 ) continue;

            $min = (float) ( $tier['min_cost'] ?? 0 );
            $max = (float) ( $tier['max_cost'] ?? PHP_FLOAT_MAX );

            if ( $cost >= $min && $cost < $max ) {
                return (float) ( $tier['margin'] ?? $config['flat_margin'] ?? 3.5 );
            }
        }

        // Wildcard fallback
        foreach ( $tiers as $tier ) {
            if ( ( $tier['brand'] ?? '*' ) !== '*' ) continue;
            $min = (float) ( $tier['min_cost'] ?? 0 );
            $max = (float) ( $tier['max_cost'] ?? PHP_FLOAT_MAX );
            if ( $cost >= $min && $cost < $max ) {
                return (float) ( $tier['margin'] ?? $config['flat_margin'] ?? 3.5 );
            }
        }
    }

    return (float) ( $config['flat_margin'] ?? 3.5 );
}

/**
 * Returns a human-readable label for the margin type applied.
 */
function gh_resolve_margin_type( float $cost, string $brand, array $config ): string {
    $tiers = $config['tiers'] ?? [];

    if ( ! empty( $tiers ) ) {
        foreach ( $tiers as $i => $tier ) {
            $tier_brand = $tier['brand'] ?? '*';
            if ( $tier_brand !== '*' && strcasecmp( $tier_brand, $brand ) !== 0 ) continue;
            $min = (float) ( $tier['min_cost'] ?? 0 );
            $max = (float) ( $tier['max_cost'] ?? PHP_FLOAT_MAX );
            if ( $cost >= $min && $cost < $max ) {
                return 'tier_' . $i . ( $tier_brand !== '*' ? '_' . sanitize_title( $tier_brand ) : '' );
            }
        }
        foreach ( $tiers as $i => $tier ) {
            if ( ( $tier['brand'] ?? '*' ) !== '*' ) continue;
            $min = (float) ( $tier['min_cost'] ?? 0 );
            $max = (float) ( $tier['max_cost'] ?? PHP_FLOAT_MAX );
            if ( $cost >= $min && $cost < $max ) return 'tier_' . $i . '_wildcard';
        }
    }

    return 'flat';
}

/**
 * Applies rounding to a price.
 */
function gh_apply_price_rounding( float $price, string $mode ): float {
    return match ( $mode ) {
        'ceil'  => (float) ceil( $price ),
        'floor' => (float) floor( $price ),
        'half'  => ceil( $price * 2 ) / 2,
        'none'  => round( $price, 2 ),
        default => (float) ceil( $price ),
    };
}

// ── Rules CRUD ───────────────────────────────────────────

/**
 * Gets configured pricing rules.
 *
 * @return array { enabled, flat_margin, tiers[], floor_price, rounding }
 */
function gh_get_price_rules(): array {
    $rules = get_option( GH_PRICE_RULES_KEY, [] );
    return is_array( $rules ) ? $rules : [];
}

/**
 * Saves pricing rules.
 *
 * @param array $rules
 */
function gh_save_price_rules( array $rules ): void {
    $clean = [
        'enabled'     => ! empty( $rules['enabled'] ),
        'flat_margin' => (float) ( $rules['flat_margin'] ?? 3.5 ),
        'floor_price' => (float) ( $rules['floor_price'] ?? 0 ),
        'rounding'    => sanitize_key( $rules['rounding'] ?? 'ceil' ),
        'tiers'       => [],
    ];

    foreach ( $rules['tiers'] ?? [] as $tier ) {
        $clean['tiers'][] = [
            'brand'    => sanitize_text_field( $tier['brand'] ?? '*' ),
            'min_cost' => (float) ( $tier['min_cost'] ?? 0 ),
            'max_cost' => (float) ( $tier['max_cost'] ?? 999999 ),
            'margin'   => (float) ( $tier['margin'] ?? $clean['flat_margin'] ),
            'floor'    => (float) ( $tier['floor'] ?? 0 ),
        ];
    }

    update_option( GH_PRICE_RULES_KEY, $clean, false );
}
