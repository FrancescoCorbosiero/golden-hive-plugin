<?php
declare(strict_types=1);

namespace HiveSync\Checks\Media;

use HiveSync\Checks\Support\Severity;
use HiveSync\Core\Check\Check;
use HiveSync\Core\Check\CheckResult;
use HiveSync\Core\Check\CheckSeverity;

/**
 * Asserts the product has at least `min` images (featured + gallery).
 * Variation thumbnails are NOT counted — they're per-variation media,
 * not catalog presentation media.
 *
 * params:
 *   min       int    minimum total images (default 1)
 *   severity  enum   warn|block, default warn
 */
final class HasImages implements Check
{
    public const ID = 'media.has_images';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Ha immagini (featured + gallery)'; }

    public function paramsSchema(): array
    {
        return [
            'min'      => ['type' => 'int', 'label' => 'Minimo immagini', 'default' => 1],
            'severity' => Severity::paramSpec('warn'),
        ];
    }

    public function defaultSeverity(): CheckSeverity
    {
        return CheckSeverity::Warn;
    }

    public function evaluate(int $productId, array $params): CheckResult
    {
        $min = max(1, (int) ($params['min'] ?? 1));
        $sev = Severity::fromParams($params, $this->defaultSeverity());

        if (! function_exists('wc_get_product')) {
            // Optimistic in unit-test mode: no WC = no data = no opinion.
            return CheckResult::pass();
        }

        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) {
            return CheckResult::fail('product_not_found', $sev);
        }

        $featuredId = (int) $p->get_image_id();
        $galleryIds = array_filter(
            (array) $p->get_gallery_image_ids(),
            static fn($id): bool => (int) $id > 0,
        );
        $count = self::count($featuredId, $galleryIds);

        if ($count >= $min) {
            return CheckResult::pass();
        }
        return CheckResult::fail(
            "Solo {$count} immagini su {$min} richieste",
            $sev,
            ['count' => $count, 'min' => $min],
        );
    }

    /**
     * Pure-PHP image counter — featured (if > 0) + gallery (positive ids).
     * Extracted so unit tests don't need WC.
     *
     * @param int   $featuredId
     * @param int[] $galleryIds
     */
    public static function count(int $featuredId, array $galleryIds): int
    {
        $n = $featuredId > 0 ? 1 : 0;
        foreach ($galleryIds as $gid) {
            if ((int) $gid > 0) $n++;
        }
        return $n;
    }
}
