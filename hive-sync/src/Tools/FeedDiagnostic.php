<?php
declare(strict_types=1);

namespace HiveSync\Tools;

use HiveSync\Core\Repo\SourceConfigRepository;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\FetchRequest;
use HiveSync\Sources\JsonSource;
use HiveSync\Sources\SkuLookup;
use HiveSync\Sources\VariationLookup;

/**
 * Feed-vs-Woo diagnostic for the GS pipeline. Fetches the feed once,
 * groups flat rows by SKU exactly the way JsonSource::aggregateFlatRows
 * does, then compares each product's feed-side size set against the
 * Woo-side variation SKU set.
 *
 * Purpose: stop guessing why "not a single product is aligned with the
 * feed". The output tells the operator the truth in one screen:
 *
 *   - How many distinct SKUs the feed actually returned
 *   - How many of those exist in Woo (created on prior runs)
 *   - For each existing product: feed_sizes vs woo_variation_count
 *     and the symmetric_diff of size_eu values
 *   - Two raw rows from the upstream payload so we can see the actual
 *     shape (in case the flat endpoint differs from what the
 *     aggregator assumes)
 *
 * Read-only. No writes, no cache mutation. Safe to re-run.
 */
final class FeedDiagnostic
{
    /**
     * @return array{
     *   config_slug: string,
     *   sample_raw_rows: array<int, mixed>,
     *   feed_total_rows: int,
     *   feed_distinct_skus: int,
     *   feed_rows_without_sku: int,
     *   feed_rows_without_size: int,
     *   sample_size: int,
     *   mismatches: array<int, array{
     *     sku:string,
     *     feed_sizes:int,
     *     woo_variations:int,
     *     gap:int,
     *     missing_in_woo:array<int,string>,
     *     extra_in_woo:array<int,string>,
     *   }>,
     *   perfect: int,
     *   missing_in_woo_total: int,
     *   extra_in_woo_total: int,
     *   not_in_woo: int,
     * }|array{error:string}
     */
    public static function run( string $configSlug, int $sampleSize = 50 ): array
    {
        $repo = new SourceConfigRepository();
        $cfg  = $repo->find( $configSlug );
        if ( ! $cfg ) {
            return [ 'error' => "Config '{$configSlug}' non trovata." ];
        }
        if ( ( $cfg['source_kind'] ?? '' ) !== 'json' ) {
            return [ 'error' => "Config '{$configSlug}' non è di tipo json." ];
        }

        // Hit the upstream the same way JsonSource does. Bypass diff +
        // pipeline + materialize — we only need raw fetched rows.
        [ $rawRows, $rawHttpError ] = self::rawFetch( $cfg['config'] ?? [] );
        if ( $rawHttpError !== null ) {
            return [ 'error' => $rawHttpError ];
        }

        // Two raw rows as sample so we can verify the shape the
        // aggregator assumes ( top-level r['sku'], r['size_eu'] )
        // matches what GS actually sends back.
        $sampleRaw = array_slice( $rawRows, 0, 2 );

        // Group by SKU exactly like aggregateFlatRows. Count rows that
        // would be silently dropped (no sku, no size_eu) so the
        // operator can see whether the feed itself is malformed.
        $bySku                = [];
        $rowsWithoutSku       = 0;
        $rowsWithoutSize      = 0;
        $totalRows            = count( $rawRows );

        foreach ( $rawRows as $r ) {
            if ( ! is_array( $r ) ) continue;
            $sku = (string) ( $r['sku'] ?? '' );
            if ( $sku === '' ) { $rowsWithoutSku++; continue; }

            if ( ! isset( $bySku[ $sku ] ) ) {
                $bySku[ $sku ] = [];
            }
            $sizeEu = trim( (string) ( $r['size_eu'] ?? '' ) );
            if ( $sizeEu === '' ) { $rowsWithoutSize++; continue; }
            $bySku[ $sku ][ $sizeEu ] = true; // dedupe per-product
        }

        // Sample first N distinct SKUs in feed order (stable for the
        // operator: same input → same sample).
        $allSkus    = array_keys( $bySku );
        $sampleSkus = array_slice( $allSkus, 0, max( 1, $sampleSize ) );

        // Batch-resolve Woo PIDs + variations. Two queries, regardless
        // of sample size.
        $skuToPid = SkuLookup::mapSkusToIds( $sampleSkus );
        $pids     = array_values( array_filter( array_map( fn( $s ) => (int) ( $skuToPid[ $s ] ?? 0 ), $sampleSkus ) ) );
        $varMap   = VariationLookup::mapParentsToVariations( $pids );

        $mismatches            = [];
        $perfect               = 0;
        $missingInWooTotal     = 0;
        $extraInWooTotal       = 0;
        $notInWoo              = 0;

        foreach ( $sampleSkus as $sku ) {
            $feedSizes = array_keys( $bySku[ $sku ] );
            $pid       = (int) ( $skuToPid[ $sku ] ?? 0 );
            if ( $pid === 0 ) {
                $notInWoo++;
                continue;
            }

            // Woo variation SKUs → derive size_eu by stripping the
            // "{parent_sku}-EU" prefix. The GS transform builds the
            // child SKU as "{sku}-EU{size_eu}" so the inverse is a
            // straightforward suffix split.
            $wooSizes = [];
            foreach ( array_keys( $varMap[ $pid ] ?? [] ) as $vsku ) {
                if ( str_starts_with( $vsku, $sku . '-EU' ) ) {
                    $wooSizes[] = substr( $vsku, strlen( $sku ) + 3 );
                } else {
                    $wooSizes[] = '?(' . $vsku . ')';
                }
            }

            $missing = array_values( array_diff( $feedSizes, $wooSizes ) );
            $extra   = array_values( array_diff( $wooSizes, $feedSizes ) );

            if ( empty( $missing ) && empty( $extra ) ) {
                $perfect++;
                continue;
            }
            $missingInWooTotal += count( $missing );
            $extraInWooTotal   += count( $extra );
            $mismatches[] = [
                'sku'             => $sku,
                'feed_sizes'      => count( $feedSizes ),
                'woo_variations'  => count( $wooSizes ),
                'gap'             => count( $missing ) - count( $extra ),
                'missing_in_woo'  => array_slice( $missing, 0, 15 ),
                'extra_in_woo'    => array_slice( $extra,   0, 15 ),
            ];
        }

        // Sort by largest gap first — operator wants to see the worst
        // offenders at the top.
        usort( $mismatches, fn( $a, $b ) => abs( $b['gap'] ) <=> abs( $a['gap'] ) );

        return [
            'config_slug'           => $configSlug,
            'sample_raw_rows'       => $sampleRaw,
            'feed_total_rows'       => $totalRows,
            'feed_distinct_skus'    => count( $bySku ),
            'feed_rows_without_sku' => $rowsWithoutSku,
            'feed_rows_without_size' => $rowsWithoutSize,
            'sample_size'           => count( $sampleSkus ),
            'mismatches'            => array_slice( $mismatches, 0, 25 ),
            'perfect'               => $perfect,
            'missing_in_woo_total'  => $missingInWooTotal,
            'extra_in_woo_total'    => $extraInWooTotal,
            'not_in_woo'            => $notInWoo,
        ];
    }

