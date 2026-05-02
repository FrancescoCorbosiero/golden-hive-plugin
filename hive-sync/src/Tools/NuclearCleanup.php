<?php
declare(strict_types=1);

namespace HiveSync\Tools;

use HiveSync\Media\Whitelist;

/**
 * Nuclear Cleanup — selective bulk deletion of products, media, taxonomy,
 * transients, and orphan meta. Honors the media whitelist (HiveSync\Media
 * \Whitelist) and the source-aware "by feed" deletion.
 *
 * Ported from golden-hive's tools/nuclear-cleanup.php (gh_nuclear_*) —
 * same SQL strategy (TRUNCATE/DELETE direct, no wp_delete_post loop)
 * for the speed needed on 2000+ products / 17k+ media stores.
 *
 * SAFETY: this is a DESTRUCTIVE class. Every public method that removes
 * data starts with a preview pass; the destructive method requires an
 * explicit `confirm` flag and `manage_options` capability at the AJAX
 * boundary so accidental clicks never reach here.
 */
final class NuclearCleanup
{
    /** @var array<int, string> Targets the user can opt into. */
    public const TARGETS = ['products', 'media', 'transients', 'taxonomy', 'orphan_meta'];

    /**
     * Dry-run: counts what would be deleted for each opted-in target.
     *
     * @param array<string, bool> $targets
     * @return array<string, array<string, mixed>>
     */
    public static function preview(array $targets): array
    {
        global $wpdb;
        $out = [];

        if (! empty($targets['products'])) {
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')",
            );
            $out['products'] = ['count' => $count, 'label' => 'Prodotti + varianti'];
        }

