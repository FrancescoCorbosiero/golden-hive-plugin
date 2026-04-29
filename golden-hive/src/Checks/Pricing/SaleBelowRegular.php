<?php
declare(strict_types=1);

namespace GH\Checks\Pricing;

use GH\Checks\Support\Severity;
use GH\Core\Check\Check;
use GH\Core\Check\CheckResult;
use GH\Core\Check\CheckSeverity;

/**
 * Sanity check: when a sale_price is set, it must be strictly less
 * than the regular_price. A misconfigured product where sale >= regular
 * shows the customer a "discount" that's a price increase — almost
 * always a markup-formula bug worth flagging.
 *
 * Pass conditions (any one):
 *   - sale_price is empty / 0 → no sale → pass
 *   - regular_price > 0 AND sale_price < regular_price → pass
 * Fail otherwise.
 *
 * params:
 *   severity  enum  warn|block, default warn
 */
final class SaleBelowRegular implements Check
{
    public const ID = 'pricing.sale_below_regular';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Sale < Regular (sanity prezzi)'; }

    public function paramsSchema(): array
    {
        return [
            'severity' => Severity::paramSpec('warn'),
        ];
    }

    public function defaultSeverity(): CheckSeverity
    {
        return CheckSeverity::Warn;
    }

    public function evaluate(int $productId, array $params): CheckResult
    {
        $sev = Severity::fromParams($params, $this->defaultSeverity());

        if (! function_exists('wc_get_product')) {
            return CheckResult::pass();
        }
        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) {
            return CheckResult::fail('product_not_found', $sev);
        }

        // Variable products: check each variation independently.
        if (method_exists($p, 'is_type') && $p->is_type('variable')) {
            return self::evaluateVariable($p, $sev);
        }

        $regular = (string) $p->get_regular_price();
        $sale    = (string) $p->get_sale_price();

        return self::verdict($regular, $sale, $sev);
    }

    private static function evaluateVariable(\WC_Product $p, CheckSeverity $sev): CheckResult
    {
        $variation_ids = (array) $p->get_children();
        $bad = [];
        foreach ($variation_ids as $vid) {
            $v = \wc_get_product((int) $vid);
            if (! $v instanceof \WC_Product) continue;
            $verdict = self::verdict(
                (string) $v->get_regular_price(),
                (string) $v->get_sale_price(),
                $sev,
            );
            if (! $verdict->passed) {
                $bad[] = ['variation_id' => (int) $vid, 'sku' => (string) $v->get_sku()];
            }
        }
        if ($bad === []) {
            return CheckResult::pass();
        }
        return CheckResult::fail(
            sprintf('%d varianti con sale >= regular', count($bad)),
            $sev,
            ['bad_variations' => $bad],
        );
    }

    /**
     * Pure-PHP verdict from raw price strings.
     * Returns CheckResult so unit tests can assert verdicts directly
     * without spinning up WC.
     */
    public static function verdict(string $regularRaw, string $saleRaw, CheckSeverity $sev): CheckResult
    {
        $sale = trim($saleRaw);
        if ($sale === '' || (float) $sale === 0.0) {
            return CheckResult::pass(); // no sale set → not a sanity violation
        }
        $regular = trim($regularRaw);
        $r = (float) $regular;
        $s = (float) $sale;

        if ($r <= 0) {
            return CheckResult::fail('sale_set_but_regular_missing', $sev, [
                'regular' => $regular, 'sale' => $sale,
            ]);
        }
        if ($s >= $r) {
            return CheckResult::fail("sale ({$s}) >= regular ({$r})", $sev, [
                'regular' => $r, 'sale' => $s,
            ]);
        }
        return CheckResult::pass();
    }
}
