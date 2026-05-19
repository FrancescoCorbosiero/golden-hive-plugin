<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Batched lookup for variation meta (sku → regular_price, sale_price,
 * stock_quantity, stock_status). One SQL pivot per chunk of parent IDs
 * replaces N × wc_get_product() hydrations (~10-30ms each) — the
 * difference between a 200ms diff and a 60s diff for a 5k-variation
 * catalog.
 *
 * Why this exists: the 3-way diff classifier (unchanged / stock /
 * full) needs to compare incoming variation stock against current Woo
 * variation stock to decide if a re-import is a no-op. Without this
 * lookup, every variable product re-import would re-write every
 * variation just because we never checked.
 *
 * The query joins postmeta to itself four times (one per metakey we
 * care about). MySQL handles this with a covering index on
 * (post_id, meta_key) — standard WP install.
 */
final class VariationLookup
{
    /**
     * @param int[] $parentIds Parent product IDs to load variations for.
     * @return array<int, array<string, array{regular_price:string, sale_price:string, stock:?int, stock_status:string}>>
     *         parent_id → variation_sku → meta snapshot
     */
    public static function mapParentsToVariations(array $parentIds): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) return [];

        $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), fn($v) => $v > 0)));
        if (! $parentIds) return [];

        $out = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            // Single query per chunk: posts(variations) ⨝ postmeta(sku) ⨝
            // postmeta(regular_price, sale_price, stock, stock_status).
            // LEFT JOINs so missing metas (e.g. no sale price) come
            // back as NULL rather than dropping the variation row.
            $sql = "
                SELECT
                    p.ID                            AS vid,
                    p.post_parent                   AS parent_id,
                    pm_sku.meta_value               AS sku,
                    pm_reg.meta_value               AS regular_price,
                    pm_sale.meta_value              AS sale_price,
                    pm_stock.meta_value             AS stock,
                    pm_status.meta_value            AS stock_status
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_sku
                       ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_reg
                       ON pm_reg.post_id = p.ID AND pm_reg.meta_key = '_regular_price'
                LEFT JOIN {$wpdb->postmeta} pm_sale
                       ON pm_sale.post_id = p.ID AND pm_sale.meta_key = '_sale_price'
                LEFT JOIN {$wpdb->postmeta} pm_stock
                       ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock'
                LEFT JOIN {$wpdb->postmeta} pm_status
                       ON pm_status.post_id = p.ID AND pm_status.meta_key = '_stock_status'
                WHERE p.post_type   = 'product_variation'
                  AND p.post_status NOT IN ('trash','auto-draft')
                  AND p.post_parent IN ($placeholders)
            ";
            $prepared = $wpdb->prepare($sql, $chunk);
            $rows = $wpdb->get_results($prepared, ARRAY_A);
            if (! is_array($rows)) continue;
            foreach ($rows as $row) {
                $pid = (int) ($row['parent_id'] ?? 0);
                $sku = (string) ($row['sku'] ?? '');
                if ($pid <= 0 || $sku === '') continue;
                $out[$pid][$sku] = [
                    'regular_price' => (string) ($row['regular_price'] ?? ''),
                    'sale_price'    => (string) ($row['sale_price']    ?? ''),
                    'stock'         => $row['stock'] === null ? null : (int) $row['stock'],
                    'stock_status'  => (string) ($row['stock_status']  ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Batch-load the term slugs attached to each parent for a given
     * taxonomy (typically `pa_taglia`). This is what Woo's storefront
     * reads to know "which sizes does this product support?". When a
     * variation's `attribute_pa_taglia` meta references a slug that
     * ISN'T in this set, Woo can't link the variation to the parent
     * and renders it as "Qualsiasi Taglia" — invisible on the size
     * dropdown.
     *
     * Single SQL join (relationships → term_taxonomy → terms) per
     * chunk of 500 parents. Standard WP indexes cover it.
     *
     * @param int[] $parentIds
     * @return array<int, array<string, true>> parent_id → set of slugs
     */
    public static function loadParentTaxonomyTermSlugs(array $parentIds, string $taxonomy): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) return [];

        $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), fn($v) => $v > 0)));
        if (! $parentIds || $taxonomy === '') return [];

        $out = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = "SELECT tr.object_id AS parent_id, t.slug AS term_slug
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t           ON t.term_id          = tt.term_id
                    WHERE tt.taxonomy = %s
                      AND tr.object_id IN ($placeholders)";
            $params   = array_merge([ $taxonomy ], $chunk);
            $prepared = $wpdb->prepare($sql, $params);
            $rows     = $wpdb->get_results($prepared, ARRAY_A);
            if (! is_array($rows)) continue;
            foreach ($rows as $row) {
                $pid  = (int) ($row['parent_id'] ?? 0);
                $slug = (string) ($row['term_slug'] ?? '');
                if ($pid <= 0 || $slug === '') continue;
                if (! isset($out[$pid])) $out[$pid] = [];
                $out[$pid][$slug] = true;
            }
        }
        return $out;
    }
}
