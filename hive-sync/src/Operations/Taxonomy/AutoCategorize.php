<?php
declare(strict_types=1);

namespace HiveSync\Operations\Taxonomy;

use HiveSync\Core\Operation\ImportRule;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;
use HiveSync\Core\Source\FeedItem;

/**
 * Auto-classify a product into Sneakers / Abbigliamento when the feed
 * doesn't carry an explicit category. Matches the heuristic used in
 * golden-hive (rp_rc_gs_transform_to_woo) and woo-importer:
 *
 *   1. Look at size labels. If most are alphabetic (S / M / L / XL,
 *      XS-S, etc.) → apparel. If most are numeric (40, 41.5, 42 2/3)
 *      → sneakers.
 *   2. Fallback when sizes are missing: scan the product name for
 *      apparel keywords (t-shirt, polo, felpa, jacket, …).
 *   3. Default: sneakers (matches the legacy plugin's bias — the
 *      catalog is sneaker-first).
 *
 * The operation appends the resolved category NAME (not ID) into
 * `draft['categories']`. ResolveTaxonomy runs afterwards and converts
 * names → term IDs (creating the term if missing). So the GS feed
 * can ship without any category mapping at all.
 *
 * params:
 *   sneakers_label  string  default 'Sneakers'
 *   apparel_label   string  default 'Abbigliamento'
 *   override        bool    default false  — overwrite categories when
 *                                            already present in draft
 */
final class AutoCategorize implements ImportRule
{
    public const ID = 'taxonomy.auto_categorize';

    private const DEFAULT_SNEAKERS_LABEL = 'Sneakers';
    private const DEFAULT_APPAREL_LABEL  = 'Abbigliamento';

    /**
     * Italian + English apparel keywords. Ordered roughly by hit
     * frequency in real GS catalogs so the loop short-circuits fast on
     * common cases.
     */
    private const APPAREL_KEYWORDS = [
        't-shirt', 'tshirt', 'tee', 'polo', 'shirt', 'maglia', 'maglietta',
        'felpa', 'hoodie', 'sweatshirt', 'sweater', 'jumper', 'cardigan',
        'jacket', 'giubbotto', 'giacca', 'cappotto', 'parka', 'piumino',
        'pants', 'pantalone', 'pantaloni', 'shorts', 'bermuda', 'jeans',
        'tracksuit', 'tuta', 'sweatpants',
        'cap', 'hat', 'cappello', 'beanie', 'bandana',
        'scarf', 'sciarpa', 'gloves', 'guanti',
        'belt', 'cintura', 'tie', 'cravatta',
        'sock', 'socks', 'calza', 'calze', 'underwear', 'intimo',
        'dress', 'vestito', 'gonna', 'skirt',
    ];

    public function id(): string { return self::ID; }
    public function label(): string { return 'Auto-categorizza (Sneakers / Abbigliamento)'; }

    public function paramsSchema(): array
    {
        return [
            'sneakers_label' => [
                'type'    => 'text',
                'label'   => 'Etichetta categoria sneakers',
                'default' => self::DEFAULT_SNEAKERS_LABEL,
            ],
            'apparel_label' => [
                'type'    => 'text',
                'label'   => 'Etichetta categoria abbigliamento',
                'default' => self::DEFAULT_APPAREL_LABEL,
            ],
            'override' => [
                'type'    => 'bool',
                'label'   => 'Sovrascrivi anche se categories è già compilato',
                'default' => false,
            ],
        ];
    }

    public function appliesTo(): array
    {
        return [ 'simple', 'variable' ];
    }

    public function apply( int $productId, array $params, OperationContext $ctx ): OperationResult
    {
        return OperationResult::failed( 'taxonomy.auto_categorize is import-rule only.' );
    }

    public function applyDuringImport( FeedItem $item, array &$draft, array $params, OperationContext $ctx ): void
    {
        $override = (bool) ( $params['override'] ?? false );
        $existing = self::asNameList( $draft['categories'] ?? null );
        if ( ! $override && ! empty( $existing ) ) return;

        $sneakersLabel = trim( (string) ( $params['sneakers_label'] ?? '' ) ) ?: self::DEFAULT_SNEAKERS_LABEL;
        $apparelLabel  = trim( (string) ( $params['apparel_label']  ?? '' ) ) ?: self::DEFAULT_APPAREL_LABEL;

        $decision = self::decide( $draft, $item->raw, $sneakersLabel, $apparelLabel );

        $cats = $override ? [] : $existing;
        if ( ! in_array( $decision, $cats, true ) ) $cats[] = $decision;
        $draft['categories'] = $cats;
    }

