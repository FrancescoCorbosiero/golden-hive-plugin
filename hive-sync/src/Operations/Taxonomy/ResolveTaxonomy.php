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
 * Hive Commerce bridge wires to get_term_by + rp_cm_create_category, so
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

    /**
     * Request-static memo: (create_missing|taxonomy|name) → term_id|null.
     *
     * Il value-space di un feed è minuscolo e quasi costante (taglie
     * 36-48, ~50 brand, 2-3 categorie) ma il resolver veniva invocato
     * per OGNI item: 10k item × ~18 nomi ≈ 180.000 dispatch
     * apply_filters → get_term_by per ~300-600 valori distinti. Stesso
     * pattern già usato da ensureGlobalAttribute qui sotto. Anche i
     * null (nome irrisolvibile) sono cachati — senza, ogni item ripaga
     * il tentativo di create fallito.
     *
     * @var array<string, int|null>
     */
    private static array $termMemo = [];

    private static function resolveTermId( string $taxonomy, string $name, array $context ): ?int
    {
        $key = ( empty( $context['create_missing'] ) ? '0' : '1' ) . '|' . $taxonomy . '|' . $name;
        if ( ! array_key_exists( $key, self::$termMemo ) ) {
            self::$termMemo[ $key ] = \hsync_resolve_taxonomy( $taxonomy, $name, $context );
        }
        return self::$termMemo[ $key ];
    }

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
                $tid = self::resolveTermId( $taxonomy, $name, $context );
                if ( $tid !== null && $tid > 0 ) $ids[] = $tid;
            }
            if ( $ids ) {
                $idField = self::ID_FIELD[ $taxonomy ];
                $existing = (array) ( $draft[ $idField ] ?? [] );
                $draft[ $idField ] = array_values( array_unique( array_merge( $existing, $ids ) ) );
            }
        }

        // Attribute taxonomies (pa_*). Two sources of pa_* candidates:
        //  - top-level scalars (e.g. `pa_brand`, `pa_model`, `pa_gender`)
        //  - the attributes block ($draft['attributes'][pa_*]['options'])
        //    populated by AttributeMerger and the source transforms.
        // We union both so the user sees consistent term resolution
        // regardless of where the value entered the draft.
        $attributeTerms = (array) ( $draft['attribute_terms'] ?? [] );
        $candidates = [];
        foreach ( $draft as $key => $value ) {
            if ( ! is_string( $key ) || ! str_starts_with( $key, 'pa_' ) ) continue;
            $candidates[ $key ] = self::asNameList( $value );
        }
        foreach ( (array) ( $draft['attributes'] ?? [] ) as $taxKey => $attrConfig ) {
            if ( ! is_string( $taxKey ) || ! str_starts_with( $taxKey, 'pa_' ) ) continue;
            if ( ! is_array( $attrConfig ) ) continue;
            $opts = self::asNameList( $attrConfig['options'] ?? [] );
            if ( ! $opts ) continue;
            $candidates[ $taxKey ] = array_values( array_unique( array_merge( $candidates[ $taxKey ] ?? [], $opts ) ) );
        }

        $createMissing = (bool) ( $params['create_missing'] ?? true );
        foreach ( $candidates as $taxKey => $names ) {
            if ( ! $names ) continue;
            // Ensure the global attribute taxonomy exists in Woo before
            // resolving terms — without this, brand-new pa_* taxonomies
            // declared by a mapping would silently drop their terms.
            if ( $createMissing ) {
                self::ensureGlobalAttribute( $taxKey );
            }

            $ids = [];
            foreach ( $names as $name ) {
                $tid = self::resolveTermId( $taxKey, $name, $context );
                if ( $tid !== null && $tid > 0 ) $ids[] = $tid;
            }
            if ( $ids ) {
                $existing = (array) ( $attributeTerms[ $taxKey ] ?? [] );
                $attributeTerms[ $taxKey ] = array_values( array_unique( array_merge( $existing, $ids ) ) );
            }
        }
        if ( $attributeTerms ) {
            $draft['attribute_terms'] = $attributeTerms;
        }
    }

    /**
     * Make sure the global Woo attribute taxonomy `pa_<slug>` exists.
     * Idempotent: a no-op when the taxonomy is already registered.
     * When Woo creates a fresh attribute the term insert/lookup that
     * follows would fail with "invalid taxonomy" until the next
     * request, so we also call register_taxonomy() inline.
     */
    private static function ensureGlobalAttribute( string $taxonomy ): void
    {
        static $created = [];
        if ( isset( $created[ $taxonomy ] ) ) return;
        if ( ! str_starts_with( $taxonomy, 'pa_' ) ) return;
        if ( function_exists( 'taxonomy_exists' ) && \taxonomy_exists( $taxonomy ) ) {
            $created[ $taxonomy ] = true;
            return;
        }
        if ( ! function_exists( 'wc_create_attribute' ) ) return;

        $slug = substr( $taxonomy, 3 );
        $label = ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );
        $result = \wc_create_attribute( [
            'name'         => $label,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ] );
        if ( is_wp_error( $result ) ) return;

        // Woo registers the taxonomy on the next 'init', but the
        // import is happening mid-request — register it now so the
        // very next term_exists() / wp_insert_term() call works.
        if ( function_exists( 'register_taxonomy' ) && ! \taxonomy_exists( $taxonomy ) ) {
            \register_taxonomy( $taxonomy, [ 'product' ], [
                'hierarchical' => false,
                'label'        => $label,
                'public'       => true,
                'rewrite'      => false,
            ] );
        }
        $created[ $taxonomy ] = true;
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
