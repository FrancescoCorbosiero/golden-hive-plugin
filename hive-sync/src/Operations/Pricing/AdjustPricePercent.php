<?php
declare(strict_types=1);

namespace HiveSync\Operations\Pricing;

use HiveSync\Core\Operation\Operation;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;

/**
 * Apply a percentage markup/discount to an existing product's price.
 *
 * The companion `pricing.markup_percent` is import-rule only — it
 * mutates the draft Woo data before create. This op runs on existing
 * products so a Rule scoped to a category/brand can re-price a subset
 * of the catalog on a schedule (the "publish a batch with markup"
 * workflow).
 *
 * For variable products, applies the percent to every variation's
 * regular_price (and to the parent's regular_price too if set).
 * Sale price is updated proportionally only when the operator opts in
 * (preserve_sale_ratio=true) — by default sale prices are left alone
 * because they're typically a manual decision.
 *
 * params:
 *   percent              float  required; +20 = +20%, -10 = 10% off
 *   target               enum   'regular_price' | 'sale_price'  default regular_price
 *   apply_to_variations  bool   default true (variable products)
 */
final class AdjustPricePercent implements Operation
{
    public const ID = 'pricing.adjust_price_percent';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Markup percentuale (prodotti esistenti)'; }

    public function paramsSchema(): array
    {
        return [
            'percent' => [
                'type'     => 'int',
                'label'    => 'Percentuale (es. 20 = +20%, -10 = -10%)',
                'required' => true,
            ],
            'target' => [
                'type'    => 'enum',
                'label'   => 'Campo prezzo',
                'options' => ['regular_price', 'sale_price'],
                'default' => 'regular_price',
            ],
            'apply_to_variations' => [
                'type'    => 'bool',
                'label'   => 'Applica anche alle varianti',
                'default' => true,
            ],
        ];
    }

    public function appliesTo(): array
    {
        return ['simple', 'variable'];
    }

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        $percent = isset($params['percent']) ? (float) $params['percent'] : 0.0;
        if ($percent === 0.0) {
            return OperationResult::failed('invalid_percent');
        }
        $target = (string) ($params['target'] ?? 'regular_price');
        if (! in_array($target, ['regular_price', 'sale_price'], true)) {
            return OperationResult::failed('invalid_target');
        }
        $applyToVariations = ! isset($params['apply_to_variations'])
            ? true
            : (bool) $params['apply_to_variations'];
        $multiplier = 1.0 + ($percent / 100.0);

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'percent' => $percent, 'target' => $target, 'dry_run' => true,
            ]);
        }

        if (! function_exists('wc_get_product')) {
            return OperationResult::failed('wc_unavailable');
        }
        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) {
            return OperationResult::failed('product_not_found');
        }

        $setter = $target === 'sale_price' ? 'set_sale_price' : 'set_regular_price';
        $getter = $target === 'sale_price' ? 'get_sale_price' : 'get_regular_price';

        $touched = 0;
        $samples = [];

        // Parent first. Skip if the parent has no value in the target
        // field (variable products often only carry prices on variations
        // — applying the percent to an empty string would corrupt it).
        $current = (string) $p->$getter();
        if ($current !== '' && is_numeric($current)) {
            $new = round((float) $current * $multiplier, 2);
            if ($new < 0) $new = 0.0;
            $p->$setter((string) $new);
            $p->save();
            $touched++;
            if (count($samples) < 3) $samples[] = ['id' => $productId, 'from' => (float) $current, 'to' => $new];
        }

        // Variations.
        if ($applyToVariations && method_exists($p, 'get_children')) {
            foreach ($p->get_children() as $vid) {
                $v = \wc_get_product((int) $vid);
                if (! $v instanceof \WC_Product) continue;
                $vCur = (string) $v->$getter();
                if ($vCur === '' || ! is_numeric($vCur)) continue;
                $vNew = round((float) $vCur * $multiplier, 2);
                if ($vNew < 0) $vNew = 0.0;
                $v->$setter((string) $vNew);
                $v->save();
                $touched++;
                if (count($samples) < 3) $samples[] = ['id' => (int) $vid, 'from' => (float) $vCur, 'to' => $vNew];
            }
            // Re-sync the parent so the storefront price range reflects
            // the new variation prices (Woo caches the aggregate).
            if (class_exists('\WC_Product_Variable')) {
                \WC_Product_Variable::sync($productId);
            }
        }

        if ($touched === 0) {
            // Nothing to update — most likely a variable product whose
            // parent has no regular_price and the operator opted out of
            // variations. Surface as a no-op rather than a silent skip.
            return OperationResult::failed('no_target_field');
        }

        return OperationResult::changedWith([
            'percent' => $percent,
            'target'  => $target,
            'touched' => $touched,
            'samples' => $samples,
        ]);
    }
}
