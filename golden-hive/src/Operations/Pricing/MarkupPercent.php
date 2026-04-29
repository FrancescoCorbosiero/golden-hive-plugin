<?php
declare(strict_types=1);

namespace GH\Operations\Pricing;

use GH\Core\Operation\Operation;
use GH\Core\Operation\OperationContext;
use GH\Core\Operation\OperationResult;
use GH\Operations\Support\LegacyHelpers;

/**
 * Increase the price by a percentage. v2 port of legacy 'markup_percent'
 * bulk action. Delegates to gh_apply_percent_change with factor = 1 + p/100.
 *
 * params:
 *   percent  number  required, > 0
 *   target   enum    'regular_price' | 'sale_price'  default regular_price
 *   rounding enum    '2dec' | '99' | 'none'           default 2dec
 */
final class MarkupPercent implements Operation
{
    public const ID = 'pricing.markup_percent';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Markup percentuale'; }

    public function paramsSchema(): array
    {
        return [
            'percent' => [
                'type'     => 'int',
                'label'    => 'Percentuale (%)',
                'required' => true,
            ],
            'target' => [
                'type'    => 'enum',
                'label'   => 'Campo prezzo',
                'options' => ['regular_price', 'sale_price'],
                'default' => 'regular_price',
            ],
            'rounding' => [
                'type'    => 'enum',
                'label'   => 'Arrotondamento',
                'options' => ['2dec', '99', 'none'],
                'default' => '2dec',
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
        if ($percent <= 0) {
            return OperationResult::failed('invalid_percent');
        }
        $target   = (string) ($params['target']   ?? 'regular_price');
        $rounding = (string) ($params['rounding'] ?? '2dec');

        if (! in_array($target, ['regular_price', 'sale_price'], true)) {
            return OperationResult::failed('invalid_target');
        }

        if ($ctx->isDryRun()) {
            return OperationResult::changedWith([
                'percent'  => $percent,
                'target'   => $target,
                'rounding' => $rounding,
                'dry_run'  => true,
            ]);
        }

        if (! function_exists('gh_apply_percent_change')) {
            return OperationResult::failed('legacy bulk module unavailable');
        }
        $p = LegacyHelpers::getProduct($productId);
        if (is_string($p)) return OperationResult::failed($p);

        $factor = 1 + ($percent / 100);
        return LegacyHelpers::mapResult(
            \gh_apply_percent_change($p, $factor, $target, $rounding),
            ['percent' => $percent, 'target' => $target, 'rounding' => $rounding],
        );
    }
}
