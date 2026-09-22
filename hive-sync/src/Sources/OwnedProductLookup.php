<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Batched "which products in Woo belong to this feed?" resolver.
 *
 * The bucket diff is built entirely from what the feed RETURNS — it can
 * answer "is this SKU already in Woo?" but never the mirror question,
 * "which products in Woo did this feed stop returning?". Answering that
 * needs a catalog-side query, and this is it: one SQL round-trip that
 * hands back every product the source owns, keyed by id, with the three
 * facts MissingSweeper needs to decide (sku, post_status, retire state).
 *
 * ─── Ownership ──────────────────────────────────────────────────────
 *
 * A product belongs to a source when its legacy provenance meta says so
 * (`_gh_import_source`, or the older `_feed_source`) — the same pair
 * NuclearCleanup::countBySource scopes on, written by the create path of
 * every feed module (rp_rc_gs_create_product, gh_sf_create_product, the
 * gs-label tool).
 *
 * ...AND the conflict engine's tiebreaker doesn't contradict it. A
 * product created by KicksDB and later price-synced by GS goes through
 * the shared bridge, which stamps `_gh_import_source = goldensneakers`,
 * but `_gh_primary_source` stays `kicksdb` (record_source only sets the
 * primary when it's empty). Without the second clause a GS sync would
 * consider KicksDB's catalog its own and retire it the moment GS stops
 * listing that SKU. Products predating the conflict engine have no
 * primary at all — those stay in scope, which is exactly right: the
 * legacy meta is the only provenance they have.
 *
 * ─── What is deliberately NOT here ──────────────────────────────────
 *
 * Products with no `_sku` are skipped by the INNER JOIN. Without a SKU
 * there is nothing to compare against the feed, so "missing" is not a
 * statement we can make about them — and a retire we can't justify is a
 * retire we must not perform.
 */
final class OwnedProductLookup
{
    /** Post statuses worth considering. `trash` / `auto-draft` are out. */
    private const STATUSES = [ 'publish', 'private', 'draft', 'pending', 'future' ];

    /**
     * Every product owned by $provenanceKey, keyed by product id.
     *
     * `retired_since` / `retired_mode` come from the markers
     * ProductRetirer writes, so the sweeper can tell "already hidden,
     * leave it alone" from "hidden under a mode the operator has since
     * changed" from "back in the feed, restore it".
     *
     * @return array<int, array{sku: string, status: string, retired_since: string, retired_mode: string}>
     */
    public static function forProvenance( string $provenanceKey ): array
    {
        global $wpdb;
        if ( $provenanceKey === '' ) return [];
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) return [];

        $statuses = implode(
            ',',
            array_map( static fn( string $st ): string => "'" . $st . "'", self::STATUSES )
        );

        $since = \HiveSync\Workflow\Run\ProductRetirer::MISSING_SINCE;
        $mode  = \HiveSync\Workflow\Run\ProductRetirer::MISSING_MODE;

        // Provenance is checked with EXISTS rather than joined.
        //
        // A join on `meta_key IN ('_gh_import_source', '_feed_source')`
        // matches BOTH keys when both are set, duplicating the product
        // row — the same trap documented for the MissingImages scan. The
        // obvious fix (GROUP BY p.ID) trades it for a worse one: under
        // MySQL 8's default ONLY_FULL_GROUP_BY, selecting a non-
        // aggregated column from a JOINED table (sku.meta_value) next to
        // a GROUP BY is a hard error, so the query would work on the dev
        // box and fatal on the customer's host. EXISTS is a membership
        // test — it can't duplicate and it can't be grouped wrong.
        //
        // NOT EXISTS carries the conflict-engine tiebreaker: a product
        // whose `_gh_primary_source` names SOMEONE ELSE is out of scope,
        // even though the shared bridge stamped our key on it. An empty
        // or absent primary means "predates the conflict engine", which
        // leaves the legacy meta as the only provenance there is — those
        // stay in scope.
        $sql = "SELECT p.ID AS pid,
                       sku.meta_value AS sku,
                       p.post_status  AS status,
                       COALESCE(rs.meta_value, '') AS retired_since,
                       COALESCE(rm.meta_value, '') AS retired_mode
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} sku
                        ON sku.post_id = p.ID
                       AND sku.meta_key = '_sku'
                       AND sku.meta_value <> ''
                LEFT JOIN {$wpdb->postmeta} rs
                       ON rs.post_id = p.ID AND rs.meta_key = %s
                LEFT JOIN {$wpdb->postmeta} rm
                       ON rm.post_id = p.ID AND rm.meta_key = %s
                WHERE p.post_type = 'product'
                  AND p.post_status IN ({$statuses})
                  AND EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} src
                        WHERE src.post_id = p.ID
                          AND src.meta_key IN ('_gh_import_source', '_feed_source')
                          AND src.meta_value = %s
                      )
                  AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} prim
                        WHERE prim.post_id = p.ID
                          AND prim.meta_key = '_gh_primary_source'
                          AND prim.meta_value <> ''
                          AND prim.meta_value <> %s
                      )";

        $rows = $wpdb->get_results(
            $wpdb->prepare( $sql, $since, $mode, $provenanceKey, $provenanceKey ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( $row['pid'] ?? 0 );
            $sku = (string) ( $row['sku'] ?? '' );
            if ( $pid <= 0 || $sku === '' ) continue;
            $out[ $pid ] = [
                'sku'           => $sku,
                'status'        => (string) ( $row['status'] ?? '' ),
                'retired_since' => (string) ( $row['retired_since'] ?? '' ),
                'retired_mode'  => (string) ( $row['retired_mode'] ?? '' ),
            ];
        }
        return $out;
    }
}
