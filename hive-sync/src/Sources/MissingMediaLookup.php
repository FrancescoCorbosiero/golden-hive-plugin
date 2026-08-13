<?php
declare(strict_types=1);

namespace HiveSync\Sources;

/**
 * Batched "which of these products have no usable featured image?"
 * lookup. One indexed query per 500 ids — the alternative
 * (wc_get_product() + get_image_id() per item) is a hydration per
 * product, unaffordable across a settled catalog's `unchanged` bucket
 * on every run.
 *
 * Why this exists: the bucket diff is media-blind by construction.
 * StockOnlyClassifier compares name / description / status / price /
 * stock; CsvSource::sfProductNeedsUpdate compares price + stock. A
 * product whose image sideload failed (upstream sent a broken URL,
 * the host rejected it, the attachment was later deleted) looks
 * IDENTICAL to the feed forever after, so it lands in `unchanged` —
 * or in `updateStock`, whose fast-patch path never calls materialize.
 * Either way the bridge's heal branch (rp_rc_gs_image_update_action:
 * "no featured image → attach from feed") is unreachable through a
 * normal re-sync. This lookup is the missing signal that lets
 * MediaHealer pull exactly the broken products back into the full
 * import path.
 *
 * "No usable featured image" means any of:
 *   - no `_thumbnail_id` meta row at all
 *   - the meta is empty or 0
 *   - the meta points at a post that no longer exists, or is no
 *     longer an attachment (Safe Cleanup / manual deletion left a
 *     dangling id — the product renders a placeholder just the same)
 *
 * Gallery images are deliberately NOT considered: the featured image
 * is what the catalog grid renders and what the bridge heals. A
 * product with a featured image but a short gallery is not "broken".
 */
final class MissingMediaLookup
{
    /**
     * @param int[] $productIds
     * @return int[] the subset of $productIds with no usable featured image
     */
    public static function withoutFeaturedImage(array $productIds): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) return [];

        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn($v): bool => $v > 0,
        )));
        if (! $productIds) return [];

        $out = [];
        foreach (array_chunk($productIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            // LEFT JOIN twice: once to read _thumbnail_id, once to prove
            // the id it carries still resolves to a live attachment. The
            // second join is what catches "image was deleted from the
            // media library" — a dangling _thumbnail_id reads as present
            // to any meta-only check while the storefront shows nothing.
            $sql = "
                SELECT p.ID AS pid
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm
                       ON pm.post_id = p.ID
                      AND pm.meta_key = '_thumbnail_id'
                LEFT JOIN {$wpdb->posts} att
                       ON att.ID = CAST(pm.meta_value AS UNSIGNED)
                      AND att.post_type = 'attachment'
                WHERE p.ID IN ($placeholders)
                  AND (
                        pm.meta_value IS NULL
                     OR pm.meta_value = ''
                     OR CAST(pm.meta_value AS UNSIGNED) = 0
                     OR att.ID IS NULL
                  )
            ";
            $rows = $wpdb->get_col($wpdb->prepare($sql, $chunk));
            if (! is_array($rows)) continue;
            foreach ($rows as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) $out[] = $pid;
            }
        }

        return array_values(array_unique($out));
    }
}
