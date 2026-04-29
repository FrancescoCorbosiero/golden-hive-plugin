<?php
declare(strict_types=1);

namespace GH\Operations\Stock;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Operations\Support\LegacyHelpers;

/**
 * Set the WooCommerce stock_quantity. v2 port of 'set_stock_quantity'.
 *
 * params:
 *   quantity  int  required, >= 0
 */
final class SetStockQuantity implements Operation
{
    public const ID = 'stock.set_quantity';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Quantita stock'; }

    public function paramsSchema(): array
    {
        return [
            'quantity' => [
                'type'     => 'int',
                'label'    => 'Quantita',
                'required' => true,
            ],
        ];
    }

    public function appliesTo(): array
    {
        // Variable products manage stock at the variation level; setting
        // quantity on the parent has no effect on Woo's catalog math.
        // Still allow it here so the user can intentionally apply it,
        // matching legacy behavior.
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
            return OperationResult::changedWith([
                'quantity' => $qty,
                'dry_run'  => true,
            ]);
        }

        if (! function_exists('gh_set_stock_quantity')) {
            return OperationResult::failed('legacy bulk module unavailable');
        }
        $p = LegacyHelpers::getProduct($productId);
        if (is_string($p)) return OperationResult::failed($p);

        return LegacyHelpers::mapResult(
            \gh_set_stock_quantity($p, $qty),
            ['quantity' => $qty],
        );
    }
}