    /**
     * Single HTTP GET — mirrors JsonSource::fetch's body-read step but
     * returns the rawRows array directly (no FeedItem hydration).
     *
     * @param array<string, mixed> $config
     * @return array{0: array<int, mixed>, 1: ?string}  [rows, errorOrNull]
     */
    private static function rawFetch( array $config ): array
    {
        $url    = (string) ( $config['url']    ?? '' );
        $token  = (string) ( $config['token']  ?? '' );
        $cookie = (string) ( $config['cookie'] ?? '' );
        if ( $url === '' ) return [ [], 'URL mancante nella config.' ];

        $headers = [ 'Accept' => 'application/json' ];
        if ( $token  !== '' ) $headers['Authorization'] = 'Bearer ' . $token;
        if ( $cookie !== '' ) $headers['Cookie']        = $cookie;

        $resp = \wp_remote_get( $url, [
            'timeout'             => 120,
            'redirection'         => 5,
            'limit_response_size' => 0,
            'headers'             => $headers,
            'user-agent'          => 'HiveSync-Diagnostic/1.0',
        ] );
        if ( \is_wp_error( $resp ) ) {
            return [ [], 'HTTP error: ' . $resp->get_error_message() ];
        }
        $code = (int) \wp_remote_retrieve_response_code( $resp );
        $body = (string) \wp_remote_retrieve_body( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return [ [], "HTTP {$code} dall'upstream" ];
        }
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return [ [], 'Risposta non è JSON valido.' ];
        }
        $rows = $decoded;
        foreach ( [ 'data', 'items', 'results', 'products' ] as $k ) {
            if ( isset( $rows[ $k ] ) && is_array( $rows[ $k ] ) ) {
                $rows = $rows[ $k ];
                break;
            }
        }
        return [ is_array( $rows ) ? $rows : [], null ];
    }
}
