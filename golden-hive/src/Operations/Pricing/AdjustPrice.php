<?php
declare(strict_types=1);

namespace GH\Operations\Pricing;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Operations\Support\LegacyHelpers;

/**
 * Adjust the price by an absolute amount (positive = increase, negative
 * = decrease). v2 port of legacy 'adjust_price'.
 *
 * params:
 *   amount  float   required (any non-zero)
 *   target  enum    'regular_price' | 'sale_price'  default regular_price
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
                'amount'  => $amount,
                'target'  => $target,
                'dry_run' => true,
            ]);
        }

        if (! function_exists('gh_adjust_price')) {
            return OperationResult::failed('legacy bulk module unavailable');
        }
        $p = LegacyHelpers::getProduct($productId);
        if (is_string($p)) return OperationResult::failed($p);

        return LegacyHelpers::mapResult(
            \gh_adjust_price($p, $amount, $target),
            ['amount' => $amount, 'target' => $target],
        );
    }
}
