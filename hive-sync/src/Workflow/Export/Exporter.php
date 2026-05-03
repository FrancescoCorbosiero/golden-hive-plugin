<?php
declare(strict_types=1);

namespace HiveSync\Workflow\Export;

/**
 * Catalog exports — pure WC public API, no Golden Hive dependency.
 *
 * Two payload shapes:
 *
 *   inventoryRows()   Flat one-row-per-product list with id, sku, name,
 *                     status, type, prices, stock, taxonomies. Suitable
 *                     for CSV or JSON output.
 *
 *   catalogTree()     Hierarchical grouping product_cat → product_brand
 *                     → [items], with minimal per-product fields. The
 *                     "ordered and grouped by taxonomy" view from the
 *                     original brief.
 *
 * For very large catalogs (>10k products) the AJAX path will hit
 * memory/time caps; phase 5 can add streaming via admin-post.php.
 * Today's defaults are conservative — limit + per_page in the query.
 */
final class Exporter
{
    /**
     * @param int $limit  Hard upper bound to avoid runaway memory.
     *                    -1 means "all" (use carefully).
     * @return array<int, array<string, mixed>>
     */
    public function inventoryRows( int $limit = 5000 ): array
    {
        if ( ! function_exists( 'wc_get_products' ) ) return [];

        $args = [
            'status'  => [ 'publish', 'private', 'draft' ],
            'limit'   => $limit,
            'orderby' => 'id',
            'order'   => 'ASC',
            'return'  => 'objects',
        ];
        $products = \wc_get_products( $args );
        $rows = [];
        foreach ( (array) $products as $p ) {
            if ( ! $p instanceof \WC_Product ) continue;

            // Variable products keep `regular_price` / `sale_price` /
            // `stock_quantity` empty on the parent — the real values
            // live on the variations. The exporter coalesces:
            //
            //   - regular_price → MIN of variation regular_prices, else
            //                     parent regular_price (simple)
            //   - sale_price    → MIN of variation sale_prices when any
            //                     variation has a sale, else parent
            //   - stock_quantity → SUM of variation stock_quantity, else
            //                      parent
            //
            // For large variable catalogs this means N+M product loads
            // per call; that's still cheap because WC's variation
            // children loader is index-backed.
            $isVariable = $p->is_type( 'variable' );
            $priceRange = $isVariable ? self::aggregateVariablePricing( $p ) : null;

            $rows[] = [
                'id'             => $p->get_id(),
                'sku'            => $p->get_sku(),
                'name'           => $p->get_name(),
                'type'           => $p->get_type(),
                'status'         => $p->get_status(),
                'regular_price'  => $priceRange
                    ? (string) ( $priceRange['regular_min'] ?? '' )
                    : (string) $p->get_regular_price(),
                'regular_price_max' => $priceRange
                    ? (string) ( $priceRange['regular_max'] ?? '' )
                    : '',
                'sale_price'     => $priceRange
                    ? (string) ( $priceRange['sale_min'] ?? '' )
                    : (string) $p->get_sale_price(),
                'stock_status'   => $p->get_stock_status(),
                'stock_quantity' => $priceRange
                    ? (int) ( $priceRange['stock_total'] ?? 0 )
                    : $p->get_stock_quantity(),
                'variant_count'  => $priceRange
                    ? (int) ( $priceRange['variant_count'] ?? 0 )
                    : 0,
                'categories'     => self::termList( $p->get_id(), 'product_cat' ),
                'brands'         => self::termList( $p->get_id(), 'product_brand' ),
                'permalink'      => $p->get_permalink(),
            ];
        }
        return $rows;
    }

