<?php
declare(strict_types=1);

namespace GH\Operations\Stock;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Operations\Support\LegacyHelpers;

/**
 * Set the WooCommerce stock_status. v2 port of 'set_stock_status'.
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
                'stock_status' => $status,
                'dry_run'      => true,
            ]);
        }

        if (! function_exists('gh_set_stock_status')) {
            return OperationResult::failed('legacy bulk module unavailable');
        }
        $p = LegacyHelpers::getProduct($productId);
        if (is_string($p)) return OperationResult::failed($p);

        return LegacyHelpers::mapResult(
            \gh_set_stock_status($p, $status),
            ['stock_status' => $status],
        );
    }
}
