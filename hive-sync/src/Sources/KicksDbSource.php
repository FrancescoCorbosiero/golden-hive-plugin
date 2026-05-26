<?php
declare(strict_types=1);

namespace HiveSync\Sources;

use HiveSync\Core\Repo\KicksDbCacheRepository;
use HiveSync\Core\Source\AbstractSource;
use HiveSync\Core\Source\Context;
use HiveSync\Core\Source\Diff;
use HiveSync\Core\Source\FeedItem;
use HiveSync\Core\Source\FetchRequest;
use HiveSync\Core\Source\FetchResult;
use HiveSync\Core\Source\MaterializeResult;
use HiveSync\Core\Source\SourceCapabilities;
use HiveSync\KicksDb\Client;
use HiveSync\KicksDb\Enricher;
use HiveSync\KicksDb\MarkupCalculator;

/**
 * Standalone Source that pulls products straight from KicksDB. Use
 * from the Importa tab (or a scheduled job) when you want to discover
 * products beyond what GS carries.
 *
 * Two discovery modes:
 *   - `skus`  config — explicit list of style codes to fetch.
 *   - `query` config — free-text search, paginated.
 *
 * Each fetched item is fully enriched via the same Enricher used by
 * the EnrichWithKicksDb ImportRule, so downstream pipeline + materialize
 * see exactly the same shape — variants with synthetic stock, market
 * prices through the tiered markup, VAT applied.
 *
 * Materialize uses the GS bridge (hsync_gs_materialize) because the
 * resulting data shape is the same: variable product with pa_taglia +
 * variations array. The bridge doesn't care where the data came from.
 */
final class KicksDbSource extends AbstractSource
{
    public const ID = 'kicksdb';

