<?php
declare(strict_types=1);

namespace GH\Checks\Taxonomy;

use GH\Checks\Support\Severity;
use GH\Core\Check\Check;
use GH\Core\Check\CheckResult;
use GH\Core\Check\CheckSeverity;

/**
 * Asserts the product has at least `min` terms in `product_cat` (or any
 * other taxonomy via params).
 *
 * params:
 *   taxonomy  text   default 'product_cat' (also useful: 'product_brand', 'product_tag')
 *   min       int    default 1
 *   severity  enum   warn|block, default warn
 */
final class HasCategory implements Check
{
    public const ID = 'taxonomy.has_category';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Ha tassonomia (es. categoria)'; }

    public function paramsSchema(): array
    {
        return [
            'taxonomy' => [
                'type'    => 'text',
                'label'   => 'Tassonomia',
                'default' => 'product_cat',
            ],
            'min'      => ['type' => 'int',  'label' => 'Minimo termini', 'default' => 1],
            'severity' => Severity::paramSpec('warn'),
        ];
    }

    public function defaultSeverity(): CheckSeverity
    {
        return CheckSeverity::Warn;
    }

    public function evaluate(int $productId, array $params): CheckResult
    {
        $tax = trim((string) ($params['taxonomy'] ?? 'product_cat'));
        if ($tax === '') $tax = 'product_cat';
        $min = max(1, (int) ($params['min'] ?? 1));
        $sev = Severity::fromParams($params, $this->defaultSeverity());

        if (! function_exists('wp_get_post_terms')) {
            return CheckResult::pass();
        }
        if ($productId <= 0) {
            return CheckResult::fail('invalid_product_id', $sev);
        }

        $term_ids = \wp_get_post_terms($productId, $tax, ['fields' => 'ids']);
        if (function_exists('is_wp_error') && \is_wp_error($term_ids)) {
            return CheckResult::fail($term_ids->get_error_message(), $sev);
        }
        $count = self::count(is_array($term_ids) ? $term_ids : []);

        if ($count >= $min) {
            return CheckResult::pass();
        }
        return CheckResult::fail(
            "Solo {$count} termini in '{$tax}' (minimo {$min})",
            $sev,
            ['taxonomy' => $tax, 'count' => $count, 'min' => $min],
        );
    }

    /**
     * Pure counter: positive int term ids.
     * @param array<int, int|string> $termIds
     */
    public static function count(array $termIds): int
    {
        $n = 0;
        foreach ($termIds as $tid) {
            if ((int) $tid > 0) $n++;
        }
        return $n;
    }
}
