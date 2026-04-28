<?php
declare(strict_types=1);

namespace GH\Operations\Pricing;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Operations\Support\LegacyHelpers;

/**
 * Set the sale price as N% off the regular price. v2 port of the
 * legacy 'set_sale_percent' bulk action.
 *
 * params:
 *   percent  number  required, 1..99
 */
final class SetSalePercent implements Operation
{
    public const ID = 'pricing.set_sale_percent';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Sconto % (sale price)'; }

    public function paramsSchema(): array
    {
        return [
            'percent' => [
                'type'     => 'int',
                'label'    => 'Sconto (%)',
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
        $percent = isset($params['percent']) ? (float) $params['percent'] : 0.0;
        if ($percent <= 0 || $percent >= 100) {
            return OperationResult::failed('invalid_percent');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'percent' => $percent,
                'dry_run' => true,
            ]);
        }

        if (! function_exists('gh_set_sale_percent')) {
            return OperationResult::failed('legacy bulk module unavailable');
        }
        $p = LegacyHelpers::getProduct($productId);
        if (is_string($p)) return OperationResult::failed($p);

        return LegacyHelpers::mapResult(
            \gh_set_sale_percent($p, $percent),
            ['percent' => $percent],
        );
    }
}
