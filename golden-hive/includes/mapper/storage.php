<?php
/**
 * Mapper Storage — CRUD for mapping rules via wp_options.
 *
 * Rules are stored as a JSON array in a single option: gh_mapper_rules.
 * Each rule has: id, name, description, items_path, source_sample, mappings[], created_at, updated_at.
 */

defined( 'ABSPATH' ) || exit;

/** wp_options key for storing all mapping rules. */
define( 'GH_MAPPER_OPTION_KEY', 'gh_mapper_rules' );

/**
 * Gets all saved mapping rules.
 *
 * @return array[] Array of rule objects.
 */
function gh_mapper_get_rules(): array {
    $rules = get_option( GH_MAPPER_OPTION_KEY, [] );
    return is_array( $rules ) ? $rules : [];
}

/**
 * Gets a single rule by ID.
 *
 * @param string $rule_id Rule identifier.
 * @return array|null The rule or null if not found.
 */
function gh_mapper_get_rule( string $rule_id ): ?array {
    $rules = gh_mapper_get_rules();
    foreach ( $rules as $rule ) {
        if ( ( $rule['id'] ?? '' ) === $rule_id ) {
            return $rule;
        }
    }
    return null;
}

/**
 * Saves (creates or updates) a mapping rule.
 *
 * @param array $rule Rule data. If 'id' is set, updates; otherwise creates.
 * @return array The saved rule with ID.
 */
function gh_mapper_save_rule( array $rule ): array {
    $rules = gh_mapper_get_rules();
    $now   = wp_date( 'c' );

    if ( empty( $rule['id'] ) ) {
        // Create new
        $rule['id']         = 'mr_' . bin2hex( random_bytes( 6 ) );
        $rule['created_at'] = $now;
        $rule['updated_at'] = $now;
        $rules[]            = $rule;
    } else {
        // Update existing
        $found = false;
        foreach ( $rules as $i => $existing ) {
            if ( ( $existing['id'] ?? '' ) === $rule['id'] ) {
                $rule['created_at'] = $existing['created_at'] ?? $now;
                $rule['updated_at'] = $now;
                $rules[ $i ] = $rule;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $rule['created_at'] = $now;
            $rule['updated_at'] = $now;
            $rules[] = $rule;
        }
    }

    update_option( GH_MAPPER_OPTION_KEY, $rules, false );

    return $rule;
}

/**
 * Deletes a mapping rule by ID.
 *
 * @param string $rule_id Rule identifier.
 * @return bool True if deleted, false if not found.
 */
function gh_mapper_delete_rule( string $rule_id ): bool {
    $rules   = gh_mapper_get_rules();
    $initial = count( $rules );

    $rules = array_values( array_filter( $rules, fn( $r ) => ( $r['id'] ?? '' ) !== $rule_id ) );

    if ( count( $rules ) === $initial ) {
        return false;
    }

    update_option( GH_MAPPER_OPTION_KEY, $rules, false );
    return true;
}

/**
 * Duplicates an existing rule with a new name.
 *
 * @param string $rule_id   Source rule ID.
 * @param string $new_name  Name for the duplicate.
 * @return array|null The duplicated rule, or null if source not found.
 */
function gh_mapper_duplicate_rule( string $rule_id, string $new_name = '' ): ?array {
    $source = gh_mapper_get_rule( $rule_id );
    if ( ! $source ) return null;

    $copy = $source;
    $copy['id']   = '';  // Force new ID generation
    $copy['name'] = $new_name ?: $source['name'] . ' (copia)';

    return gh_mapper_save_rule( $copy );
}
