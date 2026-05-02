<?php
declare(strict_types=1);

namespace HiveSync\Operations\Media;

use HiveSync\Core\Operation\ImportRule;
use HiveSync\Core\Operation\OperationContext;
use HiveSync\Core\Operation\OperationResult;
use HiveSync\Core\Source\FeedItem;

/**
 * Download external media URLs in the FeedItem into the WP media
 * library before materialize, replacing the raw URLs with attachment
 * ids the product factory understands.
 *
 * Reads from any of these draft fields (the GS + Woo conventions):
 *   - featured_image / image      (string URL — single)
 *   - image_full_url              (string URL — single, GS native)
 *   - gallery / gallery_urls      (string[] URLs — multiple)
 *
 * Writes back:
 *   - featured_image_id           (int) ← single-URL fields collapse here
 *   - gallery_image_ids           (int[]) ← gallery URLs become ids
 *
 * The host bridge wires hive_sync/host/media/preimport_batch to
 * gh_preimport_download_batch (curl_multi parallel) for throughput.
 * No-op on dry-run.
 *
 * params:
 *   concurrency  int  default 10  (passed through to the host downloader)
 *   skip_if_set  bool default true (don't re-download when ids already present)
 */
final class DownloadMedia implements ImportRule
{
    public const ID = 'media.download';

    private const SINGLE_URL_FIELDS = [ 'featured_image', 'image', 'image_full_url' ];
    private const GALLERY_FIELDS    = [ 'gallery', 'gallery_urls', 'images', 'gallery_full_urls' ];

    public function id(): string { return self::ID; }
    public function label(): string { return 'Download media (parallel)'; }

    public function paramsSchema(): array
    {
        return [
            'concurrency' => [
                'type'    => 'int',
                'label'   => 'Concorrenza (parallel curl handles)',
                'default' => 10,
            ],
            'skip_if_set' => [
                'type'    => 'bool',
                'label'   => 'Salta se gli id sono già presenti',
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
        // media.download is import-time only — not meaningful as a
        // post-import bulk operation against an existing productId.
        return OperationResult::failed( 'media.download is import-rule only; use it inside a Pipeline import phase.' );
    }

    public function applyDuringImport( FeedItem $item, array &$draft, array $params, OperationContext $ctx ): void
    {
        if ( $ctx->isDryRun() ) return;

        $skipIfSet  = (bool) ( $params['skip_if_set'] ?? true );
        $concurrency = max( 1, min( 32, (int) ( $params['concurrency'] ?? 10 ) ) );

        $featuredUrl = '';
        foreach ( self::SINGLE_URL_FIELDS as $f ) {
            if ( ! empty( $draft[ $f ] ) && is_string( $draft[ $f ] ) ) {
                $featuredUrl = (string) $draft[ $f ];
                break;
            }
        }

        $galleryUrls = [];
        foreach ( self::GALLERY_FIELDS as $f ) {
            if ( ! empty( $draft[ $f ] ) && is_array( $draft[ $f ] ) ) {
                foreach ( $draft[ $f ] as $u ) {
                    if ( is_string( $u ) && $u !== '' ) $galleryUrls[] = $u;
                }
            }
        }

        // De-duplicate and pull the featured out of the gallery list
        // (a featured image already counts as one piece of media).
        $galleryUrls = array_values( array_diff( array_unique( $galleryUrls ), [ $featuredUrl ] ) );

        if ( $skipIfSet ) {
            if ( $featuredUrl !== '' && ! empty( $draft['featured_image_id'] ) ) $featuredUrl = '';
            if ( ! empty( $draft['gallery_image_ids'] ) )                        $galleryUrls = [];
        }

        $allUrls = array_values( array_filter( array_merge( [ $featuredUrl ], $galleryUrls ) ) );
        if ( ! $allUrls ) return;

        if ( ! function_exists( 'hsync_preimport_media_batch' ) ) return;
        $map = \hsync_preimport_media_batch( $allUrls, [ 'concurrency' => $concurrency, 'sku' => $item->sku ] );

        if ( $featuredUrl !== '' && isset( $map[ $featuredUrl ] ) ) {
            $draft['featured_image_id'] = (int) $map[ $featuredUrl ];
        }
        if ( $galleryUrls ) {
            $ids = [];
            foreach ( $galleryUrls as $u ) {
                if ( isset( $map[ $u ] ) ) $ids[] = (int) $map[ $u ];
            }
            if ( $ids ) {
                $existing = (array) ( $draft['gallery_image_ids'] ?? [] );
                $draft['gallery_image_ids'] = array_values( array_unique( array_merge( $existing, $ids ) ) );
            }
        }

        // Optionally clear the URL fields once they're resolved so
        // downstream materialize doesn't re-process them.
        foreach ( self::SINGLE_URL_FIELDS as $f ) {
            if ( isset( $draft[ $f ] ) && isset( $draft['featured_image_id'] ) ) unset( $draft[ $f ] );
        }
        foreach ( self::GALLERY_FIELDS as $f ) {
            if ( isset( $draft[ $f ] ) && ! empty( $draft['gallery_image_ids'] ) ) unset( $draft[ $f ] );
        }
    }
}
