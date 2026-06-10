<?php
/**
 * Host adapter — versioned extension contract between Hive Sync and the
 * Hive Commerce host plugin. The host (or any third party) binds the filters
 * below to provide richer behavior; Hive Sync falls back to internal stubs
 * when nothing is bound.
 *
 * Stable surface — bump HSYNC_HOST_CONTRACT_VERSION on breaking changes.
 *
 *   filter  hive_sync/host/taxonomy/resolve   ($term_id_or_null, $taxonomy, $name, $context)
 *   filter  hive_sync/host/media/preimport    ($attachment_id_or_null, $url, $context)
 *   filter  hive_sync/host/media/preimport_batch  ($map_or_null, $urls, $context)
 *   filter  hive_sync/host/product/upsert     ($product_id_or_null, $product_data, $context)
 *   action  hive_sync/host/conflict/record    ($product_id, $source, $field_changes, $context)
 *   filter  hive_sync/host/conflict/resolve   ($result_or_null, $product_id, $incoming, $source_id)
 *   filter  hive_sync/host/selection/resolve  ($ids_or_null, $selection)
 *
 * Source-specific delegation (legacy adapter pattern — phase 5 absorbs):
 *   filter  hive_sync/host/source/gs/fetch        ($resp_or_null, $config, $options)
 *   filter  hive_sync/host/source/gs/diff         ($resp_or_null, $items_data)
 *   filter  hive_sync/host/source/gs/materialize  ($resp_or_null, $item_data, $dry_run, $sideload)
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
 * Batch variant — download N URLs in parallel (curl_multi sliding
 * window). Returns map url => attachment_id; URLs that failed are
 * absent from the result.
 *
 * Falls back to sequential single-URL calls when the host filter is
 * unbound, so the plugin keeps working (slowly) without Hive Commerce.
 *
 * @param string[] $urls
 * @return array<string, int>
 */
function hsync_preimport_media_batch( array $urls, array $context = [] ): array {
    $urls = array_values( array_unique( array_filter( $urls, 'is_string' ) ) );
    if ( ! $urls ) return [];

    $resolved = apply_filters( 'hive_sync/host/media/preimport_batch', null, $urls, $context );
    if ( is_array( $resolved ) ) {
        $out = [];
        foreach ( $resolved as $url => $id ) {
            if ( is_string( $url ) && is_int( $id ) && $id > 0 ) $out[ $url ] = $id;
        }
        return $out;
    }

    // Sequential fallback.
    $out = [];
    foreach ( $urls as $url ) {
        $id = hsync_preimport_media( $url, $context );
        if ( $id !== null ) $out[ $url ] = $id;
    }
    return $out;
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

/**
 * Delegating wrappers for the Golden Sneakers source. The legacy GS
 * module (rp_rc_gs_*) stays the source of truth until phase 5 — these
 * helpers route Hive Sync's GoldenSneakersSource through host filters
 * so Hive Sync never imports the legacy module directly.
 *
 * Each helper returns null when the host is unavailable. Concrete
 * sources translate null into a graceful warning.
 *
 * @return array{items: array, stats?: array, warnings?: array}|null
 */
function hsync_gs_fetch( array $config, array $options = [] ): ?array {
    $resp = apply_filters( 'hive_sync/host/source/gs/fetch', null, $config, $options );
    return is_array( $resp ) ? $resp : null;
}

/**
 * @param array<int, array> $items_data Raw woo-shaped product arrays.
 * @return array{new: array, update: array, unchanged: array}|null
 */
function hsync_gs_diff( array $items_data ): ?array {
    $resp = apply_filters( 'hive_sync/host/source/gs/diff', null, $items_data );
    return is_array( $resp ) ? $resp : null;
}

/**
 * @return array{action: string, id: int, reason?: string}|null
 */
function hsync_gs_materialize( array $item_data, bool $dry_run = false, bool $sideload = true ): ?array {
    $resp = apply_filters( 'hive_sync/host/source/gs/materialize', null, $item_data, $dry_run, $sideload );
    return is_array( $resp ) ? $resp : null;
}

/**
 * StockFirmati materialize delegation. Mirrors the GS pattern exactly:
 * the legacy bridge wires this filter to gh_sf_create_product /
 * gh_sf_update_product. The bridge owns brand + category + image
 * sideload + variant updates that the generic hsync_upsert_product
 * doesn't currently handle.
 *
 * @return array{action: string, id: int, reason?: string}|null
 */
function hsync_sf_materialize( array $item_data, bool $dry_run = false, bool $sideload = true ): ?array {
    $resp = apply_filters( 'hive_sync/host/source/sf/materialize', null, $item_data, $dry_run, $sideload );
    return is_array( $resp ) ? $resp : null;
}
