<?php
declare(strict_types=1);

namespace HiveSync\Operations\Stock;

use HiveSync\Core\Operation\Operation;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;

/**
 * Set the WooCommerce stock_quantity. Uses WC public API directly.
 *
 * Variable products manage stock at the variation level; setting
 * quantity on the parent has no effect on Woo's catalog math. Allowed
 * here so the user can intentionally apply it (matches how WC core
 * accepts the call).
 *
 * params:
 *   quantity  int  required, >= 0
 */
final class SetStockQuantity implements Operation
{
    public const ID = 'stock.set_quantity';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Quantità stock'; }

    public function paramsSchema(): array
    {
        return [
            'quantity' => [
                'type'     => 'int',
                'label'    => 'Quantità',
                'required' => true,
            ],
        ];
    }

    public function appliesTo(): array
    {
        return ['simple', 'variable'];
    }

    public function apply(int $productId, array $params, OperationContext $ctx): OperationResult
    {
        if (! array_key_exists('quantity', $params)) {
            return OperationResult::failed('quantity_required');
        }
        $qty = (int) $params['quantity'];
        if ($qty < 0) {
            return OperationResult::failed('invalid_quantity');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith(['quantity' => $qty, 'dry_run' => true]);
        }

        if (! function_exists('wc_get_product')) {
            return OperationResult::failed('wc_unavailable');
        }
        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) {
            return OperationResult::failed('product_not_found');
        }

        $p->set_manage_stock(true);
        $p->set_stock_quantity($qty);
        $p->save();

        return OperationResult::changedWith(['quantity' => $qty]);
    }
}
