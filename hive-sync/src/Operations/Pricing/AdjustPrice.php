<?php
declare(strict_types=1);

namespace HiveSync\Operations\Pricing;

use HiveSync\Core\Operation\Operation;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;

/**
 * Adjust the price by an absolute amount (positive = increase,
 * negative = decrease). Uses WC public API directly — runs against
 * any Woo install with no Hive Commerce dependency.
 *
 * params:
 *   amount  float  required, non-zero
 *   target  enum   'regular_price' | 'sale_price'  default regular_price
 */
final class AdjustPrice implements Operation
{
    public const ID = 'pricing.adjust_price';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Modifica prezzo (valore assoluto)'; }

    public function paramsSchema(): array
    {
        return [
            'amount' => [
                'type'     => 'int',
                'label'    => 'Importo (positivo o negativo)',
                'required' => true,
            ],
            'target' => [
                'type'    => 'enum',
                'label'   => 'Campo prezzo',
                'options' => ['regular_price', 'sale_price'],
                'default' => 'regular_price',
            ],
        ];
    }

    public function appliesTo(): array
    {
        return ['simple', 'variable'];
    }

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        $amount = isset($params['amount']) ? (float) $params['amount'] : 0.0;
        if ($amount === 0.0) {
            return OperationResult::failed('invalid_amount');
        }
        $target = (string) ($params['target'] ?? 'regular_price');
        if (! in_array($target, ['regular_price', 'sale_price'], true)) {
            return OperationResult::failed('invalid_target');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'amount' => $amount, 'target' => $target, 'dry_run' => true,
            ]);
        }

        if (! function_exists('wc_get_product')) {
            return OperationResult::failed('wc_unavailable');
        }
        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) {
            return OperationResult::failed('product_not_found');
        }

        $current = $target === 'sale_price' ? $p->get_sale_price() : $p->get_regular_price();
        $new = round((float) $current + $amount, 2);
        if ($new < 0) $new = 0.0;

        $setter = $target === 'sale_price' ? 'set_sale_price' : 'set_regular_price';
        $p->$setter((string) $new);
        $p->save();

        return OperationResult::changedWith([
            'target' => $target,
            'from'   => (float) $current,
            'to'     => $new,
        ]);
    }
}
