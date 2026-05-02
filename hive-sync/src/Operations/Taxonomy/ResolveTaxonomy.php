<?php
declare(strict_types=1);

namespace HiveSync\Operations\Taxonomy;

use HiveSync\Core\Operation\ImportRule;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;
use HiveSync\Core\Source\FeedItem;

/**
 * Resolve external taxonomy NAMES into Woo term IDs before materialize.
 * Looks at standard fields in the draft and routes them through the
 * host adapter's hive_sync/host/taxonomy/resolve filter (which the
 * Golden Hive bridge wires to get_term_by + rp_cm_create_category, so
 * missing terms get created instead of dropped).
 *
 * Fields scanned (each can be a string or string[]):
 *   - categories       → product_cat
 *   - brands           → product_brand
 *   - tags             → product_tag
 *   - pa_*             → custom attribute taxonomy named after the key
 *
 * Writes back:
 *   - category_ids[], brand_ids[], tag_ids[]
 *   - attribute_terms[ pa_xxx ] => int[]
 *
 * The string fields are KEPT (no destructive removal) so a downstream
 * materialize that prefers names over ids still works.
 *
 * params:
 *   create_missing  bool default true   delegated to the host filter
 */
final class ResolveTaxonomy implements ImportRule
{
    public const ID = 'taxonomy.resolve';

    private const NAME_TO_TAX = [
        'categories' => 'product_cat',
        'brands'     => 'product_brand',
        'tags'       => 'product_tag',
    ];
    private const ID_FIELD = [
        'product_cat'   => 'category_ids',
        'product_brand' => 'brand_ids',
        'product_tag'   => 'tag_ids',
    ];

    public function id(): string { return self::ID; }
    public function label(): string { return 'Risolvi tassonomia (categorie/brand/tag/attributi)'; }

    public function paramsSchema(): array
    {
        return [
            'create_missing' => [
                'type'    => 'bool',
                'label'   => 'Crea termini mancanti',
                'default' => true,
            ],
        ];
    }

    public function appliesTo(): array
    {
        return [ 'simple', 'variable' ];
    }

    public function apply( int $productId, array $params, OperationContext $ctx ): OperationResult
    {
        return OperationResult::failed( 'taxonomy.resolve is import-rule only; use it inside a Pipeline import phase.' );
    }

    public function applyDuringImport( FeedItem $item, array &$draft, array $params, OperationContext $ctx ): void
    {
        if ( $ctx->isDryRun() ) return;
        if ( ! function_exists( 'hsync_resolve_taxonomy' ) ) return;

        $context = [ 'sku' => $item->sku, 'create_missing' => (bool) ( $params['create_missing'] ?? true ) ];

        // Standard taxonomies (categories, brands, tags).
        foreach ( self::NAME_TO_TAX as $sourceField => $taxonomy ) {
            if ( empty( $draft[ $sourceField ] ) ) continue;
            $names = self::asNameList( $draft[ $sourceField ] );
            if ( ! $names ) continue;

            $ids = [];
            foreach ( $names as $name ) {
                $tid = \hsync_resolve_taxonomy( $taxonomy, $name, $context );
                if ( $tid !== null && $tid > 0 ) $ids[] = $tid;
            }
            if ( $ids ) {
                $idField = self::ID_FIELD[ $taxonomy ];
                $existing = (array) ( $draft[ $idField ] ?? [] );
                $draft[ $idField ] = array_values( array_unique( array_merge( $existing, $ids ) ) );
            }
        }

        // Attribute taxonomies (pa_*).
        $attributeTerms = (array) ( $draft['attribute_terms'] ?? [] );
        foreach ( $draft as $key => $value ) {
            if ( ! is_string( $key ) || ! str_starts_with( $key, 'pa_' ) ) continue;
            $names = self::asNameList( $value );
            if ( ! $names ) continue;

            $ids = [];
            foreach ( $names as $name ) {
                $tid = \hsync_resolve_taxonomy( $key, $name, $context );
                if ( $tid !== null && $tid > 0 ) $ids[] = $tid;
            }
            if ( $ids ) {
                $existing = (array) ( $attributeTerms[ $key ] ?? [] );
                $attributeTerms[ $key ] = array_values( array_unique( array_merge( $existing, $ids ) ) );
            }
        }
        if ( $attributeTerms ) {
            $draft['attribute_terms'] = $attributeTerms;
        }
    }

    /**
     * Coerce a draft value into a clean list of trimmed non-empty names.
     * Accepts string (single), string[], or pipe-separated string.
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
