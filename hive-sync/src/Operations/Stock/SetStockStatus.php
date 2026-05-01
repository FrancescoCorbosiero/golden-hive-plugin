<?php
declare(strict_types=1);

namespace HiveSync\Operations\Stock;

use HiveSync\Core\Operation\Operation;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;

/**
 * Set the WooCommerce stock_status. Uses WC public API directly.
 *
 * params:
 *   stock_status  enum  'instock' | 'outofstock' | 'onbackorder'
 */
final class SetStockStatus implements Operation
{
    public const ID = 'stock.set_status';

    private const ALLOWED = ['instock', 'outofstock', 'onbackorder'];

    public function id(): string { return self::ID; }
    public function label(): string { return 'Stato stock'; }

    public function paramsSchema(): array
    {
        return [
            'stock_status' => [
                'type'     => 'enum',
                'label'    => 'Stato',
                'options'  => self::ALLOWED,
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
        $status = $params['stock_status'] ?? null;
        if (! is_string($status) || ! in_array($status, self::ALLOWED, true)) {
            return OperationResult::failed('invalid_stock_status');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'stock_status' => $status, 'dry_run' => true,
            ]);
        }

        if (! function_exists('wc_get_product')) {
            return OperationResult::failed('wc_unavailable');
        }
        $p = \wc_get_product($productId);
        if (! $p instanceof \WC_Product) {
            return OperationResult::failed('product_not_found');
        }

        $p->set_stock_status($status);
        $p->save();

        return OperationResult::changedWith(['stock_status' => $status]);
    }
}
