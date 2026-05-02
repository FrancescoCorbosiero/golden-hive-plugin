<?php
declare(strict_types=1);

namespace HiveSync\Media;

/**
 * Paginated media-library query with usage + whitelist filters.
 *
 * - filename: substring LIKE on post_title + guid (DB level)
 * - usage:    'all' | 'mapped' | 'unmapped' (memory level via UsageIndex)
 * - whitelist:'all' | 'yes' | 'no'
 *
 * Hydration is batched (no per-attachment file I/O): filesize / sub-size
 * thumbnail come from the serialized _wp_attachment_metadata meta.
 */
final class Browser
{
    /**
     * @param array{filename?:string,usage?:string,whitelist?:string} $filters
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string} $pagination
     * @return array{items:array, total:int, page:int, per_page:int, total_pages:int}
     */
    public static function query(array $filters = [], array $pagination = []): array
    {
        $page    = max(1, (int) ($pagination['page']     ?? 1));
        $perPage = max(10, min(500, (int) ($pagination['per_page'] ?? 60)));
        $orderby = (string) ($pagination['orderby'] ?? 'date');
        $order   = strtoupper((string) ($pagination['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $allIds = self::filterIdsAtDbLevel($filters, $orderby, $order);

        $usageFilter = (string) ($filters['usage']     ?? 'all');
        $wlFilter    = (string) ($filters['whitelist'] ?? 'all');

        $usageIndex = null;
        $wlIndex    = null;
        if ($usageFilter !== 'all' || $wlFilter !== 'all') {
            $usageIndex = UsageIndex::build();
            $wlIndex    = Whitelist::index();
            $filtered = [];
            foreach ($allIds as $id) {
                $isMapped = isset($usageIndex[$id]);
                $isWl     = isset($wlIndex[$id]);
                if ($usageFilter === 'mapped'   && ! $isMapped) continue;
                if ($usageFilter === 'unmapped' &&   $isMapped) continue;
                if ($wlFilter    === 'yes'      && ! $isWl)     continue;
                if ($wlFilter    === 'no'       &&   $isWl)     continue;
                $filtered[] = $id;
            }
            $allIds = $filtered;
        }

        $total      = count($allIds);
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $offset  = ($page - 1) * $perPage;
        $pageIds = array_slice($allIds, $offset, $perPage);

        $items = self::hydrateBatch($pageIds);

        $usageIndex = $usageIndex ?? UsageIndex::build();
        $wlIndex    = $wlIndex    ?? Whitelist::index();

        $parentIds = [];
        foreach ($items as $item) {
            foreach ($usageIndex[$item['id']] ?? [] as $u) {
                $parentIds[$u['pid']] = true;
            }
        }
        $parents = self::hydrateParents(array_keys($parentIds));

        foreach ($items as &$item) {
            $item['is_whitelisted']   = isset($wlIndex[$item['id']]);
            $item['whitelist_reason'] = $wlIndex[$item['id']] ?? null;
            $item['usage'] = [];
            foreach ($usageIndex[$item['id']] ?? [] as $u) {
                $info = $parents[$u['pid']] ?? null;
                $item['usage'][] = [
                    'pid'       => $u['pid'],
                    'role'      => $u['role'],
                    'name'      => $info['name']      ?? "#{$u['pid']}",
                    'sku'       => $info['sku']       ?? '',
                    'permalink' => $info['permalink'] ?? '',
                ];
            }
        }
        unset($item);

        return [
            'items'       => array_values($items),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array{filename?:string,usage?:string,whitelist?:string} $filters
     * @return int[]
     */
    public static function queryAllIds(array $filters = []): array
    {
        $allIds      = self::filterIdsAtDbLevel($filters, 'date', 'DESC');
        $usageFilter = (string) ($filters['usage']     ?? 'all');
        $wlFilter    = (string) ($filters['whitelist'] ?? 'all');
        if ($usageFilter === 'all' && $wlFilter === 'all') return $allIds;

        $usageIndex = UsageIndex::build();
        $wlIndex    = Whitelist::index();
        $out = [];
        foreach ($allIds as $id) {
            $isMapped = isset($usageIndex[$id]);
            $isWl     = isset($wlIndex[$id]);
            if ($usageFilter === 'mapped'   && ! $isMapped) continue;
            if ($usageFilter === 'unmapped' &&   $isMapped) continue;
            if ($wlFilter    === 'yes'      && ! $isWl)     continue;
            if ($wlFilter    === 'no'       &&   $isWl)     continue;
            $out[] = $id;
        }
        return $out;
    }

    /**
     * @return array{
     *   total_matched:int, to_delete_count:int, whitelisted_count:int,
     *   whitelist_details:array<int,array{id:int,url:string,reason:?string}>,
     *   to_delete_ids:int[]
     * }
     */
    public static function safeCleanupPreview(): array
    {
        $unmappedIds = self::queryAllIds(['usage' => 'unmapped', 'whitelist' => 'all']);
        $wlIndex     = Whitelist::index();

        $toDelete = $wlExcluded = [];
        foreach ($unmappedIds as $id) {
            if (isset($wlIndex[$id])) $wlExcluded[] = $id;
            else                      $toDelete[]   = $id;
        }

        $wlEntries = Whitelist::all();
        $wlById    = [];
        foreach ($wlEntries as $e) {
            $id = (int) ($e['id'] ?? 0);
            if ($id > 0) $wlById[$id] = $e;
        }

        $details = [];
        foreach ($wlExcluded as $id) {
            $entry = $wlById[$id] ?? null;
            $details[] = [
                'id'     => $id,
                'url'    => (string) ($entry['url'] ?? wp_get_attachment_url($id) ?: ''),
                'reason' => $entry['reason'] ?? null,
            ];
        }

        return [
            'total_matched'     => count($unmappedIds),
            'to_delete_count'   => count($toDelete),
            'whitelisted_count' => count($wlExcluded),
            'whitelist_details' => $details,
            'to_delete_ids'     => $toDelete,
        ];
    }

    /**
     * @param array{filename?:string} $filters
     * @return int[]
     */
    private static function filterIdsAtDbLevel(array $filters, string $orderby, string $order): array
    {
        global $wpdb;

        $where  = ["p.post_type = 'attachment'", "p.post_status = 'inherit'", "p.post_mime_type LIKE 'image/%'"];
        $params = [];

        $filename = trim((string) ($filters['filename'] ?? ''));
        if ($filename !== '') {
            $like     = '%' . $wpdb->esc_like($filename) . '%';
            $where[]  = '(p.post_title LIKE %s OR p.guid LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $orderCol = match ($orderby) {
            'id'       => 'p.ID',
            'filename' => 'p.post_title',
            default    => 'p.post_date',
        };

        $sql = "SELECT ID FROM {$wpdb->posts} p WHERE " . implode(' AND ', $where)
            . " ORDER BY {$orderCol} {$order}";

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return array_map('intval', $wpdb->get_col($sql) ?: []);
    }

    /**
     * Batched hydration of attachment metadata — 2 SQL queries for any N IDs,
     * no disk I/O. Filesize comes from _wp_attachment_metadata['filesize']
     * (WP 6.0+ writes this on upload).
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public static function hydrateBatch(array $ids): array
    {
        if (empty($ids)) return [];

        global $wpdb;
        $uploads = wp_get_upload_dir();
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        $out     = [];

        foreach (array_chunk($ids, 2000) as $chunk) {
            $chunk = array_map('intval', $chunk);
            $in    = implode(',', $chunk);

            $posts = $wpdb->get_results(
                "SELECT ID, post_date, post_mime_type, guid, post_title
                 FROM {$wpdb->posts} WHERE ID IN ({$in})",
                OBJECT_K,
            );

            $metaRows = $wpdb->get_results(
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$in})
                   AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata')",
            );

            $metaByPost = [];
            foreach ($metaRows ?: [] as $r) {
                $metaByPost[(int) $r->post_id][$r->meta_key] = $r->meta_value;
            }

            foreach ($chunk as $id) {
                $post = $posts[$id] ?? null;
                if (! $post) continue;

                $file    = $metaByPost[$id]['_wp_attached_file'] ?? '';
                $rawMeta = $metaByPost[$id]['_wp_attachment_metadata'] ?? '';
                $meta    = $rawMeta ? @unserialize($rawMeta) : [];
                if (! is_array($meta)) $meta = [];

                $filesize = isset($meta['filesize']) ? (int) $meta['filesize'] : 0;
                $url      = $file ? trailingslashit($baseUrl) . ltrim($file, '/') : (string) $post->guid;
                $thumbUrl = $url;
                if (! empty($meta['sizes']['thumbnail']['file']) && $file) {
                    $dir = dirname($file);
                    $thumbUrl = trailingslashit($baseUrl)
                        . ($dir !== '.' && $dir !== '' ? trailingslashit($dir) : '')
                        . $meta['sizes']['thumbnail']['file'];
                }

                $out[] = [
                    'id'             => (int) $id,
                    'title'          => (string) $post->post_title,
                    'url'            => $url,
                    'filename'       => $file ? basename($file) : '',
                    'filesize'       => $filesize,
                    'filesize_human' => $filesize ? size_format($filesize) : '—',
                    'date'           => (string) $post->post_date,
                    'mime_type'      => (string) $post->post_mime_type,
                    'thumbnail_url'  => $thumbUrl,
                ];
            }
        }
        return $out;
    }

    /**
     * @param int[] $parentIds
     * @return array<int, array{name:string, sku:string, permalink:string}>
     */
    private static function hydrateParents(array $parentIds): array
    {
        if (empty($parentIds)) return [];

        global $wpdb;
        $parentIds = array_map('intval', $parentIds);
        $in        = implode(',', $parentIds);

        $rows = $wpdb->get_results("
            SELECT p.ID, p.post_title,
                   (SELECT meta_value FROM {$wpdb->postmeta}
                      WHERE post_id = p.ID AND meta_key = '_sku' LIMIT 1) AS sku
            FROM {$wpdb->posts} p
            WHERE p.ID IN ({$in})
        ");
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[(int) $r->ID] = [
                'name'      => (string) $r->post_title,
                'sku'       => (string) ($r->sku ?? ''),
                'permalink' => (string) (get_permalink((int) $r->ID) ?: ''),
            ];
        }
        return $out;
    }
}