    /**
     * Coalesce price + stock from variations of a variable product.
     * Returns regular_min / regular_max / sale_min / stock_total /
     * variant_count, all derived from the live variation children.
     *
     * Empty sale prices are excluded from sale_min so we don't end up
     * with sale_min = 0 when half the variations don't have a sale.
     *
     * @return array{regular_min:?float, regular_max:?float, sale_min:?float, stock_total:int, variant_count:int}
     */
    private static function aggregateVariablePricing( \WC_Product $parent ): array
    {
        $childIds = $parent->get_children(); // array of variation post IDs
        $regular  = [];
        $sale     = [];
        $stock    = 0;
        $count    = 0;
        foreach ( $childIds as $vid ) {
            $v = \wc_get_product( (int) $vid );
            if ( ! $v ) continue;
            $count++;
            $rp = $v->get_regular_price();
            $sp = $v->get_sale_price();
            if ( $rp !== '' && $rp !== null ) $regular[] = (float) $rp;
            if ( $sp !== '' && $sp !== null ) $sale[]    = (float) $sp;
            if ( $v->get_manage_stock() ) {
                $q = $v->get_stock_quantity();
                if ( $q !== null ) $stock += (int) $q;
            }
        }
        return [
            'regular_min'    => $regular ? min( $regular ) : null,
            'regular_max'    => $regular ? max( $regular ) : null,
            'sale_min'       => $sale    ? min( $sale )    : null,
            'stock_total'    => $stock,
            'variant_count'  => $count,
        ];
    }

    /**
     * Catalog grouped by category → brand → items.
     *
     * @return array<string, array<string, array{
     *   category: string,
     *   brand: string,
     *   items: array<int, array{sku:string,name:string,regular_price:string}>,
     * }>>
     */
    public function catalogTree( int $limit = 10000 ): array
    {
        return self::catalogTreeFromRows( $this->inventoryRows( $limit ) );
    }

    /**
     * Pure transformation: rows → category→brand→items tree. Extracted
     * static so tests don't need WC.
     */
    public static function catalogTreeFromRows( array $rows ): array
    {
        $tree = [];
        foreach ( $rows as $r ) {
            $cats   = ( $r['categories'] ?? '' ) !== '' ? explode( '|', (string) $r['categories'] ) : [ '(no-category)' ];
            $brands = ( $r['brands']     ?? '' ) !== '' ? explode( '|', (string) $r['brands']     ) : [ '(no-brand)' ];

            $regular = (string) ( $r['regular_price'] ?? '' );
            $maxReg  = (string) ( $r['regular_price_max'] ?? '' );
            $priceLabel = $regular === ''
                ? ''
                : ( ( $maxReg !== '' && $maxReg !== $regular ) ? $regular . ' – ' . $maxReg : $regular );

            foreach ( $cats as $c ) {
                foreach ( $brands as $b ) {
                    $tree[ $c ][ $b ]['category'] = $c;
                    $tree[ $c ][ $b ]['brand']    = $b;
                    $tree[ $c ][ $b ]['items'][]  = [
                        'sku'            => (string) ( $r['sku']           ?? '' ),
                        'name'           => (string) ( $r['name']          ?? '' ),
                        'regular_price'  => $priceLabel,
                        'sale_price'     => (string) ( $r['sale_price']    ?? '' ),
                        'stock_quantity' => (int)    ( $r['stock_quantity'] ?? 0 ),
                        'stock_status'   => (string) ( $r['stock_status']  ?? '' ),
                        'variant_count'  => (int)    ( $r['variant_count'] ?? 0 ),
                    ];
                }
            }
        }
        ksort( $tree );
        foreach ( $tree as &$brands ) ksort( $brands );
        unset( $brands );
        return $tree;
    }

    /**
     * Render rows as RFC 4180 CSV. Header row uses the row keys.
     */
    public static function rowsToCsv( array $rows ): string
    {
        if ( ! $rows ) return '';
        $out = fopen( 'php://temp', 'r+' );
        $header = array_keys( $rows[0] );
        fputcsv( $out, $header, ',', '"', '\\' );
        foreach ( $rows as $r ) {
            $line = [];
            foreach ( $header as $k ) $line[] = isset( $r[ $k ] ) ? (string) $r[ $k ] : '';
            fputcsv( $out, $line, ',', '"', '\\' );
        }
        rewind( $out );
        $csv = (string) stream_get_contents( $out );
        fclose( $out );
        return $csv;
    }

    private static function termList( int $productId, string $taxonomy ): string
    {
        if ( ! function_exists( 'wp_get_post_terms' ) ) return '';
        $terms = \wp_get_post_terms( $productId, $taxonomy, [ 'fields' => 'names' ] );
        if ( function_exists( 'is_wp_error' ) && \is_wp_error( $terms ) ) return '';
        return implode( '|', array_map( 'strval', (array) $terms ) );
    }
}
