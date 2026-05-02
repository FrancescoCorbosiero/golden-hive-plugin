<?php
declare(strict_types=1);

namespace HiveSync\Media;

/**
 * Safe attachment deletion — the ONLY class that destroys data.
 *
 * Each delete passes through three guards:
 *   1. Whitelist check (Whitelist::isWhitelisted)
 *   2. Point-in-time usage check (querying _thumbnail_id +
 *      _product_image_gallery directly — bypassing the cached UsageIndex
 *      because cache invalidation lag is unsafe for delete operations)
 *   3. Deletion log write BEFORE wp_delete_attachment, so a fatal between
 *      the three steps still leaves an audit trail
 */
final class Cleaner
{
    public const LOG_OPTION_KEY = 'hsync_media_deletion_log';
    public const LOG_MAX        = 500;

    /**
     * @return true|\WP_Error
     */
    public static function deleteOne(int $attachmentId): true|\WP_Error
    {
        if (Whitelist::isWhitelisted($attachmentId)) {
            return new \WP_Error('whitelisted', "Attachment #{$attachmentId} is whitelisted.");
        }

        if (self::isUsed($attachmentId)) {
            return new \WP_Error('in_use', "Attachment #{$attachmentId} is still in use.");
        }

        $hydrated = Browser::hydrateBatch([$attachmentId])[0] ?? [
            'id'       => $attachmentId,
            'filename' => '',
            'url'      => '',
            'filesize' => 0,
        ];
        self::logDeletion($hydrated);

        $result = wp_delete_attachment($attachmentId, true);
        if (! $result) {
            return new \WP_Error('delete_failed', "wp_delete_attachment failed for #{$attachmentId}.");
        }

        return true;
    }

    /**
     * @param int[] $attachmentIds
     * @return array{
     *   deleted:int[], errors:array<int,string>, skipped_whitelist:int[],
     *   freed_bytes:int, freed_human:string
     * }
     */
    public static function bulkDelete(array $attachmentIds): array
    {
        $deleted = $skippedWl = [];
        $errors  = [];
        $freed   = 0;

        foreach ($attachmentIds as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;

            if (Whitelist::isWhitelisted($id)) {
                $skippedWl[] = $id;
                continue;
            }

            // Query filesize from disk before deletion so the freed-bytes
            // total reflects actual reclaimed disk space, not the
            // serialized estimate.
            $file = get_attached_file($id);
            $size = $file && file_exists($file) ? (int) filesize($file) : 0;

            $r = self::deleteOne($id);
            if (is_wp_error($r)) {
                $errors[$id] = $r->get_error_message();
            } else {
                $deleted[] = $id;
                $freed    += $size;
            }
        }

        UsageIndex::invalidate();

        return [
            'deleted'           => $deleted,
            'errors'            => $errors,
            'skipped_whitelist' => $skippedWl,
            'freed_bytes'       => $freed,
            'freed_human'       => size_format($freed),
        ];
    }

    /**
     * Point-in-time usage check (NOT cached) — caller is about to delete,
     * so a stale cache could green-light a delete that should be blocked.
     */
    public static function isUsed(int $attachmentId): bool
    {
        $asThumb = get_posts([
            'post_type'      => ['product', 'product_variation', 'post', 'page'],
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_thumbnail_id', 'value' => $attachmentId]],
        ]);
        if ($asThumb) return true;

        $asGallery = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_product_image_gallery',
                'value'   => (string) $attachmentId,
                'compare' => 'LIKE',
            ]],
        ]);
        foreach ($asGallery ?: [] as $pid) {
            $csv = get_post_meta($pid, '_product_image_gallery', true);
            if (in_array((string) $attachmentId, explode(',', (string) $csv), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getLog(int $limit = 100): array
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        if (! is_array($log)) $log = [];
        return array_slice($log, 0, $limit);
    }

    /**
     * @param array<string, mixed> $hydrated
     */
    private static function logDeletion(array $hydrated): void
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        if (! is_array($log)) $log = [];

        $user = wp_get_current_user();
        array_unshift($log, [
            'attachment_id' => $hydrated['id']       ?? 0,
            'filename'      => $hydrated['filename'] ?? '',
            'url'           => $hydrated['url']      ?? '',
            'filesize'      => $hydrated['filesize'] ?? 0,
            'deleted_at'    => current_time('mysql'),
            'deleted_by'    => $user->user_login ?? 'system',
        ]);

        if (count($log) > self::LOG_MAX) {
            $log = array_slice($log, 0, self::LOG_MAX);
        }

        update_option(self::LOG_OPTION_KEY, $log, false);
    }
}
