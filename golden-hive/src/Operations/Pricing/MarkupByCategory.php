<?php
declare(strict_types=1);

namespace GH\Operations\Pricing;

use GH\Core\Operation\ImportRule;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Core\Source\FeedItem;
use GH\Operations\Support\LegacyHelpers;

/**
 * First concrete ImportRule. Applies a different percent markup
 * depending on the product's category. The same logic is callable
 * in two lifecycle moments — the user's design insight made literal:
 *
 *   - applyDuringImport(): mutates the FeedItem's draft data BEFORE
 *     materialize. The resulting product is created with the marked-up
 *     regular_price; no second write is needed.
 *
 *   - apply():            mutates an EXISTING product post-hoc. Looks up
 *     the product's category, resolves the markup, calls the legacy
 *     gh_apply_percent_change. Useful for retroactive re-pricing on
 *     catalogs imported before the rule existed.
 *
 * Both paths share a static calculate() so the math is provably the
 * same regardless of phase.
 *
 * params:
 *   markup_map  json   { "<category-slug-or-name>": <percent>, "default": <percent>? }
 *                      Example: {"sneakers": 30, "abbigliamento": 50, "default": 25}
 *   target      enum   regular_price | sale_price (post-hoc apply only; default regular_price)
 *   rounding    enum   2dec | 99 | none (post-hoc apply only; default 2dec)
 */
final class MarkupByCategory implements ImportRule
{
    public const ID = 'pricing.markup_by_category';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Markup % per categoria'; }

    public function paramsSchema(): array
    {
        return [
            'markup_map' => [
                'type'     => 'json',
                'label'    => 'Mappa categoria→% (JSON, key "default" come fallback)',
                'required' => true,
            ],
            'target' => [
                'type'    => 'enum',
                'label'   => 'Campo prezzo (post-hoc)',
                'options' => ['regular_price', 'sale_price'],
                'default' => 'regular_price',
            ],
            'rounding' => [
                'type'    => 'enum',
                'label'   => 'Arrotondamento (post-hoc)',
                'options' => ['2dec', '99', 'none'],
                'default' => '2dec',
            ],
        ];
    }

    public function appliesTo(): array
    {
        return ['simple', 'variable'];
    }

    /**
     * Pre-import path: mutate the draft data so the resulting WC product
     * is materialized with the marked-up price. Cheap and atomic — no
     * second write happens after Source::materialize.
     */
    public function applyDuringImport(FeedItem $item, array &$draft, array $params, OperationContext $ctx): void
    {
        $map = self::normalizeMap($params['markup_map'] ?? []);
        if (empty($map)) return;

        $category = self::extractCategory($draft);
        $percent  = self::calculate($category, $map);
        if ($percent === null) return;

        $regular = (float) ($draft['regular_price'] ?? 0);
        if ($regular <= 0) return;

        $factor = 1 + ($percent / 100);
        $draft['regular_price'] = round($regular * $factor, 2);

        // Mark the draft so audit can show what happened. Ignored by Woo,
        // useful in the source's per-row trace and in the conflict engine.
        $draft['_gh_markup_applied'] = [
            'rule'     => self::ID,
            'category' => $category,
            'percent'  => $percent,
        ];
    }

    /**
     * Post-hoc path: same math, applied to an existing product via the
     * legacy gh_apply_percent_change. The product's category is read
     * from product_cat (slug or name).
     */
    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        $map = self::normalizeMap($params['markup_map'] ?? []);
        if (empty($map)) {
            return OperationResult::failed('invalid_markup_map');
        }

        $target   = (string) ($params['target']   ?? 'regular_price');
        $rounding = (string) ($params['rounding'] ?? '2dec');
        if (! in_array($target, ['regular_price', 'sale_price'], true)) {
            return OperationResult::failed('invalid_target');
        }

        // Determine category. Prefer slug (deterministic); fall back to name.
        $categorySlug = self::resolveProductCategorySlug($productId);
        $percent = self::calculate($categorySlug, $map);
        if ($percent === null) {
            return OperationResult::failed('no_markup_for_category:' . ($categorySlug ?: 'none'));
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'category' => $categorySlug,
                'percent'  => $percent,
                'target'   => $target,
                'dry_run'  => true,
            ]);
        }

        if (! function_exists('gh_apply_percent_change')) {
            return OperationResult::failed('legacy bulk module unavailable');
        }
        $p = LegacyHelpers::getProduct($productId);
        if (is_string($p)) return OperationResult::failed($p);

        $factor = 1 + ($percent / 100);
        return LegacyHelpers::mapResult(
            \gh_apply_percent_change($p, $factor, $target, $rounding),
            ['category' => $categorySlug, 'percent' => $percent, 'target' => $target],
        );
    }

    // ─── Pure helpers (unit-test seam) ─────────────────────────

    /**
     * Resolve the markup percent for a category. Falls back to 'default'
     * key if present. Returns null when nothing matches OR when the
     * resolved percent is non-positive (no-op rather than confusing
     * neg-percent surprise).
     *
     * @param array<string, float|int|string> $map
     */
    public static function calculate(string $category, array $map): ?float
    {
        $candidates = [$category, 'default'];
        foreach ($candidates as $key) {
            if ($key === '') continue;
            if (! array_key_exists($key, $map)) continue;
            $p = (float) $map[$key];
            if ($p > 0) return $p;
        }
        return null;
    }

    /**
     * Accept JSON-string / array / null. Returns a clean map of
     * string-keyed percent values.
     *
     * @return array<string, float>
     */
    public static function normalizeMap(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $k => $v) {
            $key = trim((string) $k);
            if ($key === '') continue;
            $val = (float) $v;
            if ($val > 0) $out[$key] = $val;
        }
        return $out;
    }

    /**
     * Pick a category-like slug from a feed-item draft. GS uses
     * `_gs_category`; SF stores under `_sf_category`; the v2 generic
     * shape uses 'category'. First match wins.
     */
    public static function extractCategory(array $draft): string
    {
        foreach (['_gs_category', '_sf_category', 'category', 'category_slug'] as $key) {
            if (! empty($draft[$key]) && is_string($draft[$key])) {
                return $draft[$key];
            }
        }
        return '';
    }

    private static function resolveProductCategorySlug(int $productId): string
    {
        if ($productId <= 0 || ! function_exists('wp_get_post_terms')) {
            return '';
        }
        $terms = \wp_get_post_terms($productId, 'product_cat', ['fields' => 'all']);
        if (! is_array($terms) || empty($terms)) {
            return '';
        }
        $first = $terms[0];
        if (is_object($first) && isset($first->slug) && is_string($first->slug)) {
            return (string) $first->slug;
        }
        return '';
    }
}