    /**
     * Two-pass heuristic. Sizes are the more reliable signal; keywords
     * are a fallback for variant-less products that wouldn't otherwise
     * have any taxonomy hint.
     */
    private static function decide(
        array $draft,
        array $raw,
        string $sneakersLabel,
        string $apparelLabel,
    ): string {
        $sizes = self::collectSizes( $draft, $raw );
        if ( ! empty( $sizes ) ) {
            $alpha = 0;
            $total = 0;
            foreach ( $sizes as $sz ) {
                $sz = trim( (string) $sz );
                if ( $sz === '' ) continue;
                $total++;
                if ( preg_match( '/^[A-Z]{1,3}(\/[A-Z]{1,3})?$/i', $sz ) ) $alpha++;
            }
            if ( $total > 0 ) {
                return $alpha > $total / 2 ? $apparelLabel : $sneakersLabel;
            }
        }

        $name = strtolower( (string) ( $draft['name'] ?? '' ) );
        if ( $name !== '' ) {
            foreach ( self::APPAREL_KEYWORDS as $kw ) {
                if ( str_contains( $name, $kw ) ) return $apparelLabel;
            }
        }

        // Sneakers-first default — matches the legacy plugin's behavior.
        return $sneakersLabel;
    }

    /**
     * Collect size labels from every place a feed might park them.
     * Looks at the draft (post-mapping) AND the raw upstream payload
     * so we work with both the GS-style `sizes[].size_eu` shape and
     * the simpler `pa_taglia: [...]` shape produced by the mapping.
     *
     * @return string[]
     */
    private static function collectSizes( array $draft, array $raw ): array
    {
        $out = [];

        // 1. Mapped attribute fields (post-mapping draft).
        foreach ( [ 'pa_taglia', 'pa_size', 'pa_eu_size', 'sizes_eu' ] as $field ) {
            $v = $draft[ $field ] ?? null;
            if ( is_array( $v ) ) {
                foreach ( $v as $sz ) {
                    if ( is_scalar( $sz ) ) $out[] = (string) $sz;
                }
            } elseif ( is_string( $v ) && $v !== '' ) {
                $parts = str_contains( $v, '|' ) ? explode( '|', $v ) : [ $v ];
                foreach ( $parts as $sz ) $out[] = (string) $sz;
            }
        }

        // 2. Aggregated GS shape on the draft itself: sizes is a list
        //    of {size_eu, size_us, available_quantity, barcode}.
        foreach ( (array) ( $draft['sizes'] ?? [] ) as $row ) {
            if ( is_array( $row ) && isset( $row['size_eu'] ) && is_scalar( $row['size_eu'] ) ) {
                $out[] = (string) $row['size_eu'];
            }
        }

        // 3. Raw flat rows from the upstream feed (GS hands us one row
        //    per size, GoldenSneakersSource passes them through as
        //    FeedItem.raw — no `.sizes` wrapper, just an array of rows).
        foreach ( $raw as $row ) {
            if ( is_array( $row ) && isset( $row['size_eu'] ) && is_scalar( $row['size_eu'] ) ) {
                $out[] = (string) $row['size_eu'];
            }
        }
        // Older bridge versions may still ship raw with a `sizes` key —
        // accept that shape too.
        foreach ( (array) ( $raw['sizes'] ?? [] ) as $row ) {
            if ( is_array( $row ) && isset( $row['size_eu'] ) && is_scalar( $row['size_eu'] ) ) {
                $out[] = (string) $row['size_eu'];
            }
        }

        // 4. attribute_terms[] shape if a previous step already resolved them.
        $terms = $draft['attribute_terms'] ?? [];
        if ( is_array( $terms ) ) {
            foreach ( [ 'pa_taglia', 'pa_size' ] as $taxKey ) {
                $names = $terms[ $taxKey ] ?? [];
                if ( is_array( $names ) ) {
                    foreach ( $names as $n ) {
                        if ( is_scalar( $n ) ) $out[] = (string) $n;
                    }
                }
            }
        }

        return array_values( array_unique( $out ) );
    }

    /**
     * Coerce a value into a clean list of trimmed non-empty names.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function asNameList( mixed $value ): array
    {
        if ( is_string( $value ) ) {
            $value = str_contains( $value, '|' ) ? explode( '|', $value ) : [ $value ];
        }
        if ( ! is_array( $value ) ) return [];
        $out = [];
        foreach ( $value as $v ) {
            if ( ! is_scalar( $v ) ) continue;
            $name = trim( (string) $v );
            if ( $name !== '' ) $out[] = $name;
        }
        return array_values( array_unique( $out ) );
    }
}
