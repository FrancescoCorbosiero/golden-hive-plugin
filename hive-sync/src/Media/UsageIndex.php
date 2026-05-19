<?php
declare(strict_types=1);

namespace HiveSync\Media;

/**
 * Reverse media-usage index: attachment_id => [{pid, role}].
 *
 * Roles: featured | variation | post_featured | gallery | content.
 * Cached as a transient (10 minutes); auto-invalidated on add/delete
 * attachment + product save.
 *
 * Ported from golden-hive's media/browser.php (gh_media_*) and
 * media/scanner.php — single SQL query per source, no WP_Post hydration,
 * URL→id map built once for inline content scanning.
 */
final class UsageIndex
{
    public const TRANSIENT_KEY = 'hsync_media_usage_index_v1';
    public const TRANSIENT_TTL = 600; // 10 minutes

    public static function registerInvalidationHooks(): void
    {
        add_action('add_attachment',           [self::class, 'invalidate']);
        add_action('delete_attachment',        [self::class, 'invalidate']);
        add_action('save_post_product',        [self::class, 'invalidate']);
        add_action('woocommerce_update_product', [self::class, 'invalidate']);
    }

    public static function invalidate(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * @return array<int, array<int, array{pid:int, role:string}>>
     */
    public static function build(bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) return $cached;
        }

        global $wpdb;
        $index = [];

        // 1. Featured / variation thumbnails / post-page featured.
        $rows = $wpdb->get_results("
            SELECT p.ID AS parent_id, p.post_type, pm.meta_value AS thumb_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
              AND pm.meta_value != ''
              AND p.post_type IN ('product','product_variation','post','page')
              AND p.post_status NOT IN ('trash','auto-draft')
        ");
        foreach ($rows ?: [] as $row) {
            $tid = (int) $row->thumb_id;
            if ($tid <= 0) continue;
            $role = match ($row->post_type) {
                'product'           => 'featured',
                'product_variation' => 'variation',
                'post', 'page'      => 'post_featured',
                default             => 'other',
            };
            $index[$tid][] = ['pid' => (int) $row->parent_id, 'role' => $role];
        }

        // 2. Woo gallery — _product_image_gallery is a CSV of attachment IDs.
        $galleryRows = $wpdb->get_results("
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_image_gallery'
              AND meta_value != ''
        ");
        foreach ($galleryRows ?: [] as $row) {
            $pid = (int) $row->post_id;
            foreach (explode(',', (string) $row->meta_value) as $idStr) {
                $aid = (int) trim($idStr);
                if ($aid > 0) {
                    $index[$aid][] = ['pid' => $pid, 'role' => 'gallery'];
                }
            }
        }

        // 3. Inline <img src> / <a href> in post content / excerpt.
        self::scanInlineContentInto($index);

        // 4. Preimport-pending orphans — attachments downloaded by a
        // media-only pre-stage that haven't been claimed by a product
        // yet. Without this, Safe Cleanup would mark them as unmapped
        // and delete exactly what a follow-up products-pass needs.
        // The marker (_gh_preimport_pending=1) is cleared by the
        // attach path the moment the attachment gets a product role,
        // so this set self-empties as imports complete. Role 'preimport_pending'
        // surfaces in the Media Library so the operator can see the
        // pending pool at a glance.
        $pendingRows = $wpdb->get_col("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_gh_preimport_pending' AND meta_value = '1'
        ");
        foreach ($pendingRows ?: [] as $idStr) {
            $aid = (int) $idStr;
            if ($aid > 0) {
                $index[$aid][] = ['pid' => 0, 'role' => 'preimport_pending'];
            }
        }

        set_transient(self::TRANSIENT_KEY, $index, self::TRANSIENT_TTL);
        return $index;
    }

    /**
     * Scans every product/post/page content for image references and adds
     * 'content' role entries to the index. The URL→id map is built once
     * from the attachment guid column instead of N attachment_url_to_postid()
     * calls, which would be N+1.
     *
     * @param array<int, array<int, array{pid:int, role:string}>> $index
     */
    private static function scanInlineContentInto(array &$index): void
    {
        global $wpdb;

        $contentRows = $wpdb->get_results("
            SELECT ID, CONCAT_WS(' ', post_content, post_excerpt) AS blob
            FROM {$wpdb->posts}
            WHERE post_type IN ('product','post','page')
              AND post_status NOT IN ('trash','auto-draft')
              AND (post_content LIKE '%src=%' OR post_content LIKE '%href=%'
                   OR post_excerpt LIKE '%src=%' OR post_excerpt LIKE '%href=%')
        ");
        if (empty($contentRows)) return;

        $guidRows = $wpdb->get_results("
            SELECT ID, guid
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
              AND post_status = 'inherit'
              AND post_mime_type LIKE 'image/%'
        ");
        $guidToId = [];
        foreach ($guidRows ?: [] as $r) {
            $guidToId[$r->guid] = (int) $r->ID;
        }

        foreach ($contentRows as $cr) {
            $pid = (int) $cr->ID;
            if (preg_match_all(
                '/(?:src|href)=["\']([^"\']+?\.(?:jpe?g|png|gif|webp|svg))["\']?/i',
                (string) $cr->blob,
                $matches,
            )) {
                $seen = [];
                foreach ($matches[1] as $url) {
                    $aid = $guidToId[$url] ?? null;
                    if (! $aid) {
                        // WP appends size suffix on rendered URLs. Strip
                        // it and retry against the original guid.
                        $clean = preg_replace('/-\d+x\d+(?=\.[a-z]+$)/i', '', $url);
                        if ($clean !== $url && isset($guidToId[$clean])) {
                            $aid = $guidToId[$clean];
                        }
                    }
                    if ($aid && ! isset($seen[$aid])) {
                        $index[$aid][] = ['pid' => $pid, 'role' => 'content'];
                        $seen[$aid] = true;
                    }
                }
            }
        }
    }
}
