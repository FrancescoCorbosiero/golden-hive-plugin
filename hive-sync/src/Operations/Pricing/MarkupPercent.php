<?php
declare(strict_types=1);

namespace HiveSync\Operations\Pricing;

use HiveSync\Core\Operation\ImportRule;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;
use HiveSync\Core\Source\FeedItem;

/**
 * Apply a percentage markup to the draft pricing fields BEFORE the
 * source materializes the product. Designed for the StockFirmati flow
 * where each subset of the catalog (filtered by category) gets a
 * different margin.
 *
 * Multiplies regular_price by (1 + percent/100). When the feed also
 * carries a sale_price, the same multiplier is applied so the
 * effective discount is preserved.
 *
 * params:
 *   percent          float    default 0     — e.g. 30 = +30%
 *   target           enum     'regular' | 'both'  default 'both'
 *                              'regular' touches only regular_price; 'both'
 *                              also scales sale_price.
 *   rounding         enum     '2dec' | '99' | 'none'  default '2dec'
 *   floor            float    default 0     — minimum allowed result;
 *                              prices below this are bumped up.
 *   skip_if_no_price bool     default true  — when neither price field
 *                              is present in the draft, do nothing.
 */
final class MarkupPercent implements ImportRule
{
    public const ID = 'pricing.markup_percent';

    public function id(): string { return self::ID; }
    public function label(): string { return 'Markup percentuale (durante import)'; }

    public function paramsSchema(): array
    {
        return [
            'percent'  => [ 'type' => 'int',  'label' => 'Percentuale (%)', 'default' => 0 ],
            'target'   => [ 'type' => 'enum', 'label' => 'Campo',  'options' => [ 'regular', 'both' ], 'default' => 'both' ],
            'rounding' => [ 'type' => 'enum', 'label' => 'Arrotondamento', 'options' => [ '2dec', '99', 'none' ], 'default' => '2dec' ],
            'floor'    => [ 'type' => 'int',  'label' => 'Prezzo minimo (floor)', 'default' => 0 ],
            'skip_if_no_price' => [ 'type' => 'bool', 'label' => 'Salta se non c\'è prezzo', 'default' => true ],
        ];
    }

    public function appliesTo(): array { return [ 'simple', 'variable' ]; }

    public function apply( int $productId, array $params, OperationContext $ctx ): OperationResult
    {
        return OperationResult::failed( 'pricing.markup_percent is import-rule only.' );
    }

    public function applyDuringImport( FeedItem $item, array &$draft, array $params, OperationContext $ctx ): void
    {
        $percent = (float) ( $params['percent'] ?? 0 );
        if ( $percent === 0.0 ) return;

        $target          = (string) ( $params['target']   ?? 'both' );
        $rounding        = (string) ( $params['rounding'] ?? '2dec' );
        $floor           = (float)  ( $params['floor']    ?? 0 );
        $skipIfNoPrice   = (bool)   ( $params['skip_if_no_price'] ?? true );
        $multiplier      = 1.0 + ( $percent / 100.0 );

        $hasRegular = isset( $draft['regular_price'] ) && $draft['regular_price'] !== '';
        $hasSale    = isset( $draft['sale_price'] )    && $draft['sale_price']    !== '';

        if ( $skipIfNoPrice && ! $hasRegular && ! $hasSale ) return;

        if ( $hasRegular ) {
            $draft['regular_price'] = self::format(
                self::round( max( $floor, ( (float) $draft['regular_price'] ) * $multiplier ), $rounding ),
            );
        }
        if ( $target === 'both' && $hasSale ) {
            $draft['sale_price'] = self::format(
                self::round( max( $floor, ( (float) $draft['sale_price'] ) * $multiplier ), $rounding ),
            );
        }
    }

    private static function round( float $v, string $mode ): float
    {
        return match ( $mode ) {
            '99'   => floor( $v ) + 0.99,
            'none' => $v,
            default => round( $v, 2 ),
        };
    }

    private static function format( float $v ): string
    {
        // Woo stores prices as decimal strings — normalize to "12.34".
        return number_format( $v, 2, '.', '' );
    }
}
