<?php
declare(strict_types=1);

namespace HiveSync\Media;

/**
 * Attachment whitelist — protects items from Safe Cleanup deletion.
 * Stored as a flat list in wp_options under 'hsync_media_whitelist'.
 *
 * Ported from golden-hive's media/whitelist.php (rp_mm_*) — same semantics,
 * Hive Sync option key + namespaced API.
 */
final class Whitelist
{
    public const OPTION_KEY = 'hsync_media_whitelist';

    /** @return array<int, array{id:int|null,url:string|null,reason:?string,added_at:string,added_by:int}> */
    public static function all(): array
    {
        $list = get_option(self::OPTION_KEY, []);
        return is_array($list) ? $list : [];
    }

    public static function add(?int $id, ?string $url = null, string $reason = ''): bool
    {
        if (! $id && ! $url) return false;

        $list = self::all();
        foreach ($list as &$entry) {
            if (($id && (int) ($entry['id'] ?? 0) === $id)
                || ($url && (string) ($entry['url'] ?? '') === $url)) {
                $entry['reason'] = $reason;
                return update_option(self::OPTION_KEY, $list, false);
            }
        }
        unset($entry);

        if ($id && ! $url) {
            $resolved = wp_get_attachment_url($id);
            if ($resolved) $url = $resolved;
        }

        $list[] = [
            'id'       => $id,
            'url'      => $url,
            'reason'   => $reason,
            'added_at' => current_time('mysql'),
            'added_by' => get_current_user_id(),
        ];

        return update_option(self::OPTION_KEY, $list, false);
    }

    public static function remove(int $id): bool
    {
        $list = self::all();
        $list = array_values(array_filter(
            $list,
            static fn(array $e): bool => (int) ($e['id'] ?? 0) !== $id,
        ));
        return update_option(self::OPTION_KEY, $list, false);
    }

    public static function isWhitelisted(int $attachmentId): bool
    {
        $url = wp_get_attachment_url($attachmentId) ?: null;
        foreach (self::all() as $entry) {
            if ((int) ($entry['id'] ?? 0) === $attachmentId) return true;
            if ($url && (string) ($entry['url'] ?? '') === $url) return true;
        }
        return false;
    }

    /**
     * id => reason index — call once when iterating thousands of attachments
     * to avoid O(n×m) lookups against the option payload.
     *
     * @return array<int, ?string>
     */
    public static function index(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id > 0) $out[$id] = $entry['reason'] ?? null;
        }
        return $out;
    }

    public static function clear(): bool
    {
        return update_option(self::OPTION_KEY, [], false);
    }
}
