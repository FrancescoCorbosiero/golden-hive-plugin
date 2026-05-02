<?php
declare(strict_types=1);

namespace HiveSync\Media;

/**
 * Product↔attachment write helpers + reverse lookup ("which products
 * use this attachment"). Pure-write surface — read paths live in Browser
 * and UsageIndex.
 *
 * Ported from golden-hive's media/library.php (rp_mm_*) — same behavior,
 * namespaced API.
 */
final class Library
{
    /**
     * @return true|\WP_Error
     */
    public static function setProductFeaturedImage(int $productId, int $attachmentId): true|\WP_Error
    {
        $product = wc_get_product($productId);
        if (! $product) {
            return new \WP_Error('not_found', "Product #{$productId} not found.");
        }
        if (! wp_get_attachment_url($attachmentId)) {
            return new \WP_Error('invalid_attachment', "Attachment #{$attachmentId} not found.");
        }
        $product->set_image_id($attachmentId);
        $product->save();
        UsageIndex::invalidate();
        return true;
    }

    /**
     * @param int[] $attachmentIds
     * @return true|\WP_Error
     */
    public static function setProductGallery(int $productId, array $attachmentIds): true|\WP_Error
    {
        $product = wc_get_product($productId);
        if (! $product) {
            return new \WP_Error('not_found', "Product #{$productId} not found.");
        }
        foreach ($attachmentIds as $aid) {
            if (! wp_get_attachment_url((int) $aid)) {
                return new \WP_Error('invalid_attachment', "Attachment #{$aid} not found.");
            }
        }
        $product->set_gallery_image_ids(array_map('intval', $attachmentIds));
        $product->save();
        UsageIndex::invalidate();
        return true;
    }

    /**
     * Bulk strip a media set out of every product gallery that contains
     * any of them. Does NOT delete the attachments themselves — that's
     * Cleaner's job.
     *
     * @param int[] $mediaIds
     * @return array{affected_products:int, removals:int}
     */
    public static function removeFromGalleries(array $mediaIds): array
    {
        $usageIndex = UsageIndex::build();

        $stripByPid = [];
        foreach ($mediaIds as $mid) {
            $mid = (int) $mid;
            foreach ($usageIndex[$mid] ?? [] as $u) {
                if ($u['role'] === 'gallery') {
                    $stripByPid[$u['pid']][$mid] = true;
                }
            }
        }

        $affected = $removals = 0;
        foreach ($stripByPid as $pid => $stripSet) {
            $product = wc_get_product($pid);
            if (! $product) continue;
            $current = $product->get_gallery_image_ids();
            $new = array_values(array_filter(
                $current,
                static fn($g): bool => ! isset($stripSet[(int) $g]),
            ));
            if (count($new) !== count($current)) {
                $product->set_gallery_image_ids($new);
                $product->save();
                $affected++;
                $removals += count($current) - count($new);
            }
        }

        UsageIndex::invalidate();
        return ['affected_products' => $affected, 'removals' => $removals];
    }

    /**
     * @return array<int, array{product_id:int, name:string, usage:string}>
     */
    public static function getAttachmentUsage(int $attachmentId): array
    {
        $usage = [];

        $featured = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_thumbnail_id', 'value' => $attachmentId]],
        ]);
        foreach ($featured ?: [] as $pid) {
            $p = wc_get_product($pid);
            if ($p) {
                $usage[] = ['product_id' => (int) $pid, 'name' => $p->get_name(), 'usage' => 'featured'];
            }
        }

        $galleryProducts = get_posts([
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
        foreach ($galleryProducts ?: [] as $pid) {
            $csv = get_post_meta($pid, '_product_image_gallery', true);
            $ids = array_filter(explode(',', (string) $csv));
            if (in_array((string) $attachmentId, $ids, true)) {
                $p = wc_get_product($pid);
                if ($p) {
                    $usage[] = ['product_id' => (int) $pid, 'name' => $p->get_name(), 'usage' => 'gallery'];
                }
            }
        }

        $variations = get_posts([
            'post_type'      => 'product_variation',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_thumbnail_id', 'value' => $attachmentId]],
        ]);
        foreach ($variations ?: [] as $vid) {
            $v = wc_get_product($vid);
            if ($v) {
                $parent = wc_get_product($v->get_parent_id());
                $usage[] = [
                    'product_id' => $v->get_parent_id(),
                    'name'       => $parent ? $parent->get_name() : "Variation #{$vid}",
                    'usage'      => 'variation_thumbnail',
                ];
            }
        }

        return $usage;
    }
}
