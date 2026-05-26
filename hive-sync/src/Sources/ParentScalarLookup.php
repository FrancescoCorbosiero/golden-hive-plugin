<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Batched lookup for the scalar + price/stock snapshot of parent
 * products. Two SQL queries replace N×wc_get_product() hydrations,
 * letting StockOnlyClassifier::classify() run in constant time per
 * item across the whole update bucket — the difference between
 * classifying ~3k items in a 25s tick budget and classifying tens
 * of thousands.
 *
 * Why this exists: the 3-way diff classifier (unchanged / stock /
 * full) used to wc_get_product() each parent to compare name /
 * description / status / price / stock. That made classifying
 * thousands of items unaffordable, so a 500-item circuit breaker
 * short-circuited any larger update bucket to all-full. A re-sync
 * of a catalog with >500 existing SKUs lost every optimization:
 * unchanged → updateFull and stock-only → updateFull, both running
 * the full pipeline. Pre-loading the scalars in two queries removes
 * that ceiling.
 *
 * Shape returned by load():
 *   parent_id => [
 *     'name'              => post_title,
 *     'description'       => post_content,
 *     'short_description' => post_excerpt,
 *     'status'            => post_status,
 *     'sku'               => _sku meta,
 *     'regular_price'     => _regular_price meta (only meaningful for simple),
 *     'sale_price'        => _sale_price meta,
 *     'stock'             => _stock meta as ?int,
 *     'stock_status'      => _stock_status meta,
 *     'is_variable'       => product_type term slug === 'variable',
 *   ]
 */
final class ParentScalarLookup
{
    /**
     * @param int[] $parentIds
     * @return array<int, array{name:string,description:string,short_description:string,status:string,sku:string,regular_price:string,sale_price:string,stock:?int,stock_status:string,is_variable:bool}>
     */
    public static function load(array $parentIds): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) return [];

        $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), fn($v) => $v > 0)));
        if (! $parentIds) return [];

        $out = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            // Single query per chunk: parents ⨝ postmeta(sku, regular,
            // sale, stock, stock_status). post_title / content / excerpt
            // / status come straight off wp_posts. LEFT JOINs so a
            // missing meta (e.g. variable parent has no _regular_price)
            // returns NULL instead of dropping the row.
            $sql = "
                SELECT
                    p.ID                            AS pid,
                    p.post_title                    AS name,
                    p.post_content                  AS description,
                    p.post_excerpt                  AS short_description,
                    p.post_status                   AS status,
                    pm_sku.meta_value               AS sku,
                    pm_reg.meta_value               AS regular_price,
                    pm_sale.meta_value              AS sale_price,
                    pm_stock.meta_value             AS stock,
                    pm_stock_status.meta_value      AS stock_status
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_sku
                       ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_reg
                       ON pm_reg.post_id = p.ID AND pm_reg.meta_key = '_regular_price'
                LEFT JOIN {$wpdb->postmeta} pm_sale
                       ON pm_sale.post_id = p.ID AND pm_sale.meta_key = '_sale_price'
                LEFT JOIN {$wpdb->postmeta} pm_stock
                       ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock'
                LEFT JOIN {$wpdb->postmeta} pm_stock_status
                       ON pm_stock_status.post_id = p.ID AND pm_stock_status.meta_key = '_stock_status'
                WHERE p.ID IN ($placeholders)
            ";
            $prepared = $wpdb->prepare($sql, $chunk);
            $rows = $wpdb->get_results($prepared, ARRAY_A);
            if (! is_array($rows)) continue;
            foreach ($rows as $row) {
                $pid = (int) ($row['pid'] ?? 0);
                if ($pid <= 0) continue;
                $out[$pid] = [
                    'name'              => (string) ($row['name']              ?? ''),
                    'description'       => (string) ($row['description']       ?? ''),
                    'short_description' => (string) ($row['short_description'] ?? ''),
                    'status'            => (string) ($row['status']            ?? ''),
                    'sku'               => (string) ($row['sku']               ?? ''),
                    'regular_price'     => (string) ($row['regular_price']     ?? ''),
                    'sale_price'        => (string) ($row['sale_price']        ?? ''),
                    'stock'             => $row['stock'] === null ? null : (int) $row['stock'],
                    'stock_status'      => (string) ($row['stock_status']      ?? ''),
                    'is_variable'       => false,  // filled in by the product_type pass
                ];
            }
        }

        // Second pass: product_type. WooCommerce stores the type as
        // a term in the `product_type` taxonomy ('simple', 'variable',
        // 'grouped', 'external'). We only care whether it's variable,
        // so query only those ids — the rest stay is_variable=false.
        $variableIds = self::loadVariableIds(array_keys($out));
        foreach ($variableIds as $pid) {
            if (isset($out[$pid])) $out[$pid]['is_variable'] = true;
        }

        return $out;
    }

    /**
     * @param int[] $parentIds
     * @return int[] ids whose product_type term slug is 'variable'
     */
    private static function loadVariableIds(array $parentIds): array
    {
        global $wpdb;
        if (! $parentIds) return [];

        $out = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $sql = "SELECT tr.object_id AS pid
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t           ON t.term_id          = tt.term_id
                    WHERE tt.taxonomy = 'product_type'
                      AND t.slug      = 'variable'
                      AND tr.object_id IN ($placeholders)";
            $prepared = $wpdb->prepare($sql, $chunk);
            $rows     = $wpdb->get_col($prepared);
            if (! is_array($rows)) continue;
            foreach ($rows as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) $out[] = $pid;
            }
        }
        return $out;
    }
}
