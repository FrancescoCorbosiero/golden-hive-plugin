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
 * Three discovery modes:
 *   - `skus`         — explicit list of style codes to fetch.
 *   - `query`        — free-text search, paginated.
 *   - `woo_catalog`  — walks pre-existing Woo products (post_type=
 *                     product), looks each SKU up on KicksDB. Solves
 *                     the "I had products before hive-sync — how do I
 *                     backfill?" problem. Cursor-paginated via a
 *                     wp_options row so it self-progresses across
 *                     cron ticks without re-scanning processed IDs.
 *                     Update mode (`preserve` vs `overwrite`) decides
 *                     whether hand-curated descriptions/galleries on
 *                     existing products are kept or replaced.
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
                'options' => [ 'woo_catalog', 'skus', 'query' ],
                'option_labels' => [
                    'woo_catalog' => 'Catalogo Woo esistente — backfill prodotti già in inventario',
                    'skus'        => 'Lista esplicita di style code (uno per riga)',
                    'query'       => 'Ricerca per parola chiave',
                ],
                'default' => 'woo_catalog',
                'description' => '"Catalogo Woo" è la modalità giusta per la prima sincronizzazione: cammina i prodotti già in Woo (anche quelli importati prima di hive-sync) e cerca ogni SKU su KicksDB. "Lista esplicita" e "Ricerca" servono per aggiungere prodotti NUOVI non ancora in Woo.',
            ],
            'skus' => [
                'type' => 'text', 'label' => 'SKUs (uno per riga, o separati da virgola)',
                'description' => 'Solo per la modalità "Lista esplicita". Es. DH5532-100, 555088-063',
            ],
            'query' => [
                'type' => 'text', 'label' => 'Query di ricerca',
                'description' => 'Solo per la modalità "Ricerca". Es. "jordan retro", "nike dunk low"',
            ],
            'sku_pattern' => [
                'type' => 'text', 'label' => 'Filtro SKU (regex, opzionale)',
                'description' => 'Solo per "Catalogo Woo". Esempio: /^[A-Z0-9]{4,}-[0-9]{3}$/ per restringere a style code Nike (DH5532-100). Vuoto = tutti gli SKU del catalogo.',
            ],
            'update_mode' => [
                'type' => 'enum', 'label' => 'Come trattare i prodotti Woo esistenti',
                'options' => [ 'preserve', 'overwrite' ],
                'option_labels' => [
                    'preserve'  => 'Preserva — mantieni descrizioni/galleria curate, aggiorna solo prezzi + varianti',
                    'overwrite' => 'Sovrascrivi — KicksDB rimpiazza tutto (descrizione, immagini, varianti)',
                ],
                'default' => 'preserve',
                'description' => 'Solo per "Catalogo Woo". "Preserva" è sicuro: i campi non vuoti del prodotto Woo restano intatti, KicksDB aggiunge solo dati mancanti + aggiorna sempre prezzi/varianti/stock. "Sovrascrivi" tratta il prodotto come fosse un import fresco — utile per ri-fare prodotti con dati scadenti, distruttivo per quelli curati a mano.',
            ],
            'limit' => [
                'type' => 'int', 'label' => 'Numero max di prodotti per esecuzione',
                'default' => 50,
                'description' => 'Tieni basso (50) per evitare di esaurire il budget API in un solo run. Esecuzioni successive avanzano il cursor automaticamente.',
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

        $limit      = max( 1, (int) ( $config['limit'] ?? 50 ) );
        $mode       = (string) ( $config['discovery_mode'] ?? 'woo_catalog' );
        $updateMode = (string) ( $config['update_mode'] ?? 'preserve' );
        $warnings   = [];
        $items      = [];

        if ( $mode === 'woo_catalog' ) {
            return $this->fetchFromWooCatalog(
                config:     $config,
                enricher:   $enricher,
                market:     $market,
                limit:      $limit,
                updateMode: $updateMode,
                cacheCount: $cache->count(),
            );
        }

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

        foreach ( $payloads as $sku => $payload ) {
            // Empty draft + KicksDB merge = a fully formed item ready
            // for the bridge. We tag _hsync_flavor=goldensneakers so
            // the materialize path routes to the GS bridge (same shape).
            $draft = [
                'sku'             => (string) $sku,
                '_hsync_flavor'   => 'goldensneakers',
                '_kicksdb_origin' => true,
            ];
            $draft   = $enricher->merge( $draft, $payload, 'overwrite' );
            $items[] = new FeedItem( sku: (string) $sku, data: $draft, raw: $payload );
        }

        return new FetchResult(
            items: $items,
            stats: [ 'fetched' => count( $items ), 'kicksdb_cache_count' => $cache->count() ],
            warnings: $warnings,
        );
    }

    /**
     * Walk pre-existing Woo products by SKU and enrich them with
     * KicksDB data. Cursor-paginated: each run picks up where the last
     * one stopped, so a large catalog (5-50k SKUs) drains across many
     * cron ticks without re-scanning. When the cursor hits the end of
     * the catalog it auto-resets to 0 — the operator can re-run for a
     * full re-validation pass (cache hits make subsequent passes fast).
     *
     * For each SKU matched in KicksDB, builds a FeedItem; the source's
     * diff routes it to update / updateStock / unchanged exactly as if
     * it had come from GS. Materialize uses the same GS bridge.
     */
    private function fetchFromWooCatalog(
        array $config,
        Enricher $enricher,
        string $market,
        int $limit,
        string $updateMode,
        int $cacheCount,
    ): FetchResult {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return new FetchResult( items: [], warnings: [ 'WordPress DB non disponibile.' ] );
        }

        $skuPattern = trim( (string) ( $config['sku_pattern'] ?? '' ) );
        $cursorKey  = 'hsync_kicksdb_catalog_cursor_'
                    . substr( md5( $market . '|' . $skuPattern ), 0, 12 );
        $cursor     = (int) get_option( $cursorKey, 0 );

        // Over-fetch to leave room for SKU-pattern filtering + KicksDB
        // misses; we still emit at most $limit FeedItems.
        $batchSize = max( $limit * 3, 200 );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm.meta_value AS sku
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND p.ID > %d
               AND pm.meta_value != ''
             ORDER BY p.ID ASC
             LIMIT %d",
            $cursor,
            $batchSize,
        ), ARRAY_A );

        if ( ! is_array( $rows ) || ! $rows ) {
            update_option( $cursorKey, 0, false );  // auto-reset for next run
            return new FetchResult(
                items: [],
                stats: [ 'cursor' => $cursor, 'kicksdb_cache_count' => $cacheCount ],
                warnings: [ 'Catalogo Woo esaurito (cursor: ' . $cursor . '). Resettato a 0 per il prossimo run.' ],
            );
        }

        $items    = [];
        $maxId    = $cursor;
        $scanned  = 0;
        $matched  = 0;
        $filtered = 0;
        $missed   = 0;

        foreach ( $rows as $row ) {
            $maxId = max( $maxId, (int) $row['ID'] );
            if ( count( $items ) >= $limit ) break;
            $scanned++;

            $sku = (string) $row['sku'];
            if ( $sku === '' ) continue;
            if ( $skuPattern !== '' && @preg_match( $skuPattern, $sku ) !== 1 ) {
                $filtered++;
                continue;
            }

            $payload = $enricher->lookup( $sku );
            if ( $payload === null ) { $missed++; continue; }

            $pid   = (int) $row['ID'];
            $draft = [
                'sku'             => $sku,
                '_hsync_flavor'   => 'goldensneakers',
                '_kicksdb_origin' => true,
                '_existing_id'    => $pid,  // hint for diff
            ];
            if ( $updateMode === 'preserve' ) {
                $draft = self::preloadFromWoo( $draft, $pid );
            }
            $draft   = $enricher->merge( $draft, $payload, $updateMode );
            $items[] = new FeedItem( sku: $sku, data: $draft, raw: $payload );
            $matched++;
        }

        // Advance cursor by the max ID we actually examined — even
        // unmatched rows must be skipped on the next run so we make
        // forward progress through the catalog.
        update_option( $cursorKey, $maxId, false );

        $warnings = [];
        if ( $matched === 0 && $scanned > 0 ) {
            $warnings[] = "Scansionati $scanned SKU Woo, 0 match su KicksDB (filtrati: $filtered, miss API: $missed). Verifica sku_pattern o api_key.";
        }

        return new FetchResult(
            items: $items,
            stats: [
                'cursor_before'       => $cursor,
                'cursor_after'        => $maxId,
                'woo_scanned'         => $scanned,
                'kicksdb_matched'     => $matched,
                'sku_pattern_filtered' => $filtered,
                'kicksdb_missed'      => $missed,
                'kicksdb_cache_count' => $cacheCount,
            ],
            warnings: $warnings,
        );
    }

    /**
     * Pre-load existing Woo product fields into the draft so the
     * KicksDB merge in `preserve` mode has something to defer to. Skip
     * variations — those are always rebuilt from KicksDB (the size run
     * is the whole point).
     */
    private static function preloadFromWoo( array $draft, int $productId ): array
    {
        if ( ! function_exists( 'wc_get_product' ) ) return $draft;
        $p = \wc_get_product( $productId );
        if ( ! $p ) return $draft;

        $name = (string) $p->get_name();
        if ( $name !== '' ) $draft['name'] = $name;

        $desc = (string) $p->get_description();
        if ( $desc !== '' ) $draft['description'] = $desc;

        $short = (string) $p->get_short_description();
        if ( $short !== '' ) $draft['short_description'] = $short;

        $imgId = (int) $p->get_image_id();
        if ( $imgId > 0 ) {
            $url = wp_get_attachment_url( $imgId );
            if ( $url ) {
                $draft['featured_image'] = $url;
                $draft['image_url']      = $url;
            }
        }

        $galleryIds = $p->get_gallery_image_ids();
        if ( $galleryIds ) {
            $urls = [];
            foreach ( $galleryIds as $id ) {
                $u = wp_get_attachment_url( (int) $id );
                if ( $u ) $urls[] = $u;
            }
            if ( $urls ) $draft['gallery_urls'] = $urls;
        }

        return $draft;
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
