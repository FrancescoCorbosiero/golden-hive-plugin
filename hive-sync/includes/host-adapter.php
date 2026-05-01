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
