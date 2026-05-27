<?php
declare(strict_types=1);

namespace HiveSync\KicksDb;

/**
 * Compute the final retail price from a KicksDB market price.
 *
 *   final = ceil(
 *       max(
 *           market × (1 + tier_margin/100) × (1 + vat/100),
 *           floor_price
 *       )
 *   )
 *
 * The tiered margin keeps high-priced items from being bloated by a
 * flat percentage: 35% on a €600 sneaker = +€210, but at the top tier
 * (>€500) only 18% = +€108. Floor is applied AFTER VAT so the minimum
 * is a real shelf price.
 *
 * VAT is opt-in via vat_percent: pass 0 if the KicksDB IT market
 * endpoint already returns VAT-inclusive prices on your account. The
 * default is 22% (Italian standard rate) because that's the safe
 * assumption.
 *
 * Idempotent: pure function of market_price + config. No DB reads, no
 * stored state. Same input → same output. Crucially, the formula does
 * NOT read the previous Woo price, so re-runs don't compound.
 *
 * Also resolves the synthetic stock for KicksDB-only variants (sizes
 * the real feeds don't carry). Lower-priced items get higher fake
 * stock; halo SKUs get lower so we don't oversell.
 */
final class MarkupCalculator
{
    /** @var array<int, array{min:float,max:?float,margin:float}> */
    private array $tiers;
    /** @var array<int, array{min:float,max:?float,stock:int}> */
    private array $stockTiers;

    public function __construct(
        private readonly float $flatMargin = 25.0,
        array $tiers = [],
        private readonly float $floorPrice = 59.0,
        private readonly string $rounding = 'whole',
        private readonly float $vatPercent = 22.0,
        array $stockTiers = [],
    ) {
        $this->tiers      = self::normalizeMarginTiers( $tiers ?: self::defaultMarginTiers() );
        $this->stockTiers = self::normalizeStockTiers( $stockTiers ?: self::defaultStockTiers() );
    }

    public static function fromConfig( array $config ): self
    {
        return new self(
            flatMargin: (float) ( $config['flat_margin'] ?? 25.0 ),
            tiers:      (array) ( $config['tiers']       ?? [] ),
            floorPrice: (float) ( $config['floor_price'] ?? 59.0 ),
            rounding:   (string) ( $config['rounding']   ?? 'whole' ),
            vatPercent: (float) ( $config['vat_percent'] ?? 22.0 ),
            stockTiers: (array) ( $config['stock_tiers'] ?? [] ),
        );
    }

    public function calculate( float $marketPrice ): float
    {
        if ( $marketPrice <= 0 ) return 0.0;
        $margin = $this->resolveMargin( $marketPrice );
        $price  = $marketPrice * ( 1 + $margin / 100 );
        if ( $this->vatPercent > 0 ) {
            $price *= ( 1 + $this->vatPercent / 100 );
        }
        if ( $this->floorPrice > 0 && $price < $this->floorPrice ) {
            $price = $this->floorPrice;
        }
        return $this->applyRounding( $price );
    }

    public function syntheticStock( float $marketPrice ): int
    {
        if ( $marketPrice <= 0 ) return 12;
        foreach ( $this->stockTiers as $tier ) {
            $min = $tier['min'];
            $max = $tier['max'];
            if ( $marketPrice >= $min && ( $max === null || $marketPrice < $max ) ) {
                return (int) $tier['stock'];
            }
        }
        return 12;
    }

    public function vatPercent(): float
    {
        return $this->vatPercent;
    }

    private function resolveMargin( float $price ): float
    {
        foreach ( $this->tiers as $tier ) {
            $min = $tier['min'];
            $max = $tier['max'];
            if ( $price >= $min && ( $max === null || $price < $max ) ) {
                return $tier['margin'];
            }
        }
        return $this->flatMargin;
    }

    private function applyRounding( float $price ): float
    {
        return match ( $this->rounding ) {
            'whole'  => (float) ceil( $price ),
            'half'   => ceil( $price * 2 ) / 2,
            default  => round( $price, 2 ),
        };
    }

    /**
     * Default tiers — woo-importer's values. Higher margin on cheaper
     * items, lower margin on expensive ones so a €600 halo sneaker
     * doesn't become an unsellable €810.
     *
     * @return array<int, array{min:float,max:?float,margin:float}>
     */
    public static function defaultMarginTiers(): array
    {
        return [
            [ 'min' => 0.0,   'max' => 100.0, 'margin' => 35.0 ],
            [ 'min' => 100.0, 'max' => 200.0, 'margin' => 28.0 ],
            [ 'min' => 200.0, 'max' => 500.0, 'margin' => 22.0 ],
            [ 'min' => 500.0, 'max' => null,  'margin' => 18.0 ],
        ];
    }

    /** @return array<int, array{min:float,max:?float,stock:int}> */
    public static function defaultStockTiers(): array
    {
        return [
            [ 'min' => 0.0,   'max' => 100.0, 'stock' => 80 ],
            [ 'min' => 100.0, 'max' => 200.0, 'stock' => 50 ],
            [ 'min' => 200.0, 'max' => 500.0, 'stock' => 25 ],
            [ 'min' => 500.0, 'max' => null,  'stock' => 12 ],
        ];
    }

    /**
     * @param array<int, array> $tiers
     * @return array<int, array{min:float,max:?float,margin:float}>
     */
    private static function normalizeMarginTiers( array $tiers ): array
    {
        $out = [];
        foreach ( $tiers as $t ) {
            if ( ! is_array( $t ) ) continue;
            $out[] = [
                'min'    => (float) ( $t['min'] ?? 0 ),
                'max'    => isset( $t['max'] ) && $t['max'] !== null && $t['max'] !== '' ? (float) $t['max'] : null,
                'margin' => (float) ( $t['margin'] ?? 0 ),
            ];
        }
        usort( $out, fn( $a, $b ) => $a['min'] <=> $b['min'] );
        return $out;
    }

    /**
     * @param array<int, array> $tiers
     * @return array<int, array{min:float,max:?float,stock:int}>
     */
    private static function normalizeStockTiers( array $tiers ): array
    {
        $out = [];
        foreach ( $tiers as $t ) {
            if ( ! is_array( $t ) ) continue;
            $out[] = [
                'min'   => (float) ( $t['min'] ?? 0 ),
                'max'   => isset( $t['max'] ) && $t['max'] !== null && $t['max'] !== '' ? (float) $t['max'] : null,
                'stock' => (int) ( $t['stock'] ?? 12 ),
            ];
        }
        usort( $out, fn( $a, $b ) => $a['min'] <=> $b['min'] );
        return $out;
    }
}
