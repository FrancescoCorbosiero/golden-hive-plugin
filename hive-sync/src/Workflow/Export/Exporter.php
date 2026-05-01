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
            $rows[] = [
                'id'             => $p->get_id(),
                'sku'            => $p->get_sku(),
                'name'           => $p->get_name(),
                'type'           => $p->get_type(),
                'status'         => $p->get_status(),
                'regular_price'  => (string) $p->get_regular_price(),
                'sale_price'     => (string) $p->get_sale_price(),
                'stock_status'   => $p->get_stock_status(),
                'stock_quantity' => $p->get_stock_quantity(),
                'categories'     => self::termList( $p->get_id(), 'product_cat' ),
                'brands'         => self::termList( $p->get_id(), 'product_brand' ),
                'permalink'      => $p->get_permalink(),
            ];
        }
        return $rows;
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
            foreach ( $cats as $c ) {
                foreach ( $brands as $b ) {
                    $tree[ $c ][ $b ]['category'] = $c;
                    $tree[ $c ][ $b ]['brand']    = $b;
                    $tree[ $c ][ $b ]['items'][]  = [
                        'sku'           => (string) ( $r['sku']           ?? '' ),
                        'name'          => (string) ( $r['name']          ?? '' ),
                        'regular_price' => (string) ( $r['regular_price'] ?? '' ),
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
