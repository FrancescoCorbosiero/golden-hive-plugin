<?php
/**
 * Host adapter — versioned extension contract between Hive Sync and the
 * Golden Hive host plugin. The host (or any third party) binds the filters
 * below to provide richer behavior; Hive Sync falls back to internal stubs
 * when nothing is bound.
 *
 * Stable surface — bump HSYNC_HOST_CONTRACT_VERSION on breaking changes.
 *
 *   filter  hive_sync/host/taxonomy/resolve   ($term_id_or_null, $taxonomy, $name, $context)
 *   filter  hive_sync/host/media/preimport    ($attachment_id_or_null, $url, $context)
 *   filter  hive_sync/host/product/upsert     ($product_id_or_null, $product_data, $context)
 *   action  hive_sync/host/conflict/record    ($product_id, $source, $field_changes, $context)
 *   filter  hive_sync/host/conflict/resolve   ($result_or_null, $product_id, $incoming, $source_id)
 *   filter  hive_sync/host/selection/resolve  ($ids_or_null, $selection)
 *
 * Phase 1 wires only the hook names + thin helper functions. Internal
 * fallbacks throw NotImplemented so missing wiring fails loud, not silent.
 */

defined( 'ABSPATH' ) || exit;

const HSYNC_HOST_CONTRACT_VERSION = 1;

/**
 * Resolve an external taxonomy name to a Woo term_id.
 *
 * @param string $taxonomy WP taxonomy slug (e.g. 'product_cat', 'product_brand').
 * @param string $name     External name to resolve.
 * @param array  $context  Caller-provided hints (mapping_id, source_kind, etc.).
 * @return int|null term_id, or null when unresolved.
 */
function hsync_resolve_taxonomy( string $taxonomy, string $name, array $context = [] ): ?int {
    $resolved = apply_filters( 'hive_sync/host/taxonomy/resolve', null, $taxonomy, $name, $context );
    if ( is_int( $resolved ) && $resolved > 0 ) return $resolved;
    return null;
}

/**
 * Download / dedupe an external media URL into the WP media library.
 *
 * @return int|null attachment_id, or null on failure.
 */
function hsync_preimport_media( string $url, array $context = [] ): ?int {
    $resolved = apply_filters( 'hive_sync/host/media/preimport', null, $url, $context );
    if ( is_int( $resolved ) && $resolved > 0 ) return $resolved;
    return null;
}

/**
 * Create or update a Woo product. $product_data shape is contract-stable; see
 * docs once phase 2 lands. Returns the resulting product_id, or null on no-op.
 */
function hsync_upsert_product( array $product_data, array $context = [] ): ?int {
    $resolved = apply_filters( 'hive_sync/host/product/upsert', null, $product_data, $context );
    if ( is_int( $resolved ) && $resolved > 0 ) return $resolved;
    return null;
}

/**
 * Record provenance / conflict information for a product write. Fire-and-forget.
 */
function hsync_record_conflict( int $product_id, string $source, array $field_changes, array $context = [] ): void {
    do_action( 'hive_sync/host/conflict/record', $product_id, $source, $field_changes, $context );
}

/**
 * Ask the conflict engine which slices the incoming write may touch.
 * Default-allow when the host filter is unbound (preserves legacy
 * behavior of plain Woo writes when no conflict module is present).
 *
 * @return array{allowed_slices: array<string,bool>, blocked: array<string,string>, applied_rule: ?string}
 */
function hsync_resolve_conflict( int $product_id, array $incoming, string $source_id ): array {
    $resolved = apply_filters( 'hive_sync/host/conflict/resolve', null, $product_id, $incoming, $source_id );
    if ( is_array( $resolved ) && isset( $resolved['allowed_slices'] ) ) {
        return [
            'allowed_slices' => (array) $resolved['allowed_slices'],
            'blocked'        => (array) ( $resolved['blocked'] ?? [] ),
            'applied_rule'   => isset( $resolved['applied_rule'] ) ? (string) $resolved['applied_rule'] : null,
        ];
    }
    return [
        'allowed_slices' => [ 'catalog' => true, 'pricing' => true, 'stock' => true, 'media' => true ],
        'blocked'        => [],
        'applied_rule'   => null,
    ];
}
