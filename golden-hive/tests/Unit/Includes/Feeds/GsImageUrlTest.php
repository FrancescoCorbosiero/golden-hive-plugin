<?php
declare(strict_types=1);

namespace GH\Tests\Unit\Includes\Feeds;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * GS changed its image format (Aug 2026): media moved to the
 * media.goldensneakers.net subdomain AND image_full_url went from base
 * FOLDER (to be joined with image_name) to complete file URL (with
 * image_name still holding the bare filename — blind concatenation
 * produced '…/x.png/x.png' → 404 on every product).
 *
 * These tests pin the full contract:
 *  - both formats assemble correctly (no duplication, legacy join kept);
 *  - host allowlist: apex + any *.goldensneakers.net subdomain over
 *    https; lookalike/third-party/http rejected;
 *  - COALESCE update semantics: a sync without an image never erases a
 *    known image, a new image overwrites, foreign media owners are
 *    respected.
 *
 * Process-isolated: loading feed-goldensneakers.php defines the whole
 * rp_rc_gs_* family, and GoldenSneakersSourceTest asserts the
 * "legacy NOT loaded" posture (function_exists === false) in the main
 * process. Separate processes keep both contracts true.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GsImageUrlTest extends TestCase
{
    private const NEW_FULL   = 'https://media.goldensneakers.net/products/images/2913_KJ8969/raw/c67b5534062a.png';
    private const OLD_FOLDER = 'https://www.goldensneakers.net/images/2913_KJ8969/main/';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../../includes/feeds/feed-goldensneakers.php';
    }

    // ── Join: entrambi i formati ─────────────────────────────────────

    public function testNewFullUrlFormatIsNotDuplicated(): void
    {
        // Aug 2026: image_full_url È GIÀ il file completo, image_name
        // porta ancora il filename → niente '…/x.png/x.png'.
        $this->assertSame(
            self::NEW_FULL,
            rp_rc_gs_join_image_url( self::NEW_FULL, 'c67b5534062a.png' )
        );
    }

    public function testNewFullUrlWithEmptyNameIsKept(): void
    {
        $this->assertSame( self::NEW_FULL, rp_rc_gs_join_image_url( self::NEW_FULL, '' ) );
    }

    public function testPathCheckSurvivesQueryString(): void
    {
        $withQuery = self::NEW_FULL . '?v=2';
        $this->assertSame(
            $withQuery,
            rp_rc_gs_join_image_url( $withQuery, 'c67b5534062a.png' ),
            'il confronto è sul PATH: la query string non deve causare il doppione'
        );
    }

    public function testLegacyFolderFormatStillJoins(): void
    {
        $this->assertSame(
            self::OLD_FOLDER . 'c67b5534062a.png',
            rp_rc_gs_join_image_url( self::OLD_FOLDER, 'c67b5534062a.png' )
        );
    }

    public function testFolderWithoutTrailingSlashJoinsWithExactlyOneSlash(): void
    {
        $this->assertSame(
            'https://media.goldensneakers.net/images/SKU/main/a.png',
            rp_rc_gs_join_image_url( 'https://media.goldensneakers.net/images/SKU/main', 'a.png' )
        );
        $this->assertSame(
            'https://media.goldensneakers.net/images/SKU/main/a.png',
            rp_rc_gs_join_image_url( 'https://media.goldensneakers.net/images/SKU/main/', '/a.png' )
        );
    }

    public function testAbsoluteNameWinsOverBase(): void
    {
        $this->assertSame( self::NEW_FULL, rp_rc_gs_join_image_url( self::OLD_FOLDER, self::NEW_FULL ) );
    }

    // ── Host allowlist ───────────────────────────────────────────────

    public function testApexAndAnySubdomainAccepted(): void
    {
        $this->assertTrue( rp_rc_gs_is_allowed_image_url( 'https://goldensneakers.net/a.png' ) );
        $this->assertTrue( rp_rc_gs_is_allowed_image_url( 'https://www.goldensneakers.net/a.png' ) );
        $this->assertTrue( rp_rc_gs_is_allowed_image_url( self::NEW_FULL ) );
        $this->assertTrue( rp_rc_gs_is_allowed_image_url( 'https://img-eu.static.goldensneakers.net/a.png' ) );
    }

    public function testLookalikeAndThirdPartyAndHttpRejected(): void
    {
        $this->assertFalse( rp_rc_gs_is_allowed_image_url( 'https://evilgoldensneakers.net/a.png' ), 'lookalike' );
        $this->assertFalse( rp_rc_gs_is_allowed_image_url( 'https://goldensneakers.net.evil.com/a.png' ), 'suffix abuse' );
        $this->assertFalse( rp_rc_gs_is_allowed_image_url( 'https://cdn.example.com/a.png' ), 'third party' );
        $this->assertFalse( rp_rc_gs_is_allowed_image_url( 'http://media.goldensneakers.net/a.png' ), 'http' );
        $this->assertFalse( rp_rc_gs_is_allowed_image_url( '' ) );
        $this->assertFalse( rp_rc_gs_is_allowed_image_url( 'not-a-url' ) );
    }

    // ── Normalizers: join + validazione integrati ────────────────────

    public function testNormalizersProduceValidatedUrlsForBothFormats(): void
    {
        // Hierarchical, formato nuovo (full URL + filename ridondante).
        $h = rp_rc_gs_normalize_hierarchical( [ [
            'sku' => '2913_KJ8969', 'name' => 'Nike Air Max', 'brand_name' => 'Nike',
            'image_full_url' => self::NEW_FULL, 'image_name' => 'c67b5534062a.png',
            'sizes' => [],
        ] ] );
        $this->assertSame( self::NEW_FULL, $h[0]['image_url'] );

        // Flat, formato legacy (cartella + filename).
        $f = rp_rc_gs_normalize_flat( [ [
            'sku' => '2913_KJ8969', 'product_name' => 'Nike Air Max', 'brand_name' => 'Nike',
            'image_full_url' => self::OLD_FOLDER, 'image_name' => 'c67b5534062a.png',
            'size_eu' => '42',
        ] ] );
        $this->assertSame( self::OLD_FOLDER . 'c67b5534062a.png', $f[0]['image_url'] );
    }

    public function testNormalizerRejectsForeignHostToEmpty(): void
    {
        $h = rp_rc_gs_normalize_hierarchical( [ [
            'sku' => 'X', 'name' => 'Y', 'brand_name' => 'Z',
            'image_full_url' => 'https://evilgoldensneakers.net/images/', 'image_name' => 'a.png',
            'sizes' => [],
        ] ] );
        $this->assertSame( '', $h[0]['image_url'], "host rifiutato → '' (nessuna immagine dal feed)" );
    }

    // ── COALESCE sull'update ─────────────────────────────────────────

    public function testSyncWithoutImageKeepsExistingImage(): void
    {
        // Feed senza immagine (o rifiutata) → l'immagine nota resta.
        $this->assertSame( 'skip', rp_rc_gs_image_update_action( '', 123, 0, 'goldensneakers' ) );
        $this->assertSame( 'skip', rp_rc_gs_image_update_action( '', 123, 0, '' ) );
    }

    public function testMissingFeaturedImageIsHealed(): void
    {
        // Prodotto rimasto senza immagine (finestra URL doppiati) →
        // il sync successivo la ripara.
        $this->assertSame( 'attach', rp_rc_gs_image_update_action( self::NEW_FULL, 0, 0, '' ) );
    }

    public function testLaterSyncWithNewImageOverwrites(): void
    {
        // URL nuovo (non mappato, o mappato a un attachment diverso
        // dalla featured corrente) → sovrascrive.
        $this->assertSame( 'attach', rp_rc_gs_image_update_action( self::NEW_FULL, 123, 0, '' ) );
        $this->assertSame( 'attach', rp_rc_gs_image_update_action( self::NEW_FULL, 123, 456, 'goldensneakers' ) );
    }

    public function testUnchangedImageIsANoOp(): void
    {
        // Stesso URL già mappato alla featured corrente → niente lavoro.
        $this->assertSame( 'skip', rp_rc_gs_image_update_action( self::NEW_FULL, 123, 123, 'goldensneakers' ) );
    }

    public function testForeignMediaOwnerIsRespected(): void
    {
        // La slice media appartiene a kicksdb/manual → GS non la clobbera
        // (ma il heal su prodotto SENZA immagine resta permesso).
        $this->assertSame( 'skip', rp_rc_gs_image_update_action( self::NEW_FULL, 123, 0, 'kicksdb' ) );
        $this->assertSame( 'skip', rp_rc_gs_image_update_action( self::NEW_FULL, 123, 0, 'manual' ) );
        $this->assertSame( 'attach', rp_rc_gs_image_update_action( self::NEW_FULL, 0, 0, 'kicksdb' ) );
    }
}