        if (! empty($targets['media'])) {
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'",
            );
            $wlIds     = self::whitelistedIds();
            $protected = count($wlIds);
            $toDelete  = max(0, $total - $protected);
            $out['media'] = [
                'count'     => $toDelete,
                'protected' => $protected,
                'total'     => $total,
                'label'     => "Immagini ({$toDelete} da eliminare, {$protected} protette)",
            ];
        }

        if (! empty($targets['transients'])) {
            $wp = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_%'
                    OR option_name LIKE '_site_transient_%'",
            );
            $wc = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE 'wc_%_transient_%'
                    OR option_name LIKE '_wc_%'",
            );
            $out['transients'] = [
                'count' => $wp + $wc,
                'wp'    => $wp,
                'wc'    => $wc,
                'label' => "Transients ({$wp} WP + {$wc} WC)",
            ];
        }

        if (! empty($targets['taxonomy'])) {
            $cats   = (int) wp_count_terms(['taxonomy' => 'product_cat',   'hide_empty' => false]);
            $brands = taxonomy_exists('product_brand')
                ? (int) wp_count_terms(['taxonomy' => 'product_brand', 'hide_empty' => false])
                : 0;
            $tags  = (int) wp_count_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
            $attrs = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_attribute_taxonomies",
            );
            $out['taxonomy'] = [
                'count'  => $cats + $brands + $tags,
                'cats'   => $cats,
                'brands' => $brands,
                'tags'   => $tags,
                'attrs'  => $attrs,
                'label'  => "Tassonomie ({$cats} cat, {$brands} brand, {$tags} tag, {$attrs} attr)",
            ];
        }

        if (! empty($targets['orphan_meta'])) {
            $orphan = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.ID IS NULL",
            );
            $sessions = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_wc_session_%'",
            );
            $out['orphan_meta'] = [
                'count'           => $orphan + $sessions,
                'orphan_postmeta' => $orphan,
                'wc_sessions'     => $sessions,
                'label'           => "Orfani ({$orphan} postmeta, {$sessions} sessioni WC)",
            ];
        }

        return $out;
    }

    /**
     * Executes the cleanup for all opted-in targets. Direct SQL where
     * possible; only Media uses wp_delete_attachment to ensure files
     * leave the disk too.
     *
     * @param array<string, bool> $targets
     * @return array<string, mixed>
     */
    public static function execute(array $targets): array
    {
        global $wpdb;
        $results = [];

        if (! empty($targets['products'])) {
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')",
            );

            $wpdb->query(
                "DELETE tr FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                 WHERE p.post_type IN ('product', 'product_variation')",
            );
            $wpdb->query(
                "DELETE pm FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.post_type IN ('product', 'product_variation')",
            );
            $wpdb->query(
                "DELETE c FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID
                 WHERE p.post_type = 'product'",
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')",
            );
            // WC product lookup is rebuilt on next product save.
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wc_product_meta_lookup");

            $results['products'] = $count;
        }

        if (! empty($targets['media'])) {
            $wlIds = self::whitelistedIds();
            $whereNotWl = '';
            if (! empty($wlIds)) {
                $placeholders = implode(',', array_fill(0, count($wlIds), '%d'));
                $whereNotWl = $wpdb->prepare(" AND ID NOT IN ($placeholders)", ...$wlIds);
            }

            // Suppress hooks for speed — none of the usual indices are
            // useful when we're nuking the whole library.
            remove_all_actions('delete_attachment');
            remove_all_actions('wp_delete_file');

            $deleted = 0;
            while (true) {
                $ids = $wpdb->get_col(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
                    . $whereNotWl . " LIMIT 500",
                );
                if (empty($ids)) break;
                foreach ($ids as $aid) {
                    wp_delete_attachment((int) $aid, true);
                    $deleted++;
                }
            }

            $results['media'] = [
                'deleted'   => $deleted,
                'protected' => count($wlIds),
            ];
        }

        if (! empty($targets['transients'])) {
            $wp = (int) $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_%'
                    OR option_name LIKE '_site_transient_%'",
            );
            $wc = (int) $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE 'wc_%_transient_%'
                    OR option_name LIKE '_wc_%'",
            );
            wp_cache_flush();
            $results['transients'] = $wp + $wc;
        }

        if (! empty($targets['taxonomy'])) {
            $taxDeleted = 0;
            foreach (['product_cat', 'product_brand', 'product_tag'] as $taxonomy) {
                if (! taxonomy_exists($taxonomy)) continue;

                $termIds = $wpdb->get_col($wpdb->prepare(
                    "SELECT t.term_id FROM {$wpdb->terms} t
                     INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s",
                    $taxonomy,
                ));
                if (empty($termIds)) continue;

                $idList = implode(',', array_map('intval', $termIds));
                $wpdb->query(
                    "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (
                        SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = '{$taxonomy}'
                    )",
                );
                $wpdb->query("DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy = '{$taxonomy}'");
                $wpdb->query("DELETE FROM {$wpdb->terms}    WHERE term_id IN ({$idList})");
                $wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$idList})");

                $taxDeleted += count($termIds);
            }
            $results['taxonomy'] = $taxDeleted;
        }

        if (! empty($targets['orphan_meta'])) {
            $delMeta = (int) $wpdb->query(
                "DELETE pm FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.ID IS NULL",
            );
            $delSessions = (int) $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_wc_session_%'",
            );
            $results['orphan_meta'] = ['postmeta' => $delMeta, 'sessions' => $delSessions];
        }

        wp_cache_flush();
        return $results;
    }

    /**
     * Source-scoped product delete — finds products tagged with one of
     * the canonical Hive Sync sources via either the legacy meta
     * `_gh_import_source` or the unified `_gh_field_sources.catalog`,
     * then bulk-deletes parents + variations via direct SQL.
     */
    public static function countBySource(string $source): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'product'
             AND (
                 (pm.meta_key = '_gh_import_source' AND pm.meta_value = %s)
              OR (pm.meta_key = '_feed_source'      AND pm.meta_value = %s)
             )",
            $source, $source,
        ));
    }

    /**
     * @return array{deleted:int, variations:int}
     */
    public static function deleteBySource(string $source): array
    {
        global $wpdb;

        $parentIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'product'
             AND (
                 (pm.meta_key = '_gh_import_source' AND pm.meta_value = %s)
              OR (pm.meta_key = '_feed_source'      AND pm.meta_value = %s)
             )",
            $source, $source,
        ));

        if (empty($parentIds)) return ['deleted' => 0, 'variations' => 0];

        $idsCsv = implode(',', array_map('intval', $parentIds));
        $varIds = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product_variation' AND post_parent IN ({$idsCsv})",
        );
        $varCount = count($varIds);
        $allCsv   = implode(',', array_map('intval', array_merge($parentIds, $varIds)));

        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$allCsv})");
        $wpdb->query("DELETE FROM {$wpdb->postmeta}           WHERE post_id IN ({$allCsv})");
        $wpdb->query("DELETE FROM {$wpdb->comments}           WHERE comment_post_ID IN ({$idsCsv})");
        $wpdb->query("DELETE FROM {$wpdb->posts}              WHERE ID IN ({$allCsv})");
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ({$allCsv})",
        );

        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients();
        wp_cache_flush();

        return ['deleted' => count($parentIds), 'variations' => $varCount];
    }

    /** @return int[] */
    private static function whitelistedIds(): array
    {
        $ids = [];
        foreach (Whitelist::all() as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        return $ids;
    }
}