    public function id(): string    { return self::ID; }
    public function label(): string { return 'KicksDB — StockX product database'; }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            canFetch: true,
            canDiff: true,
            canMaterialize: true,
            canSelectLocal: false,
            supportsQuickPatch: false,
            supportsImageSideload: true,
        );
    }

    public function configSchema(): array
    {
        return [
            'api_key' => [
                'type' => 'secret', 'label' => 'API key KicksDB',
                'required' => true, 'max' => 512,
            ],
            'base_url' => [
                'type' => 'url', 'label' => 'Base URL API',
                'default' => 'https://api.kicks.dev/v3',
                'max' => 512,
            ],
            'market' => [
                'type' => 'enum', 'label' => 'Mercato (regional pricing)',
                'options' => [ 'IT', 'EU', 'US', 'UK' ],
                'default' => 'IT',
                'description' => 'Influenza i prezzi di mercato restituiti dall\'API.',
            ],
            'discovery_mode' => [
                'type' => 'enum', 'label' => 'Modalità discovery',
                'options' => [ 'skus', 'query' ],
                'option_labels' => [
                    'skus'  => 'Lista esplicita di style code (uno per riga)',
                    'query' => 'Ricerca per parola chiave',
                ],
                'default' => 'query',
            ],
            'skus' => [
                'type' => 'text', 'label' => 'SKUs (uno per riga, o separati da virgola)',
                'description' => 'Solo per la modalità "Lista esplicita". Es. DH5532-100, 555088-063',
            ],
            'query' => [
                'type' => 'text', 'label' => 'Query di ricerca',
                'description' => 'Solo per la modalità "Ricerca". Es. "jordan retro", "nike dunk low"',
            ],
            'limit' => [
                'type' => 'int', 'label' => 'Numero max di prodotti per esecuzione',
                'default' => 50,
                'description' => 'Tieni basso (50) per evitare di esaurire il budget API in un solo run. Esecuzioni successive aggiungono altri prodotti grazie al diff incrementale.',
            ],
            'cache_ttl' => [
                'type' => 'int', 'label' => 'TTL cache KicksDB (secondi)',
                'default' => 86400,
                'description' => 'Default 86400 = 24h. Riduci se vuoi prezzi più freschi a costo di più chiamate API.',
            ],
            'vat_percent' => [
                'type' => 'int', 'label' => 'IVA % da aggiungere ai prezzi KicksDB',
                'default' => 22,
                'description' => 'Imposta 0 se l\'endpoint KicksDB del tuo mercato restituisce già prezzi IVA-inclusi.',
            ],
        ];
    }

    public function fetch( FetchRequest $request, Context $ctx ): FetchResult
    {
        $config = $request->config;
        $apiKey = (string) ( $config['api_key'] ?? '' );
        if ( $apiKey === '' ) {
            return new FetchResult( items: [], warnings: [ 'KicksDB API key non configurata.' ] );
        }

        $market   = (string) ( $config['market'] ?? 'IT' );
        $cacheTtl = (int) ( $config['cache_ttl'] ?? 86400 );

        $client = new Client(
            apiKey:  $apiKey,
            baseUrl: (string) ( $config['base_url'] ?? 'https://api.kicks.dev/v3' ),
            timeout: 30,
        );

        $markupCfg = (array) ( $config['markup'] ?? [] );
        $markupCfg['vat_percent'] = (float) ( $config['vat_percent']
            ?? $markupCfg['vat_percent']
            ?? 22.0 );
        $calc = MarkupCalculator::fromConfig( $markupCfg );

        $cache    = new KicksDbCacheRepository();
        $enricher = new Enricher(
            client:   $client,
            cache:    $cache,
            calc:     $calc,
            market:   $market,
            cacheTtl: $cacheTtl,
        );

        $limit    = max( 1, (int) ( $config['limit'] ?? 50 ) );
        $mode     = (string) ( $config['discovery_mode'] ?? 'query' );
        $warnings = [];
        $payloads = [];

        if ( $mode === 'skus' ) {
            $skus = self::parseSkuList( (string) ( $config['skus'] ?? '' ) );
            if ( ! $skus ) {
                return new FetchResult( items: [], warnings: [ 'Nessuno SKU specificato.' ] );
            }
            if ( count( $skus ) > $limit ) $skus = array_slice( $skus, 0, $limit );
            foreach ( $skus as $sku ) {
                $p = $enricher->lookup( $sku );
                if ( $p !== null && empty( $p['_miss'] ) ) {
                    $payloads[ $sku ] = $p;
                }
            }
        } else {
            $query = trim( (string) ( $config['query'] ?? '' ) );
            if ( $query === '' ) {
                return new FetchResult( items: [], warnings: [ 'Query di ricerca vuota.' ] );
            }
            $page = 1;
            $perPage = min( 50, $limit );
            while ( count( $payloads ) < $limit ) {
                $hits = $client->search( $query, $page, $perPage );
                if ( ! $hits ) break;
                foreach ( $hits as $hit ) {
                    if ( count( $payloads ) >= $limit ) break;
                    $sku = (string) ( $hit['sku'] ?? $hit['style_id'] ?? '' );
                    if ( $sku === '' || isset( $payloads[ $sku ] ) ) continue;
                    // Warm the cache with the search hit so a subsequent
                    // per-sku lookup skips the API roundtrip entirely.
                    $cache->put( $sku, $market, $hit, $cacheTtl );
                    $payloads[ $sku ] = $hit;
                }
                if ( count( $hits ) < $perPage ) break;
                $page++;
            }
            if ( $page > 1 && count( $payloads ) < $limit ) {
                $warnings[] = 'Esaurita la ricerca su "' . $query . '" dopo ' . count( $payloads ) . ' risultati.';
            }
        }

        $items = [];
        foreach ( $payloads as $sku => $payload ) {
            // Empty draft + KicksDB merge = a fully formed item ready
            // for the bridge. We tag _hsync_flavor=goldensneakers so
            // the materialize path routes to the GS bridge (same shape).
            $draft = [
                'sku'             => (string) $sku,
                '_hsync_flavor'   => 'goldensneakers',
                '_kicksdb_origin' => true,
            ];
            $draft   = $enricher->merge( $draft, $payload );
            $items[] = new FeedItem( sku: (string) $sku, data: $draft, raw: $payload );
        }

        return new FetchResult(
            items: $items,
            stats: [ 'fetched' => count( $items ), 'kicksdb_cache_count' => $cache->count() ],
            warnings: $warnings,
        );
    }

    public function diff( array $items, Context $ctx ): Diff
    {
        // Same shape as GS — batch SKU→pid lookup, then route through
        // StockOnlyClassifier::split for the 3-way verdict. The
        // classifier reads pre-loaded parent scalars (ParentScalarLookup)
        // so re-syncs of large catalogs route correctly to unchanged /
        // updateStock instead of dumping into updateFull.
        $new = $update = [];
        $skus = [];
        foreach ( $items as $item ) {
            if ( $item instanceof FeedItem && $item->sku !== '' ) $skus[] = $item->sku;
        }
        $existing = SkuLookup::mapSkusToIds( $skus );
        foreach ( $items as $item ) {
            if ( ! $item instanceof FeedItem ) continue;
            if ( $item->sku === '' ) { $new[] = $item; continue; }
            $pid = (int) ( $existing[ $item->sku ] ?? 0 );
            if ( $pid > 0 ) {
                $update[] = new FeedItem(
                    sku:  $item->sku,
                    data: $item->data + [ '_existing_id' => $pid ],
                    raw:  $item->raw,
                );
            } else {
                $new[] = $item;
            }
        }
        [ $updateFull, $updateStock, $unchanged ] = StockOnlyClassifier::split( $update, $ctx );
        return new Diff(
            new: $new,
            update: $updateFull,
            unchanged: $unchanged,
            updateStock: $updateStock,
        );
    }

    public function materialize( FeedItem $item, Context $ctx ): MaterializeResult
    {
        if ( $ctx->dryRun ) {
            return MaterializeResult::skipped( null, 'dry_run' );
        }
        // Same Woo-shape as the GS flavor (variable + pa_taglia +
        // variations[]), so the GS bridge handles materialize.
        if ( ! function_exists( 'hsync_gs_materialize' ) ) {
            return MaterializeResult::failed( 'Bridge GS non disponibile per KicksDB materialize.' );
        }
        $sideload = (bool) ( $ctx->meta['sideload'] ?? true );
        try {
            $r = \hsync_gs_materialize( $item->data, false, $sideload );
        } catch ( \Throwable $e ) {
            return MaterializeResult::failed( $e->getMessage() );
        }
        if ( is_int( $r ) && $r > 0 ) {
            return MaterializeResult::updated( $r );
        }
        if ( is_array( $r ) && ! empty( $r['id'] ) ) {
            $action = (string) ( $r['action'] ?? 'updated' );
            $pid    = (int) $r['id'];
            return match ( $action ) {
                'created'   => MaterializeResult::created( $pid ),
                'recreated' => MaterializeResult::recreated( $pid ),
                default     => MaterializeResult::updated( $pid ),
            };
        }
        return MaterializeResult::failed( 'gs_bridge_unexpected_response' );
    }

    private static function parseSkuList( string $raw ): array
    {
        $out = [];
        foreach ( preg_split( '/[\r\n,]+/', $raw ) ?: [] as $line ) {
            $line = trim( (string) $line );
            if ( $line !== '' && ! in_array( $line, $out, true ) ) $out[] = $line;
        }
        return $out;
    }
}
