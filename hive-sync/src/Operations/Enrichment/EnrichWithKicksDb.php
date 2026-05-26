<?php
declare(strict_types=1);

namespace HiveSync\Operations\Enrichment;

use HiveSync\Core\Operation\ImportRule;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;
use HiveSync\Core\Repo\KicksDbCacheRepository;
use HiveSync\Core\Repo\SourceConfigRepository;
use HiveSync\Core\Source\FeedItem;
use HiveSync\KicksDb\Client;
use HiveSync\KicksDb\Enricher;
use HiveSync\KicksDb\MarkupCalculator;

/**
 * ImportRule that enriches the in-flight draft with KicksDB data.
 *
 * Wire into the GS pipeline (the seeded `import-default` or a clone)
 * so every fetched GS item is augmented before materialize:
 *
 *   pre_check  ─ has_required_fields
 *   import_rule ─ kicksdb.enrich          ← THIS (early, before media.download)
 *   import_rule ─ media.download
 *   import_rule ─ taxonomy.auto_categorize
 *   import_rule ─ taxonomy.resolve
 *   post_check ─ …
 *
 * Place this step BEFORE media.download so the KicksDB-enriched
 * gallery URLs get sideloaded by the existing media pipeline. The
 * enricher just stuffs URLs into draft['gallery_urls']; media.download
 * picks them up exactly as if GS had supplied them.
 *
 * Params:
 *   - config_slug — slug of the saved KicksDB source-config. If empty,
 *     uses the first kicksdb config found. If none exists, the step
 *     no-ops silently with a row-trace marker so the pipeline keeps
 *     running.
 *
 * Performance: one cache lookup per item (~1ms). API only fires on
 * cache miss/expiry. The cooperative deadline + cursor resume already
 * carry this across ticks when a cold catalog needs many API calls.
 */
final class EnrichWithKicksDb implements ImportRule
{
    public const ID = 'kicksdb.enrich';

    public function id(): string    { return self::ID; }
    public function label(): string { return 'KicksDB — arricchisci con dati StockX (descrizioni, immagini, varianti)'; }
    public function appliesTo(): array { return [ 'simple', 'variable' ]; }

    public function paramsSchema(): array
    {
        return [
            'config_slug' => [
                'type'        => 'text',
                'label'       => 'Slug source-config KicksDB',
                'description' => 'Es. "kicksdb-prod". Se vuoto, usa la prima config KicksDB salvata. Configurala nel tab Connetti → KicksDB.',
            ],
        ];
    }

    public function applyDuringImport( FeedItem $item, array &$draft, array $params, OperationContext $ctx ): void
    {
        // Cache the Enricher per (slug, request) so a run with 10k
        // items doesn't rebuild the client + cache repo per call.
        static $cached = null;
        static $cachedSlug = null;

        $slug = (string) ( $params['config_slug'] ?? '' );
        if ( $cached === null || $cachedSlug !== $slug ) {
            $cached     = self::buildEnricher( $slug );
            $cachedSlug = $slug;
        }
        if ( $cached === null ) {
            // No config / no API key → silent no-op. Tag the draft so
            // the row trace surfaces "kicksdb not configured" to the
            // operator without breaking the pipeline.
            $draft['_kicksdb_enriched'] = false;
            $draft['_kicksdb_skip']     = 'not_configured';
            return;
        }

        $sku = $item->sku !== '' ? $item->sku : (string) ( $draft['sku'] ?? '' );
        if ( $sku === '' ) {
            $draft['_kicksdb_enriched'] = false;
            $draft['_kicksdb_skip']     = 'no_sku';
            return;
        }

        $payload = $cached->lookup( $sku );
        if ( $payload === null ) {
            $draft['_kicksdb_enriched'] = false;
            $draft['_kicksdb_skip']     = 'lookup_miss';
            return;
        }
        $draft = $cached->merge( $draft, $payload );
    }

    public function apply( int $productId, array $params, OperationContext $ctx ): OperationResult
    {
        // ImportRule-only: classic Operation entry point isn't meaningful
        // here. The Rule editor filters ImportRule ops out of its dropdown
        // (admin.js o.is_import_rule), so this branch shouldn't actually
        // be reached — defensive only.
        return OperationResult::noop();
    }

    /**
     * Build an Enricher from a saved source-config. Returns null when
     * the config is missing or the API key isn't set, so the ImportRule
     * can no-op without breaking the pipeline. The static caller wraps
     * this in a per-request memo so repeated items share one instance.
     */
    private static function buildEnricher( string $slug ): ?Enricher
    {
        $repo = new SourceConfigRepository();
        $row  = $slug !== '' ? $repo->find( $slug ) : null;
        if ( ! $row ) {
            // Fallback: first kicksdb config the operator saved.
            $all = $repo->all( 'kicksdb' );
            $row = $all[0] ?? null;
        }
        if ( ! $row ) return null;
        $config = (array) ( $row['config'] ?? [] );
        // Global toggle on the source-config: when disabled, the
        // enricher is a no-op everywhere without losing API key/markup
        // settings. One switch in Connetti turns the whole proxy off.
        if ( ( $config['enabled'] ?? 'yes' ) === 'no' ) return null;
        $apiKey = (string) ( $config['api_key'] ?? '' );
        if ( $apiKey === '' ) return null;

        $client = new Client(
            apiKey:  $apiKey,
            baseUrl: (string) ( $config['base_url'] ?? 'https://api.kicks.dev/v3' ),
            timeout: (int) ( $config['timeout'] ?? 30 ),
        );

        // Markup config can be nested under `markup` or flat at the
        // root — accept both for forgiveness in hand-edited configs.
        $markupCfg = (array) ( $config['markup'] ?? [] );
        $markupCfg['vat_percent'] = (float) ( $config['vat_percent']
            ?? $markupCfg['vat_percent']
            ?? 22.0 );
        $calc = MarkupCalculator::fromConfig( $markupCfg );

        return new Enricher(
            client:   $client,
            cache:    new KicksDbCacheRepository(),
            calc:     $calc,
            market:   (string) ( $config['market'] ?? 'IT' ),
            cacheTtl: (int) ( $config['cache_ttl'] ?? 86400 ),
        );
    }
}
